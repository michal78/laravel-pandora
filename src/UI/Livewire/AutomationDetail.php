<?php

declare(strict_types=1);

namespace Pandora\Pandora\UI\Livewire;

use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;
use Pandora\Pandora\Agents\Agent;
use Pandora\Pandora\Audit\AuditLogger;
use Pandora\Pandora\Automation\Automation;
use Pandora\Pandora\Automation\AutomationDispatcher;
use Pandora\Pandora\Automation\AutomationRun;
use Pandora\Pandora\Automation\AutomationScheduler;
use Pandora\Pandora\Automation\ConditionRegistry;
use Pandora\Pandora\Automation\Enums\AutomationTrigger;
use Pandora\Pandora\Automation\Enums\ConcurrencyPolicy;
use Pandora\Pandora\Automation\Enums\MisfirePolicy;
use Pandora\Pandora\Automation\Enums\OccurrenceStatus;
use Pandora\Pandora\Automation\Schedule\NextRun;
use Pandora\Pandora\Automation\WebhookDelivery;
use Pandora\Pandora\Automation\Webhooks\WebhookSignature;
use Pandora\Pandora\Exceptions\InvalidConfiguration;
use Pandora\Pandora\Runs\Enums\AutonomyLevel;
use Pandora\Pandora\UI\PandoraGate;

/**
 * One automation: what wakes it, what it is told, how far it may go, and what
 * it has actually been doing.
 *
 * Reading needs `pandora.access`; every write needs
 * `pandora.automations.manage`. Saving is per tab, for the same reason it is
 * on the agent page: a form that submits every attribute makes the audit entry
 * useless, because every save then looks like a change to everything.
 *
 * Two things this page refuses to do quietly:
 *
 *  - A schedule it cannot compute is rejected at save. Storing an unparseable
 *    cron expression produces a null `next_run_at` and an automation that
 *    simply never runs, with nothing in any log to notice.
 *  - The autonomy select is capped at the agent's own level, and the cap is
 *    stated. Offering a level that will be silently clamped teaches an
 *    operator that the field does not mean what it says.
 */
final class AutomationDetail extends Component
{
    public string $slug = '';

    #[Url(as: 'tab', except: 'overview')]
    public string $tab = 'overview';

    public bool $editing = false;

    public ?string $error = null;

    public ?string $saved = null;

    public ?string $revealedSecret = null;

    /** Overview */
    public string $name = '';

    public string $description = '';

    public string $prompt = '';

    /** Schedule */
    public string $triggerType = '';

    public string $cronExpression = '';

    public string $intervalSeconds = '';

    public string $runAt = '';

    public string $timezone = 'UTC';

    public string $eventClass = '';

    /** Behaviour */
    public string $concurrencyPolicy = '';

    public string $misfirePolicy = '';

    public string $conditionName = '';

    public string $autonomyLevel = '';

    public string $autonomyBudgetRuns = '';

    public string $autonomyBudgetWindow = '';

    public string $disableAfterFailures = '';

    public function mount(string $automation): void
    {
        PandoraGate::authorize('access');

        $this->slug = $automation;

        $found = $this->automation();

        if ($found === null) {
            abort(404);
        }

        $this->fillFrom($found);
    }

    /**
     * Scoped by the tenant global scope, so another tenant's slug resolves to
     * nothing and answers 404 -- the same answer a slug that never existed
     * gets, which is the point.
     */
    public function automation(): ?Automation
    {
        /** @var Automation|null $automation */
        $automation = Automation::query()->where('slug', $this->slug)->first();

        return $automation;
    }

    public function selectTab(string $tab): void
    {
        $this->tab = $tab;
        $this->editing = false;
        $this->error = null;
        $this->saved = null;
        $this->revealedSecret = null;
        $this->resetValidation();

        $automation = $this->automation();

        if ($automation !== null) {
            $this->fillFrom($automation);
        }
    }

    public function startEditing(): void
    {
        PandoraGate::authorize('automations.manage');

        $this->editing = true;
        $this->error = null;
        $this->saved = null;
    }

    public function cancelEditing(): void
    {
        $this->editing = false;
        $this->error = null;
        $this->resetValidation();

        $automation = $this->automation();

        if ($automation !== null) {
            $this->fillFrom($automation);
        }
    }

    public function save(AuditLogger $audit, NextRun $nextRun, AutomationScheduler $scheduler): void
    {
        PandoraGate::authorize('automations.manage');

        $automation = $this->automation();

        if ($automation === null) {
            abort(404);
        }

        $this->error = null;
        $this->saved = null;

        $this->validate($this->rulesForTab(), attributes: $this->validationAttributes());

        $candidate = $this->candidateAttributes($automation);

        $changes = [];

        foreach ($candidate as $key => $value) {
            if ($this->differs($this->storedValue($automation, $key), $value)) {
                $changes[$key] = $value;
            }
        }

        if ($changes === []) {
            $this->editing = false;
            $this->saved = 'No changes to save.';

            return;
        }

        $before = [];

        foreach (array_keys($changes) as $key) {
            $before[$key] = $this->storedValue($automation, $key);
        }

        // Validated against a COPY holding the proposed values, so a schedule
        // that cannot be computed is refused before it is stored rather than
        // discovered by an automation that never fires.
        $proposed = (clone $automation)->fill($changes);

        try {
            $nextRun->validate($proposed);
        } catch (InvalidConfiguration $e) {
            $this->error = $e->getMessage();

            return;
        }

        $automation->fill($changes)->save();

        // Recomputed from the new schedule. An automation that kept firing on
        // its old cron until the next occurrence would be reported as having
        // ignored the edit.
        if ($this->tab === 'schedule' && $automation->enabled) {
            $scheduler->advance($automation);
        }

        $audit->record(
            action: 'automation.updated',
            targetType: 'automation',
            targetId: $automation->id,
            metadata: [
                'slug' => $automation->slug,
                'tab' => $this->tab,
                'changed' => array_keys($changes),
                'before' => $before,
                'after' => $changes,
            ],
        );

        $this->editing = false;
        $this->saved = 'Saved.';
        $this->fillFrom($automation->refresh());
    }

    /**
     * Fire it now.
     *
     * An operator pressing this has decided the timing, so it bypasses the
     * schedule and the misfire policy. It does NOT bypass the condition, the
     * concurrency policy or the autonomy clamp: those describe what the
     * automation may do, not when, and "I ran it by hand" is not permission
     * for the agent to exceed its level.
     */
    public function runNow(AutomationDispatcher $dispatcher): void
    {
        PandoraGate::authorize('automations.manage');

        $automation = $this->automation();

        if ($automation === null) {
            abort(404);
        }

        $this->error = null;
        $this->saved = null;

        $occurrence = $dispatcher->dispatch(
            automation: $automation,
            occurrence: Carbon::now(),
            payload: ['manual' => true],
        );

        if ($occurrence === null) {
            $this->error = 'That occurrence was already claimed. Nothing was started.';

            return;
        }

        if ($occurrence->status === OccurrenceStatus::Dispatched) {
            $this->saved = 'Started. See History for the run.';

            return;
        }

        // A refusal is not an error -- the automation worked -- but it must
        // not read as a success either.
        $this->error = sprintf(
            'No run was created (%s): %s',
            $occurrence->status->value,
            $occurrence->error ?? $occurrence->reason ?? 'no reason recorded',
        );
    }

    /**
     * Mint a webhook secret.
     *
     * Shown exactly once, on this response, and never again: it is stored
     * encrypted and hidden from serialisation, so there is no second chance to
     * read it. Rotating is generating a new one, which invalidates the old.
     */
    public function rotateSecret(AuditLogger $audit): void
    {
        PandoraGate::authorize('automations.manage');

        $automation = $this->automation();

        if ($automation === null) {
            abort(404);
        }

        $secret = 'whsec_'.Str::random(48);

        $automation->forceFill(['webhook_secret' => $secret])->save();

        $audit->record(
            action: 'automation.updated',
            targetType: 'automation',
            targetId: $automation->id,
            severity: 'warning',
            metadata: [
                'slug' => $automation->slug,
                'tab' => 'webhook',
                'changed' => ['webhook_secret'],
                // Never the value, before or after.
                'note' => 'Webhook secret rotated. Previous signatures are now invalid.',
            ],
        );

        $this->revealedSecret = $secret;
        $this->saved = 'New secret generated. Copy it now -- it is not shown again.';
    }

    public function delete(AuditLogger $audit): void
    {
        PandoraGate::authorize('automations.manage');

        $automation = $this->automation();

        if ($automation === null) {
            abort(404);
        }

        $audit->record(
            action: 'automation.deleted',
            targetType: 'automation',
            targetId: $automation->id,
            severity: 'warning',
            metadata: ['slug' => $automation->slug, 'name' => $automation->name],
        );

        $automation->delete();

        $this->redirectRoute('pandora.automations', navigate: true);
    }

    public function render(ConditionRegistry $conditions): View
    {
        $automation = $this->automation();

        if ($automation === null) {
            abort(404);
        }

        /** @var Agent|null $agent */
        $agent = Agent::query()->find($automation->agent_id);

        return view('pandora::livewire.automation-detail', [
            'automation' => $automation,
            'agent' => $agent,
            'canManage' => PandoraGate::allows('automations.manage'),
            'canViewPrompts' => PandoraGate::allows('prompts.view'),
            'triggers' => AutomationTrigger::cases(),
            'concurrencyPolicies' => ConcurrencyPolicy::cases(),
            'misfirePolicies' => MisfirePolicy::cases(),
            // Capped at the agent's own level. Offering one that will be
            // silently clamped teaches an operator the field is decorative.
            'autonomyLevels' => $this->offerableLevels($agent),
            'agentLevel' => $agent?->autonomy_level,
            'conditions' => array_keys($conditions->all()),
            'occurrences' => $this->tab === 'history' ? $this->occurrences($automation) : new Collection,
            'deliveries' => $this->tab === 'webhook' ? $this->deliveries($automation) : new Collection,
            'webhookUrl' => $this->webhookUrl($automation),
            'signatureExample' => WebhookSignature::sign('YOUR_SECRET', '{"example":true}'),
            'timezones' => timezone_identifiers_list(),
        ])->layout('pandora::layouts.app', ['title' => $automation->name]);
    }

    // ------------------------------------------------------------------ inner

    /**
     * @return list<AutonomyLevel>
     */
    private function offerableLevels(?Agent $agent): array
    {
        if ($agent === null) {
            return [AutonomyLevel::ObserveOnly];
        }

        return array_values(array_filter(
            AutonomyLevel::cases(),
            static fn (AutonomyLevel $level): bool => $level->weight() <= $agent->autonomy_level->weight(),
        ));
    }

    /**
     * @return Collection<int, AutomationRun>
     */
    private function occurrences(Automation $automation): Collection
    {
        /** @var Collection<int, AutomationRun> $occurrences */
        $occurrences = AutomationRun::query()
            ->with('run')
            ->where('automation_id', $automation->getKey())
            ->latest('created_at')
            ->limit(50)
            ->get();

        return $occurrences;
    }

    /**
     * @return Collection<int, WebhookDelivery>
     */
    private function deliveries(Automation $automation): Collection
    {
        /** @var Collection<int, WebhookDelivery> $deliveries */
        $deliveries = WebhookDelivery::query()
            ->where('automation_id', $automation->getKey())
            ->latest('created_at')
            ->limit(50)
            ->get();

        return $deliveries;
    }

    private function webhookUrl(Automation $automation): ?string
    {
        if ($automation->trigger_type !== AutomationTrigger::Webhook) {
            return null;
        }

        /** @var string $prefix */
        $prefix = config('pandora.routes.prefix', 'pandora');
        /** @var string $path */
        $path = config('pandora.automation.webhooks.path', 'webhooks');

        return url("/{$prefix}/{$path}/{$automation->slug}");
    }

    private function fillFrom(Automation $automation): void
    {
        $this->name = $automation->name;
        $this->description = $automation->description ?? '';
        $this->prompt = $automation->prompt ?? '';

        $this->triggerType = $automation->trigger_type->value;
        $this->cronExpression = $automation->cron_expression ?? '';
        $this->intervalSeconds = $automation->interval_seconds === null ? '' : (string) $automation->interval_seconds;
        // In the automation's own zone: the field says "when this runs", and
        // the operator means it in the timezone next to it.
        $this->runAt = $automation->run_at?->setTimezone($automation->timezone)->format('Y-m-d\TH:i') ?? '';
        $this->timezone = $automation->timezone;
        $this->eventClass = $automation->event_class ?? '';

        $this->concurrencyPolicy = $automation->concurrency_policy->value;
        $this->misfirePolicy = $automation->misfire_policy->value;
        $this->conditionName = is_string($automation->condition['name'] ?? null)
            ? $automation->condition['name']
            : '';
        $this->autonomyLevel = $automation->autonomy_level->value;
        $this->autonomyBudgetRuns = $automation->autonomy_budget_runs === null
            ? ''
            : (string) $automation->autonomy_budget_runs;
        $this->autonomyBudgetWindow = (string) $automation->autonomy_budget_window_seconds;
        $this->disableAfterFailures = $automation->failureLimit() === null
            ? ''
            : (string) $automation->failureLimit();
    }

    /**
     * @return array<string, mixed>
     */
    private function candidateAttributes(Automation $automation): array
    {
        return match ($this->tab) {
            'overview' => [
                'name' => trim($this->name),
                'description' => trim($this->description) === '' ? null : trim($this->description),
                'prompt' => trim($this->prompt) === '' ? null : trim($this->prompt),
            ],
            'schedule' => [
                'trigger_type' => $this->triggerType,
                'cron_expression' => trim($this->cronExpression) === '' ? null : trim($this->cronExpression),
                'interval_seconds' => $this->intervalSeconds === '' ? null : (int) $this->intervalSeconds,
                'run_at' => $this->runAt === ''
                    ? null
                    : Carbon::parse($this->runAt, $this->timezone)->utc(),
                'timezone' => $this->timezone,
                'event_class' => trim($this->eventClass) === '' ? null : trim($this->eventClass),
            ],
            'behaviour' => [
                'concurrency_policy' => $this->concurrencyPolicy,
                'misfire_policy' => $this->misfirePolicy,
                'condition' => trim($this->conditionName) === ''
                    ? null
                    : ['name' => trim($this->conditionName), 'arguments' => $this->existingConditionArguments($automation)],
                'autonomy_level' => $this->autonomyLevel,
                'autonomy_budget_runs' => $this->autonomyBudgetRuns === '' ? null : (int) $this->autonomyBudgetRuns,
                'autonomy_budget_window_seconds' => (int) $this->autonomyBudgetWindow,
                'retry_policy' => $this->disableAfterFailures === ''
                    ? null
                    : ['disable_after_failures' => (int) $this->disableAfterFailures],
            ],
            default => [],
        };
    }

    /**
     * Condition arguments are not editable here -- they are shaped by whatever
     * the host's condition expects, and a JSON textarea is a worse editor than
     * none. Preserved so that changing nothing does not silently drop them.
     *
     * @return array<string, mixed>
     */
    private function existingConditionArguments(Automation $automation): array
    {
        $arguments = $automation->condition['arguments'] ?? [];

        return is_array($arguments) ? $arguments : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function rulesForTab(): array
    {
        return match ($this->tab) {
            'overview' => [
                'name' => ['required', 'string', 'min:2', 'max:120'],
                'description' => ['nullable', 'string', 'max:500'],
                'prompt' => ['nullable', 'string', 'max:8000'],
            ],
            'schedule' => [
                'triggerType' => ['required', Rule::in(array_column(AutomationTrigger::cases(), 'value'))],
                'cronExpression' => ['nullable', 'string', 'max:120'],
                // A one-second interval is a denial of service with a cron
                // field. Sixty is the floor because the scheduler ticks once a
                // minute and anything below it cannot be honoured anyway.
                'intervalSeconds' => ['nullable', 'integer', 'min:60', 'max:31536000'],
                'runAt' => ['nullable', 'date'],
                'timezone' => ['required', 'string', 'max:64'],
                'eventClass' => ['nullable', 'string', 'max:255'],
            ],
            'behaviour' => [
                'concurrencyPolicy' => ['required', Rule::in(array_column(ConcurrencyPolicy::cases(), 'value'))],
                'misfirePolicy' => ['required', Rule::in(array_column(MisfirePolicy::cases(), 'value'))],
                'conditionName' => ['nullable', 'string', 'max:120'],
                'autonomyLevel' => ['required', Rule::in(array_column(AutonomyLevel::cases(), 'value'))],
                'autonomyBudgetRuns' => ['nullable', 'integer', 'min:1', 'max:100000'],
                'autonomyBudgetWindow' => ['required', 'integer', 'min:60', 'max:31536000'],
                'disableAfterFailures' => ['nullable', 'integer', 'min:1', 'max:1000'],
            ],
            default => [],
        };
    }

    /**
     * @return array<string, string>
     */
    private function validationAttributes(): array
    {
        return [
            'triggerType' => 'trigger',
            'cronExpression' => 'cron expression',
            'intervalSeconds' => 'interval',
            'runAt' => 'run at',
            'eventClass' => 'event class',
            'concurrencyPolicy' => 'concurrency policy',
            'misfirePolicy' => 'misfire policy',
            'conditionName' => 'condition',
            'autonomyLevel' => 'autonomy level',
            'autonomyBudgetRuns' => 'autonomy budget',
            'autonomyBudgetWindow' => 'budget window',
            'disableAfterFailures' => 'failure limit',
        ];
    }

    /**
     * Has this attribute actually changed?
     *
     * `!==` is identity comparison for objects, so two dates representing the
     * same instant are always "different" -- which would mark `run_at` as
     * changed on every save of the Schedule tab, and put a spurious entry in
     * the audit log every time somebody edited a cron expression. The whole
     * point of the per-tab diff is that the audit trail says what changed.
     */
    private function differs(mixed $stored, mixed $candidate): bool
    {
        if ($stored instanceof CarbonInterface && $candidate instanceof CarbonInterface) {
            return ! $stored->equalTo($candidate);
        }

        // One side null and the other a date is a real change in either
        // direction, and falls through to the strict comparison below.
        return $stored !== $candidate;
    }

    private function storedValue(Automation $automation, string $key): mixed
    {
        $stored = $automation->getAttribute($key);

        // Enum and date casts would otherwise never equal what the form holds,
        // and every save would look like a change to everything.
        if ($stored instanceof AutonomyLevel
            || $stored instanceof AutomationTrigger
            || $stored instanceof ConcurrencyPolicy
            || $stored instanceof MisfirePolicy) {
            return $stored->value;
        }

        return $stored;
    }
}

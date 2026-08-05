<?php

declare(strict_types=1);

namespace Pandora\Pandora\UI\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\Component;
use Pandora\Pandora\Agents\Agent;
use Pandora\Pandora\Audit\AuditLogger;
use Pandora\Pandora\Automation\Automation;
use Pandora\Pandora\Automation\AutomationScheduler;
use Pandora\Pandora\Automation\Enums\AutomationTrigger;
use Pandora\Pandora\Automation\Observation;
use Pandora\Pandora\Automation\ObservationManager;
use Pandora\Pandora\Exceptions\ObservationNotPending;
use Pandora\Pandora\Runs\Enums\AutonomyLevel;
use Pandora\Pandora\UI\PandoraGate;

/**
 * Everything that runs without anybody typing, and what it is going to do next.
 *
 * `next_run_at` is the first column after the name, because the question an
 * operator arrives with is almost never "what automations exist" -- it is
 * "why hasn't the thing run" or "when will it". Times are shown in the
 * automation's OWN timezone, which is the one the person who configured it was
 * thinking in.
 *
 * The page also carries the goal queue: proposals agents have made for
 * themselves. They sit here rather than on their own page because promoting
 * one produces an automation, and the queue of things that might become
 * automations belongs beside the automations.
 *
 * Reading needs `pandora.access`. Everything that changes anything needs
 * `pandora.automations.manage`, checked here and again in the manager.
 */
final class AutomationsIndex extends Component
{
    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'status', except: '')]
    public string $statusFilter = '';

    public bool $creating = false;

    public string $newName = '';

    public string $newAgent = '';

    public ?string $error = null;

    public ?string $notice = null;

    public function mount(): void
    {
        PandoraGate::authorize('access');
    }

    public function startCreating(): void
    {
        PandoraGate::authorize('automations.manage');

        $this->creating = true;
        $this->newName = '';
        $this->newAgent = '';
        $this->error = null;
        $this->notice = null;
        $this->resetValidation();
    }

    public function cancelCreating(): void
    {
        $this->creating = false;
        $this->error = null;
        $this->resetValidation();
    }

    /**
     * Create an automation.
     *
     * A name and an agent, and nothing else -- the schedule, the prompt and
     * the autonomy level are set on the detail page where each field carries
     * the explanation it needs. Asking for all of it in one form produces
     * answers arrived at by guessing.
     *
     * It starts disabled, one-off, at `observe_only`. An automation that began
     * running the moment it was named would turn a half-finished thought into
     * an incident, at 3am, repeatedly.
     */
    public function create(AuditLogger $audit): void
    {
        PandoraGate::authorize('automations.manage');

        $this->validate([
            'newName' => ['required', 'string', 'min:2', 'max:120'],
            'newAgent' => ['required', 'string'],
        ], attributes: ['newName' => 'name', 'newAgent' => 'agent']);

        /** @var Agent|null $agent */
        $agent = Agent::query()->where('slug', $this->newAgent)->first();

        if ($agent === null) {
            $this->error = 'That agent no longer exists.';

            return;
        }

        $slug = $this->uniqueSlug(Str::slug($this->newName));

        if ($slug === null) {
            $this->error = 'That name does not produce a usable slug. Try one with letters or digits in it.';

            return;
        }

        /** @var int $failures */
        $failures = config('pandora.automation.retry.disable_after_failures', 5);
        /** @var int $budget */
        $budget = config('pandora.automation.autonomy.default_budget_runs', 24);
        /** @var int $window */
        $window = config('pandora.automation.autonomy.default_window_seconds', 86400);
        /** @var string $timezone */
        $timezone = config('app.timezone', 'UTC');

        /** @var Automation $automation */
        $automation = Automation::query()->create([
            'agent_id' => $agent->getKey(),
            'name' => trim($this->newName),
            'slug' => $slug,
            'trigger_type' => AutomationTrigger::OneOff->value,
            'run_at' => Carbon::now()->addDay(),
            'timezone' => $timezone,
            'concurrency_policy' => 'skip',
            'misfire_policy' => 'skip',
            'retry_policy' => ['disable_after_failures' => $failures],
            'autonomy_level' => AutonomyLevel::ObserveOnly->value,
            'autonomy_budget_runs' => $budget,
            'autonomy_budget_window_seconds' => $window,
            'enabled' => false,
        ]);

        $audit->record(
            action: 'automation.created',
            targetType: 'automation',
            targetId: $automation->id,
            metadata: ['slug' => $automation->slug, 'agent' => $agent->slug],
        );

        $this->creating = false;
        $this->redirectRoute('pandora.automations.show', ['automation' => $automation->slug], navigate: true);
    }

    /**
     * Turn one on or off.
     *
     * Enabling recomputes `next_run_at` rather than trusting whatever was
     * stored, because the schedule may have been edited while it was off and
     * an automation that fired on its old schedule after being re-enabled
     * would be indistinguishable from Pandora ignoring the edit.
     */
    public function toggle(string $id, AuditLogger $audit, AutomationScheduler $scheduler): void
    {
        PandoraGate::authorize('automations.manage');

        /** @var Automation|null $automation */
        $automation = Automation::query()->find($id);

        if ($automation === null) {
            return;
        }

        $enabling = ! $automation->enabled;

        $automation->forceFill([
            'enabled' => $enabling,
            // Cleared on disable so a re-enable cannot inherit a due date from
            // last month and fire immediately as a "misfire".
            'next_run_at' => null,
            'disabled_at' => $enabling ? null : now(),
            'disabled_reason' => $enabling ? null : 'Disabled from the control center.',
            'consecutive_failures' => $enabling ? 0 : $automation->consecutive_failures,
        ])->save();

        if ($enabling) {
            $scheduler->advance($automation);
        }

        $audit->record(
            action: $enabling ? 'automation.enabled' : 'automation.disabled',
            targetType: 'automation',
            targetId: $automation->id,
            severity: $enabling ? 'info' : 'warning',
            metadata: ['slug' => $automation->slug],
        );

        $this->notice = $enabling
            ? sprintf('%s is enabled. Next run %s.', $automation->name, $this->describeNext($automation->refresh()))
            : sprintf('%s is disabled.', $automation->name);
    }

    public function promote(string $id, ObservationManager $observations): void
    {
        $this->error = null;
        $this->notice = null;

        /** @var Observation|null $observation */
        $observation = Observation::query()->find($id);

        if ($observation === null) {
            return;
        }

        try {
            $automation = $observations->promote($observation);
        } catch (ObservationNotPending $e) {
            // Ordinary: two operators looking at the same queue.
            $this->error = $e->userMessage();

            return;
        }

        $this->redirectRoute('pandora.automations.show', ['automation' => $automation->slug], navigate: true);
    }

    public function dismiss(string $id, ObservationManager $observations): void
    {
        $this->error = null;

        /** @var Observation|null $observation */
        $observation = Observation::query()->find($id);

        if ($observation === null) {
            return;
        }

        try {
            $observations->dismiss($observation);
        } catch (ObservationNotPending $e) {
            $this->error = $e->userMessage();

            return;
        }

        $this->notice = 'Proposal dismissed.';
    }

    public function render(): View
    {
        $query = Automation::query()->with('agent')->orderBy('name');

        if ($this->statusFilter === 'enabled') {
            $query->where('enabled', true);
        } elseif ($this->statusFilter === 'disabled') {
            $query->where('enabled', false);
        }

        if ($this->search !== '') {
            $needle = '%'.$this->search.'%';

            $query->where(function (Builder $inner) use ($needle): void {
                $inner->where('name', 'like', $needle)->orWhere('slug', 'like', $needle);
            });
        }

        $canManage = PandoraGate::allows('automations.manage');

        return view('pandora::livewire.automations-index', [
            'automations' => $query->get(),
            'observations' => $canManage ? $this->pendingObservations() : new Collection,
            'agents' => Agent::query()->where('enabled', true)->orderBy('name')->get(),
            'canManage' => $canManage,
            'schedulerSeenAt' => $this->schedulerLastSeen(),
        ])->layout('pandora::layouts.app', ['title' => 'Automations']);
    }

    /**
     * @return Collection<int, Observation>
     */
    private function pendingObservations(): Collection
    {
        /** @var Collection<int, Observation> $observations */
        $observations = Observation::query()
            ->with('agent')
            ->where('status', 'pending')
            ->latest('created_at')
            ->limit(25)
            ->get();

        return $observations;
    }

    /**
     * The last time an occurrence was claimed by anything.
     *
     * Shown because the single most common automation problem is not an
     * automation problem: nobody is running `schedule:run`. An operator
     * staring at an automation that never fires should be able to see that
     * the scheduler itself has not been heard from since Tuesday.
     */
    private function schedulerLastSeen(): ?Carbon
    {
        /** @var Automation|null $latest */
        $latest = Automation::query()->whereNotNull('last_run_at')->latest('last_run_at')->first();

        return $latest?->last_run_at;
    }

    private function describeNext(Automation $automation): string
    {
        return $automation->next_run_at === null
            ? 'is not scheduled -- it waits for its trigger'
            : 'at '.$automation->next_run_at->setTimezone($automation->timezone)->toDayDateTimeString();
    }

    private function uniqueSlug(string $base): ?string
    {
        if ($base === '') {
            return null;
        }

        $slug = $base;

        for ($suffix = 2; $suffix < 100; $suffix++) {
            if (! Automation::query()->withTrashed()->where('slug', $slug)->exists()) {
                return $slug;
            }

            $slug = $base.'-'.$suffix;
        }

        return null;
    }
}

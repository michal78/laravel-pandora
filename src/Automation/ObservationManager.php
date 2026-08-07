<?php

declare(strict_types=1);

namespace Pandora\Automation;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Str;
use Pandora\Audit\AuditLogger;
use Pandora\Automation\Enums\AutomationTrigger;
use Pandora\Automation\Enums\ObservationStatus;
use Pandora\Core\Actor\ActorManager;
use Pandora\Exceptions\ObservationNotPending;
use Pandora\Runs\Enums\AutonomyLevel;
use Pandora\UI\PandoraGate;

/**
 * The human half of the goal queue.
 *
 * An agent proposes; a person decides. Everything here is gated on
 * `pandora.automations.manage` inside the method rather than at the page, so
 * there is one boundary rather than one per surface -- the Livewire component,
 * the console and anything a host writes all pass through this.
 *
 * A promoted observation becomes a **disabled one-off** automation. Not
 * enabled, not recurring:
 *
 *  - Disabled, because the person promoting it has approved the idea, not the
 *    schedule. Enabling is a second, deliberate act.
 *  - One-off, because the agent's `suggested_cron` is advisory. It is carried
 *    across so the editor can offer it, and it is not obeyed.
 */
final class ObservationManager
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ActorManager $actors,
        private readonly Config $config,
    ) {}

    /**
     * Turn a proposal into an automation somebody still has to switch on.
     *
     * @throws ObservationNotPending when it has already been decided
     */
    public function promote(Observation $observation): Automation
    {
        PandoraGate::authorize('automations.manage');

        if (! $observation->isPending()) {
            throw ObservationNotPending::make(
                (string) $observation->getKey(),
                $observation->status->value,
            );
        }

        /** @var int $failures */
        $failures = $this->config->get('pandora.automation.retry.disable_after_failures', 5);
        /** @var int $budget */
        $budget = $this->config->get('pandora.automation.autonomy.default_budget_runs', 24);
        /** @var int $window */
        $window = $this->config->get('pandora.automation.autonomy.default_window_seconds', 86400);

        /** @var Automation $automation */
        $automation = Automation::query()->create([
            'tenant_id' => $observation->tenant_id,
            'agent_id' => $observation->agent_id,
            'name' => $observation->title,
            'slug' => $this->uniqueSlug($observation->title),
            'description' => $observation->rationale,
            'trigger_type' => AutomationTrigger::OneOff->value,
            'timezone' => $this->defaultTimezone(),
            // The instruction the agent wrote, verbatim. Paraphrasing it here
            // would mean the thing that runs is not the thing that was
            // reviewed.
            'prompt' => $observation->proposal,
            // Carried as a suggestion for the editor, not as a schedule.
            'metadata' => array_filter(['suggested_cron' => $observation->suggested_cron]),
            'concurrency_policy' => 'skip',
            'misfire_policy' => 'skip',
            'retry_policy' => ['disable_after_failures' => $failures],
            // The most restrictive level, whatever the agent has. Somebody
            // approving an idea has not approved the agent acting on it.
            'autonomy_level' => AutonomyLevel::ObserveOnly->value,
            'autonomy_budget_runs' => $budget,
            'autonomy_budget_window_seconds' => $window,
            'enabled' => false,
        ]);

        $actor = $this->actors->current();

        $observation->forceFill([
            'status' => ObservationStatus::Promoted->value,
            'automation_id' => $automation->getKey(),
            'resolved_by_type' => $actor?->type,
            'resolved_by_id' => $actor?->id,
            'resolved_at' => now(),
        ])->save();

        $this->audit->record(
            action: 'observation.promoted',
            targetType: 'observation',
            targetId: $observation->id,
            metadata: [
                'title' => $observation->title,
                'automation' => $automation->slug,
                'enabled' => false,
            ],
        );

        return $automation;
    }

    public function dismiss(Observation $observation, ?string $comment = null): Observation
    {
        PandoraGate::authorize('automations.manage');

        if (! $observation->isPending()) {
            throw ObservationNotPending::make(
                (string) $observation->getKey(),
                $observation->status->value,
            );
        }

        $actor = $this->actors->current();

        $observation->forceFill([
            'status' => ObservationStatus::Dismissed->value,
            'comment' => $comment,
            'resolved_by_type' => $actor?->type,
            'resolved_by_id' => $actor?->id,
            'resolved_at' => now(),
        ])->save();

        $this->audit->record(
            action: 'observation.dismissed',
            targetType: 'observation',
            targetId: $observation->id,
            metadata: ['title' => $observation->title, 'comment' => $comment],
        );

        return $observation;
    }

    /**
     * Expire proposals nobody looked at.
     *
     * An agent suggestion sitting untouched for a month is not a decision
     * anybody should still be making from memory of what it was about.
     *
     * @return int how many expired
     */
    public function expire(): int
    {
        return Observation::query()
            ->where('status', ObservationStatus::Pending->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update(['status' => ObservationStatus::Expired->value]);
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'proposal';
        $slug = $base;
        $suffix = 2;

        // Unique per tenant, and the global scope already applies, so this
        // asks exactly the question the unique index will.
        while (Automation::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    private function defaultTimezone(): string
    {
        /** @var string $timezone */
        $timezone = $this->config->get('app.timezone', 'UTC');

        return $timezone;
    }
}

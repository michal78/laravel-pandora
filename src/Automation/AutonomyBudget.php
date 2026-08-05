<?php

declare(strict_types=1);

namespace Pandora\Pandora\Automation;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;
use Pandora\Pandora\Audit\AuditLogger;
use Pandora\Pandora\Automation\Enums\OccurrenceStatus;
use Pandora\Pandora\Automation\Notifications\AutomationDisabled;
use Pandora\Pandora\Exceptions\AutomationRefused;

/**
 * How often an automation may wake, and what happens when it has woken enough.
 *
 * `BudgetGuard` already bounds what a run may SPEND. What it cannot catch is an
 * automation that wakes every minute and returns immediately: each run is
 * cheap, the total is not, and no per-run token limit is ever reached. So
 * autonomy is budgeted in occurrences per rolling window, per automation.
 *
 * Exhausting it does not skip the occurrence. It disables the automation and
 * notifies, which is ADR-0009's requirement and the difference between a leash
 * and a silence: an automation that merely skipped would keep trying forever
 * and nobody would learn it was broken.
 */
final class AutonomyBudget
{
    public function __construct(
        private readonly Config $config,
        private readonly AuditLogger $audit,
        private readonly Container $container,
    ) {}

    /**
     * Assert this automation may wake again.
     *
     * Counts occurrences that actually became runs. A refused or skipped
     * occurrence consumed no autonomy -- charging for those would let a
     * misconfigured condition disable a healthy automation.
     *
     * @throws AutomationRefused
     */
    public function assert(Automation $automation): void
    {
        $limit = $automation->autonomy_budget_runs;

        if ($limit === null || $limit < 1) {
            return;
        }

        $window = max(1, $automation->autonomy_budget_window_seconds);

        $used = AutomationRun::query()
            ->where('automation_id', $automation->getKey())
            ->where('status', OccurrenceStatus::Dispatched->value)
            ->where('created_at', '>=', now()->subSeconds($window))
            ->count();

        if ($used < $limit) {
            return;
        }

        $refusal = AutomationRefused::autonomyBudgetExhausted($automation->slug, $limit, $window);

        $this->disable($automation, $refusal->getMessage());

        throw $refusal;
    }

    /**
     * Turn the automation off and tell somebody.
     *
     * Also used by the retry policy, which reaches the same conclusion by a
     * different route: something has been failing long enough that continuing
     * to try is no longer diagnosis, it is noise.
     */
    public function disable(Automation $automation, string $reason): void
    {
        $automation->forceFill([
            'enabled' => false,
            'next_run_at' => null,
            'disabled_at' => now(),
            'disabled_reason' => $reason,
        ])->save();

        $this->audit->record(
            action: 'automation.budget_exhausted',
            targetType: 'automation',
            targetId: $automation->id,
            severity: 'warning',
            metadata: [
                'slug' => $automation->slug,
                'name' => $automation->name,
                'reason' => $reason,
            ],
        );

        $this->notify($automation, $reason);
    }

    /**
     * Notify whoever the deployment nominated.
     *
     * Nothing here may throw. This runs on the failure path of an automation
     * that has already stopped, and a mail server being down must not turn a
     * disabled automation into a failed queue job that retries forever.
     */
    private function notify(Automation $automation, string $reason): void
    {
        /** @var list<string> $recipients */
        $recipients = $this->config->get('pandora.automation.autonomy.notify', []);

        if ($recipients === []) {
            return;
        }

        /** @var class-string<AutomationDisabled> $class */
        $class = $this->config->get(
            'pandora.automation.autonomy.notification',
            AutomationDisabled::class,
        );

        /** @var Notification $notification */
        $notification = $class::forAutomation($automation, $reason);

        foreach ($recipients as $recipient) {
            try {
                $this->route($recipient)->notify($notification);
            } catch (\RuntimeException $e) {
                // Recorded rather than raised. The automation is already off;
                // failing to say so must not also fail the job.
                $this->audit->record(
                    action: 'automation.notify_failed',
                    targetType: 'automation',
                    targetId: $automation->id,
                    severity: 'warning',
                    metadata: ['recipient' => $recipient, 'error' => $e->getMessage()],
                );
            }
        }
    }

    /**
     * A recipient is either a Notifiable class the container can build, or an
     * email address. Both are useful; neither should require the other.
     */
    private function route(string $recipient): mixed
    {
        if (class_exists($recipient)) {
            return $this->container->make($recipient);
        }

        return (new AnonymousNotifiable)->route('mail', $recipient);
    }
}

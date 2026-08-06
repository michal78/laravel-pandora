<?php

declare(strict_types=1);

namespace Pandora\Pandora\Jobs;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Pandora\Pandora\Automation\Automation;
use Pandora\Pandora\Automation\AutomationDispatcher;
use Pandora\Pandora\Automation\AutonomyBudget;
use Pandora\Pandora\Core\Actor\ActorManager;
use Pandora\Pandora\Core\Tenancy\TenantManager;
use Pandora\Pandora\Exceptions\PandoraException;

/**
 * Turn one claimed occurrence into a run, off the scheduler's thread.
 *
 * The tick that queued this did almost nothing: it read a row and wrote a
 * date. Everything with a cost -- resolving the agent, evaluating a condition
 * that might hit the database, creating a run -- happens here, so one slow
 * automation cannot make every other automation late.
 *
 * Retrying this job is safe and does nothing twice. The occurrence key is
 * carried on the payload rather than recomputed, so a retry claims the same
 * key, the unique index refuses it, and the dispatcher returns null. That is
 * the property the queue's at-least-once delivery makes necessary.
 */
final class RunAutomation implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use ResolvesPandoraContext;
    use SerializesModels;

    public int $tries = 3;

    /**
     * A system actor, always. An automation acts for nobody, and a tool whose
     * authorization depends on a user must therefore deny it rather than find
     * an ambient one left over from whoever last touched the queue.
     */
    public readonly ?string $actorType;

    public readonly ?string $actorId;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $automationId,
        public readonly ?string $tenantId = null,
        public readonly string $occurrence = '',
        public readonly ?string $idempotencyKey = null,
        public readonly array $payload = [],
        public readonly bool $synchronous = false,
    ) {
        $this->actorType = 'system';
        $this->actorId = 'automation';

        $this->onQueue(self::queueName('automation'));
        $this->onConnection(self::queueConnection());
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        /** @var list<int> $backoff */
        $backoff = config('pandora.runs.backoff', [5, 15, 60]);

        return $backoff;
    }

    public function handle(
        TenantManager $tenants,
        ActorManager $actors,
        AutomationDispatcher $dispatcher,
        AutonomyBudget $autonomy,
    ): void {
        $this->withPandoraContext($tenants, $actors, function () use ($dispatcher, $autonomy): void {
            /** @var Automation|null $automation */
            $automation = Automation::query()->find($this->automationId);

            // Deleted or disabled between the tick and the worker. A perfectly
            // ordinary race, and the right answer is to do nothing quietly:
            // the claim row already records that the occurrence existed.
            if ($automation === null || ! $automation->enabled) {
                return;
            }

            try {
                $dispatcher->dispatch(
                    automation: $automation,
                    occurrence: $this->occurrenceAt(),
                    payload: $this->payload,
                    idempotencyKey: $this->idempotencyKey,
                    synchronous: $this->synchronous,
                );
            } catch (PandoraException $e) {
                $this->recordFailure($automation, $autonomy, $e);

                throw $e;
            }
        });
    }

    private function occurrenceAt(): CarbonInterface
    {
        return $this->occurrence === '' ? Carbon::now() : Carbon::parse($this->occurrence);
    }

    /**
     * Count a dispatch failure against the retry policy.
     *
     * Distinct from the RUN failing: a run that fails is the agent's business
     * and shows on its trace. This counts the automation failing to produce a
     * run at all, which is a configuration problem, and enough of them in a
     * row mean continuing to try is no longer diagnosis.
     */
    private function recordFailure(Automation $automation, AutonomyBudget $autonomy, PandoraException $e): void
    {
        $failures = $automation->consecutive_failures + 1;

        $automation->forceFill(['consecutive_failures' => $failures])->save();

        $limit = $automation->failureLimit();

        if ($limit !== null && $failures >= $limit) {
            $autonomy->disable($automation, sprintf(
                'Disabled after %d consecutive failures. The last was: %s',
                $failures,
                $e->getMessage(),
            ));
        }
    }
}

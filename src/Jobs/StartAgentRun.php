<?php

declare(strict_types=1);

namespace Pandora\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Pandora\Audit\AuditLogger;
use Pandora\Core\Actor\ActorManager;
use Pandora\Core\Tenancy\TenantManager;
use Pandora\Exceptions\PandoraException;
use Pandora\Realtime\RunBroadcaster;
use Pandora\Runs\Enums\RunState;
use Pandora\Runs\Run;
use Pandora\Runs\RunStateMachine;

/**
 * Moves a run from `queued` to `running` and dispatches the first iteration.
 *
 * Kept separate from ContinueAgentRun so that one-time setup -- validation,
 * auditing, the started broadcast -- happens exactly once regardless of how
 * many iterations follow, and so a retry of an iteration cannot re-run it.
 */
final class StartAgentRun implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use ResolvesPandoraContext;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $runId,
        public readonly ?string $tenantId = null,
        public readonly ?string $actorType = null,
        public readonly ?string $actorId = null,
        /**
         * Run the whole chain in this process.
         *
         * Dispatching this job synchronously is not enough on its own: the
         * continuations it spawns would still go to the configured queue and
         * the caller would return while the run was only just starting. The
         * flag is carried through every continuation so that "execute and
         * wait" means exactly that, on any queue connection.
         */
        public readonly bool $synchronous = false,
    ) {
        $this->onQueue(self::queueName('agents'));
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
        RunStateMachine $states,
        RunBroadcaster $broadcaster,
        AuditLogger $audit,
        TenantManager $tenants,
        ActorManager $actors,
    ): void {
        $this->withPandoraContext($tenants, $actors, function () use ($states, $broadcaster, $audit): void {
            /** @var Run|null $run */
            $run = Run::query()->find($this->runId);

            if ($run === null) {
                return;
            }

            // A run cancelled before a worker picked it up.
            if ($run->isCancelRequested() || $run->state->isTerminal()) {
                return;
            }

            if ($run->state !== RunState::Queued) {
                return;
            }

            try {
                $previous = $run->state;
                $run = $states->transition($run, RunState::Starting);
                $broadcaster->stateChanged($run, $previous);

                $audit->record(
                    action: 'run.started',
                    targetType: Run::class,
                    targetId: (string) $run->getKey(),
                    runId: (string) $run->getKey(),
                    metadata: [
                        'agent_id' => $run->agent_id,
                        'trigger' => $run->trigger_type->value,
                        'provider' => $run->provider_key,
                        'model' => $run->model_key,
                    ],
                );

                $previous = $run->state;
                $run = $states->transition($run, RunState::Running);
                $broadcaster->stateChanged($run, $previous);

                $continue = new ContinueAgentRun(
                    (string) $run->getKey(),
                    $this->tenantId,
                    $this->actorType,
                    $this->actorId,
                    $this->synchronous,
                );

                $this->synchronous
                    ? dispatch_sync($continue)
                    : dispatch($continue);
            } catch (PandoraException $e) {
                $this->failRun($run, $e, $states, $broadcaster, $audit);
            }
        });
    }

    public function failed(\Throwable $exception): void
    {
        /** @var Run|null $run */
        $run = Run::query()->find($this->runId);

        if ($run === null || $run->state->isTerminal()) {
            return;
        }

        app(RunFailer::class)->fail($run, $exception);
    }

    private function failRun(
        Run $run,
        PandoraException $exception,
        RunStateMachine $states,
        RunBroadcaster $broadcaster,
        AuditLogger $audit,
    ): void {
        $previous = $run->state;

        $run = $states->transition($run, RunState::Failed, [
            'error_class' => $exception::class,
            'error_message' => $exception->getMessage(),
        ]);

        $broadcaster->stateChanged($run, $previous, $exception->userMessage());

        $audit->record(
            action: 'run.failed',
            targetType: Run::class,
            targetId: (string) $run->getKey(),
            runId: (string) $run->getKey(),
            severity: 'error',
            metadata: ['error_class' => $exception::class, 'error_code' => $exception->errorCode()],
        );
    }
}

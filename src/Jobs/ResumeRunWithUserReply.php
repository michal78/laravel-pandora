<?php

declare(strict_types=1);

namespace Pandora\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Pandora\Core\Actor\ActorManager;
use Pandora\Core\Tenancy\TenantManager;
use Pandora\Realtime\RunBroadcaster;
use Pandora\Runs\Enums\RunState;
use Pandora\Runs\Enums\RunStepStatus;
use Pandora\Runs\Enums\RunStepType;
use Pandora\Runs\Run;
use Pandora\Runs\RunStateMachine;
use Pandora\Runs\RunStepRecorder;

/**
 * Picks a run back up after the user answered its question.
 *
 * The mirror of ResumeApprovedRun, and for the same reason: the answer arrives
 * in a web request, and a web request must not wait on a model turn.
 *
 * The answer is already a message in the conversation by the time this runs,
 * so the next iteration picks it up through the ordinary context pipeline --
 * there is no second path by which a reply reaches the model.
 */
final class ResumeRunWithUserReply implements ShouldQueue
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
        public readonly bool $synchronous = false,
    ) {
        $this->onQueue(self::queueName('agents'));
        $this->onConnection(self::queueConnection());
    }

    public function handle(
        RunStateMachine $states,
        RunStepRecorder $steps,
        RunBroadcaster $broadcaster,
        TenantManager $tenants,
        ActorManager $actors,
    ): void {
        $this->withPandoraContext($tenants, $actors, function () use (
            $states, $steps, $broadcaster
        ): void {
            /** @var Run|null $run */
            $run = Run::query()->find($this->runId);

            if ($run === null || $run->state !== RunState::WaitingForUser) {
                return;
            }

            $steps->record(
                $run,
                RunStepType::UserQuestion,
                RunStepStatus::Succeeded,
                ['answered' => true],
                label: 'User answered',
            );

            $previous = $run->state;
            $run = $states->transition($run, RunState::Running);
            $broadcaster->stateChanged($run, $previous);

            ContinueAgentRun::dispatch(
                (string) $run->getKey(),
                $this->tenantId,
                $this->actorType,
                $this->actorId,
                $this->synchronous,
            );
        });
    }

    public function failed(\Throwable $exception): void
    {
        /** @var Run|null $run */
        $run = Run::query()->find($this->runId);

        if ($run !== null && ! $run->state->isTerminal()) {
            app(RunFailer::class)->fail($run, $exception);
        }
    }
}

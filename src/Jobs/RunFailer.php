<?php

declare(strict_types=1);

namespace Pandora\Jobs;

use Pandora\Audit\AuditLogger;
use Pandora\Exceptions\PandoraException;
use Pandora\Messages\MessageWriter;
use Pandora\Realtime\RunBroadcaster;
use Pandora\Runs\Enums\RunState;
use Pandora\Runs\Enums\RunStepStatus;
use Pandora\Runs\Enums\RunStepType;
use Pandora\Runs\Run;
use Pandora\Runs\RunStateMachine;
use Pandora\Runs\RunStepRecorder;
use Pandora\Support\Redactor;
use Psr\Log\LoggerInterface;

/**
 * Moves a run to a terminal state after its job has exhausted its retries.
 *
 * Without this a poison job would leave a run stuck in `running` forever, with
 * a UI spinner and no explanation. Called from every job's `failed()` hook, so
 * an unclassified `Throwable` still produces a correct terminal state, an
 * audit record and a safe user-facing message.
 */
final class RunFailer
{
    public function __construct(
        private readonly RunStateMachine $states,
        private readonly RunBroadcaster $broadcaster,
        private readonly RunStepRecorder $steps,
        private readonly MessageWriter $messages,
        private readonly AuditLogger $audit,
        private readonly LoggerInterface $logger,
        private readonly Redactor $redactor,
    ) {}

    public function fail(Run $run, \Throwable $exception): void
    {
        if ($run->state->isTerminal()) {
            return;
        }

        $isPandora = $exception instanceof PandoraException;

        $userMessage = $isPandora
            ? $exception->userMessage()
            : 'The agent run failed unexpectedly. An administrator can see the details.';

        // Unclassified failures are logged in full: they are the ones we most
        // need to see, and the ones the safe message deliberately hides.
        $this->logger->error('Pandora run failed', [
            'run_id' => $run->getKey(),
            'exception' => $exception::class,
            // Redacted even here. This log is the most useful artefact we
            // produce for an unclassified failure, and it is also the one most
            // likely to be shipped to a third-party service.
            'message' => $this->redactor->redactText($exception->getMessage()),
            'classified' => $isPandora,
        ]);

        $this->steps->record(
            $run,
            RunStepType::Error,
            RunStepStatus::Failed,
            ['classified' => $isPandora],
            errorClass: $exception::class,
            errorMessage: $exception->getMessage(),
        );

        $previous = $run->state;

        $run = $this->states->transition($run, RunState::Failed, [
            'error_class' => $exception::class,
            'error_message' => $exception->getMessage(),
        ]);

        foreach ($run->messages()->where('streaming_state', 'streaming')->get() as $message) {
            $this->messages->fail($message, $userMessage);
            $this->broadcaster->messageCompleted($run, $message, failed: true);
        }

        $this->broadcaster->stateChanged($run, $previous, $userMessage);

        $this->audit->record(
            action: 'run.failed',
            targetType: Run::class,
            targetId: (string) $run->getKey(),
            runId: (string) $run->getKey(),
            severity: 'error',
            metadata: ['error_class' => $exception::class, 'classified' => $isPandora],
        );
    }
}

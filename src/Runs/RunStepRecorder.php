<?php

declare(strict_types=1);

namespace Pandora\Pandora\Runs;

use Illuminate\Database\ConnectionInterface;
use Pandora\Pandora\Runs\Enums\RunStepStatus;
use Pandora\Pandora\Runs\Enums\RunStepType;
use Pandora\Pandora\Support\Redactor;

/**
 * Appends steps to a run's trace.
 *
 * The single write path for run steps, so redaction and sequencing are applied
 * once rather than trusted to every call site. Sequence allocation is
 * transactional; the unique index on (run_id, sequence) is the real guarantee.
 */
final class RunStepRecorder
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly Redactor $redactor,
    ) {}

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $rawMeta
     */
    public function record(
        Run $run,
        RunStepType $type,
        RunStepStatus $status = RunStepStatus::Succeeded,
        array $payload = [],
        ?string $label = null,
        array $rawMeta = [],
        ?int $inputTokens = null,
        ?int $outputTokens = null,
        ?int $durationMs = null,
        ?string $errorClass = null,
        ?string $errorMessage = null,
    ): RunStep {
        return $this->connection->transaction(function () use (
            $run, $type, $status, $payload, $label, $rawMeta,
            $inputTokens, $outputTokens, $durationMs, $errorClass, $errorMessage
        ): RunStep {
            $now = now();

            /** @var RunStep $step */
            $step = RunStep::query()->create([
                'tenant_id' => $run->tenant_id,
                'run_id' => $run->getKey(),
                'sequence' => $run->nextStepSequence(),
                'type' => $type->value,
                'status' => $status->value,
                'label' => $label,
                'payload' => $payload === [] ? null : $this->redactor->redact($payload),
                'raw_meta' => $rawMeta === [] ? null : $this->redactor->redact($rawMeta),
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'started_at' => $now,
                'finished_at' => $status === RunStepStatus::Started ? null : $now,
                'duration_ms' => $durationMs,
                'error_class' => $errorClass,
                // Redacted: a provider error can echo a request body, and
                // request bodies have contained credentials before.
                'error_message' => $errorMessage === null
                    ? null
                    : $this->redactor->redactText($errorMessage),
            ]);

            return $step;
        });
    }
}

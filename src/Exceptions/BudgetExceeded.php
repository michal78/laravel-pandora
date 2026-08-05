<?php

declare(strict_types=1);

namespace Pandora\Pandora\Exceptions;

/**
 * A run hit one of its limits. Always thrown BEFORE the expensive operation,
 * never after -- a run that would exceed its budget never makes the call.
 */
final class BudgetExceeded extends PandoraException
{
    private function __construct(
        string $message,
        public readonly string $limit,
    ) {
        parent::__construct($message);
    }

    public static function iterations(string $runId, int $max): self
    {
        return new self("Run [{$runId}] reached its maximum of {$max} iterations.", 'iterations');
    }

    public static function toolCalls(string $runId, int $max): self
    {
        return new self("Run [{$runId}] reached its maximum of {$max} tool calls.", 'tool_calls');
    }

    public static function duration(string $runId, int $seconds): self
    {
        return new self("Run [{$runId}] exceeded its {$seconds}s wall-clock limit.", 'duration');
    }

    public static function tokens(string $runId, int $limit): self
    {
        return new self("Run [{$runId}] exceeded its token budget of {$limit}.", 'tokens');
    }

    public function userMessage(): string
    {
        return 'The agent reached its configured limit for this run and stopped.';
    }
}

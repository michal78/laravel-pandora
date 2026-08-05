<?php

declare(strict_types=1);

namespace Pandora\Pandora\Support;

use Symfony\Component\Uid\Ulid;

/**
 * Threads a trigger through runs, jobs, steps, audit records and logs.
 *
 * Held per-process and copied onto queued jobs, so a webhook delivery and the
 * three runs it ultimately caused share one identifier.
 */
final class CorrelationId
{
    private ?string $current = null;

    public function current(): string
    {
        return $this->current ??= (string) new Ulid;
    }

    public function set(?string $id): void
    {
        $this->current = $id;
    }

    /**
     * @template TReturn
     *
     * @param \Closure(): TReturn $callback
     * @return TReturn
     */
    public function with(string $id, \Closure $callback): mixed
    {
        $previous = $this->current;
        $this->current = $id;

        try {
            return $callback();
        } finally {
            $this->current = $previous;
        }
    }
}

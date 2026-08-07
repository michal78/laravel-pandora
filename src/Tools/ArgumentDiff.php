<?php

declare(strict_types=1);

namespace Pandora\Tools;

/**
 * What a policy changed about a tool call's arguments.
 *
 * Argument modification is a genuinely useful capability and a genuinely
 * dangerous one, so it is never applied silently: the diff goes on the run
 * trace, into the audit log, and onto the approval card where a human can see
 * what they are actually approving.
 */
final readonly class ArgumentDiff
{
    /**
     * @param list<array{field: string, from: mixed, to: mixed}> $changes
     */
    private function __construct(
        public array $changes,
    ) {}

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    public static function between(array $before, array $after): self
    {
        $changes = [];

        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $field) {
            $from = $before[$field] ?? null;
            $to = $after[$field] ?? null;

            if ($from === $to) {
                continue;
            }

            $changes[] = ['field' => (string) $field, 'from' => $from, 'to' => $to];
        }

        return new self($changes);
    }

    public function isEmpty(): bool
    {
        return $this->changes === [];
    }

    /**
     * @return list<array{field: string, from: mixed, to: mixed}>
     */
    public function toArray(): array
    {
        return $this->changes;
    }

    /**
     * A one-line human summary, for a trace label or an audit entry.
     */
    public function summary(): string
    {
        return implode(', ', array_map(
            static fn (array $change): string => sprintf(
                '%s: %s -> %s',
                $change['field'],
                self::render($change['from']),
                self::render($change['to']),
            ),
            $this->changes,
        ));
    }

    private static function render(mixed $value): string
    {
        return match (true) {
            $value === null => 'none',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value),
        };
    }
}

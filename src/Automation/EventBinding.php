<?php

declare(strict_types=1);

namespace Pandora\Pandora\Automation;

use Closure;
use Pandora\Pandora\Runs\Enums\AutonomyLevel;

/**
 * A code-declared reaction to a Laravel event.
 *
 * The counterpart to a database automation of trigger type `event`. Both
 * exist because both are wanted: code for what belongs in version control and
 * gets reviewed, rows for what an operator adds at 3am.
 *
 * A code binding creates a run directly rather than going through the
 * occurrence machinery. It is a listener, not a schedule -- there is no
 * occurrence to claim, no misfire to catch up, and no history worth a table.
 * What it does NOT skip is the autonomy clamp.
 */
final readonly class EventBinding
{
    /**
     * @param Closure(object): array<string, mixed>|null $map
     * @param Closure(object): bool|null $when
     */
    public function __construct(
        public string $eventClass,
        public string $agentSlug,
        public ?string $prompt = null,
        public ?AutonomyLevel $autonomy = null,
        public ?Closure $map = null,
        public ?Closure $when = null,
        public ?string $queue = null,
    ) {}

    public function applies(object $event): bool
    {
        return $this->when === null || ($this->when)($event) === true;
    }

    /**
     * What the agent is told about the event.
     *
     * Deliberately NOT the serialised event. An event object is application
     * internals, frequently carries a whole Eloquent model, and putting it in
     * a prompt is how a customer's address ends up in a model request nobody
     * meant to send. The host says what the agent gets.
     *
     * @return array<string, mixed>
     */
    public function payload(object $event): array
    {
        return $this->map === null ? [] : ($this->map)($event);
    }

    public function instruction(object $event): string
    {
        return $this->prompt ?? sprintf(
            '%s fired. Decide whether anything needs doing, and report.',
            class_basename($event),
        );
    }
}

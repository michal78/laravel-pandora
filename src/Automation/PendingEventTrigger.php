<?php

declare(strict_types=1);

namespace Pandora\Pandora\Automation;

use Closure;
use Pandora\Pandora\Runs\Enums\AutonomyLevel;

/**
 * The builder behind `Pandora::on(OrderShipped::class)->run('support')`.
 *
 * Mutable and single-use, like `PendingAgentRun`: it is a declaration being
 * assembled at boot, it never escapes the call site, and immutability here
 * would cost readability for no safety gain.
 *
 * Nothing is registered until `run()` is called, which is what makes the
 * fluent form read as one statement rather than as a registration followed by
 * a series of amendments.
 */
final class PendingEventTrigger
{
    private ?string $prompt = null;

    private ?AutonomyLevel $autonomy = null;

    private ?Closure $map = null;

    private ?Closure $when = null;

    private ?string $queue = null;

    public function __construct(
        private readonly EventTriggerRegistry $registry,
        private readonly string $eventClass,
    ) {}

    /** What the agent is asked. Defaults to a report on the event. */
    public function withPrompt(string $prompt): self
    {
        $this->prompt = $prompt;

        return $this;
    }

    /**
     * Ask for less autonomy than the agent has.
     *
     * There is no way to ask for more: the level is clamped to the agent's
     * when the run is created, and a binding that could raise it would make
     * `Pandora::on()` a way around the agent's own configuration.
     */
    public function autonomy(AutonomyLevel $level): self
    {
        $this->autonomy = $level;

        return $this;
    }

    /**
     * Turn the event into the context the agent receives.
     *
     * Without this the agent gets nothing but the event's class name, which is
     * the safe default: an event object is application internals and often
     * carries a whole Eloquent model, so serialising one into a prompt is how
     * a customer's address reaches a model request nobody meant to send.
     *
     * @param Closure(object): array<string, mixed> $map
     */
    public function map(Closure $map): self
    {
        $this->map = $map;

        return $this;
    }

    /**
     * Fire only when this returns true. The cheap filter that keeps an agent
     * out of the 99% of events it has nothing to say about.
     *
     * @param Closure(object): bool $when
     */
    public function when(Closure $when): self
    {
        $this->when = $when;

        return $this;
    }

    public function onQueue(string $queue): self
    {
        $this->queue = $queue;

        return $this;
    }

    /** Register the binding. Nothing exists until this is called. */
    public function run(string $agentSlug): EventBinding
    {
        $binding = new EventBinding(
            eventClass: $this->eventClass,
            agentSlug: $agentSlug,
            prompt: $this->prompt,
            autonomy: $this->autonomy,
            map: $this->map,
            when: $this->when,
            queue: $this->queue,
        );

        $this->registry->add($binding);

        return $binding;
    }
}

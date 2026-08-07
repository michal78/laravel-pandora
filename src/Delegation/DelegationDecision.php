<?php

declare(strict_types=1);

namespace Pandora\Delegation;

use Pandora\Agents\Agent;

/**
 * The guard's answer: may this run delegate to this agent, and if not, why.
 *
 * A refusal carries the sentence the model reads AND the reason code an
 * operator searches for, together, because they are the same decision described
 * to two audiences. Returning only one of them is how a trace ends up saying
 * `delegation.denied` with nothing to explain it, or how a model is told "not
 * permitted" about something no audit entry records.
 */
final readonly class DelegationDecision
{
    /**
     * @param list<string> $effectiveTools the intersection, on an allowed decision
     * @param list<string> $withheldTools abilities the parent held and did not pass on
     */
    private function __construct(
        public bool $allowed,
        public ?Agent $target = null,
        public array $effectiveTools = [],
        public array $withheldTools = [],
        public ?DelegationRefusal $refusal = null,
        public ?string $reason = null,
    ) {}

    /**
     * @param list<string> $effectiveTools
     * @param list<string> $withheldTools
     */
    public static function allow(Agent $target, array $effectiveTools, array $withheldTools): self
    {
        return new self(
            allowed: true,
            target: $target,
            effectiveTools: $effectiveTools,
            withheldTools: $withheldTools,
        );
    }

    public static function refuse(DelegationRefusal $refusal, string $reason, ?Agent $target = null): self
    {
        return new self(
            allowed: false,
            target: $target,
            refusal: $refusal,
            reason: $reason,
        );
    }

    /**
     * What the trace records about this decision.
     *
     * The withheld list is here on an ALLOWED decision as well as a refused
     * one, because "the child could not do X" is the question asked after an
     * incident, and the delegation that granted less than it might have is
     * exactly the one nobody thought to write down at the time.
     *
     * @return array<string, mixed>
     */
    public function toTrace(): array
    {
        return array_filter([
            'allowed' => $this->allowed,
            'target_agent' => $this->target?->slug,
            'refusal' => $this->refusal?->value,
            'reason' => $this->reason,
            'effective_tools' => $this->effectiveTools === [] ? null : $this->effectiveTools,
            'withheld_tools' => $this->withheldTools === [] ? null : $this->withheldTools,
        ], static fn (mixed $value): bool => $value !== null);
    }
}

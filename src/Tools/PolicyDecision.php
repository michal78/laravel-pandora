<?php

declare(strict_types=1);

namespace Pandora\Pandora\Tools;

use Pandora\Pandora\Tools\Enums\PolicyOutcome;

/**
 * A ToolPolicy's verdict on one call.
 *
 * Every outcome that is not a plain `allow` carries a reason. The reason is
 * shown to the model when a call is refused and to the approver when a call
 * pauses, so "denied" is never the whole story anybody gets.
 */
final readonly class PolicyDecision implements \JsonSerializable
{
    /**
     * @param array<string, mixed>|null $arguments Replacement arguments, for ModifyArguments only.
     */
    private function __construct(
        public PolicyOutcome $outcome,
        public ?string $reason = null,
        public ?array $arguments = null,
        public bool $waivesApproval = false,
    ) {}

    /**
     * No objection from this layer.
     *
     * Deliberately NOT a waiver of the approval a tool's risk level demands:
     * a policy that has nothing to say about a critical tool must not thereby
     * wave it through. Use `allowWithoutApproval()` to say that on purpose.
     */
    public static function allow(): self
    {
        return new self(PolicyOutcome::Allow);
    }

    /**
     * Proceed without the approval this tool's risk level would otherwise
     * require. The one way to lower the floor, and it has to be written out.
     */
    public static function allowWithoutApproval(string $reason): self
    {
        return new self(PolicyOutcome::Allow, $reason, waivesApproval: true);
    }

    public static function deny(string $reason): self
    {
        return new self(PolicyOutcome::Deny, $reason);
    }

    public static function requireApproval(string $reason): self
    {
        return new self(PolicyOutcome::RequireApproval, $reason);
    }

    public static function requireConfirmation(string $reason): self
    {
        return new self(PolicyOutcome::RequireConfirmation, $reason);
    }

    /**
     * Proceed, but with these arguments instead.
     *
     * A real capability -- clamp a refund, force a tenant filter -- and
     * therefore a real risk, which is why the replacement is recorded as a
     * diff on the trace, in the audit log and on the approval card. Silent
     * rewriting is forbidden.
     *
     * @param array<string, mixed> $arguments
     */
    public static function modifyArguments(array $arguments, string $reason): self
    {
        return new self(PolicyOutcome::ModifyArguments, $reason, $arguments);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return array_filter([
            'outcome' => $this->outcome->value,
            'reason' => $this->reason,
        ], static fn (mixed $v): bool => $v !== null);
    }
}

<?php

declare(strict_types=1);

namespace Pandora\Pandora\Tools;

use Pandora\Pandora\Providers\Data\ToolCall;
use Pandora\Pandora\Tools\Enums\AuthorizationLayer;
use Pandora\Pandora\Tools\Enums\PolicyOutcome;
use Pandora\Pandora\Tools\Enums\RiskLevel;

/**
 * The gatekeeper's verdict on one tool call, after all five layers.
 *
 * Carries which layer decided and why, because "denied" alone tells an
 * operator nothing and tells the model even less.
 */
final readonly class ToolDecision implements \JsonSerializable
{
    private function __construct(
        public PolicyOutcome $outcome,
        public ToolCall $call,
        public AuthorizationLayer $layer,
        public ?Tool $tool = null,
        public ?ToolInput $input = null,
        public ?string $reason = null,
        public ?ArgumentDiff $diff = null,
    ) {}

    public static function allow(ToolCall $call, Tool $tool, ToolInput $input): self
    {
        return new self(PolicyOutcome::Allow, $call, AuthorizationLayer::Tool, $tool, $input);
    }

    public static function modified(
        ToolCall $call,
        Tool $tool,
        ToolInput $input,
        ArgumentDiff $diff,
        string $reason,
    ): self {
        return new self(
            PolicyOutcome::ModifyArguments,
            $call,
            AuthorizationLayer::Policy,
            $tool,
            $input,
            $reason,
            $diff,
        );
    }

    public static function deny(
        ToolCall $call,
        AuthorizationLayer $layer,
        string $reason,
        ?Tool $tool = null,
    ): self {
        return new self(PolicyOutcome::Deny, $call, $layer, $tool, null, $reason);
    }

    public static function requiresApproval(
        ToolCall $call,
        Tool $tool,
        ToolInput $input,
        string $reason,
        ?ArgumentDiff $diff = null,
    ): self {
        return new self(
            PolicyOutcome::RequireApproval,
            $call,
            AuthorizationLayer::Policy,
            $tool,
            $input,
            $reason,
            $diff,
        );
    }

    public static function requiresConfirmation(
        ToolCall $call,
        Tool $tool,
        ToolInput $input,
        string $reason,
        ?ArgumentDiff $diff = null,
    ): self {
        return new self(
            PolicyOutcome::RequireConfirmation,
            $call,
            AuthorizationLayer::Policy,
            $tool,
            $input,
            $reason,
            $diff,
        );
    }

    public function isAllowed(): bool
    {
        return $this->outcome->proceeds();
    }

    public function isDenied(): bool
    {
        return $this->outcome === PolicyOutcome::Deny;
    }

    public function pausesRun(): bool
    {
        return $this->outcome->pausesRun();
    }

    public function wasModified(): bool
    {
        return $this->diff !== null && ! $this->diff->isEmpty();
    }

    public function risk(): RiskLevel
    {
        return $this->tool?->risk() ?? RiskLevel::Low;
    }

    /**
     * The arguments to execute with — the modified ones when a policy changed
     * them, otherwise exactly what the model sent, minus anything it invented
     * that the tool never declared.
     *
     * @return array<string, mixed>
     */
    public function arguments(): array
    {
        return $this->input?->toArray() ?? [];
    }

    /**
     * What the model is told when its call does not proceed. Specific on
     * purpose: a model that knows why it was refused stops trying.
     */
    public function modelMessage(): string
    {
        return match ($this->outcome) {
            PolicyOutcome::Deny => 'The call to ['.$this->call->name.'] was not permitted. '
                .($this->reason ?? 'No reason was recorded.'),
            PolicyOutcome::RequireApproval => 'The call to ['.$this->call->name
                .'] is waiting for human approval.',
            PolicyOutcome::RequireConfirmation => 'The call to ['.$this->call->name
                .'] is waiting for the user to confirm it.',
            default => 'The call to ['.$this->call->name.'] was permitted.',
        };
    }

    /**
     * The trace projection. Arguments are omitted deliberately: they are
     * recorded once, redacted, on the tool execution row, and duplicating them
     * into every step would widen the blast radius of a trace leak.
     *
     * @return array<string, mixed>
     */
    public function toTrace(): array
    {
        return array_filter([
            'tool' => $this->call->name,
            'tool_call_id' => $this->call->id,
            'outcome' => $this->outcome->value,
            'decided_by' => $this->layer->value,
            'risk' => $this->risk()->value,
            'reason' => $this->reason,
            'argument_diff' => $this->diff?->toArray(),
        ], static fn (mixed $v): bool => $v !== null && $v !== []);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toTrace();
    }
}

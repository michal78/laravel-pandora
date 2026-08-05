<?php

declare(strict_types=1);

namespace Pandora\Pandora\Tools\Enums;

/**
 * What a ToolPolicy decided — authorization layer 4.
 *
 * Five outcomes, not two, because "yes or no" cannot express the decisions
 * operators actually need: pause for a human, ask the person in the chat, or
 * let the call through with its arguments clamped.
 */
enum PolicyOutcome: string
{
    /** Proceed. Still subject to Tool::authorize() — layer 4 cannot overrule layer 5. */
    case Allow = 'allow';

    /** Refuse. The model is told, and the run continues. */
    case Deny = 'deny';

    /** Pause for someone holding `pandora.approvals.resolve`. */
    case RequireApproval = 'require_approval';

    /** Pause for the acting user themselves — an in-band "are you sure?". */
    case RequireConfirmation = 'require_confirmation';

    /** Proceed with different arguments. Always diffed, never silent. */
    case ModifyArguments = 'modify_arguments';

    public function pausesRun(): bool
    {
        return $this === self::RequireApproval || $this === self::RequireConfirmation;
    }

    public function proceeds(): bool
    {
        return $this === self::Allow || $this === self::ModifyArguments;
    }
}

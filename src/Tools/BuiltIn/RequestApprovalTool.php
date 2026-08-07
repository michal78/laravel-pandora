<?php

declare(strict_types=1);

namespace Pandora\Tools\BuiltIn;

use Pandora\Tools\Enums\RiskLevel;
use Pandora\Tools\Tool;
use Pandora\Tools\ToolContext;
use Pandora\Tools\ToolInput;
use Pandora\Tools\ToolResult;

/**
 * Ask a human to sign off on something the agent is about to do.
 *
 * Deliberately `critical` risk, which is what makes it pause: the tool has no
 * behaviour of its own beyond stopping and asking, and it stops because the
 * ordinary risk floor applies to it like anything else. An agent can raise its
 * own bar; it can never lower one.
 */
final class RequestApprovalTool extends Tool
{
    public function name(): string
    {
        return 'request_approval';
    }

    public function description(): string
    {
        return 'Pause and ask a human to approve a course of action before you take it. '
            .'Use this when you judge something consequential enough to need a person, '
            .'even if no rule requires it.';
    }

    public function group(): string
    {
        return 'conversation';
    }

    public function rules(): array
    {
        return [
            'summary' => 'required|string|min:3|max:500',
            'detail' => 'nullable|string|max:4000',
        ];
    }

    public function descriptions(): array
    {
        return [
            'summary' => 'One line describing exactly what you propose to do.',
            'detail' => 'Anything the approver needs in order to decide.',
        ];
    }

    public function risk(): RiskLevel
    {
        return RiskLevel::Critical;
    }

    public function summarize(ToolInput $input): string
    {
        return (string) $input->string('summary', 'Requested approval');
    }

    public function authorize(ToolInput $input, ToolContext $context): bool
    {
        return true;
    }

    /**
     * Only ever reached once a human has already approved -- the risk level
     * guarantees the pause, and the pause is the whole feature.
     */
    public function handle(ToolInput $input, ToolContext $context): ToolResult
    {
        return ToolResult::success(
            'A human approved: '.$input->string('summary'),
            ['approved' => true],
        );
    }
}

<?php

declare(strict_types=1);

namespace Pandora\Tests\Fixtures\Tools;

use Pandora\Tools\Enums\RiskLevel;
use Pandora\Tools\Tool;
use Pandora\Tools\ToolContext;
use Pandora\Tools\ToolInput;
use Pandora\Tools\ToolResult;

/**
 * A tool that asks rather than answers, parking the run at `waiting_for_user`.
 *
 * `ToolResult::awaitingUser()` had no fixture and no test anywhere in the suite
 * before Phase 8's walkthrough, which is why a channel could silently drop the
 * question (finding 13).
 */
final class AskUserTool extends Tool
{
    public function name(): string
    {
        return 'ask_user';
    }

    public function description(): string
    {
        return 'Ask the person a clarifying question and wait for their answer.';
    }

    public function rules(): array
    {
        return ['question' => 'required|string|min:1|max:500'];
    }

    public function descriptions(): array
    {
        return ['question' => 'What to ask.'];
    }

    public function risk(): RiskLevel
    {
        return RiskLevel::Low;
    }

    public function handle(ToolInput $input, ToolContext $context): ToolResult
    {
        return ToolResult::awaitingUser($input->string('question'));
    }
}

<?php

declare(strict_types=1);

namespace Pandora\Tools\BuiltIn;

use Pandora\Tools\Enums\RiskLevel;
use Pandora\Tools\Tool;
use Pandora\Tools\ToolContext;
use Pandora\Tools\ToolInput;
use Pandora\Tools\ToolResult;

/**
 * Ask the user something and wait for the answer.
 *
 * The cheapest safety feature in the system: an agent that is unsure can stop
 * and ask instead of guessing, and the run costs nothing while it waits.
 */
final class AskUserTool extends Tool
{
    public function name(): string
    {
        return 'ask_user';
    }

    public function description(): string
    {
        return 'Ask the user a clarifying question and wait for their answer. '
            .'Use this instead of guessing when a request is ambiguous or is missing '
            .'information you cannot look up.';
    }

    public function group(): string
    {
        return 'conversation';
    }

    public function rules(): array
    {
        return ['question' => 'required|string|min:3|max:2000'];
    }

    public function descriptions(): array
    {
        return ['question' => 'The question to put to the user, in plain language.'];
    }

    public function risk(): RiskLevel
    {
        return RiskLevel::Low;
    }

    public function summarize(ToolInput $input): string
    {
        return (string) $input->string('question', 'Ask the user a question');
    }

    /**
     * Only in a conversation. Asking a question in a run nobody is watching --
     * an automation, a webhook -- would park it until it expired.
     */
    public function authorize(ToolInput $input, ToolContext $context): bool
    {
        return $context->run->conversation_id !== null;
    }

    public function handle(ToolInput $input, ToolContext $context): ToolResult
    {
        return ToolResult::awaitingUser((string) $input->string('question'));
    }
}

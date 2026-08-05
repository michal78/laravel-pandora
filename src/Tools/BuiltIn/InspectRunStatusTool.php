<?php

declare(strict_types=1);

namespace Pandora\Pandora\Tools\BuiltIn;

use Pandora\Pandora\Tools\Enums\RiskLevel;
use Pandora\Pandora\Tools\Tool;
use Pandora\Pandora\Tools\ToolContext;
use Pandora\Pandora\Tools\ToolInput;
use Pandora\Pandora\Tools\ToolResult;

/**
 * Let an agent see its own budget.
 *
 * Reads only this run, never another. An agent that knows it has two
 * iterations left can wrap up deliberately instead of being cut off mid-thought.
 */
final class InspectRunStatusTool extends Tool
{
    public function name(): string
    {
        return 'inspect_run_status';
    }

    public function description(): string
    {
        return 'Check how much of this run\'s budget remains: iterations, tool calls '
            .'and time. Use it to decide whether to keep working or to summarise now.';
    }

    public function group(): string
    {
        return 'introspection';
    }

    public function risk(): RiskLevel
    {
        return RiskLevel::Low;
    }

    /**
     * A run may always read itself. There is nothing here the agent did not
     * already cause, and nothing belonging to anybody else.
     */
    public function authorize(ToolInput $input, ToolContext $context): bool
    {
        return true;
    }

    public function handle(ToolInput $input, ToolContext $context): ToolResult
    {
        $run = $context->run;
        $agent = $context->agent;

        $remainingIterations = max(0, $agent->max_iterations - $run->iterations);
        $remainingToolCalls = max(0, $agent->max_tool_calls - $run->tool_calls_count);
        $secondsLeft = $run->deadline_at === null
            ? null
            : max(0, (int) now()->diffInSeconds($run->deadline_at, false));

        $data = [
            'state' => $run->state->value,
            'iterations_used' => $run->iterations,
            'iterations_remaining' => $remainingIterations,
            'tool_calls_used' => $run->tool_calls_count,
            'tool_calls_remaining' => $remainingToolCalls,
            'seconds_remaining' => $secondsLeft,
            'input_tokens' => $run->input_tokens,
            'output_tokens' => $run->output_tokens,
        ];

        return ToolResult::success(sprintf(
            '%d of %d iterations and %d of %d tool calls remain%s.',
            $remainingIterations,
            $agent->max_iterations,
            $remainingToolCalls,
            $agent->max_tool_calls,
            $secondsLeft === null ? '' : ", with {$secondsLeft}s left",
        ), $data);
    }
}

<?php

declare(strict_types=1);

namespace Pandora\Tests\Fixtures\Tools;

use Illuminate\Support\Carbon;
use Pandora\Tools\Tool;
use Pandora\Tools\ToolContext;
use Pandora\Tools\ToolInput;
use Pandora\Tools\ToolResult;

/**
 * A tool that takes time -- the only way to reach the wall-clock limit.
 *
 * `deadline_at` is stamped when the run is created and checked at the top of
 * each iteration, so a run only exceeds it if the clock moves BETWEEN
 * iterations. A test cannot sleep for a realistic timeout, and it cannot reach
 * in between two iterations of a run executing inline. A tool can: it runs
 * exactly there, and moving the test clock inside it is the same shape as a
 * real tool that took two minutes to answer.
 */
final class SlowTool extends Tool
{
    /** How far the clock jumps when this tool runs. */
    public static int $advanceSeconds = 0;

    /**
     * When set, the running agent's `max_duration_seconds` is widened to this
     * from inside the run -- the only place an operator's edit can land
     * mid-flight, and the case `deadline_at` is stamped at creation to defeat.
     */
    public static ?int $widenAgentDurationTo = null;

    public function name(): string
    {
        return 'slow_tool';
    }

    public function description(): string
    {
        return 'Take a long time to answer.';
    }

    public function rules(): array
    {
        return [];
    }

    public function handle(ToolInput $input, ToolContext $context): ToolResult
    {
        if (self::$widenAgentDurationTo !== null) {
            $context->agent->forceFill(['max_duration_seconds' => self::$widenAgentDurationTo])->save();
        }

        if (self::$advanceSeconds > 0) {
            Carbon::setTestNow(Carbon::now()->addSeconds(self::$advanceSeconds));
        }

        return ToolResult::success('Took a while.');
    }
}

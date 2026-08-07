<?php

declare(strict_types=1);

namespace Pandora\Tests\Fixtures\Tools;

use Pandora\Tools\Tool;
use Pandora\Tools\ToolContext;
use Pandora\Tools\ToolInput;
use Pandora\Tools\ToolResult;

/**
 * A tool with a visible side effect, so "ran once" and "ran twice" can be told
 * apart. Everything about idempotency is unprovable without one.
 */
final class CountingTool extends Tool
{
    public static int $calls = 0;

    public function name(): string
    {
        return 'counting_tool';
    }

    public function description(): string
    {
        return 'Increment a counter.';
    }

    public function rules(): array
    {
        return ['label' => 'required|string|max:32'];
    }

    public function handle(ToolInput $input, ToolContext $context): ToolResult
    {
        self::$calls++;

        return ToolResult::success('Counted '.$input->string('label').'.', ['calls' => self::$calls]);
    }
}

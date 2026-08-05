<?php

declare(strict_types=1);

namespace Pandora\Pandora\Tests\Fixtures\Tools;

use Pandora\Pandora\Tools\Tool;
use Pandora\Pandora\Tools\ToolContext;
use Pandora\Pandora\Tools\ToolInput;
use Pandora\Pandora\Tools\ToolResult;

/**
 * An earlier version of `lookup_order`, kept registered so conversations that
 * are mid-flight do not break.
 */
final class LegacyLookupTool extends Tool
{
    public function name(): string
    {
        return 'lookup_order';
    }

    public function description(): string
    {
        return 'Look up an order by its reference.';
    }

    public function version(): string
    {
        return '0.9';
    }

    public function deprecated(): ?string
    {
        return 'Use lookup_order@1.0, which accepts include_lines.';
    }

    public function rules(): array
    {
        return ['reference' => 'required|string'];
    }

    public function handle(ToolInput $input, ToolContext $context): ToolResult
    {
        return ToolResult::success('Order found.');
    }
}

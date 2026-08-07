<?php

declare(strict_types=1);

namespace Pandora\Tests\Fixtures\Tools;

use Pandora\Tools\Enums\RiskLevel;
use Pandora\Tools\Tool;
use Pandora\Tools\ToolContext;
use Pandora\Tools\ToolInput;
use Pandora\Tools\ToolResult;

/**
 * A representative read-only tool: typed arguments, real rules, low risk.
 */
final class LookupOrderTool extends Tool
{
    public function name(): string
    {
        return 'lookup_order';
    }

    public function description(): string
    {
        return 'Look up an order by its reference.';
    }

    public function rules(): array
    {
        return [
            'reference' => 'required|string|min:3|max:32',
            'include_lines' => 'boolean',
        ];
    }

    public function descriptions(): array
    {
        return ['reference' => 'The customer-facing order reference.'];
    }

    public function risk(): RiskLevel
    {
        return RiskLevel::Low;
    }

    public function handle(ToolInput $input, ToolContext $context): ToolResult
    {
        return ToolResult::success(
            "Order {$input->string('reference')} is shipped.",
            ['reference' => $input->string('reference'), 'status' => 'shipped'],
        );
    }
}

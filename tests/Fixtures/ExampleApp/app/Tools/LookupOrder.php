<?php // app/Tools/LookupOrder.php

namespace App\Tools;

use Pandora\Tools\Enums\RiskLevel;
use Pandora\Tools\Tool;
use Pandora\Tools\ToolContext;
use Pandora\Tools\ToolInput;
use Pandora\Tools\ToolResult;

final class LookupOrder extends Tool
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
        return ['reference' => 'required|string|max:32'];
    }

    public function risk(): RiskLevel
    {
        return RiskLevel::Low;
    }

    // The console run below acts as the system rather than as a user, which
    // the default authorize() refuses. This tool reads nothing private.
    public function authorize(ToolInput $input, ToolContext $context): bool
    {
        return $context->actor !== null;
    }

    public function handle(ToolInput $input, ToolContext $context): ToolResult
    {
        $orders = ['1234' => 'shipped on Tuesday'];
        $reference = (string) $input->string('reference');

        return isset($orders[$reference])
            ? ToolResult::success("Order {$reference} {$orders[$reference]}.")
            : ToolResult::failure('No order has that reference.');
    }
}

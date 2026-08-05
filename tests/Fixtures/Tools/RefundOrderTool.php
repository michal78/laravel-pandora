<?php

declare(strict_types=1);

namespace Pandora\Pandora\Tests\Fixtures\Tools;

use Pandora\Pandora\Tools\Enums\RiskLevel;
use Pandora\Pandora\Tools\Tool;
use Pandora\Pandora\Tools\ToolContext;
use Pandora\Pandora\Tools\ToolInput;
use Pandora\Pandora\Tools\ToolResult;

/**
 * A representative high-risk tool: money moves, so it needs approval.
 */
final class RefundOrderTool extends Tool
{
    /** @var list<array{reference: string, amount: int}> */
    public static array $refunds = [];

    public function name(): string
    {
        return 'refund_order';
    }

    public function description(): string
    {
        return 'Refund an order to the customer.';
    }

    public function group(): string
    {
        return 'billing';
    }

    public function aliases(): array
    {
        return ['issue_refund'];
    }

    public function rules(): array
    {
        return [
            'reference' => 'required|string|max:32',
            'amount_minor' => 'required|integer|min:1|max:100000',
        ];
    }

    public function risk(): RiskLevel
    {
        return RiskLevel::High;
    }

    public function summarize(ToolInput $input): string
    {
        return sprintf(
            'Refund %s to order %s',
            number_format((float) $input->integer('amount_minor', 0) / 100, 2),
            (string) $input->string('reference'),
        );
    }

    public function authorize(ToolInput $input, ToolContext $context): bool
    {
        return $context->user() !== null;
    }

    public function handle(ToolInput $input, ToolContext $context): ToolResult
    {
        self::$refunds[] = [
            'reference' => (string) $input->string('reference'),
            'amount' => (int) $input->integer('amount_minor', 0),
        ];

        return ToolResult::success('Refund issued.', ['refunded' => true]);
    }
}

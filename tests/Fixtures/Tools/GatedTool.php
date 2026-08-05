<?php

declare(strict_types=1);

namespace Pandora\Pandora\Tests\Fixtures\Tools;

use Illuminate\Support\Facades\Gate;
use Pandora\Pandora\Providers\Data\ToolCall;
use Pandora\Pandora\Tools\Enums\RiskLevel;
use Pandora\Pandora\Tools\Tool;
use Pandora\Pandora\Tools\ToolContext;
use Pandora\Pandora\Tools\ToolInput;
use Pandora\Pandora\Tools\ToolResult;

/**
 * A tool authorized the way a host developer would write it: an ordinary
 * Laravel gate, checked against the acting user.
 */
final class GatedTool extends Tool
{
    public function name(): string
    {
        return 'gated_action';
    }

    public function description(): string
    {
        return 'Act on an order the user is allowed to manage.';
    }

    public function rules(): array
    {
        return ['reference' => 'required|string|max:32'];
    }

    public function risk(): RiskLevel
    {
        return RiskLevel::Medium;
    }

    public function authorize(ToolInput $input, ToolContext $context): bool
    {
        $user = $context->user();

        if ($user === null) {
            return false;
        }

        // Argument-dependent authorization: whether this user may act on THIS
        // order, not merely whether they may act on orders in general.
        if ($input->string('reference') === 'FORBIDDEN') {
            return false;
        }

        return Gate::forUser($user)->allows('manage-orders');
    }

    public function handle(ToolInput $input, ToolContext $context): ToolResult
    {
        return ToolResult::success('Acted on '.$input->string('reference').'.');
    }

    public static function call(string $reference = 'ORD-1234'): ToolCall
    {
        return new ToolCall('call_1', 'gated_action', ['reference' => $reference]);
    }
}

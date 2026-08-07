<?php

declare(strict_types=1);

namespace Pandora\Tests\Fixtures\Tools;

use Pandora\Tools\Enums\RiskLevel;
use Pandora\Tools\Tool;
use Pandora\Tools\ToolContext;
use Pandora\Tools\ToolInput;
use Pandora\Tools\ToolResult;

/**
 * A medium-risk mutating tool: it writes something the actor already owns.
 *
 * Exists so the autonomy tests can isolate their subject. A high-risk tool
 * would require approval anyway, and a test that cannot tell the autonomy
 * clamp from the risk floor proves neither.
 */
final class UpdateNoteTool extends Tool
{
    /** @var list<string> */
    public static array $written = [];

    public function name(): string
    {
        return 'update_note';
    }

    public function description(): string
    {
        return 'Change the text of a note.';
    }

    public function rules(): array
    {
        return ['text' => 'required|string|max:200'];
    }

    public function risk(): RiskLevel
    {
        return RiskLevel::Medium;
    }

    public function authorize(ToolInput $input, ToolContext $context): bool
    {
        return $context->user() !== null;
    }

    public function handle(ToolInput $input, ToolContext $context): ToolResult
    {
        self::$written[] = (string) $input->string('text');

        return ToolResult::success('Written.');
    }
}

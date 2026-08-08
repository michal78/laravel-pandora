<?php

declare(strict_types=1);

namespace Pandora\Tools\BuiltIn;

use Pandora\Exceptions\WorkspaceDenied;
use Pandora\Tools\Enums\RiskLevel;
use Pandora\Tools\ToolContext;
use Pandora\Tools\ToolInput;
use Pandora\Tools\ToolResult;

/**
 * List what is in the agent's workspace.
 *
 * Read-only and side-effect free, so it is `low` risk and available at
 * `observe_only`: an agent that may not act should still be able to see what
 * it would be acting on.
 *
 * A listing is not a lesser read. A path that escapes the root is refused here
 * exactly as it is for a read, because "does this file exist" is most of what
 * an attacker wants and it fits in a directory listing.
 */
final class ListFilesTool extends WorkspaceTool
{
    public function name(): string
    {
        return 'list_files';
    }

    public function description(): string
    {
        return 'List the files and folders in your workspace. Give a folder to look inside it, '
            .'or nothing to see the top level.';
    }

    public function rules(): array
    {
        return [
            'path' => 'nullable|string|max:1000',
        ];
    }

    public function descriptions(): array
    {
        return [
            'path' => 'A folder inside your workspace, relative to its root. Omit for the top level.',
        ];
    }

    public function risk(): RiskLevel
    {
        return RiskLevel::Low;
    }

    public function summarize(ToolInput $input): string
    {
        return 'List files: /'.($input->string('path') ?? '');
    }

    public function authorize(ToolInput $input, ToolContext $context): bool
    {
        return true;
    }

    public function handle(ToolInput $input, ToolContext $context): ToolResult
    {
        $workspace = $this->workspace($context);

        if ($workspace === null) {
            return ToolResult::success($this->noWorkspaceMessage(), ['entries' => []]);
        }

        $path = $input->string('path') ?? '';

        try {
            $entries = $this->files($workspace)->list($path);
        } catch (WorkspaceDenied $e) {
            // A refusal, a missing folder or an unreachable disk. All of them
            // are ordinary tool failures: the run continues, and nothing is
            // read from anywhere else instead.
            return ToolResult::failure($e->userMessage());
        }

        if ($entries === []) {
            return ToolResult::success('Nothing in /'.$path.'.', ['entries' => []]);
        }

        return ToolResult::success(
            count($entries).' entry(ies) in /'.$path.': '.implode(', ', $entries),
            ['entries' => $entries],
        );
    }
}

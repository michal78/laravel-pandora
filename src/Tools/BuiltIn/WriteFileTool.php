<?php

declare(strict_types=1);

namespace Pandora\Tools\BuiltIn;

use Pandora\Exceptions\WorkspaceDenied;
use Pandora\Tools\Enums\RiskLevel;
use Pandora\Tools\ToolContext;
use Pandora\Tools\ToolInput;
use Pandora\Tools\ToolResult;

/**
 * Write a file into the agent's workspace.
 *
 * Through `WorkspaceFiles::write`, which is the same call the control center's
 * upload makes: the quota is reserved before the bytes land, the MIME
 * allowlist is matched on the detected type, and the path is re-resolved and
 * checked. None of that is restated here, and none of it should be.
 *
 * `medium` risk, so it is not available to an agent at `observe_only`. The row
 * count is small and the act is not: a file an agent wrote is a file a person
 * will later read believing somebody meant it, and on shared storage it is
 * visible to every other agent pointed at the same workspace.
 *
 * Overwrites rather than refusing to. A workspace is somewhere an agent works,
 * and a tool that could only ever create would have the agent inventing
 * `notes-2.txt`, `notes-3.txt` until the quota ended it. The previous size is
 * accounted for, so the counter stays honest either way.
 */
final class WriteFileTool extends WorkspaceTool
{
    public function name(): string
    {
        return 'write_file';
    }

    public function description(): string
    {
        return 'Write a file into your workspace, replacing it if it already exists.';
    }

    public function rules(): array
    {
        return [
            'path' => 'required|string|max:1000',
            'content' => 'present|string|max:'.self::maxBytes(),
        ];
    }

    public function descriptions(): array
    {
        return [
            'path' => 'Where to write it, relative to your workspace root.',
            'content' => 'The full contents of the file. This replaces anything already there.',
        ];
    }

    public function risk(): RiskLevel
    {
        return RiskLevel::Medium;
    }

    public function summarize(ToolInput $input): string
    {
        return 'Write file: '.mb_substr($input->requiredString('path'), 0, 120);
    }

    /**
     * Available to a system actor, unlike `remember`.
     *
     * The default `authorize()` grants nothing above `low`, which would refuse
     * this to every caller including the person who owns the workspace, so it
     * has to be stated. It is stated as yes: a nightly report an agent writes
     * into its own workspace is the ordinary case, and a scheduled run has no
     * user to be acting on behalf of.
     *
     * That is a weaker bar than `RememberTool`'s deliberately. A memory is a
     * durable claim that gets repeated back to a person; a file lands in a
     * bounded root with a quota an operator set, where the worst outcome is a
     * full workspace.
     */
    public function authorize(ToolInput $input, ToolContext $context): bool
    {
        return true;
    }

    public function handle(ToolInput $input, ToolContext $context): ToolResult
    {
        $workspace = $this->workspace($context);

        if ($workspace === null) {
            return ToolResult::success($this->noWorkspaceMessage());
        }

        $path = $input->requiredString('path');
        $content = $input->string('content') ?? '';

        try {
            $this->files($workspace)->write($path, $content);
        } catch (WorkspaceDenied $e) {
            // Quota exceeded, a type the workspace does not allow, a path that
            // leaves the root, a disk that cannot be reached. Every one of them
            // is an ordinary tool failure and the run continues -- and in
            // particular the bytes are never written somewhere else instead.
            return ToolResult::failure($e->userMessage());
        }

        return ToolResult::success(
            'Wrote '.number_format(strlen($content)).' bytes to '.$path.'.',
            ['path' => $path, 'bytes' => strlen($content)],
        );
    }

    /**
     * The largest thing one call may write.
     *
     * A model cannot emit a file big enough to matter here, so this is not
     * really a memory bound -- it is a bound on how much of a run's remaining
     * quota a single confused call can consume.
     */
    public static function maxBytes(): int
    {
        $configured = config('pandora.workspaces.max_write_bytes');

        return is_numeric($configured) ? max(1, (int) $configured) : 1048576;
    }
}

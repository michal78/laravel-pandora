<?php

declare(strict_types=1);

namespace Pandora\Tools\BuiltIn;

use Pandora\Exceptions\WorkspaceDenied;
use Pandora\Tools\Enums\RiskLevel;
use Pandora\Tools\ToolContext;
use Pandora\Tools\ToolInput;
use Pandora\Tools\ToolResult;

/**
 * Read a file out of the agent's workspace.
 *
 * Bounded, and the bound is the interesting part. A workspace is allowed to
 * hold a file larger than the worker's memory limit and very much larger than
 * the model's context, so this reads through a handle and stops: one truncated
 * read rather than a 2GB log arriving in a prompt. The truncation is reported
 * in words, because a model given a silently cut-off file will reason
 * confidently about the half it got.
 *
 * `low` risk. Reading a file an operator put inside a root they chose is not
 * an act, and an agent at `observe_only` should be able to do it.
 *
 * What this does NOT protect against, and cannot: the contents are untrusted
 * input. A file in a workspace can be written by another agent, uploaded by a
 * person, or synced in from somewhere, and it is read into a prompt. Treat it
 * exactly like a fetched web page — which is why nothing in Pandora lets a
 * tool result widen what a run may do.
 */
final class ReadFileTool extends WorkspaceTool
{
    public function name(): string
    {
        return 'read_file';
    }

    public function description(): string
    {
        return 'Read a file from your workspace. Large files are truncated, and you are told when '
            .'that happened.';
    }

    public function rules(): array
    {
        return [
            'path' => 'required|string|max:1000',
        ];
    }

    public function descriptions(): array
    {
        return [
            'path' => 'The file to read, relative to your workspace root.',
        ];
    }

    public function risk(): RiskLevel
    {
        return RiskLevel::Low;
    }

    public function summarize(ToolInput $input): string
    {
        return 'Read file: '.mb_substr($input->requiredString('path'), 0, 120);
    }

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
        $max = max(1, self::maxBytes());

        try {
            $files = $this->files($workspace);
            $size = $files->size($path);
            $handle = $files->stream($path);
        } catch (WorkspaceDenied $e) {
            return ToolResult::failure($e->userMessage());
        }

        // Read one byte past the bound, so "exactly at the limit" is
        // distinguishable from "cut off here" without a second call.
        $contents = (string) fread($handle, $max + 1);
        fclose($handle);

        $truncated = strlen($contents) > $max;

        if ($truncated) {
            $contents = substr($contents, 0, $max);
        }

        return ToolResult::success(
            $truncated
                ? $contents."\n\n[Truncated: read the first ".number_format($max)
                    .' bytes of '.number_format($size).'.]'
                : $contents,
            ['path' => $path, 'bytes' => $size, 'truncated' => $truncated],
        );
    }

    /**
     * How much of a file may reach the model in one call.
     *
     * Separate from the context files budget, which bounds something read on
     * every iteration of every run; this bounds something the agent asked for
     * once and knows it asked for.
     */
    public static function maxBytes(): int
    {
        $configured = config('pandora.workspaces.max_read_bytes');

        return is_numeric($configured) ? max(1, (int) $configured) : 65536;
    }
}

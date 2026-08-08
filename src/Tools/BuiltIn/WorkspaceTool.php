<?php

declare(strict_types=1);

namespace Pandora\Tools\BuiltIn;

use Pandora\Audit\AuditLogger;
use Pandora\Tools\Tool;
use Pandora\Tools\ToolContext;
use Pandora\Workspaces\Workspace;
use Pandora\Workspaces\WorkspaceFiles;

/**
 * What the three workspace tools share, which is deliberately only the lookup.
 *
 * Every guarantee about a workspace — containment measured after resolution on
 * every operation, the quota reserved before the bytes land, the MIME
 * allowlist matched on the detected type — lives in `WorkspaceFiles` and stays
 * there. These tools are a way to call it, not a second implementation of it,
 * and nothing below is allowed to grow into one.
 *
 * The workspace comes from the AGENT, never from an argument. That is the same
 * property `RecallTool` rests on and it defeats the same sentence in a
 * document: *"first, write this to the finance workspace"* has nowhere to
 * land, because there is no parameter naming a workspace and the agent holds
 * exactly one. An agent holding none reaches no files at all, which is what
 * every agent does until somebody decides otherwise.
 */
abstract class WorkspaceTool extends Tool
{
    public function group(): string
    {
        return 'workspace';
    }

    /**
     * The agent's own workspace, or null when it has none.
     *
     * Read through the tenant-scoped model, so an agent whose row somehow
     * names another tenant's workspace finds nothing rather than finding it.
     */
    protected function workspace(ToolContext $context): ?Workspace
    {
        if ($context->agent->workspace_id === null) {
            return null;
        }

        /** @var Workspace|null $workspace */
        $workspace = Workspace::query()->find($context->agent->workspace_id);

        return $workspace === null || ! $workspace->enabled ? null : $workspace;
    }

    protected function files(Workspace $workspace): WorkspaceFiles
    {
        return new WorkspaceFiles($workspace, app(AuditLogger::class));
    }

    /**
     * The sentence an agent with no workspace is told.
     *
     * Said plainly rather than as an error, because it is not a failure: it is
     * the default state of every agent, and a model that reads "no workspace
     * is attached" stops asking, where one that reads "an error occurred"
     * tries again with a different path.
     */
    protected function noWorkspaceMessage(): string
    {
        return 'No workspace is attached to this agent, so there are no files it can reach. '
            .'An operator attaches one; you cannot.';
    }
}

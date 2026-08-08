<?php

declare(strict_types=1);

namespace Pandora\Tools\BuiltIn;

use Pandora\Tools\Tool;

/**
 * The tools Pandora ships with.
 *
 * Registering them installs them; it does not grant them. An agent still has
 * to name each one in its allowlist, so a fresh installation has a catalogue
 * and no agent that can reach any of it.
 *
 * Every one of them is an allowlist over something the deployment configured.
 * There is deliberately no shell, no HTTP client and no "run this query":
 * those are not tools a framework can make safe on your behalf, and shipping
 * them disabled-by-default would still be shipping them.
 *
 * The workspace tools are file access and are the same shape as the rest. They
 * do not reach a filesystem; they reach a workspace, which is a root an
 * operator chose, a quota they set and a type list they wrote, with an agent
 * holding at most one and most agents holding none. "Read a file" would not be
 * safe to ship. "Read a file from the directory you were given" is the same
 * bargain `QueryRecordsTool` makes about tables.
 */
final class BuiltInTools
{
    /**
     * @return list<class-string<Tool>>
     */
    public static function all(): array
    {
        return [
            AskUserTool::class,
            RequestApprovalTool::class,
            InspectRunStatusTool::class,
            QueryRecordsTool::class,
            ReadConfigTool::class,
            DispatchJobTool::class,
            EmitEventTool::class,
            SendNotificationTool::class,
            ProposeFollowUpTool::class,
            RememberTool::class,
            RecallTool::class,
            DelegateToAgentTool::class,
            ListFilesTool::class,
            ReadFileTool::class,
            WriteFileTool::class,
        ];
    }
}

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
 * There is deliberately no shell, no HTTP client, no "run this query" and no
 * file access: those are not tools a framework can make safe on your behalf,
 * and shipping them disabled-by-default would still be shipping them.
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
        ];
    }
}

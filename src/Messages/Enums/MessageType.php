<?php

declare(strict_types=1);

namespace Pandora\Pandora\Messages\Enums;

enum MessageType: string
{
    case Text = 'text';
    case ToolRequest = 'tool_request';
    case ToolResult = 'tool_result';
    case ApprovalRequest = 'approval_request';
    case ApprovalResponse = 'approval_response';
    case Event = 'event';
    case ExecutionSummary = 'execution_summary';
    case Error = 'error';
}

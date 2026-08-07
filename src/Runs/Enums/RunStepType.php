<?php

declare(strict_types=1);

namespace Pandora\Runs\Enums;

/**
 * The vocabulary of the run trace.
 *
 * Types beyond Phase 1's set are declared now so the trace format is stable
 * from the first release and later phases do not need a migration to add one.
 */
enum RunStepType: string
{
    case ModelRouting = 'model_routing';
    case ModelRequest = 'model_request';
    case ModelResponse = 'model_response';
    case ContextRetrieval = 'context_retrieval';
    case MemoryRetrieval = 'memory_retrieval';
    case PlanUpdate = 'plan_update';
    case ToolRequest = 'tool_request';
    case ToolExecution = 'tool_execution';
    case ToolResult = 'tool_result';
    case ApprovalRequest = 'approval_request';
    case ApprovalResponse = 'approval_response';
    case Delegation = 'delegation';
    case UserQuestion = 'user_question';
    case Event = 'event';
    case FinalResponse = 'final_response';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::ModelRouting => 'Model routed',
            self::ModelRequest => 'Model request',
            self::ModelResponse => 'Model response',
            self::ContextRetrieval => 'Context built',
            self::MemoryRetrieval => 'Memory retrieved',
            self::PlanUpdate => 'Plan updated',
            self::ToolRequest => 'Tool requested',
            self::ToolExecution => 'Tool executed',
            self::ToolResult => 'Tool result',
            self::ApprovalRequest => 'Approval requested',
            self::ApprovalResponse => 'Approval resolved',
            self::Delegation => 'Delegated',
            self::UserQuestion => 'Question asked',
            self::Event => 'Event',
            self::FinalResponse => 'Final response',
            self::Error => 'Error',
        };
    }
}

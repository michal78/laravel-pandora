<?php

declare(strict_types=1);

namespace Pandora\Pandora\Tests\Support;

use Pandora\Pandora\Agents\Agent;
use Pandora\Pandora\Agents\AgentRunner;
use Pandora\Pandora\Core\Actor\ActorContext;
use Pandora\Pandora\Providers\Data\ToolCall;
use Pandora\Pandora\Runs\Run;
use Pandora\Pandora\Tests\Fixtures\TestUser;
use Pandora\Pandora\Tools\Enums\ToolExecutionStatus;
use Pandora\Pandora\Tools\Tool;
use Pandora\Pandora\Tools\ToolContext;
use Pandora\Pandora\Tools\ToolDecision;
use Pandora\Pandora\Tools\ToolExecution;
use Pandora\Pandora\Tools\ToolGatekeeper;
use Pandora\Pandora\Tools\ToolRegistry;

/**
 * Scaffolding for tests that put a tool call through the gatekeeper.
 *
 * Builds a real run, session and agent rather than mocks: the layers read
 * agent columns and tenant ids, and a mock would let a wrong column name pass.
 */
trait MakesTools
{
    use MakesRuns;

    protected Agent $toolAgent;

    /**
     * @param list<Tool|class-string<Tool>> $tools
     */
    public function registerTools(array $tools): ToolRegistry
    {
        return app(ToolRegistry::class)->flush()->registerMany($tools);
    }

    /**
     * @param list<string> $allow
     * @param list<string> $deny
     */
    public function agentAllows(array $allow, array $deny = []): Agent
    {
        $this->agent()->forceFill([
            'tool_policy' => ['allow' => $allow, 'deny' => $deny],
        ])->save();

        return $this->toolAgent;
    }

    /**
     * @param array<string, mixed> $policy
     */
    public function agentApprovalPolicy(array $policy): Agent
    {
        $this->agent()->forceFill(['approval_policy' => $policy])->save();

        return $this->toolAgent;
    }

    public function agent(): Agent
    {
        return $this->toolAgent ??= $this->makeAgent();
    }

    public function toolContext(
        ?ActorContext $actor = null,
        ?string $tenantId = null,
        string $toolCallId = 'call_1',
    ): ToolContext {
        $agent = $this->agent();

        if ($tenantId !== null) {
            $agent->forceFill(['tenant_id' => $tenantId])->save();
        }

        $session = $this->makeSession($agent, ['tenant_id' => $tenantId]);
        $run = $this->makeRun([
            'agent_id' => $agent->getKey(),
            'session_id' => $session->getKey(),
            'tenant_id' => $tenantId,
        ]);

        return new ToolContext(
            run: $run,
            agent: $agent,
            session: $session,
            actor: $actor ?? ActorContext::forUser($this->toolUser()),
            toolCallId: $toolCallId,
        );
    }

    public function decide(ToolCall $call, ?ToolContext $context = null): ToolDecision
    {
        return app(ToolGatekeeper::class)->evaluate($call, $context ?? $this->toolContext());
    }

    public function lookupCall(string $reference = 'ORD-1234'): ToolCall
    {
        return new ToolCall('call_1', 'lookup_order', ['reference' => $reference]);
    }

    public function refundCall(int $amountMinor = 4200): ToolCall
    {
        return new ToolCall('call_1', 'refund_order', [
            'reference' => 'ORD-1234',
            'amount_minor' => $amountMinor,
        ]);
    }

    /**
     * Run the tool agent to completion, synchronously.
     */
    public function runToolAgent(string $input): Run
    {
        return app(AgentRunner::class)
            ->agent($this->agent())
            ->forUser($this->toolUser())
            ->inConversation($this->makeConversation($this->agent()))
            ->run($input);
    }

    /**
     * A tool execution row in a chosen state, for testing the parts of the
     * lifecycle a full run would race past.
     */
    public function makeExecution(
        Run $run,
        string $toolCallId,
        ToolExecutionStatus $status,
        string $toolName = 'counting_tool',
    ): ToolExecution {
        /** @var ToolExecution $execution */
        $execution = ToolExecution::query()->create([
            'tenant_id' => $run->tenant_id,
            'run_id' => $run->getKey(),
            'tool_name' => $toolName,
            'tool_version' => '1.0',
            'tool_call_id' => $toolCallId,
            'arguments' => ['label' => $toolCallId],
            'sanitized_arguments' => ['label' => $toolCallId],
            'status' => $status->value,
            'risk_level' => 'low',
            'idempotency_key' => ToolExecution::idempotencyKey(
                (string) $run->getKey(),
                $toolName,
                ['label' => $toolCallId],
            ),
            'attempt' => 1,
        ]);

        return $execution;
    }

    public function toolUser(): TestUser
    {
        /** @var TestUser $user */
        $user = TestUser::query()->firstOrCreate(
            ['email' => 'tool-actor@example.test'],
            ['name' => 'Tool Actor', 'password' => 'secret'],
        );

        return $user;
    }
}

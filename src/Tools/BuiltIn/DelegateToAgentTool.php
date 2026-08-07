<?php

declare(strict_types=1);

namespace Pandora\Tools\BuiltIn;

use Pandora\Agents\Agent;
use Pandora\Delegation\DelegationGuard;
use Pandora\Delegation\Delegator;
use Pandora\Tools\Enums\RiskLevel;
use Pandora\Tools\Enums\ToolExecutionStatus;
use Pandora\Tools\Tool;
use Pandora\Tools\ToolContext;
use Pandora\Tools\ToolExecution;
use Pandora\Tools\ToolInput;
use Pandora\Tools\ToolResult;

/**
 * Hand a piece of work to another agent and wait for its answer.
 *
 * Delegation is an ORDINARY TOOL, deliberately (ADR-0007). It could have been a
 * privileged path into run creation, and the reason it is not is that a second
 * privileged path is a second place to get authorization wrong. An agent that
 * may not delegate is simply not given this tool -- the same sentence that
 * describes every other capability in the system.
 *
 * What this tool can and cannot do:
 *
 *   - It cannot reach an agent absent from the caller's delegation allowlist,
 *     which is empty by default.
 *   - It cannot produce a child that holds an ability the caller lacks. The
 *     child's tools are the INTERSECTION of the two.
 *   - It cannot deepen a tree past `pandora.delegation.max_depth`, and cannot
 *     delegate to an agent already running higher up the chain.
 *   - It cannot spend money the parent's tree has not got. One budget, one
 *     tree.
 *
 * Every one of those refusals is a tool error and the caller keeps running.
 */
final class DelegateToAgentTool extends Tool
{
    public function name(): string
    {
        return 'delegate_to_agent';
    }

    public function description(): string
    {
        return 'Hand a self-contained piece of work to another agent that is better suited to it, '
            .'and wait for its answer. Give it everything it needs in the instruction: it cannot '
            .'see this conversation. Use this for work that needs a different specialism, not to '
            .'get around something you were refused.';
    }

    public function group(): string
    {
        return 'delegation';
    }

    /**
     * Medium, not low, and not high.
     *
     * Not low, because a delegation starts a run that spends tokens and may
     * touch the world -- which also means `observe_only` correctly forbids it.
     * Not high, because requiring human approval for every hop would make the
     * feature unusable and would train operators to approve delegations without
     * reading them, which is worse than not asking. A deployment that wants
     * approval on delegation has a tool policy to say so.
     */
    public function risk(): RiskLevel
    {
        return RiskLevel::Medium;
    }

    public function rules(): array
    {
        return [
            'agent' => 'required|string|max:255',
            'instruction' => 'required|string|min:3|max:8000',
        ];
    }

    public function descriptions(): array
    {
        return [
            'agent' => 'The slug of the agent to delegate to. You may only name an agent you have '
                .'been explicitly permitted to delegate to.',
            'instruction' => 'The complete, self-contained task. The other agent cannot see this '
                .'conversation, so include every fact it needs and state what you want back.',
        ];
    }

    public function summarize(ToolInput $input): string
    {
        return sprintf(
            'Delegate to %s: %s',
            $input->string('agent', 'another agent'),
            str($input->string('instruction', ''))->limit(80)->toString(),
        );
    }

    /**
     * Authorized against the actor, like every tool -- but note what is NOT
     * checked here.
     *
     * There is no ability gating which agents a person may delegate to, because
     * that is not a question about the person: the allowlist belongs to the
     * calling agent, and the child's abilities are bounded by the intersection
     * whoever the actor is. A system actor -- an automation, a webhook -- is
     * permitted for the same reason. Delegation grants nothing on its own, and
     * every tool the child eventually calls is authorized against this same
     * actor at the moment it calls it.
     *
     * A null actor is still refused. A run acting for nobody delegating to an
     * agent acting for nobody is a chain with no accountable party at either
     * end, and the trace would have nothing to name.
     */
    public function authorize(ToolInput $input, ToolContext $context): bool
    {
        return $context->actor !== null;
    }

    public function handle(ToolInput $input, ToolContext $context): ToolResult
    {
        $slug = $input->requiredString('agent');
        $instruction = $input->requiredString('instruction');

        /** @var Agent|null $target */
        $target = Agent::query()->where('slug', $slug)->first();

        $decision = app(DelegationGuard::class)->decide($context->run, $context->agent, $target);
        $delegator = app(Delegator::class);

        if (! $decision->allowed) {
            $delegator->refuse($context->run, $context->agent, $decision);

            return ToolResult::failure((string) $decision->reason, [
                'refusal' => $decision->refusal?->value,
            ]);
        }

        $execution = $this->execution($context);

        if ($execution === null) {
            // The row is written before the tool is ever dispatched, so its
            // absence means something outside this call removed it. Refusing is
            // the only safe answer: a child with nothing to report back to
            // would run, spend the tree's budget, and be waited for by nobody.
            return ToolResult::failure('This call could not be linked to the run and was not started.');
        }

        $child = $delegator->start(
            parent: $context->run,
            parentAgent: $context->agent,
            session: $context->session,
            decision: $decision,
            execution: $execution,
            instruction: $instruction,
            actor: $context->actor,
        );

        return ToolResult::delegated(
            (string) $child->getKey(),
            sprintf('Delegated to %s. Waiting for its answer.', (string) $decision->target?->name),
        );
    }

    /**
     * The parent's own tool-call row -- the thing the child will complete.
     *
     * Looked up rather than passed, because `ToolContext` carries the model's
     * `tool_call_id` rather than our row's id, and widening the context for one
     * tool would put the execution row in reach of every tool that has no
     * business writing to it.
     */
    private function execution(ToolContext $context): ?ToolExecution
    {
        /** @var ToolExecution|null $execution */
        $execution = ToolExecution::query()
            ->where('run_id', $context->run->getKey())
            ->where('tool_call_id', $context->toolCallId)
            ->whereIn('status', [
                ToolExecutionStatus::Running->value,
                ToolExecutionStatus::Pending->value,
            ])
            ->latest('created_at')
            ->first();

        return $execution;
    }
}

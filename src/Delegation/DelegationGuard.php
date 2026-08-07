<?php

declare(strict_types=1);

namespace Pandora\Delegation;

use Illuminate\Contracts\Config\Repository as Config;
use Pandora\Agents\Agent;
use Pandora\Runs\Run;

/**
 * Decides whether one run may start another, before any child run exists.
 *
 * Everything here is about whether the child is created at all. What it may DO
 * once created is `AbilityIntersection`'s question, and the separation is
 * deliberate: these checks can be satisfied by an operator writing a slug in an
 * allowlist, and the intersection cannot be satisfied by anything an operator
 * does at delegation time. Naming an agent as delegable is therefore not a
 * grant -- it decides who may be ASKED, never what the answer is allowed to do.
 *
 * Checked in cost order, cheapest first, but the order is not load-bearing: a
 * delegation refused by any one of these is refused, and the reason recorded is
 * the first one found rather than the most severe. That is a deliberate
 * simplification -- an operator who fixes the reported reason and tries again
 * will simply be told the next one.
 */
final class DelegationGuard
{
    public function __construct(
        private readonly AbilityIntersection $abilities,
        private readonly Config $config,
    ) {}

    public function decide(Run $parent, Agent $parentAgent, ?Agent $target): DelegationDecision
    {
        if ($target === null) {
            return DelegationDecision::refuse(
                DelegationRefusal::AgentUnavailable,
                'There is no agent by that name available to you.',
            );
        }

        if (! $target->enabled) {
            return DelegationDecision::refuse(
                DelegationRefusal::AgentUnavailable,
                "The agent [{$target->slug}] is disabled and cannot be delegated to.",
                $target,
            );
        }

        // Delegation across tenants is out of scope for this phase in either
        // direction, and "out of scope" here means refused rather than
        // unimplemented. A tenant boundary that held only because nobody had
        // written the crossing yet would not be a boundary.
        if ($target->tenant_id !== $parent->tenant_id) {
            return DelegationDecision::refuse(
                DelegationRefusal::AgentUnavailable,
                'There is no agent by that name available to you.',
                $target,
            );
        }

        if (! $parentAgent->mayDelegateTo($target)) {
            return DelegationDecision::refuse(
                DelegationRefusal::NotAllowed,
                "You are not permitted to delegate to [{$target->slug}].",
                $target,
            );
        }

        /** @var int $maxDepth */
        $maxDepth = $this->config->get('pandora.delegation.max_depth', 2);

        if ($parent->delegation_depth + 1 > $maxDepth) {
            return DelegationDecision::refuse(
                DelegationRefusal::DepthExceeded,
                sprintf(
                    'Delegation is limited to %d level%s and this run is already at level %d. '
                    .'Do the remaining work yourself, or report what you cannot do.',
                    $maxDepth,
                    $maxDepth === 1 ? '' : 's',
                    $parent->delegation_depth,
                ),
                $target,
            );
        }

        if ($this->wouldCycle($parent, $target)) {
            return DelegationDecision::refuse(
                DelegationRefusal::CycleRefused,
                sprintf(
                    'The agent [%s] is already running higher up in this chain of work. '
                    .'Delegating back to it would loop.',
                    $target->slug,
                ),
                $target,
            );
        }

        return DelegationDecision::allow(
            $target,
            $this->abilities->between($parent, $parentAgent, $target),
            $this->abilities->withheld($parent, $parentAgent, $target),
        );
    }

    /**
     * Is the target agent already somewhere in this run's ancestry?
     *
     * The depth limit alone terminates A -> B -> A. It terminates it by
     * spending the entire tree budget getting there, which is a refusal an
     * operator only discovers on the invoice. Refusing the cycle outright costs
     * one ancestor walk.
     *
     * The parent run counts as part of its own ancestry for this purpose, so an
     * agent cannot delegate to itself. A run asking for a fresh copy of itself
     * is asking for a loop with a longer period, not for a different agent.
     */
    private function wouldCycle(Run $parent, Agent $target): bool
    {
        if ($parent->agent_id === $target->getKey()) {
            return true;
        }

        foreach ($parent->ancestors() as $ancestor) {
            if ($ancestor->agent_id === $target->getKey()) {
                return true;
            }
        }

        return false;
    }
}

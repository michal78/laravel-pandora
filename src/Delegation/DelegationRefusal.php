<?php

declare(strict_types=1);

namespace Pandora\Delegation;

/**
 * Why a delegation was not permitted, in the two registers it has to be said
 * in: an audit action an operator can search for, and a sentence the model can
 * act on.
 *
 * The model's half matters more than it looks. Every refusal here comes back as
 * a tool error and the parent run KEEPS GOING -- so the sentence is the agent's
 * only information about what just happened, and a vague one produces an agent
 * that retries the same denied call until its iteration limit stops it.
 */
enum DelegationRefusal: string
{
    /** The target is absent from the parent agent's delegation allowlist. */
    case NotAllowed = 'not_allowed';

    /** The target does not exist, is disabled, or belongs to another tenant. */
    case AgentUnavailable = 'agent_unavailable';

    /** One more hop would exceed `pandora.delegation.max_depth`. */
    case DepthExceeded = 'depth_exceeded';

    /** The target agent is already somewhere in this run's ancestry. */
    case CycleRefused = 'cycle_refused';

    /**
     * The audit action. Two refusals share `delegation.denied` deliberately:
     * from an operator's side, "not on the allowlist" and "no such agent" are
     * the same event -- an agent asked for a delegate it may not have -- and
     * splitting them would only make the search harder.
     */
    public function auditAction(): string
    {
        return match ($this) {
            self::NotAllowed, self::AgentUnavailable => 'delegation.denied',
            self::DepthExceeded => 'delegation.depth_exceeded',
            self::CycleRefused => 'delegation.cycle_refused',
        };
    }

    /**
     * Every refusal is `warning`, never `notice`.
     *
     * An agent asking to delegate somewhere it may not go is either a
     * misconfiguration an operator wants to see, or an agent being steered
     * somewhere it should not go by something in its context. Both are worth
     * looking at, and the second is the one that will not announce itself.
     */
    public function severity(): string
    {
        return 'warning';
    }
}

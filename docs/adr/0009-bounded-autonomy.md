# ADR-0009: Autonomy is explicit, leashed and always attributable

- **Status:** accepted
- **Date:** 2026-08-05

## Context
Proactive behaviour — a daemon heartbeat that wakes, reads a checklist and acts without a prompt — is
a genuinely valuable capability of the reference products. It is also the single most dangerous thing
to install into a multi-user business application.

## Decision
Reproduce the capability, remove the ambiguity. Autonomy is modelled as: heartbeat automations, event
subscriptions, conditional automations, goal queues, pending observations and agent-proposed follow-up
work — each an inspectable database record. Every agent has an autonomy level: `observe_only`,
`suggest`, `act_with_approval`, `act_within_policy`. Every autonomous run records its trigger, its
policy decision and its budget. An agent can never schedule itself to wake without a configured,
bounded limit.

## Alternatives considered
- A free-running heartbeat that lets the agent decide when to act. Rejected: unattributable actions
  and unbounded cost in an application serving real customers.
- No proactive capability. Rejected: it is a real capability users want, and refusing it entirely is
  a worse answer than bounding it.

## Consequences
- Every autonomous action is attributable to a trigger and a policy decision, after the fact.
- Runaway cost is structurally impossible: autonomy consumes a budget, and exhausting it disables the
  automation and notifies an admin.
- Slightly more configuration than a checklist file. That is the trade we are making on purpose.

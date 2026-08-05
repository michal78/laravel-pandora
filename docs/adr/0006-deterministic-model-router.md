# ADR-0006: The v1 model router is deterministic

- **Status:** accepted
- **Date:** 2026-08-05

## Context
Capability-, cost- and latency-aware routing is attractive and frequently requested. It is also a
system whose behaviour is hard to predict, hard to test, and hard to explain when it picks the "wrong"
model in production.

## Decision
Ship `DeterministicModelRouter`: explicit call → run override → conversation override → agent default
→ config default, then walk the agent's fallback chain skipping degraded providers and models lacking
a required capability. Every hop is recorded as a run step. `ModelRouter` is an interface a host can
rebind today.

## Alternatives considered
- A cost/latency optimiser in v1. Rejected: optimising before there is production data to optimise
  against produces an unpredictable and untestable system.
- No router; agent default only. Rejected: no failover, which is a real operational need.

## Consequences
- Routing is explainable — you can read the chain and know the answer.
- Sophisticated routing is a host or extension concern until we have evidence about what to optimise.
- Tenant model restrictions are applied *before* routing, so a fallback chain can never escape them.

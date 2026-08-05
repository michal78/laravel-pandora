# ADR-0001: Runs are a durable state machine driven by queued continuations

- **Status:** accepted
- **Date:** 2026-08-05

## Context
An agent run may take minutes, pause indefinitely for a human approval, and must survive worker
restarts — while still streaming to a browser. In-memory orchestration and durable orchestration pull
in opposite directions.

## Decision
A run is a database row with an explicit state. Each iteration of the execution loop is a separate
queued job that loads state, performs one bounded unit of work, appends an immutable step, transitions
state, and dispatches the next continuation or stops. All state required to continue lives in the
database.

## Alternatives considered
- **Long-lived orchestrator process / daemon.** Simplest loop, best latency, and how the reference
  personal-agent products work. Rejected: a worker restart destroys in-flight runs, an approval pause
  holds a worker hostage for hours, and it assumes process supervision that plain Laravel deployments
  do not have.
- **Full event sourcing with projections.** Excellent auditability and replay-based resumption.
  Rejected: our steps include non-idempotent real-world effects, so replay would need a full saga
  layer; projections add cross-engine query complexity; and it imposes a large conceptual tax on host
  developers debugging their own application.

## Consequences
- Pauses cost nothing. Approvals can wait days.
- Works on any queue driver; scales horizontally with no sticky routing.
- Per-iteration load/persist overhead, and every transition must be transactional and lock-guarded.
- Streaming needs explicit handling — see ADR-0003.

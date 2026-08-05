# ADR-0002: Append-only run steps, not event sourcing

- **Status:** accepted
- **Date:** 2026-08-05

## Context
Every run must be fully inspectable. Event sourcing gives that by construction but costs replay
determinism, projections and conceptual weight (ADR-0001).

## Decision
Adopt the append-only half of event sourcing and reject the projection half. `pandora_run_steps` is
immutable and ordered: rows are inserted, never updated or deleted (outside retention pruning). Run
state is a column on `pandora_runs`, maintained transactionally — not derived by replay.

## Alternatives considered
- Mutable step rows updated in place. Rejected: destroys the audit trail and makes concurrent writes
  ambiguous.
- Full event sourcing. Rejected in ADR-0001.

## Consequences
- The step list *is* the trace; nothing can quietly rewrite history.
- Immutability is enforced by a model trait that throws on update, plus an architecture test — not by
  convention.
- Some duplication between step payloads and current run state. Accepted deliberately: it is what
  makes each readable on its own.

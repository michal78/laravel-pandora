# ADR-0003: Stream inside the continuation job; persist and broadcast together

- **Status:** accepted
- **Date:** 2026-08-05

## Context
The provider stream is consumed by a queue worker. The browser is somewhere else entirely. Users
expect sub-second feedback, and a page reload mid-stream must not lose the partial answer.

## Decision
Consume the provider HTTP stream inside a single `ContinueAgentRun` job (one model turn, not the whole
run). Buffer deltas and flush on 80 ms / 256 characters / boundary, writing the same buffer to both
the message row and a coalesced Reverb broadcast. The browser therefore has two independent paths to
correct state, and depends on neither alone.

## Alternatives considered
- **Broadcast per token, persist at the end.** Rejected: floods Reverb, and a reload mid-stream loses
  everything.
- **Persist per token.** Rejected: one row-update per token is a pathological write pattern.
- **A separate streaming process outside the queue.** Rejected: reintroduces the daemon of ADR-0001.

## Consequences
- ~80 ms perceived latency; message volume roughly two orders of magnitude below per-token.
- Persisted state and broadcast state always advance together.
- Reverb becomes genuinely optional — polling degrades gracefully because the database is already
  correct.

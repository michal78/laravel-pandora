# Implementation Log

Reverse-chronological. Every entry records what was actually done and actually verified. Commands
claimed to pass were run; output is quoted where it matters.

---

## 2026-08-05 — Phase 0: Discovery and architecture ✅

**Repository assessment.** `vendor/michal78/laravel-pandora` was completely empty — no git, no files.
A clean start with no prior work to preserve. Host app at `/home/michal/development/laravel-test` is
Laravel 13.17 + Livewire 4.1 + Flux 2.13 + Fortify, PHP 8.3.6, MySQL via Sail, Redis queue + cache,
`BROADCAST_CONNECTION=log` (Reverb not yet installed), Larastan 3.9 and Pint already present. A
sibling package `michal78/wisp` is consumed via a `path` repository with symlink — the pattern Pandora
will follow.

**⚠ Location risk (open).** The package lives inside `vendor/`, which `composer install` will delete.
Git is initialised here so nothing can be lost, but the source should move outside `vendor/` and be
consumed via a path repository. Recorded in `open-questions.md` as Q1.

**Research.** Public documentation only: OpenClaw (`docs.openclaw.ai` — the Gateway → Security page
was the most informative single source), Hermes Agent (`hermes-agent.org`, NousResearch repo,
community docs mirror), Hermes Studio (`github.com/JPeetz/Hermes-Studio` README). No source code,
asset, wording or proprietary implementation detail was copied from any of them.

The decisive finding: OpenClaw's own security documentation states it "is not a hostile multi-tenant
security boundary for multiple adversarial users sharing one agent or gateway," and recommends
separate gateways for adversarial scenarios. Both reference products are *single-operator* systems.
That is precisely the constraint Pandora exists to remove, and it drove the tenancy, session and
authorization architecture.

**Delivered.**
- `docs/product/` — vision, feature-parity (69 capabilities classified: 45 Core, 9 Official
  extension, 10 Future, 5 Unsupported), terminology
- `docs/architecture/` — overview (3 candidate architectures evaluated, 1 selected), security-model
  (15 threats, 5 authorization layers), execution-model, provider-model, database-model,
  realtime-model
- `docs/adr/` — 13 ADRs
- `docs/roadmap.md`, `docs/development/phase-1-acceptance.md` (22 criteria)
- Package skeleton: `composer.json`, directory structure, git on `master`

**Key decisions.** Durable state machine with queued continuations (ADR-0001) over a daemon or event
sourcing. Append-only steps without projections (ADR-0002). Streaming buffered inside the continuation
job, persisted and broadcast together (ADR-0003). ULIDs (ADR-0004). Tenancy as an abstraction
(ADR-0005). Deterministic router (ADR-0006). Tools authorized against the *actor*, not the agent
(ADR-0007). Skills are never executed (ADR-0008). Autonomy is leashed and attributable (ADR-0009).

**Verification.** Documentation phase — no code, therefore no test or analysis claims made.

**Next.** Phase 1 kernel vertical slice.

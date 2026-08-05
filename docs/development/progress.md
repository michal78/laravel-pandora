# Implementation Log

Reverse-chronological. Every entry records what was actually done and actually verified. Commands
claimed to pass were run; output is quoted where it matters.

---

## 2026-08-05 — Phase 1: Kernel vertical slice 🔨 (code complete, host verification blocked)

**Delivered.** A complete path from a chat message to a streamed, traced, cancellable, audited run.

- Foundation: service provider (headless + control-center modes), `config/pandora.php`, facade,
  tenancy + actor abstraction, ULID/redaction/correlation support, exception hierarchy
- Data: 9 migrations (agents, conversations, sessions, participants, messages, runs, run_steps,
  settings, audit_logs) with ULID keys, nullable `tenant_id`, short index names
- Agents: `Agent` model, `AgentDefinition` + `AgentBlueprint`, registry with class↔DB sync
- Runs: `RunState`/`RunStepType`/`TriggerType`/`AutonomyLevel` enums, `RunStateMachine`, `RunLock`,
  `RunFactory`, `RunStepRecorder`, `RunCanceller`
- Jobs: `StartAgentRun`, `ContinueAgentRun`, `RunFailer`, `ResolvesPandoraContext`
- Providers: contracts, 10 DTOs, `ProviderManager`, `FakeProvider`, `OpenAiCompatibleProvider`
  (SSE streaming, tool-call reassembly, full error classification)
- Context: builder with token budgeting + omission recording, 3 providers
- Realtime: redacting/versioned broadcast base, 4 events, `RunBroadcaster` with delta coalescing,
  `ChannelAuthorizer`, channel routes
- UI: layout with light/dark, self-contained CSS design system, Chat / Dashboard / Runs / RunDetail
- Console: `pandora:install` (idempotent, creates no agent), `:status`, `:agent:list`, `:agent:run`

**Verified — commands actually run, output quoted.**

```
vendor/bin/pest       → Tests: 119 passed (739 assertions)
vendor/bin/phpstan    → [OK] No errors            (level 8, checkModelProperties on)
vendor/bin/pint --test → passed
```

End-to-end demo (`tests/Feature/DemoWalkthroughTest.php`), real output:

```
AGENT        Echo (echo), source: class
CONVERSATION Where is order 1234?
STATE        Completed
PROVIDER     fake / fake-model
TOKENS       10 in / 13 out
TRACE:  1. Context built (3 sections, ~225 tokens)  2. Model request  3. Model response  4. Final response
AUDIT:  run.started, run.completed
```

**Three real defects found and fixed by the tests** (not cosmetic):

1. `RunCanceller` left a `queued` run in `cancelling` forever. A queued run has no work in
   progress and `StartAgentRun` already no-ops on a cancelled run, so it now finalises immediately.
2. `RunLock` let a stale *cache* lock veto acquisition even when the *database* lease — documented
   as the authority — had expired. A killed worker could strand a run until the cache entry aged
   out. The lease is now genuinely consulted first.
3. `MessageWriter` called `getDriverName()` on `ConnectionInterface`, which does not declare it.
   Caught by PHPStan; the dependency is now `Connection`.

**Acceptance status: 21 of 22 criteria verified by automated test.**

Criterion 14's manual host-application walkthrough is **blocked in this environment, not passing**:
`laravel-test` requires PHP ^8.4 (Sail/Docker), the WSL distro has no Docker integration, and local
PHP is 8.3.6. The package's own suite runs a genuine Laravel 13 application under Orchestra
Testbench on 8.3 and covers every criterion including install, chat UI, streaming, reload
reconstruction and cancellation — but that is not the same as a run against a live `queue:work`
and a live Reverb server, and it is not claimed to be. Recorded as Q9.

The host app's `composer.json` gained a PSR-4 autoload entry for the package. A `path` repository
was deliberately **not** used: it would point into `vendor/` at itself.

**Known tooling limitation.** Pest's `arch` plugin cannot build its file index in this
nested-vendor layout, so the architecture invariants are enforced by direct reflection over `src/`
instead (13 rules, `tests/Architecture/ModuleBoundaryTest.php`). Same properties, works anywhere.

**Not in Phase 1, by design:** tools, approvals, memory, automations, skills, MCP, workspaces,
channels beyond web, cost accounting, model routing beyond agent defaults.

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

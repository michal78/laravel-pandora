# Implementation Roadmap

> Status legend: ✅ complete and verified · 🔨 in progress · ⬜ not started
>
> A phase is complete only when its acceptance criteria pass, tests are green, static analysis is
> clean, and documentation exists. Nothing is marked complete on the strength of code existing.

| Phase | Title | Status |
|---|---|---|
| 0 | Discovery and architecture | ✅ |
| 1 | Kernel vertical slice | 🔨 21/22 acceptance criteria verified; host walkthrough blocked (Q9) |
| 2 | Tools and approvals | 🔨 34/36 acceptance criteria verified; database matrix and host walkthrough outstanding |
| 3 | Providers and routing | 🔨 39/40 acceptance criteria verified; database matrix outstanding |
| 4 | Automation | ⬜ |
| 5 | Memory and context | ⬜ |
| 6 | Multi-agent and MCP | ⬜ |
| 7 | Channels and extensions | ⬜ |
| 8 | Hardening and release | ⬜ |

---

## Phase 0 — Discovery and architecture ✅

**Delivered:** feature research against OpenClaw / Hermes Agent / Hermes Studio public
documentation; the parity matrix with 69 classified capabilities; terminology; the domain model; the
threat model; 13 ADRs; the public API proposal; this roadmap; the package skeleton.

**Acceptance:** all documents listed in the brief exist, are internally consistent, and specify Phase
1 unambiguously.

---

## Phase 1 — Kernel vertical slice 🔨

The thinnest path that is nonetheless *complete*: a real run, really queued, really streamed, really
persisted, really cancellable — with a fake provider, then one real one.

### Scope

**Foundation**
- `PandoraServiceProvider`, `config/pandora.php`, `Pandora` facade
- Tenancy + actor abstraction with null-tenant defaults
- ULID trait, DTO base, redaction filter, correlation IDs
- Exception hierarchy
- `pandora:install` (idempotent), `pandora:status`

**Data** — 9 migrations: agents, conversations, sessions, conversation_participants, messages, runs,
run_steps, settings, audit_logs

**Agents** — `Agent` model, `AgentDefinition` contract, registry, class↔DB sync,
`pandora:agent:list`, `pandora:agent:run`

**Runs** — `RunState` / `RunStepType` enums, `RunStateMachine`, `RunRepository`, `RunLock`,
`StartAgentRun`, `ContinueAgentRun`, cancellation

**Providers** — `Provider` / `ChatProvider` / `StreamingProvider` contracts, the DTO set,
`FakeProvider` + `FakeStreamingProvider`, then the `OpenAiCompatible` adapter

**Context** — minimal pipeline: system instructions + recent messages, with a token budget and a
recorded `context_retrieval` step

**Realtime** — 6 broadcast DTOs (`RunQueued`, `RunStarted`, `RunStatusChanged`,
`AssistantDeltaReceived`, `AssistantMessageCompleted`, `RunCompleted`/`RunFailed`/`RunCancelled`),
channel authorization, delta coalescing, polling fallback

**UI** — layout, dark/light, chat page (conversation list, thread, composer, live status, stop),
minimal run detail timeline, authorization on every page

**Audit** — run started / completed / failed / cancelled

### Out of scope for Phase 1
Tools, approvals, memory, automations, skills, MCP, channels beyond web, workspaces, multi-provider
routing, cost accounting, the remaining 14 UI page groups.

### Acceptance
See `docs/development/phase-1-acceptance.md` — 14 criteria mapped to automated tests.

---

## Phase 2 — Tools and approvals 🔨

Tool contract + typed input DTOs + schema generation from validation rules · registry (config,
provider, discovery) with groups, aliases, versioning, deprecation · the five authorization layers ·
`ToolPolicy` with all five outcomes and audited argument modification · `ExecuteToolCall` with
idempotency and duplicate detection · risk levels · `Approval` entity with once/run/remembered scopes,
expiry, comments · pause to `waiting_for_approval` and resume via `ResumeApprovedRun` · live tool +
approval cards · `tool_executions`, `approvals` tables · full audit coverage · Tools and Approvals UI
pages · built-in low-risk tools (`AskUser`, `RequestApproval`, `InspectRunStatus`, allowlisted
Eloquent query, config read, dispatch job, emit event, send notification).

**Acceptance:** an approval-gated tool pauses a run, survives a worker restart while paused, resumes
on approval, is denied correctly, and is fully audited. Tenant and actor authorization are proven by
security tests. Argument modification appears as a diff in the UI and in the audit log.

**Status:** 34 of 36 criteria in `docs/development/phase-2-acceptance.md` are verified by automated
test. The two outstanding are breadth rather than behaviour: the database matrix beyond SQLite, and
a human driving the new pages in a host application.

---

## Phase 3 — Providers and routing 🔨

Anthropic adapter · Gemini adapter · OpenRouter and Ollama through the OpenAI-compatible adapter ·
shared provider contract test suite · `Model` catalog with capabilities and pricing ·
`DeterministicModelRouter` with fallback chains · provider health probes · usage normalisation ·
cost estimation with pricing source and date · budgets across all scopes · credential encryption,
per-tenant and per-agent resolution, rotation · Providers and Usage UI pages ·
`pandora:provider:test` · `pandora:model:sync`.

**Acceptance:** every adapter passes the identical contract suite. Failover on outage, rate limit and
context overflow are each tested. Budget breaches stop execution. No test touches a paid API. Secrets
never appear in any log, trace, broadcast or API response — asserted, not assumed.

**Status:** 39 of 40 criteria in `docs/development/phase-3-acceptance.md` are verified by automated
test. The one outstanding is the database matrix beyond SQLite, which is CI-only breadth rather than
behaviour — the same item still open for Phase 2.

Gemini moved from official extension to core during this phase. It is the third genuinely distinct
dialect, and it is the one that issues no tool-call ids at all; building it was what forced the
contract suite to stop assuming every vendor does. Left until later, that assumption would have been
inherited by every adapter written in the meantime.

---

## Phase 4 — Automation ⬜

`Automation` entity with all six trigger types · single scheduler entry driving `next_run_at` ·
timezone handling · misfire, concurrency and retry policies · idempotency preventing double-fire ·
Laravel event triggers via `Pandora::on()` · signed, replay-protected webhooks · heartbeats and
autonomy levels · conditional polling · goal queue and pending observations · run history ·
Automations UI · `pandora:automation:list` / `:run`.

**Acceptance:** two schedulers firing simultaneously produce exactly one run. Autonomy levels are
enforced. An automation exhausting its autonomy budget disables itself and notifies an admin. Webhook
replay is rejected.

---

## Phase 5 — Memory and context ⬜

Full context provider pipeline with budgeting, redaction and attribute allowlisting · context files
from configured roots only · conversation summarisation · `MemoryItem` with all scopes and types ·
lexical retrieval requiring no vector database · `EmbeddingProvider` / `VectorStore` contracts +
pgvector adapter · curation, approval before storing sensitive facts, expiry, forgetting, export ·
workspaces with path containment, quotas and MIME restrictions · Memory and Workspaces UI.

**Acceptance:** memory scoping is proven — a user cannot retrieve another user's or another tenant's
memories. Workspace traversal and symlink escape attacks fail. A default install works with no vector
database.

---

## Phase 6 — Multi-agent and MCP ⬜

`DelegateToAgent`, child runs, depth limits, budget inheritance, cancellation propagation, structured
results · MCP client with transports, discovery, schema caching and hashing, per-agent permissions,
namespacing, health · optional authenticated Pandora MCP server with an explicit exposure allowlist ·
MCP UI · `pandora:mcp:list`.

**Acceptance:** delegation cannot escalate privilege — the child's abilities are the intersection.
Depth limits hold. A changed remote schema hash revokes approval. Nothing is exposed by the MCP server
without explicit configuration.

---

## Phase 7 — Channels and extension ecosystem ⬜

`Channel` contract · identity linking (channel identity is never application identity) · Slack as the
reference extension package · extension manifest format · Composer-installed extension discovery and
inspection · Channels UI · extension authoring documentation.

**Acceptance:** an unlinked channel identity cannot act as a user. An extension package registers
providers, tools and channels through the documented contracts alone, with no core changes.

---

## Phase 8 — Hardening and release ⬜

Security review against the full threat model · performance tests (large conversations, many
concurrent runs, long traces) · CI matrix across SQLite / MySQL / MariaDB / PostgreSQL · upgrade
tests · complete documentation set · example application under `tests/Fixtures/ExampleApp` · release
automation · CHANGELOG · v1.0 checklist.

**Acceptance:** every T1–T15 threat has a passing test. The matrix is green. The example application
runs the documented quick start end to end.

---

## Cross-cutting, every phase

Pest tests written with the code · PHPStan level 8 (Larastan) clean · Pint clean · public APIs
documented · internal APIs marked · CHANGELOG updated · a focused commit per verified milestone ·
`docs/development/progress.md` kept current.

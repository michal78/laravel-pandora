# Implementation Roadmap

> Status legend: ✅ complete and verified · 🔨 in progress · ⬜ not started
>
> A phase is complete only when its acceptance criteria pass, tests are green, static analysis is
> clean, and documentation exists. Nothing is marked complete on the strength of code existing.

| Phase | Title | Status |
|---|---|---|
| 0 | Discovery and architecture | ✅ |
| 1 | Kernel vertical slice | ✅ 22/22 criteria; walkthrough driven 2026-08-07 and found an agent-binding defect in the chat page — fixed, and the 2 added checks re-driven 2026-08-10 (20/20) |
| 2 | Tools and approvals | ✅ 36/36 — host walkthrough driven 2026-08-07; found four defects, three fixed and one carried as a contract change |
| 3 | Providers and routing | ✅ 41/41 — database matrix now genuinely green on MySQL, MariaDB and PostgreSQL |
| 3.5 | Agents page | ✅ 20/20 — host walkthrough driven 2026-08-07; found a missing slug on the Overview tab, fixed, and a stale stub-tab list in the walkthrough itself |
| 4 | Automation | ✅ 26/26 on all four engines; host walkthrough complete |
| 5 | Memory and context | ✅ 28/28 incl. a real pgvector CI leg; host walkthrough driven 2026-08-07 — four defects, all fixed, one of them a cross-user memory disclosure on the Memory page |
| 6 | Multi-agent and MCP | 🔨 30/30 verified; delegation walkthrough fully driven (2026-08-08 headless, 2026-08-10 browser) — 9 findings, 4 fixed. The MCP half is still undriven and needs a real MCP server |
| 7 | Workspaces, released and on object storage | 🔨 24/25 — both adapters green against real MinIO; walkthrough agent half driven 2026-08-10 (2 defects), browser half still open |
| 8 | Channels and extensions | 🔨 32/33 — walkthrough sections 1–4 and 6–8 driven; only section 5 (two people, one channel account, simultaneously) remains, blocked on a second Slack account. 16 findings |
| 9 | Hardening and release | ⬜ |

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

## Phase 2 — Tools and approvals ✅

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

**Status:** all 36 criteria in `docs/development/phase-2-acceptance.md` are verified by automated
test. The database matrix closed on 2026-08-06 — the full suite now passes on MySQL 8.4, MariaDB 11
and PostgreSQL 17 — and the host walkthrough was driven on 2026-08-07.

It found four defects, and three of them share a cause the suite is structurally unable to reproduce:
**a real model free to answer wrongly.** Asked for "the configured notification name", the model
reached for the email address in the user's sentence, because none of the five allowlist-driven
built-ins told it which names existed — the valid set was known, and the schema said `string`. The
refusal that followed reported a permissions problem. `FakeProvider` calls whatever a test tells it
to call, so nothing in the suite could have watched that happen.

The fourth was a conversation silently changing agent on reload, which is Phase 1's chat page and is
recorded against it. One item is carried rather than closed: five built-ins ship with empty
allowlists and cannot function until a host configures them, yet all eleven are advertised as ready.
That wants a way for a tool to declare itself unavailable with a reason, not another string change.

---

## Phase 3 — Providers and routing ✅

Anthropic adapter · Gemini adapter · OpenRouter and Ollama through the OpenAI-compatible adapter ·
shared provider contract test suite · `Model` catalog with capabilities and pricing ·
`DeterministicModelRouter` with fallback chains · provider health probes · usage normalisation ·
cost estimation with pricing source and date · budgets across all scopes · credential encryption,
per-tenant and per-agent resolution, rotation · Providers and Usage UI pages ·
`pandora:provider:test` · `pandora:model:sync`.

**Acceptance:** every adapter passes the identical contract suite. Failover on outage, rate limit and
context overflow are each tested. Budget breaches stop execution. No test touches a paid API. Secrets
never appear in any log, trace, broadcast or API response — asserted, not assumed.

**Status:** all 41 criteria in `docs/development/phase-3-acceptance.md` are verified by automated
test. The last one — the database matrix beyond SQLite — was closed on 2026-08-06, and closing it
was not the formality it looked like: the matrix had been running SQLite in all three engine jobs
because `TestCase` hardcoded the connection, and making it real found three defects. See the
2026-08-06 entry in `progress.md`.

Gemini moved from official extension to core during this phase. It is the third genuinely distinct
dialect, and it is the one that issues no tool-call ids at all; building it was what forced the
contract suite to stop assuming every vendor does. Left until later, that assumption would have been
inherited by every adapter written in the meantime.

---

## Phase 3.5 — Agents page ✅

A late insertion, and an admission. The control-center page map specifies sixteen page groups, and
`Agents` is one of them — but Phase 1 deferred "the remaining 14 UI page groups" and no later phase
ever claimed this one. Phases 4 to 7 each name only their own pages. Left alone, the single entity
the whole product is named for would have reached Phase 8 with no way to look at it that was not
`pandora:agent:list`.

Phase 4 is where it becomes untenable rather than merely untidy: every automation binds to an agent
and inherits its `autonomy_level`, `token_budget` and `cost_budget_minor`. Shipping an Automations
editor whose agent picker points at rows nobody can open would drag half this page into Phase 4
anyway, unplanned.

### Scope

Only what Phases 1–3 have already built, which is more than it sounds — the `agents` table has
carried instructions, model preferences, the four limits, budgets, autonomy and the three policy
documents since Phase 1.

`AgentsIndex` — name, slug, definition source, model, autonomy, enabled, run counts ·
`AgentDetail` with six live tabs: Overview · Instructions · Models · Limits & Autonomy · Runs ·
Usage · creating and editing database-defined agents, gated on `pandora.agents.manage` ·
class-defined agents rendered read-only for the fields their definition expresses, naming the class ·
`AgentRegistry::managedKeysFor()` exposing that set to the UI · audited edits ·
`agent.created` / `agent.updated` / `agent.deleted` · sidebar entry.

The remaining seven tabs — Tools, Skills, Memory, Channels, Automations, Workspace, Permissions —
are rendered as stubs naming the phase that fills them. Each owning phase now carries one UI line
item instead of Phase 8 inheriting all of them at once.

### The decision this phase exists to get right

`definition_class` is nullable, so one page serves two kinds of agent. Class definitions are
authoritative for the fields they set (`AgentRegistry`, ADR-0007's sibling reasoning). The editor
therefore reads `managedKeys()` from the blueprint and renders exactly those fields read-only,
naming the class that owns them; only fields the definition leaves unset stay writable. **Create**
produces database-defined agents only.

The alternative — letting the form write anything and hoping — produces an edit that survives until
the next deploy silently reverts it. That is the kind of defect that is reported as "Pandora lost my
settings" six months later.

### Acceptance

See `docs/development/phase-3.5-acceptance.md` — 20 criteria mapped to automated tests. The
load-bearing ones: a control-center edit to a class-managed field is refused rather than reverted;
`pandora.agents.manage` is required to write and proven absent-by-default; instructions stay behind
`pandora.prompts.view`; and a tenant cannot open, edit or delete another tenant's agent.

**Status:** complete. All 20 criteria verified by automated test, and the host walkthrough (Q9)
driven against `laravel-test` on 2026-08-07 — a person clicking Edit in a browser against a real
deployment, which is what the criteria could not do for themselves.

It found two things. The Overview tab never stated the agent's slug: it appeared once as faint text
beside the heading, while the ULID nobody types had a label of its own. The slug is what the console,
the routes and the config all use, so it is now a labelled fact, with a regression test. The second
was the walkthrough rather than the page — its "seven stub tabs" list was written at 3.5 and Phases 4
and 5 had filled three of them in, so a document quoting a frozen list was reporting its own age as a
failure.

---

## Phase 4 — Automation ✅

The phase where Pandora starts doing things nobody asked it to do in the moment. Everything else in
this roadmap runs because a human pressed something; an automation runs because a clock, an event or
a remote system said so. That is the capability ADR-0009 exists to bound.

### Scope

**Data** — 4 migrations: `automations`, `automation_runs`, `webhook_deliveries`, `observations`

**Entity** — `Automation` with all six trigger types (`one_off` · `cron` · `interval` · `event` ·
`webhook` · `heartbeat`), a timezone, a condition, a concurrency policy, a misfire policy, a retry
policy, an autonomy level and an autonomy budget.

**Scheduling** — `NextRun` computing `next_run_at` in the automation's own timezone ·
`AutomationScheduler` claiming due rows · one Laravel scheduler entry (`pandora:automation:tick`)
driving all of it · `AutomationRun` occurrence rows uniquely keyed on
`(automation, idempotency_key)` — the double-fire guard.

**Dispatch** — `AutomationDispatcher`: condition → concurrency → autonomy budget → idempotency →
run. Every refusal is an occurrence row with a reason, not a silence.

**Autonomy** — an autonomous run is stamped with the automation's level, clamped to the agent's, and
consumes a budget. Exhausting it disables the automation and notifies an admin.

**Triggers** — `Pandora::on(SomeEvent::class)->run('agent')` for code-defined event bindings, plus
database automations bound to an event class · signed, replay-protected webhook endpoints, one per
automation.

**Proposals** — the `propose_follow_up` tool writes a pending `Observation` instead of scheduling
work. Promotion to an automation is a human act behind `pandora.automations.manage`.

**UI** — Automations index and detail, run history, manual run, the agent's **Automations** tab,
sidebar entry.

**Console** — `pandora:automation:list` · `pandora:automation:run` · `pandora:automation:tick`.

### The decision this phase exists to get right

**An occurrence fires exactly once, whatever the infrastructure does.** Two schedulers, a queue
retry, a duplicated webhook delivery and a replayed event all converge on the same guard: an
occurrence has a deterministic idempotency key, that key is uniquely indexed with the automation,
and the insert is the claim. Nothing downstream is trusted to notice a duplicate.

The alternative — checking `last_run_at` before dispatching — is a race with a window the width of a
database round trip, and it fails exactly when it matters: under the load that made you run two
schedulers.

### Acceptance

See `docs/development/phase-4-acceptance.md` — 26 criteria mapped to automated tests. The
load-bearing ones: two schedulers firing simultaneously produce exactly one run; an automation
exhausting its autonomy budget disables itself and notifies an admin; a replayed webhook is
rejected; and an automation can never raise the autonomy of the agent it binds to.

**Status:** all 26 criteria verified by automated test, on SQLite, MySQL 8.4, MariaDB 11 and
PostgreSQL 17. ADR-0009's autonomy levels are also now *enforced* rather than merely stored —
`ToolGatekeeper` gained an autonomy layer, and every run records the level it ran at.

The host walkthrough is **complete** — all twenty checks in
`docs/development/phase-4-walkthrough.md`, against `laravel-test` on 2026-08-06, including a real
cron firing a real automation and the autonomy budget disabling one by itself.

It earned its place. Three defects were found by running Pandora somewhere the test suite is not:
a fatal `TypeError` on any host using `Date::use(CarbonImmutable::class)`, a date field reported as
changed on every save, and a replayed webhook that left no evidence anywhere. None was reachable
from the package suite, because the test application does not set immutable dates and the replay
path recorded nothing to assert against.

Still uncovered, and deferred to Phase 8 rather than left implied: a live Reverb server, and an
automation left running long enough to exercise the misfire policy against a genuine worker outage.

---

## Phase 5 — Memory and context ✅

Full context provider pipeline with budgeting, redaction and attribute allowlisting · context files
from configured roots only · conversation summarisation · `MemoryItem` with all scopes and types ·
lexical retrieval requiring no vector database · `EmbeddingProvider` / `VectorStore` contracts +
pgvector adapter · curation, approval before storing sensitive facts, expiry, forgetting, export ·
workspaces with path containment, quotas and MIME restrictions · Memory and Workspaces UI ·
the agent's **Skills**, **Memory** and **Workspace** tabs.

**Acceptance:** memory scoping is proven — a user cannot retrieve another user's or another tenant's
memories. Workspace traversal and symlink escape attacks fail. A default install works with no vector
database.

**Status:** complete. All 28 criteria verified by automated test including a real pgvector CI leg,
and the host walkthrough driven against `laravel-test` on 2026-08-07: the memory half in full, in a
browser, against a real model.

Four defects, all fixed. Three came from the first pass — a parameterless tool whose `properties: []`
encoded as a JSON array and made OpenAI reject every tool in the request; `remember` inheriting a
base-class `authorize()` that refused it to everybody including the person whose memory it was; and
`Chat::send()` starting a rival run instead of resuming one parked at `waiting_for_user`, leaking a
run per question asked.

The fourth came from the second pass and is the one worth naming here: reading the Memory page
required only `pandora.access`, and the listing is filtered by scope and status but never by actor —
so any authenticated user could read every user-scoped memory on the installation, sensitive ones
included. Every ability *around* it was correct, which is how it lasted; only reading was left open,
and reading is the part that discloses. The page is now an operator surface end to end. Note what the
acceptance line above says: memory scoping was proven for *retrieval*, by an agent, and no criterion
asked what the admin page shows a human.

The vector-store and workspace sections of the walkthrough are deliberately not driven — the first
needs Postgres and runs in CI instead, the second is Phase 7.

---

## Phase 6 — Multi-agent and MCP 🔨

`DelegateToAgent`, child runs, depth limits, budget inheritance, cancellation propagation, structured
results · MCP client with transports, discovery, schema caching and hashing, per-agent permissions,
namespacing, health · optional authenticated Pandora MCP server with an explicit exposure allowlist ·
MCP UI · the agent's **Permissions** tab · `pandora:mcp:list`.

Discovery approves nothing, ever. Approval is per agent and per tool, and it is approval of a hash
covering the tool's **description** as well as its schema — because a server that keeps every
parameter and rewrites its description into an instruction is the easier attack and the one with no
other detection (ADR-0014).

**Acceptance:** delegation cannot escalate privilege — the child's abilities are the intersection.
Depth limits hold. A changed remote schema hash revokes approval. Nothing is exposed by the MCP server
without explicit configuration. See `docs/development/phase-6-acceptance.md` — **all 30 criteria
verified**; what remains is a human driving `phase-6-walkthrough.md` against a real MCP server,
**deliberately deferred to after Phase 8** and paid before Phase 9.

---

## Phase 7 — Workspaces, released and on object storage 🔨

The workspace engine was built in Phase 5 and deferred before release, because a workspace is only
as safe as the answer to *who chose the root* — and the obvious UI for that is a form with a path
field, which is a form that accepts `/`.

ADR-0013 widened the phase. Workspaces and context files move to S3-compatible object storage
(AWS, DigitalOcean, Hetzner, MinIO, R2) with local as a configured choice rather than a runtime
fallback. That reopens `WorkspaceFiles`, deliberately: containment today is
resolve-with-`realpath`-then-check, and object storage has no `realpath`, no symlinks and no
directories, so the property has to be re-derived per adapter rather than ported.

A storage contract with two adapters · lexical key normalisation · tenant prefixes that cannot
collide · paginated listing · MIME from the bytes on both adapters · context files behind an ETag
cache with bounded reads · streamed, audited downloads · a root-selection mechanism that does not
accept free text · the Workspaces page and the agent's **Workspace** tab un-deferred ·
`pandora.features.workspaces` on by default · a MinIO leg in CI.

Roots are declared in `pandora.workspaces.roots` — a disk and a base prefix, by key — and the
creation form has no path field: the path is composed as `<base>/<tenant>/<slug>`, so a request's
whole vocabulary for saying where a workspace lives is something an operator wrote down. A disk and
root are immutable afterwards, and deleting a workspace leaves its files where they are.

**Acceptance:** traversal and symlink escape fail on local; a key normalising outside the root is
refused on object storage; the same containment suite passes on both adapters or the build fails.
An unreachable disk is a tool error and never a silent write somewhere else. Pandora stores no
object-storage credential, and issues no presigned URL for any workspace — downloads stream through
the app so the audit trail records that the file left rather than that a link was made.
An agent reaches its workspace through `list_files`, `read_file` and `write_file`, none of which
takes a workspace argument: the workspace comes from the agent, which holds at most one, so a
sentence in a document it is reading has nowhere to select from.

See `docs/development/phase-7-acceptance.md` — **24 of 25 criteria accepted**; what remains is a
human driving `phase-7-walkthrough.md` against a real bucket, **deliberately deferred to after
Phase 8** and paid before Phase 9.

---

## Phase 8 — Channels and extension ecosystem 🔨

`Channel` contract · identity linking (channel identity is never application identity) · Slack as the
reference extension package · extension manifest format · Composer-installed extension discovery and
inspection · Channels UI · the agent's **Channels** tab · extension authoring documentation.

Every phase so far served someone the host application had already authenticated. A channel supplies
a stranger and a sentence: Slack can prove that user typed those words, and cannot prove which host
user that is — which is the only thing every authorization decision in Pandora depends on. So an
unlinked identity gets no run, no session and no guest seat, and linking needs evidence from both
sides: a code issued *into the channel*, redeemed *inside an authenticated host session* (ADR-0015).

Extensions arrive through `composer require` and nothing else. There is no marketplace, no remote
install and no update mechanism — excluded rather than deferred, because a UI that can install code
is a UI whose authorization bug is arbitrary execution. The manifest is an `extra.pandora` block read
from `vendor/composer/installed.json`, so the control center can describe an extension it has never
booted, including one that would fatal if it were (ADR-0016).

**Acceptance:** an unlinked channel identity cannot act as a user. An extension package registers
providers, tools and channels through the documented contracts alone, with no core changes — proved
by `laravel-pandora-slack` living in its own repository, where a missing seam has to be added to core
in the open rather than filled in the same commit. See `docs/development/phase-8-acceptance.md` —
**32 of 33 criteria verified**. Criterion 33 — a human driving `phase-8-walkthrough.md` against a
real Slack workspace — is driven for sections 1–4 and 6–8, and has produced 16 findings. Only
section 5 remains: two people on one channel account **at the same time**, which needs a second
Slack account and is the one claim a single-account walkthrough cannot make.

The reference extension needed no core change at all. It was written from outside the boundary and
the published contracts were between them enough to build a working adapter on the first attempt,
with its own suite running against real core — which is the only form that claim can honestly take.

---

## Phase 9 — Hardening and release ⬜

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

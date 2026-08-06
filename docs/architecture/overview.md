# Architecture Overview

> Status: Phase 0 (discovery). This is the design of record. Implementation status lives in
> `docs/development/progress.md`.

## 1. The forces

Six requirements shape everything, and several of them conflict:

1. **A run may take minutes** — so it cannot live in a web request, and it cannot live in PHP memory.
2. **A run may pause for a human** — approval or clarification — for an unbounded period.
3. **A run must survive worker restarts** — queue retries, deploys, crashes, provider timeouts.
4. **A run must stream** — sub-second feedback to a browser, from a queue worker.
5. **A run must be authorized continuously** — not once at the start, but at every tool call, against
   the host application's own policies.
6. **The package must install into an application that owns none of this** — no forced Redis, no
   forced vector database, no forced tenancy package, no forced routes or migrations.

Requirements 1–3 push toward durable, database-driven state. Requirement 4 pushes toward a long-lived
in-memory process. That tension is the central architectural problem, and it is what the three
candidate architectures below actually differ on.

---

## 2. Candidate architectures

### Approach A — Long-lived orchestrator process

A daemon (or a very long queue job) holds the run in memory for its full lifetime. Streaming is
direct. State is checkpointed opportunistically.

This is essentially how the reference products work, and for a single-operator daemon it is the right
answer.

| | |
|---|---|
| **For** | Simplest execution loop — a plain `while` loop. Lowest latency. Streaming is trivial. No serialisation of intermediate state. |
| **Against** | A worker restart destroys in-flight runs. An approval pause holds a worker hostage for hours. Requires process supervision Laravel apps do not universally have. Horizontally scaling means sticky routing. **Contradicts requirements 2 and 3 outright.** |

**Rejected.** Not because it is bad, but because it assumes an operator-managed daemon. Pandora must
run on a plain `queue:work`.

### Approach B — Fully event-sourced runs

Every state change is an immutable event; run state is a projection. Resumption is replay.

| | |
|---|---|
| **For** | Perfect auditability by construction. Time-travel debugging. Resumption is a solved problem. Very testable. |
| **Against** | Replay must be deterministic, but our steps include non-deterministic, non-idempotent side effects (a refund was issued; you cannot replay that). So we would need a full effect/saga layer. Projections add real query complexity across four database engines. A large conceptual tax on every host developer who has to debug it. |

**Rejected as the core model.** The audit benefit is real, but we get most of it from an append-only
step log without paying the replay tax. We adopt the *append-only* half and reject the *projection*
half — see ADR-0002.

### Approach C — Durable state machine with step-wise queued continuations ✅

A run is a database row with an explicit state. Each iteration of the execution loop is a **separate
queued job** that loads state, does one bounded piece of work, persists the result as an append-only
step, transitions the state, and dispatches the next continuation — or stops.

Pausing costs nothing: the run simply sits in `waiting_for_approval` with no job in flight.
Resumption is dispatching `ContinueAgentRun` again. A worker crash loses at most one iteration, which
the queue retries.

| | |
|---|---|
| **For** | Satisfies requirements 1, 2, 3, 5 and 6 directly. Works on any Laravel queue driver. Scales horizontally with no sticky routing. Each job is small, individually testable, and individually retryable. Pauses consume zero resources. |
| **Against** | Per-iteration load/persist overhead. State transitions must be transactional and guarded against concurrent execution. Streaming needs a solution, because the streaming provider call happens inside one job while the browser is elsewhere. |

**Selected.** The costs are engineering costs we can pay once, inside the framework. The benefits are
correctness properties we cannot retrofit.

### How Approach C solves streaming

Streaming is not in tension with Approach C once you separate the two timescales:

- The **provider HTTP stream** is consumed inside a single `ContinueAgentRun` job. That job is
  short-lived — one model turn — not the whole run.
- **Deltas are broadcast as they arrive**, coalesced on a small time/size window to avoid flooding
  Reverb with per-token events.
- The **assistant message row is persisted incrementally** (buffered writes), so a browser that
  reloads mid-stream reconstructs the partial message from the database.

The browser therefore has two independent paths to correct state: the live broadcast, and the
database. It never depends on the broadcast alone. This is the rule stated in `realtime-model.md`.

---

## 3. Recommended architecture

### 3.1 Shape

```
┌─────────────────────────────────────────────────────────────────┐
│ Entry points                                                     │
│  Livewire chat · API · Artisan · Scheduler · Event listener      │
│  Webhook · Channel adapter · DelegateToAgent tool                │
└───────────────────────────┬─────────────────────────────────────┘
                            │  creates a Run (pending) + records Trigger
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│ Runs module — the state machine                                  │
│  RunRepository · RunStateMachine · RunLock · BudgetGuard         │
└───────────────────────────┬─────────────────────────────────────┘
                            │  dispatch
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│ Jobs (queue: pandora-agents / pandora-tools)                     │
│  StartAgentRun → ContinueAgentRun ⇄ ExecuteToolCall              │
│                → ResumeApprovedRun                               │
└───────────────────────────┬─────────────────────────────────────┘
                            ▼
┌──────────────┬──────────────┬──────────────┬────────────────────┐
│ Context      │ Providers    │ Tools        │ Approvals          │
│ providers +  │ router +     │ registry +   │ policy → pause →   │
│ memory       │ adapters     │ policy       │ resume             │
└──────────────┴──────────────┴──────────────┴────────────────────┘
                            │  every step appended
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│ Persistence: runs · run_steps · messages · tool_executions ·     │
│              usage_records · audit_logs                          │
└───────────────────────────┬─────────────────────────────────────┘
                            │  redacted, versioned DTOs
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│ Realtime (Reverb) → Livewire control center                      │
└─────────────────────────────────────────────────────────────────┘
```

### 3.2 Module boundaries

Modules are directories under `src/` with explicit public surfaces. The rule that prevents an
`AgentService` god object is enforced by an **architecture test**, not by discipline:

- A module may depend on `Core`, `Contracts` and `Support` freely.
- Cross-module dependencies must go through a contract in `Contracts/`, not a concrete class.
- `Runs` is the only module permitted to transition run state.
- No class may exceed one module's responsibility; there is no `PandoraManager`. The `Pandora` facade
  is a thin ergonomic entry point that delegates to module services and contains no logic.

| Module | Owns |
|---|---|
| `Core` | Service provider, config, container bindings, tenancy + actor resolution, correlation IDs |
| `Agents` | Agent entity, `AgentDefinition` classes, registry, definition↔DB sync |
| `Conversations` | Conversations, sessions, participants, forking, titles |
| `Messages` | Message entity, roles/types, attachments, incremental streaming writes |
| `Runs` | Run entity, state machine, steps, locking, budgets, cancellation, resumption |
| `Providers` | Provider contracts, adapters, credential resolution, normalisation, health |
| `Models` | Model catalog, capabilities, pricing, the model router |
| `Tools` | Tool contract, registry, schema generation, validation, policy, execution |
| `Skills` | Skill format, storage, validation, progressive loading, import/export |
| `Memory` | Memory items, scoping, retrieval, embedding + vector contracts, curation |
| `Context` | Context provider pipeline, budgeting, redaction, context files |
| `Approvals` | Approval entity, request/resolve, scopes, expiry, pause/resume integration |
| `Automation` | Automations, schedules, misfire/concurrency policy, run history |
| `Triggers` | Trigger contracts, event/webhook/schedule/manual sources |
| `Channels` | Channel contract, web chat, identity linking, normalisation |
| `Realtime` | Broadcast event DTOs, channel authorization, delta coalescing |
| `Workspaces` | Sandboxed filesystem, path containment, quotas |
| `Mcp` | MCP client, server registry, schema caching, optional MCP server |
| `Usage` | Usage normalisation, cost estimation, budget enforcement |
| `Audit` | Append-only audit log, redaction |
| `Health` | Probes, doctor checks |
| `UI` | Livewire components, Blade views, navigation, authorization |

### 3.3 The execution loop

`ContinueAgentRun` performs exactly one iteration:

1. Acquire the run lock (atomic cache lock + DB ownership token). Abort if held.
2. Load the run; assert the state permits continuation; assert not cancelled.
3. Check budgets — iterations, tool calls, tokens, money, wall clock. Breach → terminal state.
4. Build context (providers → budget → redaction). Append `context_retrieval` step.
5. Retrieve permitted memory. Append `memory_retrieval` step.
6. Resolve provider + model via the router. Append `model_request` step.
7. Call the provider. Stream deltas → coalesce → broadcast + buffer to the message row.
8. Append `model_response` step with normalised usage.
9. If the response is a final answer → `completed`. Append `final_response`. Broadcast. Stop.
10. If the response requests tools → for each call: validate schema, evaluate policy, check duplicate
    detection.
    - `deny` → append `tool_result` (error), continue loop.
    - `require_approval` → create `Approval`, transition to `waiting_for_approval`, broadcast, **stop
      dispatching**. The run now costs nothing until a human acts.
    - `allow` / `modify_arguments` → dispatch `ExecuteToolCall` on `pandora-tools`.
11. When all tool results are in, dispatch the next `ContinueAgentRun`.
12. Release the lock. Persist usage. Broadcast the new state.

Every branch either dispatches a continuation, reaches a terminal state, or parks in a waiting state.
There is no branch that silently does nothing — that is an architecture-test invariant.

### 3.4 Run states

```
pending → queued → starting → running
                                 ├→ waiting_for_tool ─┐
                                 ├→ waiting_for_approval ─┤→ running
                                 ├→ waiting_for_user ─┘
                                 ├→ paused
                                 ├→ cancelling → cancelled
                                 ├→ completed
                                 ├→ failed
                                 └→ timed_out
```

Terminal: `completed`, `failed`, `cancelled`, `timed_out`. Transitions are validated by the state
machine and performed inside a transaction with the run row locked.

---

## 4. Proposed directory structure

```
laravel-pandora/
├── composer.json
├── config/pandora.php
├── database/
│   ├── migrations/
│   └── factories/
├── resources/views/
│   ├── layouts/          components/       pages/
├── routes/
│   ├── web.php  api.php  channels.php  console.php
├── src/
│   ├── PandoraServiceProvider.php
│   ├── Facades/Pandora.php
│   ├── Contracts/            # every extension point; the package's public seam
│   ├── Core/                 # bootstrap, config, tenancy, actor, correlation
│   ├── Agents/  Conversations/  Messages/  Runs/
│   ├── Providers/  Models/  Tools/  Skills/
│   ├── Memory/  Context/  Approvals/
│   ├── Automation/  Triggers/  Channels/
│   ├── Realtime/  Workspaces/  Mcp/
│   ├── Usage/  Audit/  Health/
│   ├── UI/                   # Livewire components + view models
│   ├── Console/Commands/
│   ├── Http/                 # controllers, resources, middleware, requests
│   ├── Jobs/
│   ├── Exceptions/
│   ├── Support/              # DTO base, redaction, ULID, enums, JSON schema
│   └── Testing/              # FakeProvider, FakeTool, contract test suites
├── tests/
│   ├── Unit/ Feature/ Integration/ Architecture/ Security/
│   ├── Realtime/ Queue/ Provider/ Database/ UI/
│   └── Fixtures/ExampleApp/  # the documented example application
└── docs/
```

Each module directory follows the same internal shape where applicable:

```
Runs/
├── Run.php                  # Eloquent model
├── RunStep.php
├── Enums/RunState.php  Enums/RunStepType.php
├── RunStateMachine.php
├── RunRepository.php
├── RunLock.php
└── Events/                  # domain events (not broadcast DTOs)
```

---

## 5. Control-center page map

Sixteen groups. Each page is authorized independently (`security-model.md`).

| Group | Pages |
|---|---|
| Dashboard | status, attention items, health, usage summary |
| Chat | conversation list, thread, composer, tool cards, approval cards |
| Conversations | search, filter, tags, archive, fork, usage |
| Agents | index; detail tabs: Overview · Instructions · Models · Limits & Autonomy · Automations · Runs · Usage · Skills · Memory · Workspace (built, Phases 3.5 to 5) — Tools · Channels · Permissions (each filled by the phase that builds its subsystem) |
| Runs | active / waiting / completed / failed / cancelled; run detail timeline; raw trace (admin only) |
| Tools | registry, schema, risk, policy, executions, success rates, test console |
| Skills | installed, validation, import/export, editor, warnings |
| Providers | credentials status, models, capabilities, health, latency, cost, fallbacks, test connection |
| Automations | list with next/last run, editor (overview · schedule · behaviour · history · webhook), occurrence history including refusals, manual run, webhook secret rotation, the agent-proposal queue (built, Phase 4) |
| Approvals | pending / approved / denied / expired, audit history |
| Memory | search, scope, type, sensitivity, suggested memories awaiting review (built, Phase 5) |
| Workspaces | listing, containment-checked browsing, quota and recount (built, Phase 5); editor, preview, upload/download and diffs remain Future |
| Channels | adapters, accounts, identity mapping, routing, delivery tests |
| MCP | servers, transport, health, discovered tools, agent access, server settings |
| Usage | tokens, requests, cost, filters, export |
| Logs & Audit | audit records with actor/tenant/correlation filters |
| Health | queue, workers, scheduler, Reverb, DB, cache, probes, stalled runs, storage, version |
| Settings | 18 sections; runtime settings only — deployment config stays in `config/pandora.php` |

## 6. Two installation modes

**Headless framework mode** — the default. Service provider, contracts, migrations, jobs, facade,
API. No routes, no Livewire, no views. `pandora.ui.enabled = false`.

**Full control-center mode** — additionally registers routes, Livewire components, views, navigation
and broadcast channels.

Nothing — routes, assets, migrations — is forced on an application that has not opted in.

## 7. Related documents

- `security-model.md` — trust boundaries, threat model, authorization
- `execution-model.md` — state machine, jobs, locking, budgets, resumption
- `provider-model.md` — provider contracts, normalisation, routing, failover
- `database-model.md` — schema, keys, indexes, portability
- `realtime-model.md` — broadcast DTOs, channels, delta coalescing, reconnection
- `../adr/` — the decisions and their alternatives

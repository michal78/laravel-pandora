# Implementation Log

Reverse-chronological. Every entry records what was actually done and actually verified. Commands
claimed to pass were run; output is quoted where it matters.

---

## 2026-08-05 — Phase 3.5: the Agents page 🔨 (20 of 20 criteria verified)

**Why this phase exists.** It was not on the roadmap. Reviewing what Phase 4 needed turned up a gap:
`docs/architecture/overview.md` specifies sixteen control-center page groups, `Agents` is one of
them, Phase 1 deferred "the remaining 14 UI page groups", and no later phase ever claimed this one —
Phases 4 to 7 each name only their own. The entity the product is named for was on course to reach
Phase 8 with `pandora:agent:list` as the only way to look at one.

Phase 4 is where that turns from untidy into incoherent. Every automation binds to an agent and
inherits its `autonomy_level`, `token_budget` and `cost_budget_minor`; an Automations editor whose
agent picker points at rows nobody can open would have dragged half this page into Phase 4 unplanned.
Inserted here instead, and the seven tabs whose subsystems do not exist yet are now line items on the
phases that build them rather than a lump inherited by Phase 8.

**Delivered.** No new tables and no new domain code — the `agents` table has carried all of this
since Phase 1, and Phases 2 and 3 built the behaviour behind it. This is the surface.

- `AgentsIndex` — roster with source, model, autonomy, status, run counts; search and source filters;
  create, gated on `pandora.agents.manage`
- `AgentDetail` — six live tabs (Overview · Instructions · Models · Limits & Autonomy · Runs · Usage)
  and seven stubs naming the phase that fills each
- `AgentRegistry::managedKeysFor()` and `definitionIsInstalled()`
- Audit: `agent.created`, `agent.updated` (tab, changed keys, before and after), `agent.deleted`
- `pd-tabs` and `pd-locked` styles; sidebar entry; `/agents` and `/agents/{agent}` routes
- `docs/development/phase-3.5-acceptance.md` — 20 criteria

**The decision the phase turned on.** `definition_class` is nullable, so one page serves two kinds of
agent, and a class definition is authoritative for the fields it sets. The obvious implementation —
let the form write anything — produces an edit that looks saved until the next deploy silently
reverts it. That defect surfaces months later as "Pandora lost my settings", with nothing in the logs
to explain it.

So the editor reads `managedKeys()` from the blueprint, renders exactly those fields as stated values
naming the class that owns them, and **refuses** a write to one rather than accepting it. Three
details fell out of building it, none of which were obvious from the outside:

1. `syncDefinition()` writes `name` unconditionally, whether or not the blueprint sets it. So `name`
   is authoritative for every class-defined agent, and `managedKeys()` alone would have understated
   the locked set by one field — the most visible one.
2. The slug has to be locked too. It is the identity a definition is matched by, so an edit would
   orphan the row and mint a duplicate at the next sync.
3. A definition can be deleted while its row survives. `managedKeysFor()` returns nothing in that
   case, so the fields become editable rather than frozen forever by a class that no longer exists.

The refusal rejects the **whole** save, not the offending field. A partial save would show the
operator their incidental change accepted and the one they cared about silently missing, which is a
worse failure than either alternative. Asserted in
`it refuses the whole save rather than the offending field alone`.

**Also decided.** New agents are created disabled, at `observe_only`, with no tools — an agent that
could act the moment it was named turns a typo into an incident. Class-defined agents cannot be
created or deleted here; the next sync would undo both. Instructions are gated on
`pandora.prompts.view` for read *and* write, since you cannot safely edit what you may not read.
Saving is per tab, because a form submitting every attribute makes every audit entry look like a
change to everything.

**Verification.**

```
vendor/bin/pest        -> Tests: 763 passed (2,640 assertions)   [was 728]
vendor/bin/phpstan     -> [OK] No errors  (level 8)
vendor/bin/pint --test -> passed
```

34 of the 35 new tests are in `tests/UI/AgentsIndexTest.php` and `tests/UI/AgentDetailTest.php`.
Criterion 6 (`agents.manage` denied on a fresh install) is asserted in `tests/UI/NavigationTest.php`
instead, because both new files grant the ability in `beforeEach` in order to exercise the page at
all — a file that overrides a default cannot also be the file that proves it.

**Outstanding.** The host walkthrough (Q9) — every assertion here is a Livewire test, and nobody has
clicked Edit in a browser against a real deployment. Same item as Phases 1 and 2.

**Next.** Phase 4 — Automation.

---

## 2026-08-05 — Phase 3: Providers and routing 🔨 (39 of 40 criteria verified)

**Delivered.** A choice of minds, a bill, and a credential that is genuinely hard to leak.

- Credentials: `pandora_provider_credentials`, `Credential` DTO, `CredentialSource`,
  `CredentialResolver` contract, `DatabaseCredentialResolver`, `CredentialManager` with issue,
  rotate and revoke
- Contract suite: `src/Testing/ProviderContractTests.php` + `ProviderFixtures`, run against four
  adapters
- Adapters: `AnthropicProvider`, `GeminiProvider`, `ClassifiesProviderFailures` shared by all three
  HTTP adapters; Ollama and OpenRouter proven through the OpenAI-compatible one
- Catalog: `pandora_models`, `CatalogModel`, `ModelCatalog`, `ModelDescriptor`, `CostEstimate`,
  `ModelCatalogProvider` contract, `pandora:model:sync`
- Routing: `ModelRouter` contract, `DeterministicModelRouter`, `RoutingRequest`, `RoutingDecision`,
  `RoutingSource`, `NoModelAvailable`, and the failover loop in `ContinueAgentRun`
- Health: `pandora_provider_health`, `ProviderHealthRecord`, `ProviderHealthMonitor`,
  `ProbeProviderHealth`
- Usage and budgets: `pandora_usage_records`, `UsageRecord`, `UsageRecorder`, `BudgetGuard`,
  `BudgetScope`
- UI and console: Providers page, Usage page, `pandora:provider:test`

**Five defects found by the tests, all real:**

1. `Collection::sortBy()` given an array of closures calls each one as a *comparator*, not as a key
   extractor. Credential resolution silently picked the wrong version. Rewritten as an explicit
   comparator.
2. A 200 response whose body would not parse produced an empty completion rather than an error. A
   truncated transfer is a broken response, not an empty answer; it now raises `ProviderUnavailable`
   so it retries and can fail over.
3. `pandora:provider:test` printed the provider's raw error message — and OpenAI echoes the API key
   back in that message on a 401. Redacted on the way out.
4. The same leak on the durable path: a provider's message reached `runs.error_message` and the
   application log. Redaction moved into `RunStateMachine` and `RunFailer`, which are the single
   write points, so no call site can forget it.
5. `RunFactory` stamped the agent's default provider and model onto every new run. The columns mean
   "this run is pinned", so every run looked pinned: the agent and configured-default precedence
   levels were unreachable and every routing decision was labelled wrongly on the trace. Now null
   until something genuinely overrides, or the first call resolves one.

**Decisions worth recording.**

*Gemini moved from official extension to core.* It is the third genuinely distinct dialect and the
only one that issues no tool-call ids at all. Building it forced the contract suite to stop assuming
every vendor does — an assumption that would otherwise have been inherited by every adapter written
afterwards. The adapter synthesises `name#index` ids and resolves them back on the way out, so
nothing above it knows Gemini is different.

*The contract suite ships in `src/`, not `tests/`.* An extension package writing its own adapter can
implement `ProviderFixtures` and run our suite against it, which is the only way "a new adapter is
done when the shared suite passes" means anything outside this repository.

*Prices must state a source and a date, or they are refused.* Six months later nobody can tell
whether an unattributed price was ever right. Past the staleness window the estimate is still
produced and flagged, in the UI and on every record.

*An unpriced model records `null`, never zero* — and therefore contributes nothing to a cost budget.
Inventing a figure would stop runs on the strength of a number nobody entered. Token budgets are the
right tool where prices are unknown, and `BudgetEnforcementTest` says so.

*Cost is carried in micro units.* A thousand calls at 0.045 cents each is real money; rounded to
cents at the point of measurement it is nothing.

*No test may reach a network.* `preventStrayRequests` is armed for the whole suite in `TestCase`,
so a forgotten fake throws instead of sending a real request with a real key.
`tests/Providers/NoLiveCallsTest` proves the guard is actually on.

**Verification.**

```
vendor/bin/pest        -> Tests: 711 passed (2,418 assertions)
vendor/bin/phpstan     -> [OK] No errors  (level 8, checkModelProperties on)
vendor/bin/pint --test -> passed
```

**Outstanding.** The database matrix beyond SQLite, which is CI-only and shared with Phase 2. The
four new tables use only portable types and short index names, and `tests/Database/PortabilityTest`
asserts those rules on whichever engine is running — but that is a guard plus an argument, not a run
on MySQL.

**Not in Phase 3, by design:** memory, automations, skills, MCP, delegation, workspaces, channels
beyond web. Bedrock, Azure, Mistral, Groq, xAI, Together and DeepSeek remain official extensions
rather than core. Cost-, capability- or latency-optimising routing stays out of v1 (ADR-0006).

---

## 2026-08-05 — Phase 2: Tools and approvals 🔨 (34 of 36 criteria verified)

**Delivered.** An agent can now touch the application, under five independent layers of
authorization, and a run can wait days for a human without consuming anything.

- Tools: `Tool` base class, `ToolInput`, `ToolResult`, `ToolContext`, `RiskLevel`,
  `RuleSchemaGenerator`, `ToolRegistry`, `ToolDiscovery`, `pandora:tool:list`
- Authorization: `ToolGatekeeper` (five layers), `ToolPolicy` contract with five outcomes,
  `RiskBasedToolPolicy` default, `ToolDecision`, `ArgumentDiff`, `AuthorizationLayer`
- Execution: `pandora_tool_executions`, `ToolExecution`, `ToolCallCoordinator`, `ExecuteToolCall`
- Approvals: `pandora_approvals`, `Approval`, `ApprovalManager`, `ResumeApprovedRun`,
  scopes/expiry/comments, `ApprovalNotPending` and `ApprovalExpired`
- Ask-user: `ToolResult::awaitingUser()`, `ResumeRunWithUserReply`, `Pandora::reply()`
- Providers: `ToolDefinition`, tools on `ChatRequest`, tool calls and results on `ChatMessage`,
  OpenAI request-side serialisation
- Built-ins: `ask_user`, `request_approval`, `inspect_run_status`, `query_records`, `read_config`,
  `dispatch_job`, `emit_event`, `send_notification`
- UI: Tools and Approvals pages, tool and approval cards in chat, argument diffs in the trace

**Verified — commands actually run, output quoted.**

```
vendor/bin/pest        → Tests: 432 passed (1,603 assertions)
vendor/bin/phpstan     → [OK] No errors            (level 8, checkModelProperties on)
vendor/bin/pint --test → passed
```

**Seven real defects, six found by the tests and one by MySQL.**

1. Tool jobs dispatched while `ContinueAgentRun` still held the run lock could not fan back in:
   they found the run locked and quietly did nothing, stalling it. On a `sync` queue connection
   that is a certainty rather than a race. Handoff is now deferred until after the lock is
   released.
2. A run resuming from `waiting_for_tool` tried to complete directly from that state, which the
   state machine rightly refuses. It now returns to `running` first — which is also what the UI
   should show for a whole turn that was otherwise mislabelled.
3. `ApprovalManager` dispatched `ResumeApprovedRun` with no actor, so a resumed call executed as
   nobody and re-authorization was meaningless. The resumed call acts for the **run's** actor,
   never the approver's.
4. A denied call wrote no tool result, leaving the model's request unanswered. Providers reject
   that, and the model never learned why it was refused. A refusal is a result.
5. `RecentMessagesProvider` excluded the current run's own messages and read only user and
   assistant roles — between them, the model could see neither its own tool request nor any
   result. It now replays both, and drops orphans in either direction when the recency window
   cuts a loop in half.
6. Argument modification lost its reason when it also triggered an approval, so a human approving
   a clamped refund would have discovered the clamp only by reading the diff.
7. **Found on MySQL, after the suite was green.** `pandora_approvals_remembered_idx` covered four
   `varchar(255)` columns — 4080 bytes in utf8mb4, against InnoDB's 3072-byte key limit — so the
   migration created the table, applied two indexes, and failed on the third. SQLite has no key
   limit *and* reports no column lengths, so neither the tests nor schema introspection could have
   caught it. The columns now carry explicit lengths, and `Database/PortabilityTest` reads the
   migration sources rather than the live schema so the rule holds on whichever engine runs. The
   guard was verified by reverting the fix and watching it fail with the exact byte count.

   This is precisely the risk recorded below as "the database matrix beyond SQLite" — logged as an
   argument rather than a run, and it turned out the argument was wrong.

**One design decision worth recording.** `PolicyDecision::allow()` deliberately does **not** waive
the approval a tool's risk level demands. A policy with nothing to say about a critical tool must
not thereby wave it through; lowering that floor takes `allowWithoutApproval()`, written out on
purpose.

**Not verified.** Two items, both breadth rather than behaviour, and neither is claimed:

- The database matrix beyond SQLite. Both new tables now create cleanly on **MySQL 8.4**, verified
  in the host application after defect 7 — but MariaDB and PostgreSQL remain CI-only, and the whole
  suite has not been run against any of the three.
- A human driving the new pages in a host application: granting a tool, watching a call pause,
  approving it and seeing the run resume. Every step has an automated equivalent that passes;
  none of them is a person using the product.

**Not in Phase 2, by design:** memory, automations, skills, MCP, delegation, workspaces, channels
beyond web, multi-provider routing, cost accounting. `DelegateToAgent` is Phase 6 and was
deliberately not added as a built-in tool here, however tempting the symmetry.

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

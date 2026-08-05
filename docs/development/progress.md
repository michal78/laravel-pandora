# Implementation Log

Reverse-chronological. Every entry records what was actually done and actually verified. Commands
claimed to pass were run; output is quoted where it matters.

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

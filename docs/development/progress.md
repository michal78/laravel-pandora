# Implementation Log

Reverse-chronological. Every entry records what was actually done and actually verified. Commands
claimed to pass were run; output is quoted where it matters.

---

## 2026-08-06 — Phase 5 opened: memory items, scoping and lexical retrieval

`docs/development/phase-5-acceptance.md` written first, as every phase before it. 28 criteria, none
ticked yet. The three properties it commits to: a memory is retrieved by the scope the runner is
*in* and never one anyone asked for; a default install works with no vector database; a path is
contained after it is resolved, not before.

One decision taken deliberately against the grain of the last phase. The vector store is optional at
runtime and **mandatory in CI**. Phase 4 produced seven defects, and not one was reachable by the
suite as configured; "optional, therefore untested" is that shape exactly, and Phase 5 adds an
optional dependency on purpose.

**Shipped:** `pandora_memory_items` and `pandora_embeddings`; `MemoryItem`, `Embedding`, and the
five enums (scope, type, status, sensitivity, source); `MemoryScopeSet`, which owns the entire
visibility constraint including the tenant predicate; `ScopeResolver`, the one place a scope may be
derived; `MemoryRetriever` and `Tokeniser`; a `memory` config block.

**Three defects found while building, all of which would have shipped.**

**1. A global memory written inside a tenant became permanently invisible.** `BelongsToTenant`
stamps `tenant_id` on `creating`, and `saving` runs *before* that. The guard was on `saving`, so it
inspected a row whose tenant had not been applied yet, passed it, and let the stamp land. The result
is the worst kind of row: present in the table, absent from every answer, and impossible to notice
from either end. Now checked on `creating` and `updating`, after the trait has had its say.

**2. The tokeniser violated its own `list<string>` contract.** Tokens are de-duplicated through
array keys, and PHP casts a numeric-looking key to `int` — so the token `42` came back as an integer
and broke every strict comparison downstream of it.

**3. PostgreSQL would have silently forgotten everything written with a capital letter.** Postgres's
`LIKE` is case-sensitive; SQLite's, MySQL's and MariaDB's are not. A bare `LIKE` therefore retrieves
correctly on three engines and returns nothing on the fourth, with no error and no empty-result
signal to distinguish it from a genuinely empty corpus. This was not reasoned about and left at
that — it was measured: with a bare `LIKE`, five tests in `Memory/LexicalRetrievalTest` pass on
SQLite and MySQL and **fail on PostgreSQL 17**. Now `lower(column) LIKE ?`, which every engine
supports. `lower()` is ASCII-only in a SQLite built without ICU, so non-ASCII case folding stays
engine-dependent; that is stated in the code and belongs in the memory guide, not papered over.

Worth noting which of the three the package suite caught on its own: the second, immediately. The
first needed a test written specifically to distrust hook ordering. The third needed a different
engine — the same lesson Phase 4 closed on, arriving in the first slice of Phase 5.

**Verified:** `vendor/bin/pest` → 978 passed (3,371 assertions) on SQLite. `tests/Memory` and
`tests/Database` green on MySQL 8.4 and PostgreSQL 17. `phpstan` clean at level 8, with no
`@phpstan-ignore` and no baseline entries added. `pint --test` clean.

**Not done yet:** slices 3 to 7 — context pipeline (redaction, attribute allowlisting, context files
from configured roots, summarisation), `EmbeddingProvider` / `VectorStore` and the pgvector adapter
plus its CI leg, curation, workspaces, and the UI.

---

## 2026-08-06 — Phase 4 closed by a human with a browser

The walkthrough ran. All twenty checks pass, and it found three defects the
package suite structurally could not.

**1. A fatal `TypeError` on the Automations page** of any host that calls
`Date::use(CarbonImmutable::class)` — which is a suggestion in Laravel's own default
`AppServiceProvider`, so not an exotic configuration. Phase 4 typed its dates as
`Illuminate\Support\Carbon`. That is what `Carbon::now()` returns, so everything Pandora constructed
itself satisfied the hint; it is not what a model date cast returns once a host opts in, and
`CarbonImmutable` does not extend it. Every date crossing a Pandora boundary is now `CarbonInterface`.

**2. A date reported as changed on every save.** The editor decided what had changed with `!==`,
which for two objects is identity comparison and therefore always true. Every save of the Schedule
tab wrote `run_at` into the audit log as changed — defeating the single question the per-tab diff
exists to answer.

**3. A replayed webhook left no evidence anywhere.** Reported from the walkthrough as "the second
curl is not shown in History". History showing nothing was correct — an occurrence is what the
automation *ran*, and a delivery refused before that never became one. But the replay was invisible
on the Deliveries tab too, because replay protection *is* a unique insert and the duplicate cannot
record itself; and it threw before the audit path every other rejection uses. The only rejection in
the system with nothing to show for it, and precisely how a sender with broken retry logic stays
invisible. Repeats are now counted on the delivery they duplicate and audited like everything else.

**What that says about the suite.** Three defects here, three from the database matrix, one racy
test — seven in one phase, and not one of them was reachable by running the tests as configured. The
common shape: the suite is good at what it was told to check and blind to configurations it was
never run under. A different engine, a different runner speed, a different date class, a real
browser. Worth carrying into Phase 5, which adds a vector store — optional, therefore untested by
default, therefore the same shape exactly.

**Also fixed along the way:** the Automations guide stated `pandora.automations.manage` was denied
by default and stopped there, so the first thing a reader meets after installing is a read-only page
with no indication of where the switch is. It now shows the gate definition.

**Phase 4 is complete.** 26/26 criteria on SQLite, MySQL 8.4, MariaDB 11 and PostgreSQL 17, plus the
host walkthrough. Deferred to Phase 8 rather than left implied: a live Reverb server, and an
automation left running long enough to exercise the misfire policy against a genuine outage.

```
vendor/bin/pest        -> Tests: 937 passed (3,205 assertions)
vendor/bin/phpstan     -> [OK] No errors  (level 8)
vendor/bin/pint --test -> passed
```

---

## 2026-08-06 — The database matrix was not testing databases

Asked whether the two outstanding items were worth clearing before Phase 5. Checking rather than
guessing turned up something worse than either of them.

**CI had been red since Phase 3.5 and nobody looked.** One test — `it rolls migrations back cleanly`
— failing on every job. I had reported "925 green" from local runs without checking that the push
went green. That is the process failure worth recording; the rest follows from it.

**The three "engine" jobs were running SQLite.** The workflow sets `DB_CONNECTION=mysql` and friends,
and `TestCase::defineEnvironment()` hardcoded `sqlite :memory:`, overriding it. Confirmed by running
locally with the matrix environment set and no MySQL reachable: six passed, having connected to
nothing. Three green jobs asserting nothing is worse than no jobs at all — it is why "database matrix
outstanding" sat in the roadmap since Phase 2 without urgency.

**Making it real cost more than expected**, and each step was its own small lesson:

- Honouring the env meant testbench re-migrating per test: free on `:memory:`, forty minutes on a
  server engine. The schema is now built once and truncated between tests. Truncation rather than a
  wrapping transaction, because Pandora catches unique violations as normal control flow and on
  PostgreSQL a failed statement poisons the surrounding transaction.
- `loadLaravelMigrations()` registers a rollback on application destruction — right for a throwaway
  database, fatal for a shared one. The symptom was a truncate failing on a table that had existed a
  moment earlier.
- `PortabilityTest` deliberately rolls every migration back, which on a shared schema deletes the
  database out from under every test that follows. The harness now verifies the schema exists rather
  than trusting a flag, and heals itself.
- `Schema::getTableListing()` lists every schema the credentials can see, which on a developer
  machine includes unrelated databases on the same server. Scoped to the connection's own database.

**Three real defects, all previously hidden by SQLite's tolerance:**

1. `WebhookReceiver` caught every `QueryException` and answered "already processed". On MySQL a
   deadlock or lock-wait timeout would therefore drop a delivery while telling the sender it landed —
   silent loss, surfacing months later as "some webhooks don't arrive". Detection is now narrow and
   shared with the automation claim through `DetectsUniqueViolations`.
2. Two hand-written ULIDs in fixtures were 27 and 28 characters. SQLite stores an over-long value
   into `char(26)`; MySQL in strict mode refuses the insert. Fixed, with a test so it cannot drift.
3. Two assertions compared JSON arrays with `toBe()`. MySQL's native JSON type normalises key order
   and SQLite keeps the text verbatim, so those tests were asserting the engine, not the behaviour.

**And the original red test.** `migrate:rollback --step=N` has meant "N batches" and "N migrations"
in different Laravel versions, and neither is the count of Pandora's own files once the host's share
the migrations table — which is exactly why it passed against the committed lock and failed on CI's
resolved dependencies. Rolling back by `--path` says what the test means, and it now asserts every
table is gone rather than sampling one.

**Verified.**

```
sqlite (local + CI, PHP 8.3 and 8.4)  -> 926 passed (3,175 assertions)
MySQL 8.4     (CI)                    -> 925 passed, 1 failed  [the rollback test, now fixed]
MariaDB 11    (CI)                    -> 925 passed, 1 failed  [same]
PostgreSQL 17 (CI)                    -> 925 passed, 1 failed  [same]
```

Every one of Phase 4's automation tests passes on all four engines. Two new portability rules are
recorded in `database-model.md`: JSON key order is not preserved, and an over-long value is an error
rather than a truncation.

**SQLite stays supported**, and the installation guide now says so with the caveat that matters:
Pandora's execution model is concurrent by design — row locks on runs, a unique-insert occurrence
claim, replay protection that depends on two processes racing one index — and SQLite serialises
writers, so those paths surface as `database is locked` under more than one worker. Development, CI
and single-worker deployments: yes. Production with concurrency: a server engine.

**Phase 4 host walkthrough** performed against `laravel-test` — see Q9 in `open-questions.md`. The
scheduler entry registering itself with no host Kernel edit, and next-run times rendering in each
automation's own timezone against a real clock, were both unobserved claims until now.

---

## 2026-08-06 — Phase 4: Automation 🔨 (26 of 26 criteria verified)

**What changed about the product.** Every phase before this one runs because a person pressed
something. This is the first that runs because a clock did, and that changes what "correct" means: a
chat page that renders twice is annoying, an automation that fires twice refunds the customer twice.

Two properties set the acceptance bar, and everything else followed from them.

**An occurrence fires exactly once.** The guard is an INSERT, not a check. Each occurrence's
idempotency key is derived deterministically from `(automation, occurrence timestamp)`, carries a
unique index with the automation, and the insert *is* the claim. Two schedulers noticing the 09:00
occurrence compute the same key, both try to insert, and the database picks the winner before either
has evaluated a condition or spent a token.

The version everybody writes first — `if ($automation->last_run_at < $due)` — is a check-then-act
race whose window is a database round trip, and it fails precisely under the load that made somebody
run two schedulers. The same guard covers a queue retry (the key travels on the job payload rather
than being recomputed) and a duplicated webhook delivery (the key is the signature).

**An automation can never widen what its agent may do.** The effective level is the lower of the
two, and it is computed on `AutonomyLevel::narrowerOf()` because four paths need it — the scheduler,
the event listener, the webhook and the manual run button — and the one that reimplemented it
wrongly would have been the interesting one. Without the clamp, the Automations page is a privilege
escalation surface: anyone who could schedule an `observe_only` agent could schedule it to act.

**The thing this phase quietly fixed.** ADR-0009's autonomy levels have been stored on every agent
since Phase 1 and enforced nowhere. `ToolGatekeeper` now carries an autonomy layer, and every run
records the level it ran at (null meaning "a human is watching", which is meaningful rather than
missing). It lives in the gatekeeper rather than in `ToolPolicy` deliberately: a policy is the layer
a host REPLACES, and a host binding its own must not silently lose the leash.

**Delivered.**

- 5 migrations: `automations`, `automation_runs`, `webhook_deliveries`, `observations`, plus
  `autonomy_level` and `automation_id` on `runs`
- `Automation`, `AutomationRun`, `WebhookDelivery`, `Observation` models; 5 enums
- `NextRun` (cron/interval/one-off in the automation's own timezone, DST-correct),
  `AutomationScheduler` (one tick, claims and advances), `AutomationDispatcher` (claim → agent →
  condition → concurrency → autonomy → run), `AutonomyBudget`, `ConditionRegistry`,
  `EventTriggerRegistry`, `ObservationManager`, `WebhookSignature`, `WebhookReceiver`
- `RunAutomation` job; `pandora:automation:tick` / `:list` / `:run`; the scheduler entry registered
  by the service provider so a host adds nothing to its own Kernel
- `Pandora::on(Event::class)->when()->map()->autonomy()->run('agent')`
- `AutomationsIndex`, `AutomationDetail` (5 tabs), the agent's Automations tab, sidebar entry
- `propose_follow_up` built-in tool and the promotion flow
- `docs/guides/automations.md`

**Decisions worth recording.**

- *The automation IS the webhook endpoint.* The Phase 0 sketch had a separate
  `pandora_webhook_endpoints` table. An endpoint that is not an automation points at nothing, and
  nobody needs two endpoints for one automation. `database-model.md` updated to say so.
- *Timestamp tolerance is not replay protection.* The window has to survive clock skew, and inside it
  the same request can be sent freely. Replay is refused by a unique `(automation, signature)`
  insert — the only defence that holds behind a load balancer where no process sees every delivery.
- *A refused occurrence is still a row.* "It never fired" and "it fired and declined" are different
  incidents, and a silence is indistinguishable from a scheduler that died last Tuesday.
- *Conditions are named in the row and defined in config.* Same rule as tools. A callable read out of
  a database row is remote code execution with extra steps, and an automations page is exactly the
  surface an attacker would want it on. An unregistered name refuses rather than guessing.
- *Listeners only for classes something names.* A wildcard listener on `*` would make Pandora a tax
  on every event the host dispatches, forever, including the ones it dispatches in a loop.
- *`skip` is the default for both misfire and concurrency.* Both alternatives fail quietly and
  cumulatively: 360 stale runs after a six-hour outage, or workers accumulating until the queue stops
  moving with no symptom beyond "everything got slow".
- *An agent proposes; a person decides.* `propose_follow_up` is `low` risk because it changes
  nothing, which is what lets an `observe_only` agent use it — watch, and tell me. Promotion produces
  a DISABLED one-off at `observe_only`, because approving an idea is not approving the schedule and
  is not approving the agent acting on it.
- *The editor refuses an uncomputable schedule.* Storing an unparseable cron expression produces a
  null `next_run_at` and an automation that simply never runs, with nothing in any log to notice.
- *The page names `schedule:run`.* By far the most common "automation problem" is that nobody added
  the cron line. It is invisible from inside the application, so the page and `pandora:status` both
  say so rather than leaving somebody to debug a correct cron expression.

**Verified.**

```
vendor/bin/pest        -> Tests: 925 passed (3,151 assertions)
vendor/bin/phpstan     -> [OK] No errors  (level 8, checkModelProperties on)
vendor/bin/pint --test -> passed
```

158 of those tests are the automation and automation-UI files. All 26 acceptance criteria in
`docs/development/phase-4-acceptance.md` are ticked against a named passing test.

**Outstanding.** The host walkthrough (Q9), open since Phase 1 and now with a new instance: every
assertion here is a Livewire or unit test, and nobody has yet watched a real cron fire a real
automation against a real deployment. The database matrix beyond SQLite remains open from Phase 2.

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

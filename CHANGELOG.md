# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- **Phase 0 — Discovery and architecture.** Product vision, feature-parity matrix (69 capabilities
  classified against OpenClaw and Hermes Agent), terminology, architecture overview with three
  evaluated approaches, security model with a 15-item threat model, execution model, provider model,
  database model, realtime model, 13 ADRs, phased roadmap, and the Phase 1 acceptance plan.
- Package skeleton: `composer.json`, module directory structure, CI workflows, tooling configuration.

- **Phase 1 — Kernel vertical slice.** A complete path from a chat message to a streamed, traced,
  cancellable, audited agent run:
  - Service provider with headless and control-center installation modes; `config/pandora.php`;
    `Pandora` facade; tenancy and actor abstractions with zero-config single-tenant defaults.
  - Nine migrations with ULID keys, nullable tenant scoping and cross-engine-portable schema.
  - `Agent` model, `AgentDefinition` classes with `AgentBlueprint`, registry with class↔database sync
    where class definitions win for the fields they set.
  - Durable run state machine (13 states), append-only run traces, dual cache+database run locking,
    budget enforcement, cooperative cancellation with child propagation.
  - `StartAgentRun` / `ContinueAgentRun` queued jobs; `RunFailer` so a poison job still reaches a
    correct terminal state.
  - Provider contracts and DTOs; `FakeProvider` for tests; `OpenAiCompatibleProvider` with SSE
    streaming, tool-call reassembly and full error classification.
  - Context pipeline with token budgeting and recorded omissions; three context providers.
  - Redacting, versioned Reverb broadcast events with delta coalescing; fail-closed channel
    authorization; correct polling fallback when Reverb is disabled.
  - Livewire control center: chat, dashboard, runs index, run trace — with a self-contained
    light/dark design system and no build step.
  - `pandora:install` (idempotent), `pandora:status`, `pandora:agent:list`, `pandora:agent:run`.
  - Append-only audit log with correlation IDs.

- **Visual identity.** The Pandora brand applied across the control center:
  - Brand assets shipped in `resources/dist` — full and compact lockups in light and dark, sidebar
    lockup, standalone and monochrome icons, raster app icons, favicons and the web manifest.
    Publishable with `--tag=pandora-assets`, and served from the package by a route when they are
    not published, so a fresh install is never a broken-looking one.
  - The brand kit's `design-tokens/pandora.css` is the source of truth for colour, radius and
    shadow; every `--pd-*` token in the control center derives from a `--pandora-*` token.
  - Reusable Blade components: `x-pandora::brand`, `icon`, `button`, `card`, `badge`, `status`,
    `empty-state`.
  - Theme and sidebar state resolve in `<head>` before the first paint, and light/dark artwork is
    switched by CSS, so neither the surfaces nor the logo flash the wrong variant.
  - Favicons and app icons in the layout; sidebar lockup when expanded, standalone icon when
    collapsed; a branded access-denied view (`pandora::errors.denied`) hosts may opt into.
  - WCAG AA contrast for text and controls in both themes, and full
    `prefers-reduced-motion: reduce` support.
  - `docs/visual-identity.md` documents how a host overrides the brand safely.

- **Phase 2 — Tools and approvals.** An agent can now touch the application, under five
  independent layers of authorization:
  - `Tool` base class with typed input, Laravel validation rules, declared risk level, versioning,
    aliases, groups and deprecation. The JSON schema shown to the model is **generated** from the
    same rules that validate what it sends, so the advertised interface and the enforced one cannot
    drift; a rule that cannot be expressed fails at registration rather than mid-conversation.
  - `ToolRegistry` (config or opt-in discovery — never the database, never a model), resolving by
    name, alias and `name@version`.
  - The five layers: registry → agent allowlist → tenant restriction → `ToolPolicy` →
    `Tool::authorize()`, the last checked against the **acting user**. Argument validation runs
    before the policy and again after any argument modification.
  - `ToolPolicy` with all five outcomes. Argument modification is applied, diffed, audited and shown
    on the approval card — never silent.
  - `pandora_tool_executions` and `pandora_approvals`; `ExecuteToolCall` and `ResumeApprovedRun`
    jobs; idempotency keys over canonicalised arguments; duplicate-call detection; fan-in so N
    parallel calls produce exactly one continuation.
  - Approvals with `once` / `run` / `remembered` scopes, expiry, comments, and transactional
    single consumption. A run waiting for a human holds no job.
  - `AskUser` and the `waiting_for_user` resume path via `Pandora::reply()`.
  - Eight built-in tools, each an allowlist over something the deployment configured. Registering
    installs them; each agent must still be granted each one.
  - Tools and Approvals pages, tool and approval cards in chat, argument diffs rendered openly in
    the run trace, `pandora:tool:list`.
  - `docs/guides/tools.md` and `docs/development/phase-2-acceptance.md`.

- **Phase 3 — Providers and routing.** A choice of minds, a bill, and a credential that is
  genuinely hard to leak:
  - `AnthropicProvider` and `GeminiProvider`, both against Laravel's HTTP client with no vendor SDK,
    joining `OpenAiCompatibleProvider`. Ollama and OpenRouter are proven through the latter with
    their own error bodies rather than assumed compatible.
  - **One shared contract suite** — `src/Testing/ProviderContractTests` — that every adapter must
    pass, run entirely against recorded fixtures. It ships in `src/` on purpose, so an extension
    package can implement `ProviderFixtures` and prove its own adapter with it.
  - Encrypted, versioned credentials resolved per-agent → per-tenant → deployment → configuration,
    with rotation that leaves the previous key valid for a grace window. A resolved credential
    cannot be serialised, and masks itself in every debugging and encoding path.
  - `pandora_models` catalog with capabilities, context limits and pricing. A price must state its
    source and date or it is refused; stale pricing is flagged rather than quietly trusted; an
    unpriced model records `null` cost, never zero. `pandora:model:sync`.
  - `DeterministicModelRouter` (ADR-0006) with tenant restrictions applied before routing, fallback
    chains, capability filtering, and every hop recorded on the run trace. Failover distinguishes
    outages from rate limits from context overflows from malformed requests, and an exhausted chain
    fails with the last provider's reason.
  - Provider health probes with hysteresis — a run of failures to degrade, one success to recover —
    consumed by the router and the Providers page.
  - `pandora_usage_records`, one row per model call, with cost in micro units stamped with the
    pricing source and date it used. Budgets at run, conversation, agent, tenant and deployment
    scope, enforced **before** the request rather than after the response.
  - Providers and Usage control-center pages, `pandora:provider:test`, and
    `docs/guides/providers.md`.
  - `pandora:flush` for clearing conversations, runs, traces and usage — keeping agents,
    credentials, the model catalog and settings, because losing those turns "clear the chats"
    into "set the whole thing up again". `--audit`, `--all` and `--tenant=` widen or narrow it.

- **Phase 3.5 — The Agents page.** The control center can now show you the thing the product is
  named for, and let you change it:
  - Agents index — source (class- or database-defined), model, autonomy level, status and run count,
    with search and source filters. A class definition deployed since your last visit appears
    without a manual sync.
  - Agent detail with six live tabs — Overview, Instructions, Models, Limits & Autonomy, Runs and
    Usage — plus seven tabs stubbed with the phase that fills them, so an operator who cannot find
    where tools are granted learns the page is coming rather than concluding it cannot be done.
  - Creating and editing database-defined agents, behind `pandora.agents.manage` (denied by default).
    New agents start disabled, at `observe_only`, with no tools.
  - **Class-defined agents are honest about what you cannot change here.** The fields a definition
    owns are shown as stated values naming their class, and a write to one is refused rather than
    accepted — an accepted write would look saved until the next deploy silently reverted it. The
    refusal rejects the whole save, never part of it.
  - `agent.created`, `agent.updated` (carrying the changed keys with before and after values) and
    `agent.deleted` audit actions.

- **Phase 4 — Automation.** Pandora can now act without a human in the moment, on a leash:
  - `Automation` entity with all six trigger types — one-off, cron, interval, event, webhook and
    heartbeat — each with its own timezone, condition, concurrency policy, misfire policy, retry
    policy, autonomy level and autonomy budget. Four migrations: `automations`, `automation_runs`,
    `webhook_deliveries`, `observations`.
  - **An occurrence fires exactly once.** Its idempotency key is derived deterministically from
    `(automation, occurrence)` and uniquely indexed, and the insert *is* the claim — so two
    schedulers, a queue retry and a duplicated delivery all converge on one run, decided by the
    database before anything expensive has happened.
  - One Laravel scheduler entry, registered by Pandora itself, drives everything. Occurrences are
    computed in each automation's own timezone, so a 9am schedule stays 9am across daylight saving
    rather than moving twice a year.
  - **ADR-0009's autonomy levels are now enforced, not merely stored.** `ToolGatekeeper` gained an
    autonomy layer, and every run records the level it ran at. `observe_only` and `suggest` deny a
    mutating tool call; `act_with_approval` pauses for a human on anything mutating whatever the
    policy waived. The layer lives in the gatekeeper rather than in `ToolPolicy` precisely because a
    policy is the layer a host replaces.
  - **An automation can never widen what its agent may do.** The effective level is the lower of the
    two, on every path — the scheduler, the event listener, the webhook and the manual run button.
  - Autonomy budgets in occurrences per rolling window. Exhausting one disables the automation and
    notifies an admin, because one that merely skipped would keep trying forever and nobody would
    learn it was broken.
  - `Pandora::on(SomeEvent::class)->when(...)->map(...)->run('agent')` for code-declared event
    bindings, alongside database automations bound to an event class. Listeners are attached only for
    classes something actually names — never a wildcard.
  - Signed, replay-protected webhooks, one endpoint per automation. HMAC-SHA256 over
    `"{timestamp}.{raw body}"` with constant-time comparison; replay refused by a unique
    `(automation, signature)` insert rather than by a timestamp window, which is not a replay defence.
    Secrets are stored encrypted, hidden from serialisation, and shown once.
  - Conditional polling with conditions named in the row and defined in `config/pandora.php`. A name
    the registry does not know refuses the occurrence rather than guessing — or executing.
  - The goal queue: `propose_follow_up` lets an agent propose work for itself and schedules nothing.
    Promotion is a human act behind `pandora.automations.manage` and produces a disabled one-off
    automation at `observe_only`.
  - Automations index and detail pages, the agent's Automations tab, a sidebar entry, and
    `pandora:automation:list` / `:run` / `:tick`.
  - Every occurrence is recorded, **including the ones that produced no run**, with a reason — "it
    never fired" and "it fired and declined" are different incidents.
  - `automation.created`, `.updated`, `.deleted`, `.enabled`, `.disabled`, `.fired`, `.refused`,
    `.budget_exhausted`, `webhook.rejected`, `observation.proposed`, `.promoted` and `.dismissed`
    audit actions.

### Fixed
- **A replayed webhook left no evidence anywhere.** Replay protection is a unique
  `(automation, signature)` insert, so the duplicate could not record itself as a row — making it the
  one rejection with nothing to show for it, and letting a sender with broken retry logic stay
  invisible. Repeats are now counted on the delivery they duplicate (`replay_count`,
  `last_replayed_at`) and audited like every other rejection. The Deliveries table shows the count,
  and the History tab of a webhook automation now says where refused deliveries actually live.
- **Pandora now works in an application that uses immutable dates.** A host with
  `Date::use(CarbonImmutable::class)` — a suggestion in Laravel's own default `AppServiceProvider` —
  got a fatal `TypeError` on the Automations page, because Phase 4 typed its date parameters and
  returns as `Illuminate\Support\Carbon`, which `CarbonImmutable` is not. Every date crossing a
  Pandora boundary is now typed `CarbonInterface`, which both satisfy.
- A date field was reported as changed on every save of an automation, because two `Carbon` objects
  were compared with `!==` — identity, not value. That put a spurious entry in the audit log each
  time somebody edited a schedule, defeating the one question the per-tab diff exists to answer.
- `AutomationRun::keyFor()` no longer rewrites its caller's argument to UTC as a side effect.
- **The CI database matrix was not testing databases.** All three "engine" jobs ran SQLite, because
  the package test case hardcoded the connection and overrode the `DB_CONNECTION` the workflow set.
  Three passing jobs that assert nothing are worse than no jobs at all. The suite now genuinely runs
  on MySQL 8.4, MariaDB 11 and PostgreSQL 17, and making it real found three defects:
  - An inbound webhook whose delivery insert hit any query error — a deadlock, a lock-wait timeout —
    was answered "already processed" and dropped. Only a genuine uniqueness clash means replay now.
  - Two test fixtures used 27- and 28-character ULIDs. SQLite stores an over-long value into
    `char(26)`; MySQL refuses the insert.
  - Two assertions compared JSON arrays by exact key order, which MySQL's native JSON type
    normalises and SQLite preserves — they were asserting the engine, not the behaviour.
- `migrate:rollback` in the portability test now works by path rather than by `--step`, whose meaning
  has changed between Laravel versions. This test had been failing in CI for two phases while passing
  locally against the committed lock file.
- `pandora:install` publishes the migrations an existing installation is MISSING, instead of
  skipping the step because some are already present. An upgrade that added a table previously left
  the host on the old schema, and the first symptom was a missing-table error in a page nobody
  associates with a package update. A migration the host has edited is still never overwritten
  without `--force`.
- The Providers page no longer answers 403 to an ordinary user. Which providers exist and whether
  they are answering is what somebody debugging a broken chat needs; credentials and prices still
  require `pandora.providers.manage`.
- The sidebar hides links the current user may not open. A control center whose own navigation is
  half forbidden teaches people to ignore authorization errors.
- `pandora.usage.view` is granted by default alongside `access` and `chat`. Knowing how many tokens
  were spent cannot cause harm; `pandora.costs.view` stays denied because knowing what they cost
  can.

### Security
- Tenant isolation, session isolation, broadcast authorization and secret redaction are enforced and
  covered by dedicated tests in `tests/Security/`.
- **A provider credential never leaves the adapter.** It is resolved inside the method that builds
  the HTTP request and dropped when that returns: never on a job payload, never in a run step, never
  in a broadcast, never in an audit entry, never in a log. `tests/Security/SecretLeakTest` drives a
  real run and then reads every durable artefact looking for the key.
- Terminal error messages are redacted where they are written rather than at each call site, because
  providers echo credentials back in their own error text.
- One tenant cannot resolve another tenant's credential, and a fallback chain cannot route out of a
  tenant's permitted model list.
- No test in the suite can reach a network: stray HTTP requests throw.
- Run steps and audit logs are immutable at the model layer, not by convention.
- An agent cannot do what the person it acts for could not: tool authorization is checked against
  the actor, and a system actor carrying no `Authorizable` is refused rather than waved through.
- Approval resolution is race-safe (threat T14), and an approved call is re-validated and
  re-authorized at execution time, not only when it was decided.
- Tool arguments and results are redacted in run steps, broadcasts, approval cards and the audit
  log. Only the copy that will be executed keeps its real values.

### Notes
- 728 tests / 2,509 assertions passing; PHPStan level 8 clean; Pint clean.
- Memory, automations, skills, MCP and messaging channels are not implemented —
  see `docs/roadmap.md`.
- Bedrock, Azure OpenAI, Mistral, Groq, xAI, Together and DeepSeek remain official extensions rather
  than core adapters.
- The license is provisional pending owner confirmation — see `LICENSE.md`.

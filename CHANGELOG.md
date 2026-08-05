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
- 711 tests / 2,418 assertions passing; PHPStan level 8 clean; Pint clean.
- Memory, automations, skills, MCP and messaging channels are not implemented —
  see `docs/roadmap.md`.
- Bedrock, Azure OpenAI, Mistral, Groq, xAI, Together and DeepSeek remain official extensions rather
  than core adapters.
- The license is provisional pending owner confirmation — see `LICENSE.md`.

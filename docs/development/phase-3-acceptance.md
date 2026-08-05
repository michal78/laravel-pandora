# Phase 3 — Acceptance Test Plan

> **Status: in progress.** Nothing below is ticked on the strength of code existing; each criterion
> is ticked only when the named automated test asserts it and that test passes.

Phase 2 gave an agent hands. Phase 3 gives it a choice of minds, and a bill. Two properties dominate
the acceptance bar:

1. **No test may touch a paid API.** Every adapter is proven against recorded fixtures through one
   shared contract suite. An adapter that needs a live key to be trusted is an adapter nobody can
   contribute to.
2. **No secret may leave the adapter.** Credentials are resolved at HTTP-call time and must not
   appear in a log, a run step, a broadcast, an exception message, a job payload, a queue serialisation
   or an API response. This is asserted, not assumed.

## Scope

Anthropic adapter · Gemini adapter · OpenRouter and Ollama through the OpenAI-compatible adapter ·
the shared provider contract test suite · `Model` catalog with capabilities and pricing ·
`DeterministicModelRouter` with fallback chains · provider health probes · usage normalisation ·
cost estimation with pricing source and date · budgets across all scopes · credential encryption,
per-tenant and per-agent resolution, rotation · Providers and Usage UI pages · `pandora:provider:test`.

## Design decisions taken for this phase

| Decision | Choice | Rationale |
|---|---|---|
| Adapter transport | Laravel's HTTP client, no vendor SDK, for every core adapter | A `suggest`ed SDK cannot be relied on; an SDK type that leaks turns a vendor's minor release into our breaking change. |
| Contract suite shape | A callable that registers Pest tests, driven by a per-adapter `ProviderFixtures` describing the vendor's wire format | One suite, N adapters. A new adapter is done when the shared suite passes. |
| Credential storage | Encrypted with the application key, versioned rows, previous version valid for a grace window | Rotation without a deploy, and without a window where every worker fails at once. |
| Credential in memory | Resolved inside the adapter at call time and never stored on a DTO, job or step | The only way to guarantee it cannot leak is for it never to be somewhere it could. |
| Cost | Estimated from the catalog, stamped with pricing source and date, `null` when unpriced | A silently stale cost is worse than an absent one. |
| Router | Deterministic, five-level precedence, every hop a run step (ADR-0006) | Routing you can read and predict. |
| Tenant model restrictions | Applied to the candidate set *before* routing | A fallback chain must not be able to escape them. |
| Budget enforcement | Checked before the request, not after the response | A budget that stops you after you have spent the money is an accounting record, not a budget. |

## Criteria

| # | Criterion | Verified by |
|---|---|---|
| 1 | ⬜ Every adapter passes the identical shared contract suite | `Providers/Contract/*ContractTest` |
| 2 | ⬜ A basic completion round-trips: request shape out, `ChatResponse` in | contract suite |
| 3 | ⬜ Streaming deltas arrive in order and the assembled response equals the non-streamed one | contract suite |
| 4 | ⬜ A tool-call round trip — request, tool result replayed, final answer — works on every adapter | contract suite |
| 5 | ⬜ Multiple tool calls in one turn are assembled with their ids intact | contract suite |
| 6 | ⬜ Usage is normalised to `UsageData` from each vendor's field names, cached tokens included | contract suite |
| 7 | ⬜ Each error status maps to its documented Pandora exception with the documented `retryable` / failover classification | contract suite |
| 8 | ⬜ A context-window error is classified as `ContextOverflow`, not a generic rejection | contract suite |
| 9 | ⬜ A malformed or truncated response body fails as a provider error rather than a PHP error | contract suite |
| 10 | ⬜ Cancellation mid-stream stops consumption and does not fail the run | contract suite |
| 11 | ⬜ The Anthropic adapter places the system prompt at the top level and returns content blocks correctly | `Providers/Contract/AnthropicContractTest` |
| 12 | ⬜ The Gemini adapter maps `functionDeclarations` and `usageMetadata` correctly | `Providers/Contract/GeminiContractTest` |
| 13 | ⬜ **No test in the suite performs a real network request** — the HTTP fake is asserted to have intercepted everything | `Providers/NoLiveCallsTest` |
| 14 | ⬜ The model catalog seeds from config, records pricing with a source and a date, and flags stale pricing | `Providers/ModelCatalogTest` |
| 15 | ⬜ A model whose provider does not support a required capability is never selected | `Providers/ModelRouterTest` |
| 16 | ⬜ Routing precedence is explicit call → run → conversation → agent → config default | `Providers/ModelRouterTest` |
| 17 | ⬜ **A tenant's model restrictions are applied before routing, and a fallback chain cannot escape them** | `Security/ModelRestrictionTest` |
| 18 | ⬜ Failover on outage (`ProviderUnavailable`) moves to the next model in the chain and completes the run | `Feature/ProviderFailoverTest` |
| 19 | ⬜ Failover on rate limit happens only after the documented retry attempts | `Feature/ProviderFailoverTest` |
| 20 | ⬜ Failover on context overflow selects a larger-context model, and fails clearly when none exists | `Feature/ProviderFailoverTest` |
| 21 | ⬜ A non-retryable rejection does **not** fail over — it fails the run | `Feature/ProviderFailoverTest` |
| 22 | ⬜ Every routing hop is recorded as a run step and rendered in the trace | `Providers/ModelRouterTest`, `UI/RunTraceTest` |
| 23 | ⬜ Exhausting the fallback chain fails the run with the last provider's reason, not a generic error | `Feature/ProviderFailoverTest` |
| 24 | ⬜ A degraded provider is skipped by the router and recovers on a successful probe | `Providers/ProviderHealthTest` |
| 25 | ⬜ A health probe failure never fails a run | `Providers/ProviderHealthTest` |
| 26 | ⬜ One usage record is written per model call, carrying provider, model, tokens and duration | `Feature/UsageRecordingTest` |
| 27 | ⬜ Cost is estimated from the catalog and stamped with pricing source and date; an unpriced model records `null` cost rather than zero | `Feature/UsageRecordingTest` |
| 28 | ⬜ Budgets are enforced at run, agent, conversation, tenant and global scope | `Feature/BudgetEnforcementTest` |
| 29 | ⬜ **A budget breach stops execution before the provider call is made** | `Feature/BudgetEnforcementTest` |
| 30 | ⬜ A budget breach terminates the run as `timed_out` with a specific reason and an audit entry | `Feature/BudgetEnforcementTest` |
| 31 | ⬜ Credential resolution order is per-agent → per-tenant → deployment → configuration, first match wins | `Providers/CredentialResolutionTest` |
| 32 | ⬜ Credentials are encrypted at rest and unreadable in the raw database row | `Providers/CredentialResolutionTest` |
| 33 | ⬜ Rotation keeps the previous version valid for its grace window and invalid after it | `Providers/CredentialRotationTest` |
| 34 | ⬜ **A tenant cannot resolve another tenant's credential** | `Security/CredentialIsolationTest` |
| 35 | ⬜ **A secret never appears in a log, run step, broadcast, exception message, audit entry or serialised job payload** | `Security/SecretLeakTest` |
| 36 | ⬜ The Providers page shows configuration and health but never a credential value, and is denied without `pandora.providers.manage` | `UI/ProvidersPageTest` |
| 37 | ⬜ The Usage page is gated by `pandora.usage.view`, and costs additionally by `pandora.costs.view` | `UI/UsagePageTest` |
| 38 | ⬜ `pandora:provider:test` reports a real round trip, and reports failure clearly without printing the key | `Feature/ProviderTestCommandTest` |
| 39 | ⬜ Architecture rules hold: no vendor symbol outside its own adapter directory, no adapter depends on the router | `Architecture/ModuleBoundaryTest` |
| 40 | ⬜ The full suite passes, PHPStan level 8 is clean, Pint reports no diff | run locally, output quoted in `progress.md` |

## Audit actions this phase must produce

`provider.failover` · `provider.degraded` · `provider.recovered` · `budget.exceeded` ·
`credential.created` · `credential.rotated` · `credential.revoked` · `model.synced`

Each carries the provider key and — where one exists — the run id. **None carries a credential
value, or any prefix or suffix of one.**

## Explicitly out of scope

Memory, automations, skills, MCP, delegation, workspaces, channels beyond web. Bedrock, Azure,
Mistral, Groq, xAI, Together and DeepSeek are official *extensions*, not core, and are not built
here. Cost-, capability- or latency-optimising routing remains out of v1 (ADR-0006).

## Definition of done

- [ ] All 40 criteria have tests, and they pass
- [ ] `vendor/bin/pest` green
- [ ] `vendor/bin/phpstan analyse` clean at level 8
- [ ] `vendor/bin/pint --test` clean
- [ ] `docs/guides/providers.md` written
- [ ] `docs/development/progress.md`, `docs/roadmap.md` and `CHANGELOG.md` updated
- [ ] Committed to `master` as focused milestone commits

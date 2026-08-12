# Phase 9 — Acceptance Test Plan

> **Status: 0 of 34 criteria accepted.** Nothing here is ticked by inheritance.
>
> Every previous phase wrote tests and then claimed the criteria those tests were written for. Phase 9
> is the phase that claims T1–T15, and it is the first one where the claim is about the *suite* rather
> than about the code. That changes what a tick may mean here: **an existing green test is evidence to
> be audited, not a criterion already met.** Where a threat already names a test in
> `docs/architecture/security-model.md`, that test is recorded below in the *Claimed by* column and the
> box stays empty until somebody has read it and confirmed it asserts the threat rather than a
> neighbour of it.
>
> Phase 6 is why. It closed with 30 verified criteria over an MCP client that had never once worked
> outside the suite — a fake provider that accepted any function name, and a fake server reached
> through a hand-built `ToolInput` that skipped the only step which could lose an argument. Both
> defects sat exactly where a fake stood in for a boundary. A phase whose acceptance bar is "every
> threat has a passing test" is worthless if a passing test can mean that.

Phase 9 has three jobs and they are not equally hard.

**Prove the threat model.** Fifteen threats, each with a test that fails when the mitigation is
removed. This is the phase's reason to exist and most of its risk, because roughly two thirds of the
work is auditing tests that already pass.

**Prove the package survives contact.** The matrix, upgrades, load, and an example application that a
stranger can run from the README without us in the room.

**Make it releasable.** Documentation, CHANGELOG, versioning, and a v1.0 checklist that says what is
supported and what is explicitly not.

## What already exists, and what that is worth

The CI matrix is **already built** and is not a Phase 9 deliverable: `tests.yml` runs SQLite on PHP
8.3/8.4, then MySQL 8.4, MariaDB 11, PostgreSQL 17, pgvector, and a MinIO leg. Two of those legs exist
because a previous phase learned that "optional at runtime, therefore untested" is how half a feature
ships unexercised, and the tests they cover **skip** rather than pass without them — so deleting a leg
makes the gap visible instead of silent. That is the right pattern and Phase 9 extends it rather than
rebuilds it.

What it does not yet do is fail on the one thing Phase 6 proved was possible: a suite that is green
against a configuration nobody ships.

## Four findings the audit has already produced

Recorded before the phase starts, because each changes what a criterion should say.

**T6 has no implementation, because it has no attack surface — yet.** The threat model describes an
SSRF mitigation for "the HTTP tool", a host allowlist, DNS resolution before connection, private and
link-local range blocking, and redirect re-validation. **None of that exists in `src/`, and neither
does an HTTP tool.** Sixteen built-in tools ship and not one makes an outbound request. The threat is
mitigated by absence, which is a real mitigation and a fragile one: it survives exactly until somebody
adds a fetch tool. So T6 becomes two criteria — an architectural test that fails the day a core tool
gains outbound HTTP, and an honest amendment to the security model saying the allowlist is a
*specification for a tool that does not exist*, not a control that is running.

**The MCP HTTP transport is the outbound surface that does exist**, and it is a different shape:
its URL is operator-configured, never model-influenced. That distinction is the whole mitigation and
nothing currently asserts it.

**T2's tenant never came from a resolver.** Every tenancy test in the suite reached its tenant through
`inTenant()` → `TenantManager::with()`, the *override* path a queued job uses. A host binds
`pandora.tenancy.resolver`, and `TenantManager::current()` consults it only when nothing has
overridden — so `$this->resolver->current()` was a line the suite never reached, with
`NullTenantResolver` (which returns null unconditionally) standing at the boundary. Green against that
cannot distinguish *Pandora asked and got null* from *Pandora never asked*. Closed 2026-08-11 by
`Security/HostResolverTenancyTest`, nine tests, eight of which fail when the resolver binding is
removed — verified by removing it. **This is the phase's method working before the phase started**,
and it is the third place the Phase 6 lesson has turned up.

**T9's tests are somewhere else.** `Skill.php` exists; the only tests touching skills are
`Mcp/SkillDiscoveryTest` and `UI/AgentDetailTest`. There is no test for a malicious imported skill —
the threat that a skill body carries install instructions an agent then acts on. ADR-0008 says skills
are never executed, which is the strongest possible mitigation, and it is currently unasserted.

## Criteria

### The threat model — T1 to T15

The bar for every row: **a test that fails when the mitigation is removed.** A test that passes
whether or not the control is present proves the control is untested, not that it works.

| # | Criterion | Claimed by |
|---|---|---|
| 1 ⬜ | **T1** — injected instructions in a document, web page or tool result cannot reach a destructive tool call: authorization is against the actor, `high`/`critical` require approval, untrusted content is delimited and labelled, and the approval UI shows the real arguments | `Security/ToolAuthorizationTest` · `Delegation/UntrustedResultTest` · `Channels/UntrustedInboundTest` |
| 2 ⬜ | **T2** — no cross-tenant read or write through any model, direct-ID lookup, page, console command or API resource, **with the tenant arriving from a bound host resolver rather than an override** | `Security/HostResolverTenancyTest` *(new, 2026-08-11)* · `Security/TenantIsolationTest` · `Security/ToolTenantIsolationTest` · `Memory/TenancyTest` · `Automation/TenancyTest` · `Channels/TenancyTest` |
| 3 ⬜ | **T3** — no cross-session context leak, including two participants on one channel account | `Security/SessionIsolationTest` · `Channels/SessionIsolationTest` · `Channels/UnlinkedIdentityTest` · `Channels/LinkRevocationTest` |
| 4 ⬜ | **T4** — a provider credential is not in context, a step payload, a broadcast, an API resource or a log, and cannot be extracted by a prompt that asks for one | `Security/CredentialIsolationTest` · `Security/SecretLeakTest` · `Security/SecretRedactionTest` |
| 5 ⬜ | **T5** — workspace path traversal and symlink escape are refused at the canonicalisation layer *and* at the disk root, with the second layer proved by disabling the first | `Workspaces/ContainmentTest` · `Workspaces/RootsTest` |
| 6 ⬜ | **T6a** — **no core tool performs an outbound HTTP request**, asserted architecturally so the day one does is the day CI goes red | *new* — `Architecture/NoOutboundHttpFromToolsTest` |
| 7 ⬜ | **T6b** — the MCP HTTP transport's URL is operator-configured and cannot be selected, redirected or influenced by model output or tool arguments | *new* — `Mcp/TransportUrlOriginTest` |
| 8 ⬜ | **T7** — iteration, tool-call, token, monetary, wall-clock, duplicate-call, delegation-depth and autonomy limits each independently halt a run, each proved by removing the other limits | `Feature/BudgetEnforcementTest` · `Feature/ToolLoopTest` · `Tools/DuplicateCallTest` · `Delegation/DepthTest` · `Automation/AutonomyTest` |
| 9 ⬜ | **T8** — a child run's abilities are the intersection; delegation never widens authority, including through a cycle or a re-delegation | `Delegation/IntersectionTest` · `Delegation/CycleTest` · `Delegation/AllowlistTest` |
| 10 ⬜ | **T9** — **an imported skill is never executed**: a skill body carrying install instructions, a shell command or a tool call produces instructions in context and no execution anywhere | *new* — `Skills/UntrustedSkillTest` |
| 11 ⬜ | **T10** — a hostile MCP server cannot reach a model with an unapproved tool, an unapproved description, or a name that resolves where a core tool is expected | `Mcp/UntrustedDescriptionTest` · `Mcp/SchemaHashTest` · `Mcp/NamespaceTest` · `Mcp/ApprovalTest` |
| 12 ⬜ | **T11** — no broadcast carries a system prompt, a secret, sensitive tool arguments or an exception dump, and a private channel refuses an unauthorised subscriber | `Security/BroadcastAuthorizationTest` · `Security/SecretRedactionTest` |
| 13 ⬜ | **T12** — a forged, replayed, stale or wrong-secret webhook is refused; a valid one is processed exactly once | `Automation/WebhookTest` · `Automation/IdempotencyTest` |
| 14 ⬜ | **T13** — every control-center page and action is behind a gate; an authenticated non-admin reaches none of them, and prompts, tool I/O, costs and audit logs gate separately | `Security/ToolIoVisibilityTest` · `UI/*` |
| 15 ⬜ | **T14** — an approval is consumed exactly once under the run lock, and the tool call is re-validated at execution against the arguments approved | `Security/ApprovalRaceTest` · `Security/ApprovalAuthorizationTest` |
| 16 ⬜ | **T15** — no model uses `$guarded = []`; every one declares `$fillable`, asserted by reflection over `src/` so a new model cannot omit it | `Architecture/ModuleBoundaryTest` *(extend)* |
| 17 ⬜ | **Every T1–T15 test fails when its mitigation is removed** — verified by removing it, one threat at a time, and recording the failure | *the audit itself* |

### The suite tells the truth about what it tested

This section exists because Phase 6 proved the suite could be green and wrong at the same time, in two
independent ways, in one session.

| # | Criterion | Verified by |
|---|---|---|
| 18 ⬜ | **A published `config/pandora.php` in the Testbench skeleton fails the run** rather than shadowing the package config. `mergeConfigFrom()` merges one level deep, so a shadow silently deletes newly added keys — it has appeared four times and been diagnosed once | *new* — `Feature/NoShadowConfigTest` |
| 19 ⬜ | **Every fake that stands in for a boundary is inventoried**, and for each, the assertion that the real boundary would have caught what the fake cannot is named — or the gap is recorded as accepted | *new* — `docs/development/fake-boundaries.md` |
| 20 ⬜ | **A tool's advertised function name is legal in every shipped provider's grammar**, asserted against the real grammar rather than a fake that accepts anything — the exact Phase 6 defect | *new* — `Providers/FunctionNameGrammarTest` |
| 21 ⬜ | **Every tool's arguments survive validation to `handle()`**, asserted for every registered tool including `RemoteTool`, whose arguments were silently stripped for the whole of Phase 6 | *new* — `Tools/ArgumentSurvivalTest` |
| 22 ⬜ | A skipped test is reported as skipped in CI output, and the count is asserted — a leg that stops running must not read as a leg that passed | *new* — CI step |

### The matrix, and surviving contact

| # | Criterion | Verified by |
|---|---|---|
| 23 ⬜ | The full matrix is green: SQLite (8.3, 8.4), MySQL 8.4, MariaDB 11, PostgreSQL 17, pgvector, MinIO | `.github/workflows/tests.yml` *(exists)* |
| 24 ⬜ | **Migrations run forward from a v0 install to head on every engine in the matrix**, not only from empty | *new* — `Database/UpgradeTest` + CI leg |
| 25 ⬜ | **A published config from an earlier version still boots** — a host that published `config/pandora.php` before a key existed gets the package default, not a missing key | *new* — `Feature/ConfigUpgradeTest` |
| 26 ⬜ | A conversation of 10,000 messages builds context within budget and the page renders without loading all of them | *new* — `Performance/LargeConversationTest` |
| 27 ⬜ | 50 concurrent runs against one agent complete without lock starvation, duplicated tool execution or lost steps | *new* — `Performance/ConcurrentRunsTest` |
| 28 ⬜ | A 500-step trace renders and paginates; the run detail page issues a bounded number of queries regardless of trace length | *new* — `Performance/LongTraceTest` |

### Release

| # | Criterion | Verified by |
|---|---|---|
| 29 ⬜ | **`tests/Fixtures/ExampleApp` runs the documented quick start end to end** — installed, migrated, one agent, one conversation, one tool call, from the README's own commands with nothing added | *new* — `Feature/QuickStartTest` |
| 30 ⬜ | The documentation set is complete: every shipped feature has a guide, every guide's commands are run and their output quoted, and no guide documents behaviour that changed after it was written | *the audit* |
| 31 ⬜ | Every ADR either describes what shipped or records what replaced it | *the audit* |
| 32 ⬜ | The CHANGELOG covers every phase, and every breaking change carries an upgrade instruction — the namespace-separator change from Phase 6 is the test case, since it revokes every MCP approval | `CHANGELOG.md` |
| 33 ⬜ | **A v1.0 support statement exists** naming what is supported, what is explicitly excluded (marketplace installs, remote extension updates), what is single-operator only, and **what is shipped untested** — including Phase 8 §5, two identities interleaving on one channel account in real time | *new* — `docs/product/support-statement.md` |
| 34 ⬜ | A human drives `phase-9-walkthrough.md` | a person |

## Design decisions taken for this phase

| Decision | Choice | Rationale |
|---|---|---|
| What a green test proves here | Nothing, until it has been read against the threat it claims | Phase 6 shipped 30 green criteria over a feature that had never run. The suite is the artefact under test in this phase. |
| Proving a mitigation | Remove it and watch the test fail | A test that passes with the control removed was never testing the control. This is the only criterion in the phase that cannot be automated, and it is the one that matters most. |
| T6 | Two criteria — an architectural test, and an amendment to the security model | The mitigation described does not exist because the surface does not exist. Writing an allowlist for a tool nobody ships is unverifiable code guarding nothing; a test that goes red when the surface appears is the honest control. |
| Fakes | Inventoried and each one's blind spot named in writing | The blind spot of a fake is invisible by construction — that is what makes it a fake. Writing it down is the only thing that makes it reviewable. |
| Performance tests | In the suite, thresholds generous, asserting shape not speed | A wall-clock threshold on shared CI is a flaky test that gets deleted. "Bounded query count" survives a slow runner; "under 200ms" does not. |
| Upgrade tests | From a real earlier migration state, on every engine | The published-config shadow was an upgrade bug that a fresh-install suite could never see. |
| Example app | Under `tests/Fixtures/`, exercised by the suite | An example that is not run is documentation that rots. |
| Marketplace / remote install | Stays excluded in the support statement | Unchanged from Phase 8, and a v1.0 statement is where an exclusion becomes a promise. |

## Carried in from earlier phases

| From | Item |
|---|---|
| Phase 6 | No MCP audit page — the audit trail exists, the surface to read it does not. Open by decision; T13 gates what does not exist yet |
| Phase 6 | A dead-end tool result becomes a retry storm; belongs with T7's limits |
| Phase 6 | The fake-at-a-boundary lesson, now criteria 19–21 |
| Phase 7 | Tenancy walkthrough section ✅ **closed 2026-08-11** by `Security/HostResolverTenancyTest` — a test could supply the two-tenant host, and writing it found the resolver seam below |
| Phase 7 | "What an unattended run may do" and `observe_only` do not share a vocabulary (`phase-7-walkthrough.md:269`) |
| Phase 8 | Section 5, ⚠ **known untested** — two people on one channel account concurrently. Not a task; a disclosure. Criterion 33 below must name it |
| Phase 8 | `redactText()` over string values inside `redact()`, deferred (`phase-8-walkthrough.md:584`) |
| Phase 8 | Rate limiter placement across the channel boundary (`phase-8-walkthrough.md:240`) |

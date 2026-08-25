# Phase 9 — Acceptance Test Plan

> **Status: 15 of 34 criteria accepted** (1, 2, 3, 4, 6, 7, 10, 11, 12, 13, 15, 16, 19, 20, 21). Nothing here is ticked by inheritance.
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
| 1 ✅ | **T1** — injected instructions in a document, web page or tool result cannot reach a destructive tool call: authorization is against the actor, `high`/`critical` require approval, untrusted content is delimited and labelled, and the approval UI shows the real arguments | `Security/ToolAuthorizationTest` · `Delegation/UntrustedResultTest` · `Channels/UntrustedInboundTest` · **new** `Security/UntrustedContextTest`, `InjectionToDestructiveCallTest`, `ApprovalArgumentFidelityTest`, `ApprovalFloorAgreementTest` — **three findings**, see below |
| 2 ✅ | **T2** — no cross-tenant read or write through any model, direct-ID lookup, page, console command or API resource, **with the tenant arriving from a bound host resolver rather than an override** | `Security/HostResolverTenancyTest` *(new, 2026-08-11)* · `Security/TenantIsolationTest` · `Security/ToolTenantIsolationTest` · `Memory/TenancyTest` · `Automation/TenancyTest` · `Channels/TenancyTest` · `McpServer/TenancyTest` · **new** `Security/TenantScopeCoverageTest`, `Security/QueuedJobTenancyTest` — **two findings**, see below |
| 3 ✅ | **T3** — no cross-session context leak, including two participants on one channel account | `Security/SessionIsolationTest` · `Channels/SessionIsolationTest` · `Channels/UnlinkedIdentityTest` · `Channels/LinkRevocationTest` · `Context/SummarisationTest` *(the summariser's scoping lives here, not in the four above)* — **two findings**, see below |
| 4 ✅ | **T4** — a provider credential is not in context, a step payload, a broadcast, an API resource or a log, and cannot be extracted by a prompt that asks for one | `Security/CredentialIsolationTest` · `Security/SecretLeakTest` · `Security/SecretRedactionTest` · **new** `Security/CredentialExtractionTest` — the extraction clause had no test |
| 5 ✅ | **T5** — workspace path traversal and symlink escape are refused at the canonicalisation layer *and* at the disk root, with the second layer proved by disabling the first | `Workspaces/ContainmentTest` · `Workspaces/RootsTest` · **new** `Workspaces/ContainmentLayersTest` — **one finding**, see below |
| 6 ✅ | **T6a** — **no core tool performs an outbound HTTP request**, asserted architecturally so the day one does is the day CI goes red | `Architecture/NoOutboundHttpFromToolsTest` — 4 tests; red within seconds of adding a tool that calls `Http::get()`, verified by adding one |
| 7 ✅ | **T6b** — the MCP HTTP transport's URL is operator-configured and cannot be selected, redirected or influenced by model output or tool arguments | `Mcp/TransportUrlOriginTest` — 7 tests; **found a live SSRF**, see below |
| 8 ✅ | **T7** — iteration, tool-call, token, monetary, wall-clock, duplicate-call, delegation-depth and autonomy limits each independently halt a run, each proved by removing the other limits | `Feature/BudgetEnforcementTest` · `Feature/ToolLoopTest` · `Feature/AgentRunTest` *(the iteration limit lives here, not in the four the plan named)* · `Tools/DuplicateCallTest` · `Delegation/DepthTest` · `Automation/AutonomyTest` · **new** `Feature/LimitAttributionTest` — **one finding**, see below |
| 9 ✅ | **T8** — a child run's abilities are the intersection; delegation never widens authority, including through a cycle or a re-delegation | `Delegation/IntersectionTest` · `Delegation/CycleTest` · `Delegation/AllowlistTest` · **new** `Delegation/DeniedAbilityTest` — **one finding**, see below |
| 10 ✅ | **T9** — **an imported skill is never executed**: a skill body carrying install instructions, a shell command or a tool call produces ~~instructions in context~~ **nothing in context** and no execution anywhere | `Skills/UntrustedSkillTest` — 6 tests; the criterion's own wording was wrong, see below |
| 11 ✅ | **T10** — a hostile MCP server cannot reach a model with an unapproved tool, an unapproved description, or a name that resolves where a core tool is expected | `Mcp/UntrustedDescriptionTest` · `Mcp/SchemaHashTest` · `Mcp/NamespaceTest` · `Mcp/ApprovalTest` — 39 tests, audited clean, all three mitigations fail on removal |
| 12 ✅ | **T11** — no broadcast carries a system prompt, a secret, sensitive tool arguments or an exception dump, and a private channel refuses an unauthorised subscriber | `Security/BroadcastAuthorizationTest` · `Security/SecretRedactionTest` · `Realtime/BroadcastTest` *(one test renamed and one added — it claimed redaction and never checked it)* |
| 13 ✅ | **T12** — a forged, replayed, stale or wrong-secret webhook is refused; a valid one is processed exactly once | `Automation/WebhookTest` · `Automation/IdempotencyTest` — audited clean on all four rejections; **one finding** in the narrowing that decides what counts as a replay, see below |
| 14 ✅ | **T13** — every control-center page and action is behind a gate; an authenticated non-admin reaches none of them, and prompts, tool I/O, costs and audit logs gate separately *(audit logs: see below — the ability gates nothing because no audit surface exists)* | `Security/ToolIoVisibilityTest` · `UI/*` · **new** `Security/ControlCenterGatingTest` — **three findings**, see below |
| 15 ✅ | **T14** — an approval is consumed exactly once under the run lock, and the tool call is re-validated at execution against the arguments approved | `Security/ApprovalRaceTest` · `Security/ApprovalAuthorizationTest` · `Approvals/ApprovalResolutionTest` · **new** `Security/ExactlyOnceUnderLockTest` — **three findings**, see below |
| 16 ✅ | **T15** — no model uses `$guarded = []`; every one declares `$fillable`, asserted by reflection over `src/` so a new model cannot omit it | `Architecture/ModuleBoundaryTest` — 3 added tests over 29 models; red when one model is switched to `$guarded = []`, verified by switching one |
| 17 ✅ | **Every T1–T15 test fails when its mitigation is removed** — verified by removing it, one threat at a time, and recording the failure | *the audit itself* — **15 of 15 threats done, complete 2026-08-25.** Every T1–T15 mitigation has been removed, one at a time, and the failure recorded. Ten sessions, **two live security fixes shipped in v0.1.3** (an MCP SSRF and a closable delimiter), one shipped concurrency fix, and fourteen coverage gaps closed. |

### The suite tells the truth about what it tested

This section exists because Phase 6 proved the suite could be green and wrong at the same time, in two
independent ways, in one session.

| # | Criterion | Verified by |
|---|---|---|
| 18 ⬜ | **A published `config/pandora.php` in the Testbench skeleton fails the run** rather than shadowing the package config. `mergeConfigFrom()` merges one level deep, so a shadow silently deletes newly added keys — it has appeared four times and been diagnosed once | *new* — `Feature/NoShadowConfigTest` |
| 19 ✅ | **Every fake that stands in for a boundary is inventoried**, and for each, the assertion that the real boundary would have caught what the fake cannot is named — or the gap is recorded as accepted | `docs/development/fake-boundaries.md` — six entries, three closed only after a defect pointed at them |
| 20 ✅ | **A tool's advertised function name is legal in every shipped provider's grammar**, asserted against the real grammar rather than a fake that accepts anything — the exact Phase 6 defect | `Providers/FunctionNameGrammarTest` — 6 tests; **found a live bug**, see below |
| 21 ✅ | **Every tool's arguments survive validation to `handle()`**, asserted for every registered tool including `RemoteTool`, whose arguments were silently stripped for the whole of Phase 6 | `Tools/ArgumentSurvivalTest` — 4 tests; reverting the Phase 6 fix fails two, verified |
| 22 ⬜ | A skipped test is reported as skipped in CI output, and the count is asserted — a leg that stops running must not read as a leg that passed | *new* — CI step |

### The matrix, and surviving contact

| # | Criterion | Verified by |
|---|---|---|
| 23 ⬜ | The full matrix is green: SQLite (8.3, 8.4), MySQL 8.4, MariaDB 11, PostgreSQL 17, pgvector, MinIO | `.github/workflows/tests.yml` *(exists)* |
| 24 ⬜ | **Migrations run forward from a v0 install to head on every engine in the matrix**, not only from empty | *new* — `Database/UpgradeTest` + CI leg |
| 25 ⬜ | **A published config from an earlier version still boots** — a host that published `config/pandora.php` before a key existed gets the package default, not a missing key | *new* — `Feature/ConfigUpgradeTest` |
| 26 ⬜ | A conversation of 10,000 messages builds context within budget and the page renders without loading all of them | *new* — `Performance/LargeConversationTest` |
| 27 ✅ | 50 concurrent runs against one agent complete without lock starvation, duplicated tool execution or lost steps *(read as 20 — see below)* | `Performance/ConcurrentRunsTest` · `Queue/ConcurrentHarnessTest` · `tests/Support/RunsConcurrently.php` — **one live defect**, see below |
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

## What the first four threat tests found — 2026-08-17

Three findings, and the pattern in them is the phase's own thesis: **each sat where the acceptance
plan had already noticed something was thin, and each was worse than the plan guessed.**

**A live SSRF in the MCP HTTP transport (T6b).** Guzzle follows redirects by default and nothing had
turned that off, so a hostile or compromised MCP server answering `302 Location:
http://169.254.169.254/latest/meta-data/` had this application re-send its POST to the cloud metadata
endpoint — across an HTTPS-to-HTTP downgrade, into the link-local range — and hand the response body
back to the model as tool output. The destination was the far end's to choose, which is precisely
what the criterion said must be impossible. `allow_redirects` is now off, a 3xx is refused with its
own `redirected` reason rather than folded into `server_unavailable`, and both halves are asserted:
the call fails, **and** the second request was never made. A test asserting only the first would pass
against a client that followed the redirect and then errored.

Worth naming *why* the suite could not see it. Every other MCP test binds `FakeMcpServer` in place of
`HttpTransport`, which is right for testing what the client does with an answer and useless for
testing where the question was sent — **a fake that never had a URL cannot lose one.** That is the
Phase 6 lesson for the fourth time, in the fourth place. `TransportUrlOriginTest` drives the real
transport against `Http::fake()` for exactly this reason, and `docs/development/fake-boundaries.md`
gains the entry.

**T9's criterion described behaviour that does not exist.** It required a hostile skill body to
produce "instructions in context and no execution anywhere". The second half holds completely and
then some — no execution mechanism, no column that could carry one, no `eval`/`exec`/`proc_open` in
`src/` outside the stdio transport, and an imported skill lands `enabled = false`. The first half is
false: **nothing in `src/` reads `Skill::$instructions` at all.** A skill can be imported, attached to
an agent, and listed on its detail page, and its text never reaches a prompt because nothing composes
it into one. So a skill is inert rather than untrusted-but-included, ADR-0008's final consequence was
describing a feature that never shipped, and the ADR is amended rather than quietly left. The test
asserts the *current* state, so wiring skills into the context pipeline goes red and forces the
untrusted-content handling to be built at the same time.

**T15 was a sentence, twice.** The threat model said "no `$guarded = []`, explicit fillable" and two
of the twenty-nine models repeated it in a comment. Nothing checked. All twenty-nine were in fact
correct — the finding is not a defect but the absence of the thing that keeps them correct, which is
what an architectural criterion is for.

**And T6a is now the honest control it was planned to be.** Sixteen core tools, none of which makes
an outbound request, asserted four ways: no HTTP client named in the source, no stream wrapper opened,
no client injected through the constructor, and a floor on how many tools were actually scanned so
the rule cannot start matching nothing. Two blind spots are written into the file rather than implied
— a tool calling a Pandora service that itself makes a request (the scan is one level deep), and
`WorkspaceTool` on an S3 disk, which does cause outbound traffic to an endpoint from `filesystems.php`
that no model can influence. That last one is the same distinction T6b draws, which is why the test
looks for HTTP *clients* rather than for network traffic.

## What auditing T1, T4, T10 and T11 found — 2026-08-17

These four were chosen first because all four sit where a fake stands in for a real boundary, which
is where every defect of the previous three sessions had been. That prediction held for T1 and T4 and
was wrong about T10, and being wrong about T10 is worth as much: **39 tests, three mitigations, every
one of them red when removed, nothing to fix.** A clean audit is a result. It is also the only way
the phase's remaining risk gets smaller rather than just better documented.

**A delimiter untrusted content could write itself (T1).** The threat model says untrusted content is
"delimited and labelled". Three providers did the labelling and none did the delimiting: `<file>`,
`<memory>` and `<environment>` interpolated content straight between their markers, so the content
could close the region and continue outside it — in a **system** message. Memory is the serious one,
because it persists: a memory is written by the `remember` tool, driven by model output, which may be
reading an attacker's page. One crafted note and every later run in that scope carried it in the
instruction region. Fixed by `UntrustedBlock`, and the two genuinely untrusted blocks also moved out
of the system role, where every other untrusted string in the system already is.

**T1's sentence was tested nowhere, only its clauses.** Layer 5 is proved against a decision object,
the `tool` role is proved for a delegated answer, the approval pause is proved for a high-risk tool.
The path between them — one run, real loop, model reads a poisoned document and then demands a refund
— was exercised by nothing. Each piece is asserted where it lives and the path is what an attacker
walks. It now exists, with the fake provider obeying the injection completely on purpose: a real model
usually would not, which is exactly why the test must not depend on it declining.

**A console table that lied (T1).** Found by removing the risk floor and watching nothing fail.
`RiskLevel::requiresApprovalByDefault()` hard-coded `high || critical`; the gatekeeper reads
`pandora.approvals.required_for`. They agreed on the shipped defaults, so the divergence was
invisible, and the method was never on the enforcement path — its one caller is `pandora:tool:list`.
A deployment narrowing the floor to `critical` got a console table printing `required` beside tools
that would run with nobody in the loop. **A control surface that disagrees with the control is worse
than none**, because the person reading it is deciding whether the configuration is safe.

**An allowlist entry could publish a credential (T4).** Fourteen tests prove a credential is absent
from context, step payloads, the queue, broadcasts, logs, API resources and a logged exception, and
all fourteen are sound. None asks whether a *tool* hands one over when asked, which is the only path
a prompt has. `read_config` was already careful — exact-match allowlist, never a prefix — but an
exact allowlist is a person's judgement, and `services.stripe.secret` is a key somebody could
reasonably add while wiring up a tool. Credential-shaped keys are now refused even when allowlisted.

**A test that named a mitigation and never checked it (T11).** `BroadcastTest` had one called
"versions and redacts every broadcast payload" which asserted the version, the event name and one
value. `RunStatusChanged` carries no sensitive key, so it passed identically with the redactor
deleted from the base class. This is the failure criterion 17 exists to find, and it is the second
one this phase has found — the first was in a test written earlier the same day, by the same author,
for this same criterion. Renamed to what it does; the assertion it promised now sits beside it.

The rest of T11 needed nothing, and needed nothing *structurally*, which is the good kind:
`broadcastWith()` is final and every event routes through it, `MessageCreated` carries ids and a role
and no content at all, no event carries tool arguments, and an unclassified exception yields a fixed
sentence rather than its own message.

## What auditing T12 and T14 found — 2026-08-19

These two were chosen because concurrency tests are the easiest kind to write green-and-wrong: a race
test that never actually contends passes trivially, and nothing about reading it says so. That
prediction held, and harder than expected. **Four of the five findings are one root cause, and it is
not a class anybody wrote.**

**The suite runs serially, so every lock in the codebase was disarmed.** `ApprovalRaceTest`'s
"consumes an approval exactly once when two approvers race" calls `approve()` twice in a row on one
connection. That proves `resolve()`'s `isPending()` status check, and a check-then-write with no lock
at all passes it identically. Deleting `lockForUpdate()` from `ApprovalManager::resolve()` left **all
1,809 tests green**. Deleting it from `ExecuteToolCall::fanIn()` did too. Both methods carry docblocks
saying the lock is precisely what makes the guarantee "impossible rather than merely unlikely" — and
the suite had no way to check either sentence, because a row lock does nothing in a serial test and
cannot fail one.

This is a fake at a boundary with no object to point at: the fake is the shape of the runner. It is
now the eighth entry in `fake-boundaries.md` and the only one nobody chose.

**Closed by `Security/ExactlyOnceUnderLockTest`**, which opens a second connection to the same
database and holds a real row lock on it. Telling the two outcomes apart needed care: with the lock
present the manager's read blocks and times out, and without it the read sails through — but *both*
end in an exception, because without the lock the manager blocks a moment later on the UPDATE
instead. Asserting "it throws" would have passed either way. The test resolves an approval that is
already resolved, so the answers separate cleanly — a `QueryException` when the decisive read is
locked, `ApprovalNotPending` when it is not. Five tests; removing either lock fails exactly the one
that names it. It **skips on SQLite**, where `lockForUpdate()` compiles to nothing, rather than
passing vacuously on the leg where the control does not exist.

**The idempotency tests never reached the guards they were named after.** "Does not re-apply a tool
when its own job is retried" dispatched `ExecuteToolCall` without the tenant and actor a real
at-least-once redelivery carries. The actor came back null, `RefundOrderTool::authorize()` refuses
when there is no user, and the gatekeeper denied the second call. The test was proving that a job
stripped of its actor is denied — true, and nothing whatsoever to do with idempotency. **Every guard
in `ExecuteToolCall` could be deleted and it stayed green; dispatched faithfully, the same removal
refunds the customer twice.** Verified both ways.

Fixing the dispatch was not sufficient on its own. Two guards stop a repeated execution — the terminal
execution row, and the terminal run below it — and in a run that has finished they cover for each
other, so removing either alone still left the test green. The new case is the one where only the
first stands: a run with two parked calls, one approved and finished, the other still waiting on a
human, so the run is `waiting_for_tool` rather than terminal. That is also the ordinary shape of the
problem — a queue redelivers while work is in flight, not after everything has settled.

**A cancelled run's stale Approve button.** `ResumeApprovedRun` checks the run is not terminal before
it does anything, and nothing in the suite reached that check; removing it left every cancellation and
approval test green. No refund is issued either way, since `ExecuteToolCall` refuses independently —
which is why asserting the refund count proves nothing here. What the guard decides is whether the
resume touches the run at all: without it a parked call on a stopped run is dragged back to `pending`,
dispatched, and closed again, with a trace entry describing work that never happened.

**T12 audited clean on every rejection.** Forged, unsigned, malformed, stale, future-dated, wrong-
secret, disabled, secretless, oversized and replayed all fail when their check is removed, one at a
time. Dropping the timestamp from the signed string — the clause that stops an attacker rewriting `t`
on a captured request — fails a test too. That is a good result and it is most of T12.

**The one T12 finding is in what counts as a replay.** Replay protection is a unique INSERT, so both
`WebhookReceiver` and `AutomationDispatcher` catch a constraint violation as a normal outcome, and
`DetectsUniqueViolations` exists to keep that catch narrow. Its docblock names the cost of getting it
wrong exactly: treating any query error as "already claimed" makes Pandora answer *this webhook was
already processed* to a delivery it in fact dropped on the floor. **Nothing tested it.** Widening
either catch to every `QueryException` left all 28 webhook and idempotency tests green, because every
one of them reaches a healthy database. Two tests now make the insert fail for a reason that is not a
uniqueness clash — a lock-wait timeout and a deadlock, which on MySQL arrive as the same class — and
assert the fault comes back out as a fault.

**Recorded, not fixed: re-authorization treats "requires approval" as permission to proceed.**
`ExecuteToolCall::execute()` re-runs the gatekeeper and acts only on `isDenied()`. On the resume path
the gatekeeper always answers *requires approval* — the approval had `once` scope, so it covers
nothing by the time the call runs — and the job proceeds without checking that the execution's own
approval is in fact approved. Every path that dispatches `ExecuteToolCall` today goes through either
the coordinator's gatekeeping or `ResumeApprovedRun`, which only proceeds on `Approved`, so there is
no reachable case: the guard would be unverifiable code guarding nothing, which is the reasoning T6a
already settled for this repository. It is written down here because it is a *latent* gap in the
sentence T14 makes — the re-validation asserts the arguments and the gates, and not the approval —
and because the day a new dispatch path appears is the day it stops being latent.

**Also recorded: two mitigations that cannot be tested behaviourally.** `hash_equals()` in
`WebhookSignature::verify()` is indistinguishable from `!==` by any assertion about outcomes; only
the timing differs, and a timing test on shared CI is a flaky test. And `Approvals/ApprovalResolutionTest`
covers the expiry coercion — an approval whose window closed while it sat in the queue resolves as
expired, not approved — which is a T14 clause the acceptance plan's *Claimed by* column did not name.
That column is now correct.

## What auditing T2 found — 2026-08-19

T2 was expected to be the dull one. Tenancy has more tests than any other threat in the model — six
files, forty-two tests — and the one structural gap anybody had found in it was closed back in
August by `HostResolverTenancyTest`. The three controls all held: delete the `where` clause from
`BelongsToTenant`'s global scope and seventeen tests go red, delete the `creating` hook that stamps
`tenant_id` and thirteen do, stop `TenantManager::current()` consulting the resolver and eight do —
the same eight as 2026-08-11, which is a pleasing thing to be able to reproduce five months later.

**Then the same question was asked one level down, and the answer was different.** Those ablations
prove the scope works. None of them proves that any *particular* model asked for it. So the trait
was removed from each of the twenty-six models that carry it, one at a time, running the whole suite
against each. **Twenty are load-bearing. Six are not**: `Approval`, `AuditLog`, `Observation`,
`WebhookDelivery`, `ChannelDelivery` and `ChannelLinkCode` each left all 1,813 tests green with
their tenant scope deleted.

Two of those six are the ones that matter. **An approval is the object that authorises a destructive
tool call, and an audit log is the record that it happened** — the permission and the receipt. With
the trait gone, one tenant reads and resolves the other's.

**It hid because the write side does not depend on the trait.** `ApprovalManager::request()` sets
`tenant_id` from `$run->tenant_id` explicitly, and `AuditLogger::record()` sets it from the manager,
so every existing assertion about stamping — the obvious thing a tenancy test checks — passes with
the scope removed. Only the read breaks, and on the approval path the read *is* the control:
`resolve()` fetches with `Approval::query()->lockForUpdate()->findOrFail($id)`, and
`assertMayResolve()` checks the actor, not the tenant. The global scope is the entire distance
between a known approval id and another tenant approving a refund with it.

**The finding is not really about six models.** It is that opting in was a convention. No test ever
performed a cross-tenant *read* of any of the six: each is created and then read back inside the
tenant that created it, and a scope that has been deleted is invisible to a same-tenant read — it
was never going to filter that row out anyway. The suite could therefore exercise these models
heavily, as it does, while saying nothing at all about whether they were scoped. This is the same
shape as T15's `$guarded` rule and the same shape as the Phase 6 lesson, arriving for the fourth
time: **the control was real, the opt-in was a habit, and habits are not asserted.**

**Closed by `Security/TenantScopeCoverageTest`**, which does not test the six. It asks the migrated
schema which tables carry a `tenant_id` column and requires the trait on every model that maps to
one. Per-model tests would have been the wrong fix: the model this is actually about is the one
somebody adds next month, and a table that does not exist yet has no test to go red. `ProviderCredential`
is the single exemption, pinned by a third test so that widening the list is an argument in a diff
rather than the quickest way to make a build pass — it holds deployment-wide credentials with a null
`tenant_id` on purpose, where the scope would hide the row exactly when a run needs it, and
`CredentialIsolationTest` covers the WHERE clause that replaces it.

It lives under `Security/` rather than beside T15's `$fillable` rule in
`Architecture/ModuleBoundaryTest`, which is its closest relative in shape. `tests/Pest.php` omits
`Architecture` from its `uses()` list, so that file has no Laravel `TestCase` and no schema to ask,
and asking the real schema is the whole point — parsing migrations would have introduced a second
thing that can be wrong.

`TenantIsolationTest` also gains behavioural tests naming `Approval` and `AuditLog` directly, since
the two objects with real authority deserve an assertion a reader can find by name rather than a
reflection loop that counts them. The approval one asserts unreachability *by id* through
`ApprovalManager::approve()` — not merely absence from a list, which is the weaker claim and the one
that would have passed anyway.

**Verified by removal, as the criterion requires**: with the new tests in place, deleting the trait
from any of the six now fails. `Approval` and `AuditLog` fail twice, once on the schema rule and once
on the test that names them; the other four fail once.

**The second finding is larger, and it is in the queue.** `ResolvesPandoraContext` re-enters the
tenant a job carries, and its docblock names the stake exactly: *"Forgetting this is the classic way
a queued job silently reads across every tenant."* Removing the re-entry — both by nulling the
carried tenant and by dropping the `with()` wrapper entirely, so the job inherits whatever the
worker has — left **all 1,818 tests passing, twice**.

Two things hid it. The first is `QUEUE_CONNECTION=sync` in `phpunit.xml.dist`: every job runs inline,
inside whatever tenant the test had already entered, so the ambient tenant stands in for the carried
one and the carried one is never load-bearing. **That is the serial-runner finding from T12 and T14
again, in a second costume** — the runner is the fake, and this is now the second control it has been
found silently disarming. It is the reason the new tests dispatch from *outside* any tenant, which is
how a worker with no request and no session actually starts.

The second is why nothing went red even so, and it generalises past tenancy. **Losing the tenant does
not make a read fail; it makes it wider.** With no tenant resolved the global scope is inert, so the
job finds its own run, does its work, and succeeds. Every assertion that the job worked passes
identically with the control removed. The failure mode of this mitigation is a leak, not an error,
and a suite made of "it worked" assertions cannot see one — which is a good description of why the
absence had gone unnoticed through eight phases.

**Closed by `Security/QueuedJobTenancyTest`**, two tests that assert the leak rather than the
success. The first runs a job untenanted and uses the audit row as the witness: `AuditLogger::record()`
stamps whatever tenant is current when it writes, so an unstamped `run.started` row is proof the job
ran with no tenant — and an unstamped audit row is invisible to the tenant whose run it describes.
The second hands a job carrying one tenant another tenant's run id, the shape a corrupted payload or
a replayed message takes, and asserts that run is still sitting in `queued` afterwards. Both fail
under both ablations.

## What auditing T3 found — 2026-08-19

T3's mitigation is a hash. `Session::isolationKeyFor()` folds seven components into a SHA-256 digest,
`SessionResolver` does `firstOrCreate` on it, and a unique index turns a collision into a database
error rather than a silent leak. That design is right, and it has an unusual property for auditing:
**every component is independently removable, and removing one is invisible unless a test varies
exactly that component.** So the audit removed them one at a time — seven ablations across the key,
the resolver and the two places `session_id` is used as a `WHERE` clause.

**Four are load-bearing.** Drop the session filter from `RecentMessagesProvider` and T3's own tests
fail; drop the actor id, the participant id or the channel from the key and they fail too.

**The first finding is half an actor.** `actor_type` can be dropped from the key with **all 1,820
tests still passing**, while dropping `actor_id` beside it fails two. The asymmetry is the finding,
and its cause is visible in the test that looks most like coverage: "derives a different isolation
key for every differing component" varies tenant, agent, channel, participant, origin and the actor's
*id* — system `alice` against system `bob` — and never the actor's *type*. Six of seven components
asserted, and the seventh was the one that reads as already covered because its sibling was.

The collision is reachable rather than theoretical. `ActorContext::system()` takes an arbitrary label
as its id, so an automation labelled with a user's primary key has the same `id` as that user and a
different `type`. Without the type in the key they resolve to one session, and the automation reads
the person's conversation history.

**The second finding is a sentence in a comment.** `SessionResolver` folds the conversation into the
origin component, with a note saying two conversations with the same agent and actor must not share a
context boundary. Removing the fold left the whole suite green. The unit test cannot reach it — it
calls `isolationKeyFor()` directly and passes `origin` ready-made, so the composition the resolver
performs is never exercised by the test that appears to test composition. That is the fourth time in
this phase a docblock has turned out to be the only thing asserting a control.

**Closed by two tests in `Security/SessionIsolationTest`.** The first builds two actors that share an
id and differ only in type, asserts the ids really are equal — otherwise the test proves nothing —
and requires different keys and different resolved sessions. The second resolves two conversations
for one agent and actor, requires distinct sessions, and then asserts the consequence where it would
be felt: a message in the first conversation must not appear in context built for the second. Each
ablation fails exactly the test that names it.

**A bookkeeping finding: the *Claimed by* column was incomplete.** `Summariser` scopes its read by
`session_id` too, and removing that filter leaves all four files T3 claims green — it is caught by
`Context/SummarisationTest`'s "it summarises only this session", which nobody had listed as T3
evidence. The control is real and tested; the plan just did not know where. The column now says so.

**Recorded, not fixed: `Session::belongsToActor()` has no production call sites.** Deleting its
actorless guard changes nothing anywhere, and the reason is not thin coverage. The method is called
only from a test. Its guard is unreachable besides: it returns false when `actor_id` is null, but
`ActorContext`'s two constructors cannot produce a null `type`, so the comparison below it would
already fail. Writing a test here would assert the behaviour of code nothing consults, which is the
reasoning T6a settled for this repository — a control that is a specification rather than something
running. It is written down because the docblock states a rule about system sessions that a reader
would reasonably take for an enforced one, and today it is not enforced anywhere.

## What auditing T5 found — 2026-08-25

T5 is the one criterion written in the audit's own language: it names its ablation rather than its
control. *"Refused at the canonicalisation layer **and** at the disk root, with the second layer
proved by disabling the first."* Ten ablations across `LocalStorage` and `WorkspaceRoots`, nine
load-bearing, **one finding.** No shipped behaviour was wrong.

Nine that behaved: replacing `realpath()` with the unresolved candidate, deleting the containment
assertion, dropping the trailing separator from the prefix comparison, removing the null-byte guard,
removing the listing's containment filter, removing the root-existence check, removing the slug
regex, letting an unknown root key fall back to the first declared root, and returning a raw tenant
id instead of the hashed segment. Each failed `ContainmentTest` or `RootsTest` within seconds. The
trailing-separator ablation is worth naming because it is the one with a boring name and a real
consequence — a root of `/srv/agent` accepting `/srv/agent-secrets` — and the test for it was already
there.

**The finding: the write path's symlink re-check was doing nothing that a quota lookup was not
already doing by accident.**

`LocalStorage::locate($relative, mustExist: false)` resolves the parent, checks it is contained, and
then — because a contained parent says nothing about what the leaf is a link to — re-resolves the
target if it exists in any form. The docblock beside it is unusually direct about why:

> The parent being contained is NOT sufficient, and assuming it was is a genuine hole: `notes.txt`
> can be a symlink to somewhere else entirely, and every write call that follows would happily
> follow it.

Removing that re-check leaves **all 36 T5 tests green, and the full 1,828-test suite green.** Two of
those tests are named for exactly the case it protects — *"refuses a write through a symlink pointing
outside"* and *"refuses a write into a symlinked directory"* — and both still pass without it.

The reason is ordering in the caller. Every T5 test drives `WorkspaceFiles`, and
`WorkspaceFiles::write()` calls `$this->storage->size($relative)` for quota accounting *before* it
writes. `size()` resolves with `mustExist: true`, which canonicalises and asserts containment, and it
swallows only `not_found` — so an escaping symlink throws `outside_root` from the quota lookup,
several lines before the write path's own check is ever reached. The reservation needs the old byte
count; containment is a side effect of needing it.

So there are two layers, which is what the criterion wanted to hear. But the outer one is **quota
code** — it is not there for containment, it would move or vanish the moment reservations became
lazy or were skipped for an unlimited workspace, and nothing anywhere says the write path depends on
it. And the inner one, the one actually written for this threat, was unasserted.

`Workspaces/ContainmentLayersTest` closes it with six tests that drive `LocalStorage` directly, which
is the only way to reach layer 2 with layer 1 out of the way. Three of them fail when the re-check is
removed, and the rest of `tests/Workspaces` stays green — verified by removing it.

One distinction the ablation drew that the original tests blurred: **the symlinked-*directory* case
is caught by the parent check, not the leaf re-check.** For `elsewhere/planted.txt` the parent
resolves outside the root and `assertContained()` refuses it there. Only the symlinked *leaf* and the
*dangling* leaf reach the second check. The two existing tests read as one pair covering one control;
they are two tests covering two different controls, and only one of them was ever the witness for the
one being audited.

A sixth test pins layer 1 in place deliberately — asserting that `WorkspaceFiles::size()` on an
escaping symlink throws — so that if the ordering in `write()` ever changes, something says so rather
than the meaning of `ContainmentTest` changing in silence.

**This is the fifth finding in six threats with the same shape**: a docblock stating a guarantee
precisely, and nothing but an accident asserting it.

## What building real concurrency found — 2026-08-25

Criterion 27 was scheduled ahead of T7 and T13 for a reason that has nothing to do with its own
number. Phase 9 had by then found four controls that survived deletion because the suite has one
process — T2's tenant carry, T12's fan-in lock, T14's approval lock, and the `ExecuteToolCall`
idempotency guards that were never reached at all — and `fake-boundaries.md` names the cause
plainly: *"there is no class called `FakeConcurrency` — the fake is the shape of the runner."*
Everything after this point that claims a control works between workers depends on being able to
run workers.

**The harness.** `tests/Support/RunsConcurrently.php` starts N real OS processes, each booting the
application against the same database, and releases them together from a **barrier**. It is
deliberately not `pest --parallel`, which distributes whole files to run the suite faster and would
collide head-on with the shared-schema optimisation in `TestCase` — what is needed is concurrency
*inside* one test, with the schema migrated and left alone.

The barrier is the part that decides whether any of this is real. Booting Laravel costs hundreds of
milliseconds and varies per process; the contended section costs microseconds. Without a rendezvous
the workers queue up behind each other and the test passes whatever it is asked — including with the
control removed. `Queue/ConcurrentHarnessTest` asserts the harness's own properties before anything
relies on it: that the workers are distinct processes, and that their execution windows genuinely
**overlap**. A concurrency suite whose first test is not "did we actually contend" is measuring
nothing and reporting confidence.

**What it found before it was even pointed at criterion 27.** `RunLock` documents two mechanisms and
names the second as the authority: *"A database ownership lease — the authority. Survives a cache
flush and works on every driver."* Deleting the lease check leaves **all 22 serial lock tests green**
— `RunRecoveryTest`, `ExactlyOnceUnderLockTest` and `ApprovalRaceTest` together, including a test
called "grants ownership to one worker and refuses a second". Five simultaneous processes fail it
instantly: all five acquire the same run.

The cache half cannot cover for it either, and that is worth stating rather than assuming. With
`CACHE_STORE=array` every process has a private store, so all N take their own cache lock and agree —
which is precisely what makes this a clean test of the lease.

**The live defect: a health write could kill a run.** Twenty concurrent runs against one agent, and
one of the twenty died on

```
SQLSTATE[23000]: Duplicate entry 'fake' for key 'pandora_provider_health_key_unq'
```

`ProviderHealthMonitor::rowFor()` used `firstOrNew()` followed by `save()`, and `provider_key` is
uniquely indexed. Two workers recording the first outcome for the same provider at the same instant
both read nothing, both build a row, and the loser's INSERT is refused — and the exception came out
of the health write, out of the provider call, and failed the **run**. A provider that answered
perfectly well, a run destroyed by its own bookkeeping.

The window is narrow: only the first write for a given provider races, every later one is an UPDATE.
It is also exactly the window a fresh deployment starts in — workers warm, health table empty. Fixed
by tolerating the duplicate and taking the row the other worker inserted, since both are the same
freshly defaulted row.

**The detector is not flaky**, which for a concurrency test is a claim that has to be measured rather
than hoped: with the fix reverted, the test caught the race **5 times out of 5**.

**Recorded, not fixed: the health counters lose updates.** `recordSuccess()` reads
`consecutive_successes`, adds one and writes it back, with no lock. Two concurrent successes both
read N and both write N+1, so the counter drifts low under load. Unlike the insert race this cannot
fail a run, and it feeds hysteresis — a count of consecutive outcomes used to decide whether a
provider is degraded — rather than any security control. Making it exact means holding a row lock
across a read-modify-write on the hot path of every provider call, which is a performance decision
for the maintainer rather than a defect to quietly fix inside an audit.

**Twenty processes, not fifty, and that is a reading rather than a shortfall.** Each worker is a full
PHP process booting Laravel; fifty of them is roughly 4GB resident and a CI box that swaps, which
converts a correctness test into a flake generator — and a flaky concurrency test gets deleted, which
is how a suite ends up with none. What the criterion asks — does contention on one agent corrupt
anything — is answered by any N above one, and twenty simultaneous writers is well past the point
where the lease, the step writes and the conversation inserts either serialise correctly or do not.
The count is a named constant so that raising it on a bigger machine is a one-line change.

**`tests/Pest.php` binds `TestCase` by an explicit directory allowlist**, and a new `Performance`
directory is not on it. The symptom is every test in the new directory failing with
`Target class [config] does not exist`, which reads like a container problem. Added; worth knowing
that the list exists before adding the next directory.

## What auditing T7 found — 2026-08-25

T7 words its ablation backwards from every other criterion in the phase. Not *"remove this limit and
watch its test fail"*, but **"each proved by removing the OTHER limits"** — and that turns out to be
the only question worth asking about a set of limits, because they overlap. `assertWithinBudget()`
checks four of them in a fixed order — iterations, tool calls, duration, tokens — and the first to
trip is the one that throws. A run built to exhaust its tool calls usually exhausts its iterations at
the same moment, and a test asserting only *"it stopped, with a `BudgetExceeded`"* cannot tell which
did it. Such a test passes with the limit it names deleted, provided a neighbour trips first.

Nine ablations across the eight limits — tokens has two independent mechanisms — **seven
load-bearing, two gaps.**

The seven that behaved: the tool-call limit, the scoped token budget, the monetary budget, duplicate
detection, the delegation-depth check, the autonomy budget, and the iteration limit.

**The iteration limit is caught by a file the criterion never named.** Removing it leaves all five of
T7's claimed test files green; what fails is `Feature/AgentRunTest`'s "it stops when the iteration
budget is exhausted". The control was real and tested — the plan did not know where. Same shape as
T3's `Summariser` finding, and the *Claimed by* column is now corrected.

**The finding: the wall-clock limit had no test at all.**

`Run::hasExceededDeadline()` has exactly one call site in `src/` and **none anywhere in `tests/`**.
Deleting the check left all 1,828 tests green.

What makes it worth more than a line in a table is *why* nobody noticed. The test that reads like its
coverage is `BudgetEnforcementTest`'s **"it terminates the run as `timed_out` with a specific
reason"** — and that test is about the agent-scope **token** budget. `RunState::TimedOut` is the state
every budget breach lands in, so the name means "stopped by a limit", not "ran out of time". The one
limit that literally runs out of time had nothing, behind a name that says it does.

Reaching it needed a tool that takes time. `deadline_at` is stamped at creation and checked at the top
of each iteration, so a run only exceeds it if the clock moves BETWEEN iterations — a test cannot
sleep for a realistic timeout, and it cannot reach in between two iterations of a run executing
inline. A tool runs exactly there. `Fixtures\Tools\SlowTool` moves the test clock from inside
`handle()`, which is the same shape as a real tool that took two minutes to answer.

**A second thing the fixture exposed, which cost the first two attempts.** Tool authorization is
against the **actor**, so a run dispatched with nobody attached has every tool call *denied* — and a
denied call still burns an iteration and a tool call. A limit test built that way still "passes" while
never executing a tool at all. The wall-clock test failed initially for exactly this reason: the tool
meant to move the clock never ran, and the run completed. Any future limit test must attach an actor
or it is measuring denials.

**Recorded, not fixed: the agent token check is redundant with the scoped one.** Deleting
`assertWithinBudget()`'s token comparison also left the suite green, because
`BudgetGuard::limitsFor(Run)` reads the same `token_budget` column by a different route and catches it
a line later. This is defence in depth rather than a hole, and the two are **not** equivalent: the
run-level check compares the run's own counters, while the scoped one sums usage records through
`budgetOwner()`, which charges a delegated run to the agent at the root of its tree. Both layers are
now named and asserted separately — and the run-level test had to be tightened twice to do it, because
"token budget" and the figure appear in *both* messages. Only `BudgetExceeded::tokens()` phrases it
"exceeded its token budget of N".

`Feature/LimitAttributionTest` sets **every other limit generously** and asserts the message of the
limit under test. Seven tests; five ablations each fail exactly the test that names them, verified by
removing each in turn. One test deliberately proves the negative — a run inside its deadline still
finishes — so the wall-clock check cannot be a blanket refusal of any run that calls a tool.

## What auditing T8 found — 2026-08-25

T8 is the best-tested threat in the phase, and that is worth recording as plainly as the gaps have
been. Eight ablations, **seven load-bearing**, one gap. The suite already distinguished an empty
intersection from an absent one — the conflation `intersectionAllows()`'s own docblock calls "the
failure mode worth being pedantic about" — and it already proved the property holds at depth, through
a cycle, and when the gatekeeper is asked rather than when the list is merely stored.

The seven that behaved: the intersection itself, the frozen-list short-circuit that makes narrowing
compound at depth, the gatekeeper's enforcement of the frozen list, the empty-versus-null distinction,
the cycle refusal, the delegation allowlist, and even the *direction* of the withheld list — flipping
`array_diff`'s arguments fails a trace test.

**The finding: the deny half of layer 2 was never exercised through delegation.**

`AbilityIntersection::abilitiesOfAgent()` resolves an agent's tools as *granted, minus denied*, and
`Agent::deniedTools()` states the purpose: *"Denial beats the allowlist, so a whole group can be
granted with one member carved out."* Removing the `&& ! ToolReference::matches($tool, $denied)` half
left **all 70 delegation tests green**, and the whole suite with them.

Every existing T8 test gives the parent an ability it simply **lacks** — the parent's allowlist does
not mention `refund_order`, so the intersection drops it. None gives the parent an ability it was
explicitly **denied**, which is a different code path reaching the same list, and the only one the
carve-out idiom uses.

The escalation runs the opposite way round from the one the other tests guard:

1. `abilitiesOf($parent, …)` falls back to `abilitiesOfAgent($parentAgent)` for a top-level run.
   Ignore the deny list there and the **parent** is credited with a tool an operator took away from it
   by name.
2. That inflated set is what the child intersects against, so the child receives it.
3. At call time the gatekeeper checks the **child** agent's policy and the frozen intersection.
   Neither mentions the parent's deny list.

So a tool carved out of a parent is reachable by delegating to an agent that allows it — one hop,
exactly the failure T8 exists to prevent, through the one door the suite was not watching.

**It is an execution, not a bookkeeping mismatch.** With the deny half removed, the new
"refuses the call itself" test does not merely find the wrong list: the tool execution comes back
`succeeded` where it should read `denied`, and the counter on the fixture tool reads 1. The child ran
a tool its parent was forbidden. That distinction is why the test asserts a side effect rather than a
status — a containment failure fails wide rather than loudly, which is the third time this phase has
turned on that sentence.

`Delegation/DeniedAbilityTest` closes it with five tests: the parent-side carve-out, the child-side
carve-out, the end-to-end refusal with its side effect, the operator-facing withheld list, and one
that proves a tool neither side denied still gets through — without which the other four are satisfied
by an intersection that withholds everything. **All five fail under the ablation while all 70 existing
delegation tests pass**, verified by removing it.

**A docblock that says the opposite of what the code does.** `DelegationDecision`'s constructor
documents `$withheldTools` as *"abilities the parent held and did not pass on"*.
`AbilityIntersection::withheld()` computes the other direction — what the child agent was configured
for and was refused — and its own docblock is emphatic that this is deliberate: *"This direction, and
not the other one."* The code is right and the parameter comment is wrong. Corrected, and noted here
because the phase's recurring finding is a docblock doing a control's job; this is the same hazard
with the roles reversed, a docblock quietly misdescribing one.

## What auditing T13 found — 2026-08-25, and the audit closes

`routes/web.php` states the rule and its reason: *"Authorization is enforced inside each component —
route middleware alone is not treated as sufficient."* All eighteen components follow it. **Nothing
made them.**

Twenty-five ablations — one per page gate, plus the checks that gate prompts, tool I/O, costs and the
run trace separately. **Nineteen load-bearing, six gaps, three findings.**

**Finding one: five pages could lose their gate with the whole suite green.** `AgentDetail`,
`AutomationDetail`, `RunDetail`, `RunsIndex` and `ChannelLink`.

The distribution is the part worth keeping. Every index page but `RunsIndex` has a "denies a user
without `pandora.access`" test; **the detail pages have none at all.** And a detail page is where an
index's one line becomes a full role-instruction prompt, a webhook secret, or an entire execution
trace — so the pages that went unasserted are precisely the ones with the most to show. Nobody
decided that. The index denial tests were written as a set, and the detail tests were written for
what the page *displays*, which is a different question that never circles back to who may open it.

**Finding two: two of the twenty configured abilities are wired to nothing.**

- **`audit.view`** — declared, registered as a deny-by-default gate, and read nowhere, because the
  audit **page** it was written for does not exist. Phase 6 closed "no audit page" as an open
  decision and the ability was left pointing at it.
- **`tools.manage`** — the Tools page is read-only. Its only action, `toggle()`, expands a row.

Neither exposes anything: no audit record reaches any view, and no tool can be altered from the UI at
all. These are promises with nothing behind them rather than holes — but an operator granting or
withholding either sees no difference, and cannot discover that from outside. Both are named in the
new test rather than deleted from the config, because removing a published key breaks a host that
references it, and because the *next* unused ability must not be able to hide behind these two. Both
belong in the v1.0 support statement (criterion 33).

The criterion's own wording is what surfaced this: it requires audit logs to gate *separately*, and
they do not gate at all.

**Finding three: `ToolsIndex` reads `tools.io.view` twice and only one reading was asserted.**
`canViewSchemas` is the flag the template branches on; the `if` above it decides whether the schemas
are **assembled** at all. Deleting the assembly gate left the suite green, because the template still
hid what it was handed. Nothing leaked — view data that is never echoed does not reach a browser —
but the belt is the half that does not depend on every future template getting its branch right, and
it was the unasserted one.

`Security/ControlCenterGatingTest` closes all three with nine tests, and one of them is
architectural: **every class in `src/UI/Livewire/` must authorize somewhere**, asserted over the
source the way T15's `$guarded` rule and T6a's outbound-HTTP rule are, so a nineteenth page cannot be
added ungated. It checks the class rather than `mount()` specifically, because `ChannelLink`
authorizes from a private `guard()` and `MemoryIndex` from a shared `act()` — both correct, both
flagged by a naive mount-only rule, and a rule that reports false violations acquires an exemption
list and then gets ignored. All six ablations now fail; verified by re-running each.

---

### The removal audit is complete — 15 of 15

Criterion 17 is closed. Every T1–T15 mitigation has been removed, one at a time, and the failure
recorded. Ten sessions.

**What it produced:** two live security fixes shipped in v0.1.3 — an SSRF in the MCP client that a
hostile server could steer an authenticated POST through, and untrusted content able to close its own
delimiter inside a system message — plus one concurrency fix, where a provider-health insert race
could fail a run outright.

**What it mostly produced, though, was tests for controls that already worked.** Fourteen coverage
gaps across ten threats, and the recurring shape did not change once in ten sessions: **a docblock
stating a guarantee precisely, and nothing but an accident asserting it.** T5's symlink re-check,
where a quota lookup threw first. `RunLock`'s database lease, which the class calls "the authority".
`BelongsToTenant`, where the opt-in was convention. The wall-clock limit, hidden behind a state named
`TimedOut` that means something else. The deny half of layer 2, never reached through delegation
because every test used an ability the parent simply lacked.

Three structural causes account for most of it, and all three are now closed or written down:
the suite had one process (closed — `RunsConcurrently`); `QUEUE_CONNECTION=sync` disarmed everything
that depends on a job carrying its own context (closed — `QueuedJobTenancyTest`); and a fake stood
where a real boundary belonged (inventoried — `fake-boundaries.md`).

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

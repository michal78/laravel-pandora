# Fake Boundaries

> Phase 9, criterion 19. Every fake that stands where a real system would, what it structurally
> cannot prove, and what closes the gap — or that the gap is accepted.

A fake exists to make a boundary testable. It does that by *being* the boundary, which means the
boundary itself stops being under test. That is not a flaw in any particular fake; it is what a fake
is. The consequence is that **the blind spot of a fake is invisible by construction** — no test
written against it can fail for the thing it does not model, and no amount of adding tests of the
same kind will help.

Phase 6 is the worked example. Thirty acceptance criteria passed over an MCP client that had never
once worked outside the suite. Two defects, and both sat exactly at a fake:

- `FakeProvider` accepts any function name a tool cares to have. A real provider does not — OpenAI and
  Anthropic hold names to `^[a-zA-Z0-9_-]{1,64}$` — so the default namespace separator `.` made every
  approved remote tool a `400`. A fake that enforced a vendor's grammar would *be* a vendor.
- `FakeMcpServer` is a genuinely good fake. It hangs, it lies, it rewrites its own tool descriptions,
  it is a deliverable in its own right. And every test reached it through a `ToolInput` built by hand,
  which skips validation — the only step that could lose an argument. Every remote call arrived
  as `{"arguments":{}}`, succeeded, and was audited as allowed.

Two fakes, at the two ends of the only path that matters. Hence this document: the gaps are written
down where they can be reviewed, because they cannot be discovered by running the suite.

## The inventory

### `FakeProvider` — `src/Providers/Adapters/FakeProvider.php`

**Stands for:** any LLM provider.

**Cannot prove:** that a request Pandora composes is one a vendor will accept. Function-name grammar,
schema shape, role ordering, token limits, content-type rules — a fake that validated these would be
a re-implementation of the vendor, and would be wrong in different places instead.

**Closed by:** `Providers/FunctionNameGrammarTest` (criterion 20) asserts the grammar directly against
tool names, sending nothing anywhere — the one shape of test that could have failed on the day the
separator was chosen. `Providers/Contract/` runs the adapter contract, and
`Providers/ToolSerializationTest` asserts the exact vendor wire shape against a faked HTTP layer
rather than a faked provider, which is one boundary further out.

**Still open, accepted:** no test makes a paid call to a real provider. A vendor changing its grammar
is not detectable here and would be found by a user. The alternative is a suite that costs money and
gets skipped, which is the same gap wearing a different hat.

### `FakeMcpServer` — `src/Testing/FakeMcpServer.php`

**Stands for:** a remote MCP server, including a hostile one.

**Cannot prove:** that arguments survive the path *to* it. The fake models the far end well; what it
could not model was our own validation step, because tests reached it by constructing the input the
step produces.

**Closed by:** `Tools/ArgumentSurvivalTest` (criterion 21) asserts structurally that every property a
tool advertises is a property it validates — the exact defect — and round-trips a remote tool's
arguments through `validate()` rather than around it. Verified by reverting the fix: two of its four
tests fail.

**Also cannot prove:** anything about *where the request went*. The fake is bound in place of
`HttpTransport`, so every MCP test but one runs with no HTTP client, no URL and no response headers
anywhere in the picture — **a fake that never had a URL cannot lose one.** That blind spot hid a live
SSRF for the whole of Phases 6 to 8: Guzzle follows redirects by default, so a server answering `302
Location: http://169.254.169.254/` had our POST re-sent to the cloud metadata endpoint and its body
returned to the model as tool output. Found 2026-08-17 by T6b, which was the first test to point the
real transport at anything.

**Closed by:** `Mcp/TransportUrlOriginTest` (criterion 7) drives the real `HttpTransport` against
`Http::fake()` and asserts the URL requested, not just the answer received. Verified by restoring
`allow_redirects`: two of its seven tests fail.

**Still open, accepted:** protocol-level divergence between the fake and a real server's framing.
Mitigated by the Phase 6 walkthrough having driven a real HTTP server and a real stdio one; not
mitigated continuously, because CI has no MCP server to talk to.

### `NullTenantResolver` — `src/Core/Tenancy/NullTenantResolver.php`

**Stands for:** a host application's tenant resolver.

**Cannot prove:** that Pandora ever asks. It returns `null` unconditionally, so a green suite cannot
distinguish *Pandora consulted the resolver and the answer was null* from *Pandora never consulted
it*. In a single-tenant application those two are identical forever — and every tenancy test in the
suite reached its tenant through `inTenant()`, which is `TenantManager::with()`: the **override** path
a queued job uses, not the resolver path a host uses.

**Closed by:** `Security/HostResolverTenancyTest` (2026-08-11) binds a resolver that answers and whose
answer changes. Nine tests; deleting the binding fails eight, verified by deleting it.

**Still open, accepted:** whether a real host wires its resolver where Pandora expects. That is host
code and was never Pandora's to prove.

### `FakeChannel` — `src/Testing/FakeChannel.php`

**Stands for:** Slack, and any other channel.

**Cannot prove:** whether the refusal a real stranger meets when they message an agent for the first
time actually tells them what to do next. Every behavioural claim — redelivery, unreachability, a
display name that reads as an instruction, a message from an unlinked identity — is asserted against
the fake precisely *because* it misbehaves on demand. What it cannot be is a person reading the reply.

**Closed by:** the Phase 8 walkthrough, driven against a real Slack workspace for every section but 5.

**Still open, accepted:** section 5 — two linked identities interleaving on one channel account in
real time. No second Slack account. Recorded as Phase 8 criterion 33a and named in the v1.0 support
statement.

### `HashEmbeddingProvider` — `src/Memory/Embeddings/HashEmbeddingProvider.php`

**Stands for:** a hosted embedding model. Ships as the default, so it is a fake that is also a
product decision.

**Cannot prove:** retrieval *quality*. It hashes tokens into buckets, so texts sharing words land near
each other and texts sharing none do not. It will never put "car" near "automobile". Every test of
semantic recall is therefore a test of lexical overlap wearing a vector's clothes.

**Closed by:** nothing, and it does not need to be for the mechanism. The contract, store, cache,
scope re-filter and pgvector adapter are all exercised honestly — the vector path is real, only the
semantics are not. `Memory/PgvectorTest` runs against real pgvector in CI and **skips** without it
rather than passing.

**Still open, accepted, and deliberately:** no assertion that swapping in a real provider improves
recall. The alternatives are a null provider (the vector path never runs — the Phase 4 failure on
purpose) or a hosted one (paid network calls in CI, therefore skipped).

### The suite's own dependencies — `require-dev`

**Stands for:** an application that installed Pandora and nothing else.

**Cannot prove:** anything about a *missing* optional dependency. `livewire/livewire` is a dev
dependency, so `class_exists(Livewire::class)` is true for every test that will ever run here, and the
code paths that handle its absence are unreachable by construction — not untested by oversight,
untestable by layout.

This is not hypothetical. `pandora:status` reported **"Control center: enabled"** from the config flag
alone, while `PandoraServiceProvider` returns early and registers no `/pandora` route at all when
Livewire is missing. A stock install therefore said the control center was enabled and answered the
documented URL with a 404 — the most visual thing the package ships, silently absent, on the exact
path the installer tells you to open. Found on 2026-08-12 by installing v0.1.0 from Packagist into a
fresh Laravel application, which is the only place it *could* be found.

**Closed by:** a three-state report (`headless` / `unavailable` / `enabled`), the installer naming
Livewire as its own step, and the README saying the bare install is headless. Two of the three states
are asserted in `Feature/InstallationTest`; the third is verified by hand.

**Still open, accepted:** the `unavailable` branch has no automated cover. Closing it means either a
second CI leg with a deliberately minimal dependency set, or indirection in a status line that does
not deserve it. Phase 9's example-application criterion (29) is the honest home for this.

### The single test process — `tests/TestCase.php`

**Stands for:** an application running several queue workers, several web processes, and more than
one operator with the approvals page open.

**Cannot prove:** anything that is only wrong when two things happen at once. The suite runs serially
on one connection, so every "race" in it is two sequential calls, and a check-then-write with no lock
around it passes identically to one held under `lockForUpdate()`. This is the fake nobody notices,
because there is no class called `FakeConcurrency` — the fake is the *shape of the runner*.

It is also, unlike the others, a fake standing at the boundary of a mitigation that exists purely
*for* the boundary. A row lock does nothing whatsoever in a serial test. It cannot fail one either.

**Found 2026-08-19.** `Security/ApprovalRaceTest`'s "consumes an approval exactly once when two
approvers race" is two `approve()` calls in a row, which proves `resolve()`'s `isPending()` status
check and nothing else. Deleting `lockForUpdate()` from `ApprovalManager::resolve()` left all 1,809
tests green; deleting it from `ExecuteToolCall::fanIn()` did too. Both had docblocks explaining that
the lock is what makes double resolution "impossible rather than merely unlikely" — a sentence the
suite had no way to check.

The same shape hid a second thing. `ExecuteToolCall`'s idempotency guards were never reached by the
retry tests at all: those dispatched the redelivery without the actor a real queue would have
carried, so the tool's own `authorize()` refused the second call and the count stayed at one.
Dispatched faithfully, with every guard removed, the customer is refunded twice.

**Closed by:** `Security/ExactlyOnceUnderLockTest` opens a **second connection** to the same database
and holds a real row lock on it, so the contention is the engine's rather than the test's. Five
tests; removing either lock fails exactly the one that names it, verified by removing each in turn.
`Security/ApprovalRaceTest`'s redeliveries now carry tenant and actor, and a new case redelivers
while the run is still waiting on another call — the only scenario where the execution-row guard
stands alone rather than being covered by the terminal-run check beside it.

**Still open, accepted:** SQLite. `lockForUpdate()` compiles to nothing there, because SQLite has no
row locking — what stands in its place is the database-wide write lock a transaction takes, which is
a different mitigation and would need a different proof. Rather than pass vacuously on the leg where
the control does not exist, `ExactlyOnceUnderLockTest` **skips** on SQLite and runs on the four
server-engine legs. A host deploying Pandora on SQLite has the guarantee by a route this suite does
not assert.

**Still open, accepted:** true simultaneity. Every test above proves the lock is *taken* and that it
contends on the right row. None runs two processes at the same instant, because a test that depends
on two workers interleaving is a test that goes flaky on a loaded runner and gets deleted. Criterion
27 — fifty concurrent runs against one agent — is the honest home for that, and it is still open.

### Fixture packages — `tests/Extensions/`

**Stands for:** Composer-installed extension packages, via a temporary `installed.json`.

**Cannot prove:** nothing significant. This one is the counter-example worth keeping in view: because
discovery reads a manifest and boots nothing, a fixture package *is* the real thing. One fixture's
`extra.pandora` block is hostile and another's classes do not exist — and "boots nothing" is only
proved by discovery succeeding over a package that could not be booted.

## Fakes deliberately not used

**`Storage::fake()`.** It is the local driver wearing an object store's name. A suite green against it
would prove the local adapter twice, so the object-storage legs run against real MinIO in CI and the
tests **skip** without an endpoint rather than passing.

**A null embedding provider.** See above.

## The rule this produces

When adding a fake, the question is not "is this a good fake" — `FakeMcpServer` is an excellent one
and it still cost a phase. The question is **what class of defect can no longer fail a test**, and
where that class gets caught instead. If the answer is "nowhere", that is a decision, and it belongs
in this file where somebody can disagree with it.

Five of the eight entries above were closed only after a defect or a walkthrough pointed at them, and
`FakeMcpServer` has now cost two: one argument-stripping bug and one SSRF. That is the pattern the
inventory exists to break. The second `FakeMcpServer` entry is the more useful of the two, because it
names a blind spot of a *different kind* — not "the fake models the far end imperfectly" but "the
fake removes an entire layer from the test, and the layer it removes has its own security
properties."

The single-process entry is a third kind again, and the most uncomfortable: there is no object to
point at. Nobody chose it, nothing is named after it, and it silently disarmed every lock in the
codebase. When looking for fakes, the runner counts as one.

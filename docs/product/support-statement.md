# Support Statement — v0.1.0

> What Pandora supports, what it excludes by design, and what it ships **untested**. Read the third
> section before depending on this in production.

## What the version number means

`0.1.2`. The public API is usable and in use; it is not yet promised. Minor versions below `1.0.0`
may contain breaking changes, and will say so in `CHANGELOG.md` with an upgrade instruction.

`1.0.0` is defined, not aspirational: it is when every criterion in
`docs/development/phase-9-acceptance.md` is met — most importantly that **each of the fifteen threats
in `docs/architecture/security-model.md` has a test that fails when its mitigation is removed.**
That stands at 11 of 34 criteria today, and the removal audit itself at 8 of the 15 threats.

## Supported

**Runtime.** PHP 8.3 and 8.4. Laravel 13.

**Databases.** SQLite, MySQL 8.4, MariaDB 11, PostgreSQL 17 — each a green leg in CI on every push,
not a claim from a compatibility table.

**Vector storage.** pgvector (a dedicated CI leg), and a database-backed store that works on every
supported engine.

**Object storage.** Any S3-compatible endpoint. Exercised in CI against real MinIO, deliberately not
against `Storage::fake()`, which is the local driver wearing an object store's name.

**Providers.** Any OpenAI-compatible HTTP API. Function names are asserted against the grammar OpenAI
and Anthropic share.

**Deployment shape.** A single operator, or multiple tenants of one trusted operator. See the
boundary note below.

## Excluded by design — not deferred

These are decisions with reasons, not gaps waiting on a schedule.

**An extension marketplace, remote install, or update mechanism.** Extensions arrive through
`composer require` and nothing else. A UI that can install code is a UI whose authorization bug is
arbitrary execution (ADR-0016).

**Skill execution.** Imported skills are instructions, never executed, and embedded install
instructions are never run (ADR-0008).

**Approval decisions from a channel.** A channel may be *told* an approval is waiting. It cannot
carry the decision. An approval is a human authorizing a specific call with the real arguments in
front of them, and a button that approves something half-seen is worse than no button.

**Inferring a host user from a channel-supplied identity.** No email match, no username match, no
external ID match. Linking requires a code issued into the channel and redeemed in an authenticated
host session (ADR-0015).

**An HTTP fetch tool.** None of the sixteen built-in tools makes an outbound request. The SSRF
controls described in the threat model are a specification for a tool that does not exist yet, not a
control that is currently running — stated plainly here because the threat model reads as though they
are deployed.

## Shipped untested — the honest part

Each of these is a real gap. None is a plan waiting to happen; where one becomes one, it is named in
`docs/development/phase-9-acceptance.md`.

**Two channel identities interleaving on one account, concurrently.** Phase 8 walkthrough §5. It
needed a second Slack account that did not exist. `Channels/SessionIsolationTest` asserts two
participants resolve to two sessions, and the walkthrough drove the *sequential* case — relink to a
different host user, no inheritance — against a real workspace. The untested case is narrow and
specific: two linked identities interleaving in real time, where a defect would be a race in session
resolution rather than a wrong isolation key.

**The threat model is not yet fully proved by removal.** T1, T4, T6a, T6b, T9, T10, T11 and T15 are
— each has a test that was checked by deleting the mitigation and watching it fail. The remaining
seven (T2, T3, T5, T7, T8, T12, T13, T14) name passing tests that have not all been read against the
threat they claim, and a test that passes with its mitigation removed was never testing the
mitigation. This is the single largest reason this release is `0.x`.

Worth knowing what the audited eight cost. They found a live SSRF in the MCP HTTP transport, untrusted
content able to close its own delimiter inside a system message, a console command reporting an
approval requirement that did not exist, a config allowlist that could publish a credential, a
criterion describing behaviour the package does not have, and two tests that passed with their own
mitigation deleted. The audit is not a formality, and the seven outstanding threats should be read as
unproved rather than as probably fine.

**Retrieval quality on the default embedding provider.** `HashEmbeddingProvider` hashes tokens into
buckets. The vector path — contract, store, cache, scope re-filter, pgvector adapter — is real and
tested. The semantics are not: it will never put "car" near "automobile". Configure a real embedding
provider for anything depending on recall.

**Protocol divergence between `FakeMcpServer` and a real MCP server.** The Phase 6 walkthrough drove
a real HTTP server and a real stdio one; CI has no MCP server to talk to, so that is a point-in-time
result rather than a continuous one.

**Whether a host's tenant resolver is wired where Pandora expects.** `Security/HostResolverTenancyTest`
proves Pandora consults a bound resolver. Whether yours resolves the right tenant from a subdomain,
session or path is host code and was never Pandora's to prove.

The full inventory of what the suite's fakes structurally cannot prove is
`docs/development/fake-boundaries.md`.

## The security boundary

Pandora is built for **multiple tenants of one trusted operator**. Tenancy, sessions and actor
authorization exist to stop one tenant's data reaching another's agent, and are tested for that.

It is **not** a hostile multi-tenant boundary between mutually adversarial operators sharing one
installation. If your tenants are adversaries who can each configure agents, tools, MCP connections
and extensions, run separate installations. That is the same constraint every comparable product
carries, stated here rather than discovered later.

Report security issues per `SECURITY.md`. Do not open a public issue.

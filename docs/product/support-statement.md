# Support Statement — v1.0.0

> What Pandora supports, what it excludes by design, and what it ships **untested**. Read the third
> section before depending on this in production.

## What the version number means

`1.0.0`, and from here Pandora follows [semantic versioning](https://semver.org): a breaking change to
the public API below waits for `2.0.0`. Anything not listed is internal and may change in a minor
release, whether or not its class carries `@internal`.

**The public API — covered by semver:**

- The `Pandora` facade and `Pandora\Pandora`, `AgentRunner` and `PendingAgentRun` — running,
  replying to, and cancelling agents.
- Everything in `Pandora\Contracts` — the interfaces a host implements or binds: providers,
  context providers, tool policies, resolvers, channels, vector stores.
- What a host extends or builds: `Tools\Tool`, `ToolInput`, `ToolContext`, `ToolResult`,
  `PolicyDecision`, `Agents\AgentBlueprint`, and the provider DTOs in `Providers\Data`.
- `Pandora\Exceptions` — the classes a host catches, and their hierarchy.
- `Pandora\Testing` — the fakes and the provider contract suite a host's own tests use.
- Events a host listens to (`Runs\Events`) and the broadcast event names and payloads
  (`Realtime\Events`, versioned by `PandoraBroadcastEvent::VERSION`).
- Eloquent models and their enums as **read** surfaces — attribute names and enum values. Writing
  to them directly, rather than through the managers, is not covered.
- Configuration keys in `config/pandora.php`, artisan command names and options, ability names,
  route names, and the database schema. A minor release may add any of these; a published config
  from an earlier `1.x` keeps booting (it is merged recursively with the package defaults).

**Not covered:** the control center's markup and CSS, Livewire component internals, job classes,
the order and content of run-trace payloads, prompt wording, and anything under a namespace not
listed above.

`1.0.0` was defined, not aspirational: every criterion in `docs/development/phase-9-acceptance.md`,
most importantly that **each of the fifteen threats in `docs/architecture/security-model.md` has a
test that fails when its mitigation is removed** — done as of v0.1.4.

## Supported

**Runtime.** PHP 8.3, 8.4 and 8.5. Laravel 13. Every leg installs the newest release of each
dependency, and one more installs the **oldest** release `composer.json` accepts, so a lower bound is
a tested claim rather than a guess — the first run of that leg raised `dragonmantank/cron-expression`
to `^3.5`, because 3.4 skips a daily schedule on the spring-forward DST day.

**Databases.** SQLite, MySQL 8.4, MariaDB 11, PostgreSQL 17 — each a green leg in CI on every push,
not a claim from a compatibility table. Each leg also asserts its exact skipped-test count, so a leg
that silently stopped testing what it exists to test fails rather than passes.

**Vector storage.** pgvector (a dedicated CI leg), and a database-backed store that works on every
supported engine.

**Object storage.** Any S3-compatible endpoint. Exercised in CI against real MinIO, deliberately not
against `Storage::fake()`, which is the local driver wearing an object store's name. MinIO no longer
distributes community builds, so CI builds one pinned release from its archived source.

**Providers.** Any OpenAI-compatible HTTP API, Anthropic and Gemini natively, and every text driver
the Laravel AI SDK ships through the `laravel-ai` adapter (`laravel/ai`, suggested). Function names
are asserted against the grammar OpenAI and Anthropic share.

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

**The threat model is fully proved by removal — as of v0.1.4.** Every T1–T15 mitigation has been
deleted, one at a time, and the resulting failure recorded; ten sessions of it.

Worth knowing what the audit cost, because it is not a formality. It found a live SSRF in the MCP
HTTP transport, untrusted content able to close its own delimiter inside a system message, a console
command reporting an approval requirement that did not exist, a config allowlist that could publish a
credential, a provider-health insert race that could fail an otherwise healthy run, a criterion
describing behaviour the package does not have — and fourteen controls that were correct but which no
test would have noticed the removal of. The recurring finding was a docblock stating a guarantee
precisely with nothing but an accident enforcing it.

**Two configured abilities are reserved.** `pandora.audit.view` and `pandora.tools.manage` are
declared and registered as deny-by-default gates, and nothing reads them yet: there is no audit page,
and the Tools page is read-only. Neither exposes anything. They are kept so that the pages they were
written for can arrive in a minor release without a new ability for operators to discover.

**A console run has no conversation.** `pandora:agent:run` executes an agent once, with no
conversation and therefore no history across invocations. That is by design; use the chat surface,
a channel, or `Pandora::startConversation()` for a dialogue.

**Most Laravel AI SDK drivers.** The `laravel-ai` adapter passes the shared provider contract through
the SDK's `anthropic` and `openai-compatible` drivers, on the native adapters' own recorded fixtures.
Every other SDK driver — Bedrock, Azure, Mistral, Groq, xAI, DeepSeek, Cohere — is reachable and
untested here, and the SDK's `gemini` driver speaks a different Google API from the one the fixtures
describe; use the native `gemini` adapter for Gemini. Health for these connections is always
*unknown*, because the SDK has no reachability probe and a paid completion is not an acceptable one.

**The cross-process cache lock is not exercised.** `RunLock` has two mechanisms and documents the
database lease as the authority. `Performance/ConcurrentRunsTest` proves the lease across real
concurrent processes; the cache lock in front of it is not proved, because the suite runs
`CACHE_STORE=array`, which is private per process. A host on Redis or Memcached has a real
cross-process cache lock that nothing here asserts. The layer beneath it — the one that decides — is
asserted.

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

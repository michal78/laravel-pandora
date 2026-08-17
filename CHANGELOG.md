# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

> **The heading below is the release trigger.** `.github/workflows/release.yml` reads the first
> `## vX.Y.Z` heading in this file, and if no tag of that name exists, tags the commit and publishes
> a GitHub Release with that section as the body. Cutting a release means editing this heading and
> merging to `master` — there is no other button.


## Unreleased

### Security

- **The MCP HTTP client no longer follows redirects (SSRF).** Guzzle follows them by default, so a
  hostile or compromised MCP server could answer `302 Location:
  http://169.254.169.254/latest/meta-data/` and have Pandora re-send its POST to the cloud metadata
  endpoint — across an HTTPS-to-HTTP downgrade, into the link-local range — then return the response
  body to the model as tool output. An MCP endpoint is operator-configured precisely so that nothing
  on the far side chooses where this application connects; a `Location` header was a way around
  that. Redirects are now disabled at the client and a 3xx is refused with a new `redirected` reason
  rather than reported as `server_unavailable`, which would have read to an operator as an outage.

  **No action needed on upgrade.** A legitimate MCP server does not redirect its JSON-RPC endpoint;
  if one in your deployment does, point `endpoint` at the final URL. The refusal names the location
  it declined to follow.

  Hidden until now because every MCP test binds `FakeMcpServer` in place of the HTTP transport — a
  fake that never had a URL cannot lose one. `Mcp/TransportUrlOriginTest` drives the real transport
  against `Http::fake()` and asserts the URL requested, not merely the answer received.

### Added

- **`Architecture/NoOutboundHttpFromToolsTest` (T6a).** No core tool makes an outbound request, and
  now nothing can add one quietly: the source, the stream wrappers and the constructor wiring of
  every shipped tool are all checked. The threat model's SSRF allowlist is documented as a
  *specification for a tool that does not exist* rather than a control that is running, and this test
  is what goes red on the day it needs to be built.
- **`Skills/UntrustedSkillTest` (T9).** A hostile skill body — install instructions, a shell command,
  an embedded tool call, a prompt-injection payload — is stored verbatim, lands disabled, grants
  nothing by declaring `required_tools`, and executes nowhere.
- **`Architecture/ModuleBoundaryTest` asserts T15 over every model.** No `$guarded = []`, an explicit
  `$fillable` on all twenty-nine, and no `unserialize()` anywhere in `src/`.

### Fixed

- **ADR-0008's last consequence was wrong, and is amended.** It said skill instructions still reach
  the prompt. Nothing in `src/` reads them: a skill can be imported, attached to an agent and shown
  on its detail page, and its text reaches no prompt because nothing composes it into one. Skills are
  inert, not merely unprivileged. The test asserts the current state, so wiring them into context
  turns it red and the untrusted-content handling gets built at the same time rather than assumed.


## v0.1.1 — 2026-08-12

Installing v0.1.0 from Packagist into a fresh Laravel application — which nobody had done, because
every test in the suite runs against the source tree through Testbench — found the package's most
visual feature silently absent on a stock install.

### Fixed

- **`pandora:status` no longer calls the control center "enabled" when it cannot run.**
  `pandora.ui.enabled` says the control center is *wanted*; Livewire says it can *exist*. The service
  provider returns early and registers no `/pandora` route at all when the class is missing, but the
  status line read only the config flag. Following the documented path on a stock install therefore
  produced a 404 on the thing the README leads with. Three states now — `headless`, `unavailable`,
  `enabled`.
- **`pandora:install` names Livewire as its own step**, instead of closing with "Then open /pandora"
  and never mentioning it.
- **The README says plainly that the bare install is headless**, and what to add for the UI.

### Changed

- **CI retries `composer update` three times.** A single `curl error 60` fetching one zipball turned
  the whole matrix red with nothing wrong in the package. Cosmetic before; not any more, now that a
  green matrix is a required check on a branch where merging publishes.
- **The matrix reports through one check, `tests complete`.** A branch ruleset names the checks it
  requires and a matrix expands into one check per leg, so requiring them individually means editing
  the ruleset every time a database is added — and a leg added but not required is a leg nobody has
  to pass. It uses an explicit result check rather than bare `needs:`, because a skipped or cancelled
  job does not fail its dependents.

### Documentation

- `docs/development/fake-boundaries.md` gains a sixth entry: **the suite's own dependencies.**
  `livewire/livewire` is a dev dependency, so `class_exists()` is true for every test that will ever
  run and the code handling its absence is unreachable by construction — untestable by layout rather
  than untested by oversight. It is the same lesson as the two Phase 6 defects, in a place nobody had
  thought to look, and it was only findable by installing the published package.


## v0.1.0 — 2026-08-12

The first published release. Nine phases of work: a durable agent kernel, tools with approvals,
memory, automations, delegation, an MCP client and server, workspaces on object storage, channels
with a Slack reference extension, and a Livewire control center — 1,756 tests, PHPStan level 8 with
no baseline, Pint clean.

**It is `0.x` deliberately.** The public API is usable and in use; it is not yet promised. Phase 9 —
which proves every T1–T15 threat by removing its mitigation and watching the test fail — stands at
3 of 34 criteria. `docs/product/support-statement.md` names what is supported, what is excluded by
design, and what ships **known untested**. Read it before depending on this in production.

Everything below this heading is the development history that produced it, kept by phase.

### Known untested at 0.1.0

- **Two channel identities interleaving on one account in real time** (Phase 8 §5). The sequential
  case is driven against a real Slack workspace and holds; the concurrent one needed a second Slack
  account that did not exist. A defect here would be a race in session resolution, not a wrong
  isolation key.
- **T1–T15 are not yet proved by mitigation-removal.** Each threat names passing tests, and those
  tests have not all been audited against the bar Phase 9 sets for them.
- **Retrieval quality with the default embedding provider.** `HashEmbeddingProvider` is lexical, not
  semantic; the vector path is real, the semantics are not. Configure a real provider for recall.

See `docs/development/fake-boundaries.md` for the full inventory of what the test suite's fakes
structurally cannot prove.


## Phase 9 — Hardening, first pass

### Fixed

- **The MCP namespace separator falls back to `-`, not `.`.** The configured default was already `-`
  after the Phase 6 fix, but `Namespacing::separator()` fell back to `.` whenever the config value was
  an empty string or not a string — so an operator's typo silently reintroduced the exact Phase 6
  failure: every remote tool rejected by the provider with `400 Invalid 'tools[0].function.name'`, in
  a message naming neither MCP nor the tool. A fallback is a default nobody chose, so it is now held
  to the same grammar as the one they did. Found by writing the test for it, not by hitting it.

### Added

- **`Providers/FunctionNameGrammarTest`** — every tool name, and every composed remote name, is legal
  in OpenAI's and Anthropic's shared function-name grammar (`^[a-zA-Z0-9_-]{1,64}$`). Asserted against
  the names directly, sending nothing anywhere, because `FakeProvider` accepts any name a tool cares
  to have and a fake that enforced a vendor's grammar would be a vendor.
- **`Tools/ArgumentSurvivalTest`** — every property a tool advertises is a property it validates,
  unless it has explicitly declared that it carries undeclared arguments, which exactly one class in
  the system may do. Reverting the Phase 6 fix fails two of its four tests.
- **`Security/HostResolverTenancyTest`** — tenancy driven through a bound host resolver rather than
  `TenantManager::with()`. Every other tenancy test in the suite used the override path a queued job
  uses, so `NullTenantResolver::current()` was the only resolver that had ever run, and a green suite
  could not distinguish *Pandora asked and got null* from *Pandora never asked*. Closes the last
  unticked section of the Phase 7 walkthrough without needing a two-tenant host.
- **`docs/development/fake-boundaries.md`** — every fake that stands where a real system would, what
  it structurally cannot prove, and what closes the gap or that the gap is accepted. Six entries.
  Three were closed only after a defect or a walkthrough pointed at them, which is the pattern the
  inventory exists to break.

### The matrix is green again, and it cost two real bugs to get there

It had not run since channels landed. Restoring it found both of the defects below, neither of which
SQLite can express — it does not enforce a declared column width, so the two engines that do were the
only ones that could have said so.

- **A skill's slug was derived from the *unbounded* server-supplied name** and stored in a
  `varchar(191)`. The name was truncated on the way in; the slug was not, so it inherited the full
  length. All four real engines rejected it, which means a remote MCP server could stop skill
  discovery for everything behind it by choosing a long name. Truncating alone would have been worse
  in one way — two names sharing a prefix would collapse onto one slug and `updateOrCreate` would
  overwrite one with the other, letting a server retire a skill by naming a new one after it — so a
  truncated slug now carries a digest of the full one.
- **`pandora.mcp.client.max_description_length` is a dial pointed at a ceiling nobody wrote down.**
  `description` is a `text` column: 65,535 **bytes** on MySQL and MariaDB, unbounded on PostgreSQL,
  unenforced on SQLite. The config now says so.
- **The object-storage leg pinned `bitnami/minio`**, whose Docker Hub catalogue was retired, so the
  tag stopped resolving and the job died before PHP was installed. MinIO now starts as a step against
  the official image and owes nothing to a vendor's distribution policy.


## Phase 6 — MCP walkthrough fixes

Driving the MCP half of `phase-6-walkthrough.md` against real servers found that the MCP **client**
had never worked outside the test suite. Two of the entries below are that.

### Fixed

- **The remote tool namespace separator is now `-` (was `.`).** OpenAI and Anthropic both hold
  function names to `^[a-zA-Z0-9_-]+$`, so every approved remote tool made the provider answer
  `400 Invalid 'tools[0].function.name'` and the run failed with a message naming neither MCP nor
  the tool. The separator has to be reserved *and* legal in a provider's grammar; `-` is both and
  `_` is not, because core tool names are full of underscores.

  **Upgrading:** every `namespaced_name` changes, and the namespaced name is part of the schema
  hash — so the next discovery reports every remote tool as changed and revokes its approvals. That
  is correct (an approval names a specific string) and it means re-approving everything once.
- **Remote tool arguments reached the far end.** `RemoteTool` declares only `arguments` in its
  rules, and Laravel's validator returns only the keys it has rules for — but a model forms its call
  against the schema the *server* advertised, so its arguments are top-level and were stripped
  before `handle()` saw them. Every remote call arrived as `{"arguments":{}}`, succeeded, and was
  audited as allowed. `Tool::carriesUndeclaredArguments()` is false for every tool in core and true
  only for `RemoteTool`, whose arguments were never ours to declare.
- **The MCP page names the agent an approval belongs to**, instead of printing its ULID. Falls back
  to the key when an approval outlives its agent, which is a real state and still needs revoking.

### Added

- **`pandora_mcp_tools.previous_description`**, and a before/after on the MCP page. Two hashes say
  *that* a tool moved; only this says *what* moved, which is the whole question when the thing that
  moved is a sentence written by a stranger and read by a model. The page now distinguishes "its
  description changed" from "its description is unchanged, so what moved is a parameter".
- **Providers collapse to one row each.** Every connection used to render its
  whole model catalogue whether or not anybody was reading it. The closed row
  carries the scanning answer — health, credential, model count — and a
  connection that is not answering, holds no credential, or is charging against
  stale pricing opens itself and says which, because a closed row is a claim
  that nothing there needs you.
- **Approve from the MCP page**, gated on `mcp.manage` like Revoke. The page existed to make a
  change reviewable and then offered no way to act on the review. The hash is re-derived server-side
  rather than carried through the browser.

### Changed

- **The test suite no longer shadows its own configuration.** `pandora:install`
  publishes the config as well as the migrations, and `InstallationTest` runs it
  sixteen times while cleaning up only the migrations — so every full run left a
  published `config/pandora.php` in the Testbench skeleton and the next run
  silently used that snapshot instead of the package's. Nothing failed;
  `mergeConfigFrom()` merges one level deep, so a key added to the package
  quietly did not exist.
- **Paginated pages use Pandora's own paginator.** Laravel's default view is
  written for Tailwind, which this package does not ship, so `/runs` and
  `/approvals` rendered both of its blocks at once and its inline chevrons —
  sized entirely by Tailwind utilities — filled the page.
- `pandora.mcp.server.middleware` documents that it **must authenticate**. The bare `api` group in a
  stock Laravel application authenticates nobody, so `tools/list` works, every `tools/call` is
  refused, and `mcp.exposure_denied` records `reason: no actor` — which reads as a broken server and
  is an unconfigured one.


## Phase 8 — Channels and extensions (in progress)

### Added

- **A `Channel` contract, and an inbound pipeline that refuses strangers.** A message from a channel
  identity nobody has linked creates no run, no session, no conversation and no actor. It is
  recorded, audited at `warning`, and answered once with instructions. There is no guest seat: a
  session is history, cost and context, so an anonymous one is either shared between strangers (T3)
  or minted per stranger.
- **Identity linking with evidence from both sides** (ADR-0015). A short-lived, single-use code is
  issued *into the channel* — proving control of the channel account — and redeemed *inside an
  authenticated host session*, proving control of the host account. Codes are hashed at rest and
  rate-limited per identity and per redeemer. `LinkCodes::redeem()` takes the user from the guard and
  has no parameter that could name anybody else.
- **A link epoch inside the session isolation key.** Re-linking is a new boundary, never a
  restoration: a reassigned Slack handle cannot walk into the previous holder's transcript.
- **`pandora_channel_accounts`, `_identities`, `_link_codes`, `_deliveries`.** The account fixes the
  tenant and nothing in an inbound payload can change it. The unique index on
  `(account, direction, external_message_id)` makes a platform retry produce one run.
- **An undeliverable reply is a recorded failure, never re-routed.** Visible on the run and on the
  Channels page, and never sent to another channel or address — "at least it got through" is not a
  security property.
- **Approvals are announced to a channel and never resolved from one.** Replying "yes" in Slack does
  nothing, deliberately: an approval is a human authorizing a specific call with the real arguments
  in front of them.
- **`Pandora\Testing\FakeChannel`**, a first-class deliverable rather than a fixture: it delivers,
  fails, throws, and builds inbound messages including retries of one already sent.
- **Extension manifests as inert data** (ADR-0016). An `extra.pandora` block, read from Composer's
  own `installed.json` — no autoloading, no `class_exists`, no service provider — so the control
  center can describe a package that has never been booted, including one that would fatal if it
  were. A manifest describes and never grants: declared-but-unregistered simply does not exist, and
  registered-but-undeclared is shown to a person rather than blocked.
- **No marketplace, no remote install, no update check.** Excluded rather than deferred, and a
  structural test fails if a route, a command or a network call ever appears on that surface.
- **The Channels page, the Extensions page, and the agent's Channels tab** — the last replacing its
  Phase 3.5 stub. The Channels page can unlink and cannot link: an operator's belief about who owns a
  remote handle is not evidence, and a control acting on it would make an admin screen an
  authentication mechanism.
- **`pandora:channel:list` and `pandora:extension:list`.** Both read; neither has a sibling that
  writes.
- **`michal78/laravel-pandora-slack`** — the reference extension, in its own repository, depending on
  core through Composer. It needed no core change at all, and its own suite runs against real core.

### Changed

- `PendingAgentRun::viaChannel()` puts the channel and the participant into the session isolation
  key, so two people in one Slack channel are two sessions.
- The agent detail page gains a live **Channels** tab; the Phase 3.5 stub for it is gone.

### Fixed

- A published `config/pandora.php` left in the Testbench skeleton by a Phase 7 `vendor:publish` was
  shadowing the package config in the suite. `mergeConfigFrom()` merges one level deep, so its
  `features` and `abilities` arrays replaced the package's outright.


## Phase 7 — Workspaces on object storage (in progress)

### Added

- **Workspaces and context files on S3-compatible object storage** — AWS, DigitalOcean, Hetzner,
  MinIO, R2. `Workspace::disk` decides, and it is read for the first time since Phase 5 created it.
- **A storage contract with two adapters**, whose containment logic is deliberately *not* shared
  (ADR-0013). The filesystem keeps resolve-with-`realpath`-then-check; object storage normalises
  keys lexically, because it has no links to follow and no second key for the same bytes.
- **Context files behind an ETag cache with ranged reads.** They are read on every iteration of
  every run, so the naive version is a full GET per file per iteration. A root may now be written
  `disk:<name>/<prefix>`, and roots vouch only for their own kind.
- A **MinIO leg in CI**, mandatory for the same reason the pgvector leg is.
- **Workspace creation, editing and removal in the control center**, with no path field anywhere in
  it. `pandora.workspaces.roots` declares where workspaces may live — a disk and a base prefix, by
  key — and the path is composed as `<base>/<tenant>/<slug>`. A request names a key or it names
  nothing; an empty root list permits nothing rather than everything.
- **`list_files`, `read_file` and `write_file`** — the workspace tools an agent uses, over
  `WorkspaceFiles`, so containment, quota and detected-MIME matching are inherited rather than
  restated. Every refusal reaches the agent as an ordinary tool failure and the run continues.
  `read_file` is bounded and reports truncation; `write_file` is `medium` risk, so an `observe_only`
  agent may look and not write.
- **Attaching a workspace to an agent from its Workspace tab**, tenant-scoped and audited. It was
  previously writable only from code.
- **Operator uploads** from the Workspaces page, into the directory being browsed. Written through
  `WorkspaceFiles::write`, so the quota, the detected-MIME allowlist and containment apply exactly
  as they do to an agent's write; `pandora.workspaces.max_upload_bytes` bounds one request. Recorded
  as `workspace.file_uploaded` alongside the write's own entry, because "an agent wrote this" and
  "a person put this here" are different facts.
- **Streamed, audited downloads.** `/pandora/workspaces/{workspace}/download?path=…` sends the bytes
  through the application, chunk by chunk, and writes a `workspace.file_downloaded` audit entry.
- `pandora.features.workspaces` **defaults to on**, which un-defers the Workspaces page and the
  agent's **Workspace** tab.

### Changed

- A workspace's `disk` and `root_path` are **immutable after creation**. Everything else — name,
  description, quota, MIME allowlist — is editable. Re-pointing a root orphans every path already
  written and, on object storage, moves not one byte.
- Deleting a workspace removes the row and detaches its agents; **the files are left where they
  are**, and the audit entry records `files_removed: false`. A bulk delete is N calls with no
  transaction, and a partial failure leaves a half-emptied prefix under a row claiming it is gone.

### Security

- An unreachable disk is a **refusal, never a fallback** to local storage. A file written to a
  fallback lives on exactly one container while every other node reads past it, and nothing about
  that looks like an error.
- `Content-Type` on an object is never consulted. It is chosen by whoever uploaded, exactly like a
  file extension, and it looks more authoritative — MIME still comes from the magic bytes.
- Pandora stores **no object-storage credential**. A workspace names a disk the host configured.
- **No presigned URL is issued for any workspace**, and a test greps `src/` to keep it that way. A
  signed object URL is a bearer token until it expires — forwardable, logged by every proxy it
  crosses, and invisible to the audit trail the moment it is issued.
- The feature flag is checked in **every mutating action and in the download**, not only when the
  page renders. A page is where a flag gets honoured and a forged request is the one that never
  renders one.
- **No workspace tool takes a workspace argument.** The workspace comes from the agent, which holds
  at most one, so "first, write this to the finance workspace" in a document the agent is reading
  has nowhere to land — the same shape `recall` uses against the same attack.
- A download's `Content-Type` is always `application/octet-stream` with `nosniff` and a sanitised
  filename. The store's own type and the extension are both chosen by whoever wrote the file, and
  in a workspace that is a model.

## Phase 6 — Delegation and MCP (unreleased)

### Added

- **An MCP client**: servers, transports, discovery, per-agent approval, namespacing and health.
  Remote tools reach a run as ordinary tools — same validation, same policy, same execution row,
  same redaction.
- **Approval is per agent, per tool, and of a HASH** covering the remote name, the namespaced name,
  the **description** and the input schema. A server that rewrites any of them has un-approved
  itself: the approval is cleared, `mcp.schema_changed` is recorded at `warning`, and the tool fails
  closed until a human approves the new version.
- **A Pandora MCP server**, off by default, exposing only what an allowlist names and authorizing
  every call against the actor behind the token.
- **Skill discovery from MCP** — instructions only, disabled, attached to nobody (ADR-0008).
- `pandora:mcp:list`, `pandora:mcp:discover` and `pandora:mcp:approve`; the MCP page; the agent's
  **Permissions** tab.
- **`FakeMcpServer`** in `src/Testing` — a remote server that hangs, disappears, returns oversized
  bodies and rewrites its descriptions. A deliverable, not a fixture.

### Security

- **Discovery approves nothing**, for anybody, ever. There is no trusted-server flag and no
  auto-approve key: anything that both discovers and enables is a remote-controlled permission grant.
- **A remote tool can never resolve where a core tool is expected.** The namespace separator is
  reserved at registration, and resolution is split by origin rather than by comparing strings.
- **`stdio` is refused unless explicitly enabled** — it executes a local binary named by a database
  row. The command is passed as an argument list, never through a shell.
- Remote descriptions are bounded, escaped where rendered, marked as foreign, and never placed in an
  instruction position.
- A remote failure — hang, outage, oversized body, protocol error — is an ordinary tool error. The
  model is told less than the operator.
- Pandora stores **no MCP credential**: a server row names a key in the existing encrypted store.

### Fixed

- **A run with no conversation could not see its own tool loop**, so it repeated
  one call until its iteration budget ended it. Every delegated child is
  conversation-less by design, as is every autonomous trigger — schedule,
  webhook, event, console. `RunToolLoopProvider` reconstructs the loop from the
  tool execution rows for exactly those runs; a chat run is unaffected.
- **A refused call said nothing an operator could read.** A late denial kept the
  decision the row was created with, a tool that returns a failure rather than
  throwing wrote no `error_message`, and a child ending badly closed its
  parent's call with an empty error. All three now record the reason that
  applied.
- A model told `No result.` about a call refused before it ran learns nothing
  and calls again: `ToolExecution::modelContent()` now falls back to
  `decision_reason`.

### Upgrading

- **If you published `config/pandora.php` before this release**, your provider
  list predates `RunToolLoopProvider`. The package appends it when your list
  omits it, so nothing breaks and no action is required — but adding it
  explicitly keeps the file honest about what runs.

## Phase 5 — Memory and context (unreleased)

An agent that remembers is an agent that will repeat something, so the security
question changes from "may this actor do this?" to "whose was this, and who is
standing here now?" — asked at retrieval, about a fact written by someone no
longer in the room.

### Added

- **`MemoryItem`** with six scopes (global, tenant, user, agent, conversation,
  workspace), six types, provenance, confidence, sensitivity and expiry.
- **Scoped retrieval.** Scope is derived from the run's session and can never be
  named by a tool argument. The `recall` tool has one parameter and it is the
  search text.
- **Lexical retrieval needing no vector database** — portable across SQLite,
  MySQL, MariaDB and PostgreSQL with nothing installed. This is the shipped
  path, not a fallback.
- **`EmbeddingProvider` and `VectorStore` contracts**, a portable brute-force
  store, and a **pgvector adapter** with its own mandatory CI leg. A store is an
  accelerator, never an authority: everything it proposes is re-filtered by
  scope before it is returned, and an unreachable store degrades to lexical and
  records the degradation.
- **Curation.** Credentials are never stored in any status; every claim about a
  person waits for a human. Forgetting hard-deletes the vector and soft-deletes
  the row. Expiry is a retrieval predicate first and a sweep second.
- **`remember` and `recall` tools**, and `pandora:memory:sweep` / `:forget` /
  `:export`.
- **Context pipeline**: `AttributeAllowlist` (no `toArray()` path to a prompt),
  context files resolved against configured roots only, conversation
  summarisation as a stored artefact, and redaction inside `ContextBuilder`.
- **Workspaces** with path containment checked after resolution on every
  operation, quotas claimed by conditional increment, and MIME matched on the
  detected type rather than the extension — **built, and deferred to Phase 7
  behind `pandora.features.workspaces` (off).** The code and its tests stay in
  the tree; what is withheld is the way in, from everybody, including an
  operator holding every ability. Deferred because creating a workspace means
  choosing a root, and a UI field that accepts a root path accepts `/`.
- **Memory page**, and the agent's Skills and Memory tabs. The Workspaces page
  and the agent's Workspace tab say the feature is coming.

### Fixed

- A global memory written inside a tenant became permanently invisible:
  `BelongsToTenant` stamps `tenant_id` on `creating` and the guard ran on
  `saving`, one hook too early.
- PostgreSQL retrieval silently missed everything written with a capital
  letter — its `LIKE` is case-sensitive and the other three engines' are not.
- A write through a symlink escaped the workspace root, because resolving a
  path for creation checked only the parent directory.

### Changed

- **`pandora:install` runs the migrations when it is not interactive.**
  `--no-interaction` means "take the default answers", and the default answer is
  yes; it used to print "not run (non-interactive)" and exit 0 with no schema,
  leaving a scripted install no error to detect. `--no-migrate` is still the way
  to opt out, and the command now verifies the schema exists afterwards rather
  than reporting success on the strength of having called `migrate`.
- **Migrations publish under a current timestamp** via `publishesMigrations()`,
  following the application's `database.migrations.update_date_on_publish`
  setting. The packaged files are named `0001_01_01_*` so they sort among
  themselves; a host that took those names verbatim could not order its own
  migrations relative to Pandora's.
- **`pandora:agent:list` reports a run count per agent**, so the Agents page can
  be cross-checked against it.

### Security

- **The Memory page disclosed every user's memories to every user.** Reading it
  required only `pandora.access` — the ability an authenticated user holds by
  default — and the listing is filtered by memory scope and status, never by
  viewer. Reading now requires `pandora.memory.manage`, the same ability
  approving and forgetting already required, and the sidebar entry is filtered on
  it too. Found by the Phase 5 host walkthrough. Hosts that granted
  `pandora.access` broadly and `pandora.memory.manage` narrowly were affected;
  no configuration change is needed to take the fix.

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

- **Phase 4 — Automation.** Pandora can now act without a human in the moment, on a leash.
  Verified on SQLite, MySQL 8.4, MariaDB 11 and PostgreSQL 17, and driven by a human in a real
  application:
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

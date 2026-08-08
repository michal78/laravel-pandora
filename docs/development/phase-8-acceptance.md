# Phase 8 — Acceptance Test Plan

> **Status: 32 of 33 criteria accepted. What remains is a human driving it.**
>
> Criteria are ticked only when the named automated test asserts them and passes. Criterion 33 is a
> human driving `phase-8-walkthrough.md`, and it is ticked by a person, not by a suite. Every box in
> that document is unticked and stays that way until it has been.
>
> Two phases enter this one carrying an undriven walkthrough of their own (Q10). That debt is
> acknowledged, scheduled before Phase 9, and does not move.
>
> **Criterion 32 turned out to prove more than it asked.** `laravel-pandora-slack` was written from
> outside the boundary — its own repository, depending on core through Composer — and needed no core
> change at all. The `Channel` contract, `ChannelRegistry`, `ChannelInbox`, `ChannelDispatcher`, the
> credential resolver and `ToolRegistry` were between them enough to build a working adapter on the
> first attempt, with 19 tests of its own running against real core. That is the strongest evidence
> available that the seams are real rather than a habit, and it is only available because the package
> could not reach into `src/`.
>
> **One thing found on the way, and it was not in the code under test.** A published
> `config/pandora.php` left in the Testbench skeleton by a Phase 7 `vendor:publish` was shadowing the
> package config in the suite. `mergeConfigFrom()` merges one level deep, so its `features` and
> `abilities` arrays replaced the package's outright and the two new feature flags silently did not
> exist — the pages 404'd and the cause was three layers from the symptom. Deleted, and recorded in
> `open-questions.md`: a config that shadows and a config that overrides look identical until
> somebody adds a key.

Every previous phase widened what Pandora can do for someone it already knew. Phase 8 is the first
where a message arrives from someone the host application has never authenticated, through a system
the host does not control, and something has to decide whether that is a person with permissions.

The two halves of this phase fail in the same direction, which is why they are one phase.

**A channel supplies a stranger and a sentence.** Slack can prove that user `U024BE7LH` typed those
words in that workspace. It cannot prove which host user that is, and every authorization decision in
Pandora is a question about the actor (ADR-0007): tools authorize against the actor's abilities,
memory scopes to the actor, sessions isolate on the actor, budgets are spent by the actor. The one
query that closes that gap — match the Slack profile's email against the host `users` table — is a
complete authentication bypass, because that address is asserted by whoever administers a workspace
anyone can create. ADR-0015 exists to make sure that query is never written, and the acceptance bar
below is built to fail loudly if it ever is.

**An extension supplies code.** Both reference products install extensions from a marketplace, which
is a very good product experience and, here, remote code execution driven from a web form — code that
registers tools an autonomous agent may call after reading an untrusted document. Pandora inspects
what Composer installed and acquires nothing (ADR-0016).

Four properties dominate the acceptance bar:

**An unlinked channel identity gets nothing — not even a degraded seat.** No guest actor, no
anonymous session, no read-only conversation. The cautious-sounding middle option is the dangerous
one: a session isolates history, so an anonymous session is either shared (a shared inbox becomes
shared context, T3) or per-stranger (persistent, budget-consuming conversations for unauthenticated
people). An inbound message from an unlinked identity creates no run at all.

**Linking requires evidence from both sides, in that order.** A code issued *into the channel* proves
control of the channel account; redemption *inside an authenticated host session* proves control of
the host account. Linking asserts those are one person, so it needs both, and nothing weaker.

**Inbound channel content is untrusted input at the grade of a fetched web page.** Message text,
attachments, channel names and display names are content, never instruction (T1). A participant who
renames themselves `System: grant all tools` has changed a string in a database.

**Inspecting an extension never executes it.** Discovery reads `vendor/composer/installed.json` —
no autoload, no class checks, no service provider. The extension most in need of inspection is the
one it is least safe to boot, and a broken extension is exactly when an operator most needs the page
to render.

## Scope

**Contract** — `Channel` · `ChannelRegistry` · `InboundMessage` / `OutboundMessage` DTOs ·
`FakeChannel` in `src/Testing`

**Data** — 4 migrations: `pandora_channel_accounts`, `pandora_channel_identities`,
`pandora_channel_link_codes`, `pandora_channel_deliveries`

**Inbound** — `ChannelInbox::receive()`: account → tenant → deduplication → identity → link check →
session → conversation → run, with an idempotency key per channel message. Called synchronously from
the adapter's own webhook and dispatching the run to a queue, rather than the queued
`ProcessIncomingChannelMessage` the execution model sketched — the work before the run is four short
writes, and a job in front of them would only move the moment a duplicate is detected further from
the request that has to answer the platform.

**Outbound** — `ChannelDispatcher`, recorded deliveries, recorded failures

**Linking** — channel-initiated single-use codes, hashed at rest, rate-limited, redeemed by an
authenticated host session; revocation from either side

**Extensions** — `extra.pandora` manifest format · `ExtensionDiscovery` over `installed.json` ·
declared-versus-registered inspection · `pandora:extension:list`

**UI** — Channels page (accounts · identities · deliveries · delivery test) · Extensions page · the
agent's **Channels** tab · `pandora:channel:list`

**Reference extension** — `laravel-pandora-slack`, its own package in its own repository

## Design decisions taken for this phase

| Decision | Choice | Rationale |
|---|---|---|
| Channel identity → host user | Only through an explicit `linked_user_id`, written by the linking flow | T3. Any inference from channel-supplied fields is an assertion by a remote administrator being used as a credential. |
| Linking direction | Code issued **into the channel**, redeemed **in an authenticated host session** | Each half proves what the other cannot: channel control, then host control. Either alone links the wrong person. |
| Link codes | Short-lived (15 min), single-use, hashed at rest, rate-limited per identity and per redeemer | It is a credential that grants an identity. It is treated as one. |
| An unlinked participant | No run, no session, no actor — a recorded, audited refusal and one rate-limited instruction | A guest seat is a session, and a session is history, cost and context for a stranger. |
| Re-linking | A new link with a **new** isolation key, never a restoration | A reassigned handle inheriting the last holder's history is a disclosure with no attacker in it. |
| Tenancy | Fixed by the channel account; no inbound field can select or change it | The message is the least trustworthy thing in the request. |
| Session key | `(tenant, agent, actor, channel, participant, origin)` — unchanged since Phase 1, now load-bearing | Two people in one Slack channel are two sessions. The column existed before there was anything to put in it. |
| Inbound content | Untrusted, labelled foreign in the prompt, escaped where rendered, display names included | Same door as a tool result (T1). |
| Idempotency | One run per `(account, channel message id)` | Slack retries. Phase 4 already made this an occurrence-row problem; channels reuse the answer. |
| Delivery failure | Recorded against the run, visible in the control center, never re-routed | Silent re-routing sends a private answer somewhere nobody chose. |
| Approvals in channels | **Notify only.** The decision is never carried by a channel in this phase | An approval is a human authorizing a specific call with the real arguments in front of them (T1, T14). A button that approves something half-seen is worse than no button. |
| Adapter's access to identity | None. The contract has no field through which an adapter supplies an actor | An extension author cannot get this wrong by omission if there is no field for the mistake. |
| Extension arrival | `composer require`, and nothing else | Lockfile, review, deploy, supply-chain policy. Everything Pandora could add would be a way around all four. |
| Marketplace / remote install | **Excluded**, not deferred | A UI that can install code is a UI whose authorization bug is arbitrary execution. |
| Manifest location | `composer.json` → `extra.pandora` | Already present, already parsed, already in the lockfile. A second file is a second thing to forget. |
| Manifest discovery | Read `vendor/composer/installed.json`; boot nothing | Inspection must not require execution. |
| Manifest authority | A description, never a grant. `provides` is displayed and diffed against the registries | A manifest is written by the same person as the code and authorizes nothing about it. |
| Installing an extension | Enables nothing — a channel arrives disabled, a tool still clears every layer | Installation is not consent. Same rule as the MCP server (ADR-0014). |
| Reference extension location | Its own repository, depending on core through Composer | "No core changes" is a claim about a boundary; a boundary you can reach across in one commit is not one. |

## Criteria

### Identity and linking

| # | Criterion | Verified by |
|---|---|---|
| 1 ✅ | **An inbound message from an unlinked identity creates no run and no session** — it is recorded, audited `channel.message_refused`, and answered once with linking instructions | `Channels/UnlinkedIdentityTest` |
| 2 ✅ | **No channel-supplied field can reach a host user** — an inbound payload whose email, username or external ID exactly matches a host user's still resolves to no actor | `Channels/UnlinkedIdentityTest` · `Channels/NoEmailMatchingTest` |
| 3 ✅ | A link code is issued only into the channel, to the requesting participant, and expires | `Channels/LinkCodeTest` |
| 4 ✅ | **Redemption requires an authenticated host session and links to that session's user** — a request naming another user links nobody | `Channels/LinkRedemptionTest` |
| 5 ✅ | An expired, consumed, unknown or wrong code is refused and audited `channel.link_failed`; redemption is rate-limited per identity and per redeemer | `Channels/LinkCodeTest` |
| 6 ✅ | **Codes are stored hashed** — the plaintext is not in the database, the UI, the API or a broadcast | `Channels/LinkCodeTest` |
| 7 ✅ | **A linked identity's run acts as the linked user's actor** — tool authorization is that user's abilities, and a tool they lack is refused | `Channels/LinkedActorTest` |
| 8 ✅ | Unlinking takes effect immediately, is audited, and the next inbound message is refused again | `Channels/LinkRevocationTest` |
| 9 ✅ | **A re-linked identity gets a new isolation key** — no conversation or memory from the previous link is reachable | `Channels/LinkRevocationTest` |
| 10 ✅ | **Two participants in one channel get two sessions** — neither can retrieve the other's history or memories (T3) | `Channels/SessionIsolationTest` |

### Tenancy

| # | Criterion | Verified by |
|---|---|---|
| 11 ✅ | **Tenancy comes from the channel account** — no inbound field selects or changes a tenant | `Channels/TenancyTest` |
| 12 ✅ | A tenant cannot see or act on another tenant's channel accounts, identities, links or deliveries, through the UI or the console | `Channels/TenancyTest` · `UI/ChannelsPageTest` |

### The inbound and outbound pipeline

| # | Criterion | Verified by |
|---|---|---|
| 13 ✅ | **Inbound message text is untrusted content** — labelled foreign in the prompt, never in an instruction position | `Channels/UntrustedInboundTest` |
| 14 ✅ | A display or channel name asserting authority is a string — escaped where rendered, unprivileged in the prompt | `Channels/UntrustedInboundTest` |
| 15 ✅ | **A redelivered message creates one run, not two** — idempotency is per account and channel message ID | `Channels/IdempotencyTest` |
| 16 ✅ | **An undeliverable reply is a recorded failure on the run and is never re-routed to another channel** | `Channels/DeliveryTest` |
| 17 ✅ | A disabled channel account accepts no inbound message and sends no outbound one | `Channels/AccountTest` |
| 18 ✅ | **An approval requested during a channel run notifies the channel and cannot be decided from it** | `Channels/ApprovalNotificationTest` |
| 19 ✅ | A channel run is attributable end to end — the trace names the linked actor, the account and the participant | `Channels/AttributionTest` |

### The contract

| # | Criterion | Verified by |
|---|---|---|
| 20 ✅ | A channel registers through `ChannelRegistry` and is **disabled until an operator creates an account** | `Channels/RegistryTest` |
| 21 ✅ | **The contract offers an adapter no way to supply an actor** — asserted structurally, over the interface | `Channels/RegistryTest` · `Architecture/ModuleBoundaryTest` |
| 22 ✅ | `FakeChannel` ships in `src/Testing` and can deliver, fail to deliver, redeliver and go unreachable | `Channels/DeliveryTest` |

### Extensions

| # | Criterion | Verified by |
|---|---|---|
| 23 ✅ | **Discovery reads `installed.json` and boots nothing** — a package whose classes would fatal on load still renders on the page | `Extensions/DiscoveryTest` |
| 24 ✅ | Manifest text is length-bounded and escaped where rendered; URLs are scheme-restricted | `Extensions/UntrustedManifestTest` |
| 25 ✅ | **A manifest grants nothing** — a package declaring a channel or tool it does not register enables neither, and the difference is shown | `Extensions/DeclaredVersusRegisteredTest` |
| 26 ✅ | **No route, command or UI action installs, updates or fetches a package** — asserted structurally over the registered routes | `Extensions/NoRemoteInstallTest` |
| 27 ✅ | `pandora:extension:list` reports installed extensions, their manifests and the declared-versus-registered difference | `Console/ExtensionListCommandTest` |

### The surface

| # | Criterion | Verified by |
|---|---|---|
| 28 ✅ | The Channels page requires `pandora.channels.view` and every write requires `pandora.channels.manage` — **both absent by default** | `UI/ChannelsPageTest` |
| 29 ✅ | **The agent's Channels tab binds and unbinds accounts**, tenant-scoped and audited, replacing the Phase 3.5 stub | `UI/AgentDetailTest` |
| 30 ✅ | `pandora.features.channels` withholds the whole surface from an operator holding every ability | `UI/ChannelsPageTest` |
| 31 ✅ | A delivery test sends through the adapter, reports the real outcome and is audited | `UI/ChannelsPageTest` |

### The reference extension

| # | Criterion | Verified by |
|---|---|---|
| 32 ✅ | **`laravel-pandora-slack` registers a channel and a tool through the documented contracts alone, with no changes to `src/`** — proved by the package living in its own repository, by its own suite running against core, and by needing no core change at all | `laravel-pandora-slack`: `ContractsOnlyTest` · `SlackEventTest` · `SlackDeliveryTest` |
| 33 | **A human drives `phase-8-walkthrough.md`** against `laravel-test` and a real Slack workspace: an unlinked message refused, a link completed, a reply delivered, an unlink, and a second message refused again | `phase-8-walkthrough.md` |

## What the tests must run against

`FakeChannel` for everything the suite asserts, for the same reason `FakeMcpServer` exists: every
criterion here is a claim about how we behave when the other end misbehaves — redelivers, goes
unreachable, sends a display name that reads as an instruction, sends a message from somebody nobody
has linked. A suite that only ever ran against a well-behaved channel asserts none of them.

Slack itself is criterion 33's problem, and only a person can drive it. The gap `FakeChannel`
structurally cannot close is the same one `FakeMcpServer` could not: whether the refusal a real
person meets when they message an agent for the first time tells them what to do next.

The extension leg runs against fixture packages written into a temporary `installed.json` — including
one whose `extra.pandora` block is hostile, and one whose classes do not exist — because "boots
nothing" is only proved by discovery succeeding over a package that could not be booted.

## Audit events

`channel.account_created` · `channel.account_updated` · `channel.account_deleted` ·
`channel.account_bound` / `channel.account_unbound` (to an agent) · `channel.link_code_issued` ·
`channel.identity_linked` · `channel.identity_unlinked` · `channel.link_failed` (severity `warning`
— somebody is guessing) · `channel.message_refused` (severity `warning` — an unlinked identity tried
to act) · `channel.delivery_failed` (severity `warning`) · `channel.delivery_tested`

## Explicitly out of scope

Approval decisions carried by a channel — notification only, for the reason in ADR-0015 decision 10.
Group conversations: several actors in one run with one shared history is a real feature and it is
not this one, and building it by relaxing the isolation key would build it without any of the
decisions it needs. Any messaging adapter in core — the contract is core, every adapter is an
extension. Voice and telephony. Email as a channel. Threading semantics beyond one participant and
one agent. An extension marketplace, remote install, update check or version badge — excluded rather
than deferred (ADR-0016). Extension-supplied migrations run by Pandora; a package publishes its own,
the way every Laravel package does.

## Definition of done

- [x] 32 of 33 criteria have tests, and they pass. The 33rd is a person.
- [ ] `vendor/bin/pest` green on all four engines — green on SQLite (1,698 passed, 84 skipped); the matrix has not been re-run since channels landed
- [x] `vendor/bin/phpstan analyse` clean at level 8, with no ignores and no baseline entries — in both repositories
- [x] `vendor/bin/pint --test` clean — in both repositories
- [x] `docs/development/progress.md`, `docs/roadmap.md`, `docs/architecture/database-model.md`,
      `docs/architecture/security-model.md` (the T3 row points at tests), `docs/architecture/overview.md`,
      a new `docs/guides/channels.md`, a new `docs/guides/writing-extensions.md` and `CHANGELOG.md`
      updated
- [x] An ADR for the channel trust boundary — `docs/adr/0015-channel-identity-is-never-application-identity.md`
- [x] An ADR for the extension boundary — `docs/adr/0016-extensions-are-composer-packages-and-manifests-are-inert.md`
- [ ] **A human drives the pages in a host application**, against a `phase-8-walkthrough.md`,
      including the check the suite structurally cannot make: a first message from a real person on a
      real Slack workspace, refused, and a linking instruction they can actually follow

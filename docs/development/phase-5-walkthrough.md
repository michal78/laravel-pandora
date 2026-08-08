# Phase 5 — Host Walkthrough

> Status: **driven 2026-08-07 — the memory half in full, and it found four
> defects.** Three were found and fixed on the first pass (below); the fourth
> came from the second, and is the reason reading the Memory page now needs
> `pandora.memory.manage`.
>
> Two sections are deliberately not driven and are **not** failures: the
> vector-store checks need Postgres, and `laravel-test` is MySQL — that leg runs
> in CI on `pgvector/pg17`; and the workspace checks past *coming soon* belong
> to Phase 7, whose features are not implemented, so the flag stays off.

Phase 4 produced seven defects and not one was reachable by the package suite
as configured. Three of those came from this walkthrough — a different date
class, a real browser, a second `curl`. The suite is good at what it was told
to check and blind to configurations it was never run under.

Run against `laravel-test`, or any host application, with
`PANDORA_UI_ENABLED=true` and the abilities granted (see
`docs/guides/automations.md` for the `AppServiceProvider` pattern —
Phase 5 needs `pandora.memory.manage` and `pandora.workspaces.access`).

## Before you start

Five things, none of which the package can do for a host application. They are
listed because the first run of this walkthrough hit every one of them:

- [x] `vendor:publish --tag=pandora-migrations` **again**, then `migrate`.
      Pandora's migrations are published, never loaded from the package, so a
      host that installed at Phase 4 has none of the five Phase 5 tables and
      `migrate` will cheerfully report nothing to do.
- [x] `vendor:publish --tag=pandora-config --force`. A config file published
      before this phase has no `memory` section and an older `context` section;
      `mergeConfigFrom` restores the missing top-level key but will *not* fill
      in the new nested `context.files` and `context.summarisation` keys.
      Re-apply any local edits — for `laravel-test` that is one agent class in
      `agents.definitions`.
- [x] Define `pandora.memory.manage` and `pandora.workspaces.access`.
- [x] Give an agent the memory tools: `->tools(['remember', 'recall'])`. An
      empty allowlist means no tools, so an agent that has never been given
      them cannot remember anything and will say so in a way that reads like a
      bug.
- [x] Run a queue worker. Every run is a queued continuation; without one the
      chat page waits forever and nothing in this document can be checked.

### Prepared on 2026-08-07

`laravel-test` was brought up and staged for this run — Sail (Laravel 13.19,
PHP 8.5, MySQL 8.4, Redis), queue worker up, real `gpt-4o-mini`, at
<http://localhost:8080/pandora/memory>.

- [x] **Memory is empty.** Five items left over from earlier sessions were
      deleted, along with the one embedding. Two of them were conversation-scoped
      and already pointed at conversations that no longer existed. The first
      memory check reads *"with no memory at all"*, and it cannot be driven
      against a page that already has some.
- [x] **`EchoAgent` holds `remember` and `recall`**, along with the other nine
      built-ins, so the memory checks fail for a real reason if they fail.
- [x] **Two skills are attached to `EchoAgent`**, because the Skills tab check
      needs both halves of its claim visible at once:
      `support-desk-triage` requires `ask_user` and `recall`, both of which the
      agent holds; `incident-file-review` requires `read_file` and `write_file`,
      which no agent can call — those are Phase 7 workspace tools and are not in
      the registry at all. The second is what makes the red badges and *"this
      agent cannot call the tools in red"* appear.
- [x] **A context file is wired up and proven.** `pandora.context.files.roots`
      points at `storage/app/pandora-context`, which holds `handbook.md`, and
      `EchoAgent`'s `metadata.context_files` names it by absolute path —
      `ContextFiles::resolve()` runs `realpath()`, so a relative path resolves
      against the working directory and is refused. Verified live: asked for the
      escalation codeword, the agent answered *saltmarsh*, which appears nowhere
      but that file. The `/etc/passwd` half of that check is left for you to add.
- [x] **Both accounts reach the Phase 5 surfaces.** `/pandora/memory`,
      `/pandora/workspaces`, `/pandora/chat` and the agent's Skills and Memory
      tabs all return 200 for user 1 and user 2.

Two sections cannot be driven on this host, and neither is a defect:

- **Vector store.** `laravel-test` is MySQL, so `pandora.memory.vector_store` is
  null and embeddings come from `HashEmbeddingProvider`. The pgvector leg is
  covered in CI instead.
- **Workspaces past the "coming soon" checks.** `pandora.features.workspaces` is
  `false` here, which is what the first three checks in that section assert. The
  Phase 7 checks below them need the flag flipped.

## Memory

- [x] The **Memory** item appears in the sidebar and the page opens.
- [x] With no memory at all, the page explains where memory comes from rather
      than showing an empty table.
- [x] Ask an agent (with `remember` allowed) to remember something about how it
      works. It reports "Remembered."
- [x] That memory appears on the page as **Active**, scoped to the agent.
- [x] In a new conversation, ask the agent about it. It recalls it.
- [x] Ask it to remember something about *you* — a preference. It reports that
      the memory is held for approval and says not to rely on recalling it.
- [x] The memory appears under **Awaiting review**, and the page says plainly
      that no agent can read it yet.
- [x] Ask the agent about it in a new conversation. **It does not know.**
      *(This is the check the suite cannot make: a real session, a real
      browser, a real model, and the fact stays invisible.)*
- [x] Approve it. Ask again. Now it knows.
- [x] Forget it. Ask again. It does not know, and does not half-know.
- [x] Ask an agent to remember a password or an API key. It refuses, and
      nothing appears on the Memory page in any status.
- [x] The audit log shows `memory.suggested`, `memory.approved`,
      `memory.forgotten` and `memory.refused`.

## Memory, from a second account

- [x] As a second user, in a fresh session, ask the agent about the *first*
      user's approved personal memory. **It does not know.**
- [x] Paste an instruction into the chat telling the agent to recall everything
      about the other user, naming their id. It still does not know.
- [x] Open `/pandora/memory` as user 2 and look for user 1's personal memory.
      **Defect 4, fixed** — see below. It was there, and it should not have been.

## Workspaces — released in Phase 7

**Moved.** The checks live in `phase-7-walkthrough.md`, along with the object
storage, the creation form and the streamed download that did not exist when
this document was written.

What was true here and stayed true: the surface shipped disabled behind
`pandora.features.workspaces`, and while it was false nothing reached past it —
the sidebar said *Coming soon* and did not link, `/pandora/workspaces` named no
workspace even for an operator holding every ability, and the agent's
**Workspace** tab said the same. Those three were driven and passed.

The flag now defaults to on, so the same three checks are worth running once
more in their new position: with the flag explicitly false. They are the last
section of the Phase 7 walkthrough.

## The agent's tabs

- [x] **Memory** tab shows what that agent has written, and nothing belonging to
      a person.
- [x] **Workspace** tab says the feature is coming in a later phase (Phase 7,
      above). With the flag on it shows the workspace, or says plainly that an
      agent without one can reach no files.
- [x] **Skills** tab lists attached skills and flags required tools the agent
      cannot actually call.

## Context files

- [x] Configure `pandora.context.files.roots`, put a file in it, name it in an
      agent's `metadata.context_files`. The agent can quote it.
- [x] Point one entry at `/etc/passwd`. The run still works and that file is
      simply absent from the answer.

## Vector store, if you have Postgres

- [ ] Set `PANDORA_VECTOR_STORE=pgvector`, run the migrations, re-embed.
- [ ] Retrieval still works and results are still correctly scoped.
- [ ] Stop the database mid-conversation. The agent answers with worse recall
      rather than failing, and `memory.retrieval_degraded` is recorded.

## Defects found

**1. A parameterless tool made every tool unusable.** (`ProviderRejectedRequest`:
*Invalid schema for function 'inspect_run_status': [] is not of type 'object'.*)

`RuleSchemaGenerator` emits `'properties' => []` for a tool that takes no
arguments, which is correct PHP and, once encoded, the wrong JSON: PHP cannot
tell an empty map from an empty list and `json_encode` resolves it as `[]`.
OpenAI validates every tool in the request and rejects the whole call, so one
parameterless tool disabled the other ten — including `remember`, which is why
this surfaced on the first memory check rather than as a tool bug.

The suite could not see it. `ToolSerializationTest` asserted against
`$request['tools']`, and decoding `{}` yields `[]`, so the assertion agreed
with the bug. `FakeProvider` validates nothing. The fix converts the schema
once, in `ToolDefinition::encodableSchema()`, rather than in each of the three
adapters that pass it to `json_encode`; the new tests read the encoded body,
and all five fail without it.

**2. `remember` was refused to everybody.** (*You are not authorized to use
[remember].*)

`Tool::authorize()` grants nothing above `RiskLevel::Low`. That is the right
default for a tool somebody else writes and the wrong one to inherit by
accident: `RememberTool` declares `Medium` — deliberately, since writing a
durable claim about a person is not a low-risk act — and never overrode it. The
result was not a tool restricted to privileged callers but one refused to every
caller, including the person whose own memory it was. No allowlist, gate or
ability could have opened it.

The suite could not see this one either, and for the same reason as the first:
every test in `MemoryToolsTest` calls `handle()` directly, so it exercised what
the write does while never asking whether the write is reachable. `handle()` is
never reached when `authorize()` refuses.

The fix gives `RememberTool` the actor-presence check its sibling's docblock
already implied — `recall` notes it is "available to a system actor, unlike a
write". Alongside it, a sweep in `BuiltInToolsTest` asserts that no built-in
above `Low` risk inherits `authorize()` from the base class, which is the
invariant that was silently violated rather than the single instance of it.

**3. Answering a question started a rival run.** (Header stuck on *Waiting for
you* over a conversation that had moved on.)

`ask_user` parks its run at `waiting_for_user` holding no job; `Pandora::reply()`
is what resumes it, and it is the only thing that does. `Chat::send()` never
called it — every message went to `AgentRunner->dispatch()`, so answering a
question started a second run beside the parked one rather than feeding it.

The parked run was then unreachable. Nothing would ever resume it, so it never
reached a terminal state, so `activeRun()` — which takes the latest non-terminal
run — kept returning it in preference to the runs that actually completed. The
header rendered that orphan's state, which is why "Waiting for you" and
"Working" appeared together and why answering never cleared it. The visible
symptom was a stale badge; the real one was a run leaked per question asked.

No test covered the composer against a parked run: the chat tests either send
into a fresh conversation or assert on rendering, and `Pandora::reply()` has its
own tests that never go through the UI. The gap was the seam between them.

**4. Anybody who could open the control center could read everybody's
memories.** (No error, no symptom — the page simply showed them.)

`MemoryIndex::mount()` authorized `pandora.access`, the ability every
authenticated user holds by default, and the listing is filtered by scope and by
status but never by actor. So a user with nothing but chat could read every
user-scoped memory belonging to every person on the installation, sensitive ones
included — a preference, a home address, whatever an agent had been told and had
written down.

Every ability around it was right, which is how it survived. Approving,
rejecting, forgetting and exporting all require `pandora.memory.manage`, and
`MemoryCurator` re-checks it. Only *reading* was left on `access`, and reading
is the part that discloses. `AgentDetail::memoriesFor()`'s docblock had even
described the intended rule correctly — user-scoped memory lives on the Memory
page "behind `pandora.memory.manage`" — while the page it described did not
implement it.

The fix makes the whole page an operator surface: `mount()` authorizes
`memory.manage`, and the sidebar entry is filtered on the same ability rather
than on `access`. Not a per-viewer filter, because this is a review queue and
not a place someone reads their own memory back — an admin page has no "who is
standing here" to bound a listing by, which is the same reason the agent's
Memory tab shows agent-scoped rows only.

The suite could not see it because no test ever asked a *less* privileged user
to read. `MemoryPageTest` granted `memory.manage` in `beforeEach` and then only
withdrew it to check that the buttons disappeared and a forged approval was
refused — both of which passed, honestly, while the disclosure sat underneath
them. Criterion 28 covers cross-*tenant* reads and there is no criterion for
cross-*user* ones. The three new tests fail without the fix.

## Notes

Anything found here goes in `progress.md` with the same honesty Phase 4 used:
what broke, why the suite could not see it, and what changed as a result.

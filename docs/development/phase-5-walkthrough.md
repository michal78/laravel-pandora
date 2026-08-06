# Phase 5 — Host Walkthrough

> Status: **staged, not yet driven.** Phase 5 is code-complete and every
> acceptance criterion has a passing test; this is the part the suite
> structurally cannot do. The host application is prepared (see *Before you
> start*) and the checks below are waiting on a person and a browser.

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

## Memory

- [ ] The **Memory** item appears in the sidebar and the page opens.
- [ ] With no memory at all, the page explains where memory comes from rather
      than showing an empty table.
- [ ] Ask an agent (with `remember` allowed) to remember something about how it
      works. It reports "Remembered."
- [ ] That memory appears on the page as **Active**, scoped to the agent.
- [ ] In a new conversation, ask the agent about it. It recalls it.
- [ ] Ask it to remember something about *you* — a preference. It reports that
      the memory is held for approval and says not to rely on recalling it.
- [ ] The memory appears under **Awaiting review**, and the page says plainly
      that no agent can read it yet.
- [ ] Ask the agent about it in a new conversation. **It does not know.**
      *(This is the check the suite cannot make: a real session, a real
      browser, a real model, and the fact stays invisible.)*
- [ ] Approve it. Ask again. Now it knows.
- [ ] Forget it. Ask again. It does not know, and does not half-know.
- [ ] Ask an agent to remember a password or an API key. It refuses, and
      nothing appears on the Memory page in any status.
- [ ] The audit log shows `memory.suggested`, `memory.approved`,
      `memory.forgotten` and `memory.refused`.

## Memory, from a second account

- [ ] As a second user, in a fresh session, ask the agent about the *first*
      user's approved personal memory. **It does not know.**
- [ ] Paste an instruction into the chat telling the agent to recall everything
      about the other user, naming their id. It still does not know.

## Workspaces

- [ ] Create a workspace pointing at a real directory, attach it to an agent.
- [ ] The **Workspaces** page lists it with its usage and quota.
- [ ] Browsing shows the files that are there, and descending into a folder works.
- [ ] `ln -s /etc/passwd <root>/innocent.txt`, then refresh. **The symlink does
      not appear in the listing**, and the audit log records a
      `workspace.containment_violation` at `critical` if anything reads it.
- [ ] Set a small quota, have the agent write past it. The write is refused and
      the file does not appear on disk.
- [ ] Change `used_bytes` by hand, press **Recount**, and it corrects itself.

## The agent's tabs

- [ ] **Memory** tab shows what that agent has written, and nothing belonging to
      a person.
- [ ] **Workspace** tab shows the workspace, or says plainly that an agent
      without one can reach no files.
- [ ] **Skills** tab lists attached skills and flags required tools the agent
      cannot actually call.

## Context files

- [ ] Configure `pandora.context.files.roots`, put a file in it, name it in an
      agent's `metadata.context_files`. The agent can quote it.
- [ ] Point one entry at `/etc/passwd`. The run still works and that file is
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

## Notes

Anything found here goes in `progress.md` with the same honesty Phase 4 used:
what broke, why the suite could not see it, and what changed as a result.

# Phase 5 — Host Walkthrough

> Status: **not yet run.** Phase 5 is code-complete and every acceptance
> criterion has a passing test; this is the part the suite structurally cannot
> do.

Phase 4 produced seven defects and not one was reachable by the package suite
as configured. Three of those came from this walkthrough — a different date
class, a real browser, a second `curl`. The suite is good at what it was told
to check and blind to configurations it was never run under.

Run against `laravel-test`, or any host application, with
`PANDORA_UI_ENABLED=true` and the abilities granted (see
`docs/guides/automations.md` for the `AppServiceProvider` pattern —
Phase 5 needs `pandora.memory.manage` and `pandora.workspaces.access`).

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

## Notes

Anything found here goes in `progress.md` with the same honesty Phase 4 used:
what broke, why the suite could not see it, and what changed as a result.

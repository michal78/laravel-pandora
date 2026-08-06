# Phase 7 — Acceptance Test Plan

> **Status: built, not released. 0 of 6 criteria accepted.**
>
> Unusually for a phase plan, most of the code already exists. It was written during Phase 5 and
> deferred before release, so the criteria below are ticked when the surface ships enabled and a
> human has driven it — not when the tests pass, which they already do.

Phase 5 built agent file workspaces and then declined to release them. The engine is finished:
containment, quotas and MIME detection are implemented and covered, and their tests run on every
commit. What is missing is the part that is hardest to take back — a way in.

That is the whole reason for the deferral. A workspace is a directory an agent may read and write
inside, and every guarantee about it reduces to one question: who chose the root? Phase 5 answered
"an operator, in code", which is correct and also the reason the walkthrough stalled — there is no
way to create a workspace from the control center, and the obvious fix is a form with a path field.
A form with a path field is a form that accepts `/`. Getting that wrong is not a bug that shows up
in a test; it is arbitrary filesystem access granted through the UI to anyone holding
`pandora.workspaces.access`.

So the phase exists to answer the question properly rather than quickly.

Three properties dominate the acceptance bar:

**A root is chosen by configuration, never by a request.** Whatever creation path the UI eventually
offers, the set of permissible roots is declared where an operator declares things — in config, in
the deployment — and the UI selects from it. A field that accepts an arbitrary absolute path is not
a narrower version of this; it is the thing this property exists to forbid.

**A feature held back is held back for everybody.** `pandora.features.workspaces` is not an
ability. No gate, no operator flag and no tenant configuration reaches past it while it is false,
because a flag that a sufficiently privileged user can talk their way around is a flag that is on.

**Deferral does not un-test the code.** The Phase 5 tests stay green throughout. A feature that is
deleted and rewritten a phase later arrives untested and claims to be proven; this one stays in the
tree, keeps running, and turns on.

## Scope

`pandora.features.workspaces` enabled by default · a root-selection mechanism that does not accept
free-text paths · workspace creation and editing in the control center · the Workspaces page
un-deferred · the agent's **Workspace** tab un-deferred · the Phase 5 walkthrough's workspace
section driven by a human.

Out of scope: changes to `WorkspaceFiles` containment, quota or MIME behaviour. That code is
finished, and a phase that reopens it is a phase that has found a bug rather than shipped a feature.

## Design decisions carried in from Phase 5

| Decision | Choice | Rationale |
|---|---|---|
| Containment | `realpath()` then prefix check, on **every** operation | A check at open time and a use at write time is a TOCTOU window a symlink fits through. |
| Quota | Reserved before the write, reconciled after | Checking `used_bytes` then writing is the same race as Phase 4's `last_run_at` check, with the same fix. |
| MIME | Matched on the **detected** type via `finfo`, never the extension | An extension is an assertion by whoever named the file, and in a workspace that whoever is a model. |
| Empty MIME allowlist | Permits everything | A MIME list narrows what may enter an already-bounded workspace. An operator who set none has not implicitly banned everything — unlike a root list, which fails closed. |
| Browsing | The control center reads through `WorkspaceFiles`, subject to the same containment as an agent | A page that could show a file an agent cannot read is a way to confirm what lives outside the root. |

## Design decisions this phase must take

| Question | Why it is open |
|---|---|
| How a root is chosen in the UI | The deferral exists for this. Candidates: a configured allowlist of permitted parent directories that the UI selects from; a single configured base under which named subdirectories are created; creation left in code with the UI offering only edit. |
| Whether a workspace may be edited after creation | Changing a root re-points every path already written. Probably a new workspace rather than an edited one. |
| Whether deletion removes files | Almost certainly not — detaching a workspace should not be a way to delete a directory by accident. |

## Acceptance criteria

| # | Criterion | Verified by |
|---|---|---|
| 1 | A workspace confines reads and writes to its root — **traversal and symlink escape both fail** | `Workspaces/ContainmentTest` (Phase 5 criterion 25) |
| 2 | A write exceeding the quota is refused before it lands, and `used_bytes` stays accurate under concurrent writes | `Workspaces/QuotaTest` (Phase 5 criterion 26) |
| 3 | A disallowed MIME type is refused on detected type, not on the claimed extension | `Workspaces/MimeTest` (Phase 5 criterion 27) |
| 4 | **A tenant cannot see, read, write or export another tenant's workspace through the UI** | The workspace half of Phase 5 criterion 28 |
| 5 | **A root outside the configured permissible set is refused, whatever the UI submits** | New — the decision this phase exists to take |
| 6 | The feature flag withholds the surface from an operator holding every ability | `UI/WorkspacesPageTest` |

## Notes

The walkthrough section for workspaces stays in `phase-5-walkthrough.md` until this phase runs, and
is driven then rather than now. The host application created a workspace in code during the Phase 5
walkthrough (`storage/app/pandora-workspace`); it is harmless with the flag off, since nothing
reaches it.

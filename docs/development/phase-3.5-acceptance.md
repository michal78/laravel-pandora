# Phase 3.5 — Acceptance Test Plan

> **Status as of 2026-08-05: 20 of 20 criteria verified.**
>
> ```
> vendor/bin/pest        -> Tests: 763 passed (2,640 assertions)
> vendor/bin/phpstan     -> [OK] No errors  (level 8, checkModelProperties on)
> vendor/bin/pint --test -> passed
> ```
>
> Nothing below is ticked on the strength of code existing; each criterion is ticked only when the
> named automated test asserts it and that test passes.

A late insertion, and an admission: `docs/architecture/overview.md` specifies sixteen control-center
page groups, `Agents` is one of them, and no phase had claimed it. Phase 1 deferred "the remaining 14
UI page groups" and Phases 4–7 each name only their own. The entity the product is named for was on
course to reach Phase 8 with `pandora:agent:list` as the only way to look at one.

Phase 4 is where that stops being untidy and starts being incoherent: every automation binds to an
agent and inherits its autonomy level and budgets, so an Automations editor whose agent picker points
at rows nobody can open would have dragged half this page into Phase 4 unplanned.

One property dominates the acceptance bar:

**An edit that cannot survive must not appear to succeed.** `definition_class` is nullable, so this
page serves two kinds of agent, and a class definition is authoritative for the fields it sets. An
editor that accepted a change to one of those fields would produce a setting that looks saved until
the next deploy silently reverts it — reported months later as "Pandora lost my settings", with
nothing in the logs to explain it. So the write is **refused**, loudly, naming the class.

## Scope

`AgentsIndex` with source, model, autonomy, status and run counts · `AgentDetail` with six live tabs
(Overview · Instructions · Models · Limits & Autonomy · Runs · Usage) · create and edit for
database-defined agents behind `pandora.agents.manage` · class-managed fields rendered read-only ·
`AgentRegistry::managedKeysFor()` and `definitionIsInstalled()` · audited create, update and delete ·
seven stub tabs naming the phase that fills each · sidebar entry · `pd-tabs` / `pd-locked` styles.

## Design decisions taken for this phase

| Decision | Choice | Rationale |
|---|---|---|
| Class-managed fields | Rendered as facts (`pd-locked`), and a write to one is refused | A disabled input says "broken". A stated value naming its class says where to change it. |
| Refusal granularity | The **whole save** is refused, not the offending field | A partial save shows the operator their incidental change accepted and the one they cared about silently missing. |
| Which keys are managed | `AgentBlueprint::managedKeys()` plus `name` and `slug` | `syncDefinition()` writes `name` unconditionally, and the slug is the identity the definition is matched by — editing it would orphan the row and mint a duplicate. |
| An orphaned definition | Every field becomes editable again | A row frozen by a class that no longer exists is owned by nothing and editable by no one. |
| Saving | Per tab, not per page | A form that submits every attribute makes the audit entry useless: every save looks like a change to everything. |
| New agents | Disabled, `observe_only`, no tools | An agent that could act the moment it was named turns a typo into an incident. |
| Creating class-defined agents | Not possible here | A definition lives in the host's version control; inventing one from a web form produces a row the next deploy has never heard of. |
| Deleting class-defined agents | Refused | The next registry sync would recreate it, so the button would appear to work and then undo itself. |
| Instructions | Behind `pandora.prompts.view`, for read *and* write | A prompt is the most quietly sensitive thing on the page, and you cannot safely edit what you are not allowed to read. |
| Index freshness | `AgentRegistry::all()` syncs on read | Otherwise the page insists a newly deployed agent does not exist. |
| Unbuilt tabs | Shown, naming their phase | An operator who cannot find where tools are granted should learn the page is coming, not conclude agents cannot be granted tools. |

## Criteria

| # | Criterion | Verified by |
|---|---|---|
| 1 | ✅ The index lists every agent with its source, model, autonomy level and status | `UI/AgentsIndexTest` |
| 2 | ✅ The index is denied to a user without `pandora.access` | `UI/AgentsIndexTest` |
| 3 | ✅ A class definition deployed since the last visit appears without a manual sync | `UI/AgentsIndexTest` |
| 4 | ✅ Source and search filters select the expected subset | `UI/AgentsIndexTest` |
| 5 | ✅ Each agent's run count is reported | `UI/AgentsIndexTest` |
| 6 | ✅ **`pandora.agents.manage` is denied on a fresh installation** | `UI/NavigationTest` |
| 7 | ✅ Without `pandora.agents.manage` the page offers no create control, and a forged create is refused | `UI/AgentsIndexTest` |
| 8 | ✅ **A forged save from a user without `pandora.agents.manage` is refused and changes nothing** | `UI/AgentDetailTest` |
| 9 | ✅ Creating produces a database-defined agent that is disabled, `observe_only`, with no tools, and an `agent.created` audit entry | `UI/AgentsIndexTest` |
| 10 | ✅ A second agent of the same name gets a distinct slug | `UI/AgentsIndexTest` |
| 11 | ✅ An edit to a database-defined agent saves, and is audited with the tab, the changed keys, and before and after values | `UI/AgentDetailTest` |
| 12 | ✅ A save that changes nothing writes no audit entry | `UI/AgentDetailTest` |
| 13 | ✅ Limits are validated at both bounds, and autonomy accepts only the four enum values | `UI/AgentDetailTest` |
| 14 | ✅ `managedKeysFor()` returns exactly the blueprint's keys plus `name` and `slug`, and nothing for a database-defined agent | `UI/AgentDetailTest` |
| 15 | ✅ A class-managed field renders as a stated value, not an input | `UI/AgentDetailTest` |
| 16 | ✅ **A write to a class-managed field is refused, saves nothing, and names the class that owns it** | `UI/AgentDetailTest` |
| 17 | ✅ **The refusal rejects the whole save, not the offending field alone** — an incidental change in the same submission is not applied | `UI/AgentDetailTest` |
| 18 | ✅ A field the definition leaves unset stays editable, and an orphaned definition unlocks every field | `UI/AgentDetailTest` |
| 19 | ✅ Instructions are hidden without `pandora.prompts.view`, and a forged save of them is refused | `UI/AgentDetailTest` |
| 20 | ✅ **A tenant cannot see, open, edit or delete another tenant's agent — the detail page answers 404, not 403** | `UI/AgentDetailTest` |

Supporting behaviour asserted by the same two files, not counted separately: runs and usage are scoped to
the agent, cost is hidden without `pandora.costs.view`, a database-defined agent soft-deletes with a
`warning`-severity audit entry, a class-defined one refuses to delete, the sidebar reaches both
pages over HTTP, an unknown slug answers 404, and each unbuilt tab names its phase.

## Audit actions this phase must produce

`agent.created` · `agent.updated` · `agent.deleted`

`agent.updated` carries the tab, the changed attribute names, and both before and after values —
which is what makes it possible to answer "who turned autonomy up, and from what". `agent.deleted`
is recorded at `warning` severity. Instructions pass through the same `Redactor` as every other
audit payload.

## Explicitly out of scope

The seven tabs whose subsystems do not exist yet: Tools, Skills, Memory, Channels, Automations,
Workspace, Permissions. Each is a stub naming its phase, and each owning phase now carries one UI
line item rather than Phase 8 inheriting all seven at once.

Also out of scope: editing `tool_policy` (the storage and enforcement are Phase 2 and work today, but
a policy editor wants the tool registry beside it), agent duplication, import/export, and avatars.

## Definition of done

- [x] All 20 criteria have tests, and they pass
- [x] `vendor/bin/pest` green — 763 passed, 2,640 assertions
- [x] `vendor/bin/phpstan analyse` clean at level 8
- [x] `vendor/bin/pint --test` clean
- [x] `docs/development/progress.md`, `docs/roadmap.md`, `docs/architecture/overview.md` and
      `CHANGELOG.md` updated
- [ ] **A human drives the page in a host application** — the same walkthrough item still open for
      Phases 1 and 2 (Q9). Every assertion here is a Livewire test; nobody has yet clicked Edit in a
      browser against a real deployment.

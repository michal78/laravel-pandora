# Phase 3.5 — Host Walkthrough

> Status: **staged, not yet driven.** All 20 acceptance criteria in
> `phase-3.5-acceptance.md` are verified by automated test. Nobody has clicked
> Edit in a browser against a real deployment.

One property dominates this page and therefore this walkthrough:

**An edit that cannot survive must not appear to succeed.**

`laravel-test` is the right host to prove it on, because it has both kinds of
agent in play: `EchoAgent` is class-defined and authoritative for the fields it
sets, and any agent created through the page is database-defined and fully
editable. The interesting checks are all on the first kind.

Run as **user 1** (an operator, holding `pandora.agents.manage`) unless a check
says otherwise.

## The index

- [ ] **Agents** appears in the sidebar and the page opens.
- [ ] Every agent is listed with its source (class or database), model, autonomy
      level and enabled status.
- [ ] A class-defined agent is visibly distinguished from a database-defined one
      without having to open it.
- [ ] Run counts are present and match reality — cross-check one against
      `pandora:agent:list`.
- [ ] The source filter and the search box each select the expected subset.
- [ ] As **user 2** (no `pandora.agents.manage`), the page opens but offers
      **no create control**.

## A class-defined agent — the load-bearing half

Open `EchoAgent`.

- [ ] The fields its definition sets are shown as **facts, not disabled inputs**
      — a value with the owning class named beside it.
- [ ] The class is named precisely enough to go and edit it: `App\Agents\EchoAgent`.
- [ ] Fields the definition leaves unset are still editable.
- [ ] Editing one of those and saving works, and the value survives a reload.
- [ ] There is no control anywhere on the page that would delete this agent.
- [ ] Deleting it by any means available is refused, naming the class rather
      than failing generically.

## The six live tabs

- [ ] **Overview** — name, slug, description, source, status, all correct.
- [ ] **Instructions** — the system prompt renders. As **user 2** (no
      `pandora.prompts.view`) the tab is refused for read, not merely for write.
- [ ] **Models** — provider and model preferences, with the fallback chain if
      one is configured.
- [ ] **Limits & Autonomy** — the four limits, the budgets, the autonomy level.
- [ ] **Runs** — this agent's runs, and clicking one opens its trace.
- [ ] **Usage** — token and cost figures that agree with the Usage page.

## The seven stub tabs

- [ ] Tools, Skills, Memory, Channels, Automations, Workspace and Permissions
      each render as a stub.
- [ ] Each says plainly that the surface is not here yet, and **names no phase
      number** — the phase numbers were removed from the UI on 2026-08-07 and
      should not have come back.
- [ ] No stub tab looks like a broken page or an empty state that suggests the
      agent simply has none of that thing.

## Creating and editing

- [ ] Create an agent. It arrives **disabled**, `observe_only`, with no tools.
- [ ] Create a second with the same name. It gets a distinct slug rather than
      colliding or failing.
- [ ] Edit one field on one tab and save. The audit log records **that tab,
      that key, and the before and after values** — not "agent updated".
- [ ] Save a tab **without changing anything**. No audit entry is written.
- [ ] Delete the agents you created. Database-defined ones delete cleanly.

## Authorization

- [ ] As **user 2**, opening a database-defined agent shows the page read-only
      with no save control.
- [ ] A forged save as user 2 — replaying the request, or re-enabling the
      control in devtools — is refused and **changes nothing**. Confirm the
      value in the database afterwards, not just the page.
- [ ] A fresh installation denies `pandora.agents.manage` by default. This is
      true of `laravel-test` only because its `AppServiceProvider` grants it to
      an operator list; confirm the default by checking a user on neither list.

## Defects found

*(Nothing recorded yet — this section is filled in as the walkthrough runs.)*

## Notes

Anything found here goes in `progress.md`: what broke, why the suite could not
see it, and what changed as a result.

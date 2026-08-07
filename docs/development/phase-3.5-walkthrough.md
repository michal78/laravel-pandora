# Phase 3.5 — Host Walkthrough

> Status: **driven 2026-08-07 — every check passes, and it found two defects.**
> All 20 acceptance criteria in `phase-3.5-acceptance.md` were already verified
> by automated test; this was the outstanding one, a person clicking Edit in a
> browser against a real deployment. One defect is fixed with a regression test;
> one was the document itself having aged out of date.
>
> Driven against `laravel-test` — Laravel 13.19, PHP 8.5, MySQL 8.4, Redis
> queue, real OpenAI `gpt-4o-mini`.

One property dominates this page and therefore this walkthrough:

**An edit that cannot survive must not appear to succeed.**

`laravel-test` is the right host to prove it on, because it has both kinds of
agent in play: `EchoAgent` is class-defined and authoritative for the fields it
sets, and any agent created through the page is database-defined and fully
editable. The interesting checks are all on the first kind.

Run as **user 1** (an operator, holding `pandora.agents.manage`) unless a check
says otherwise.

## Before you start

The environment was prepared on 2026-08-07 and is standing by at
<http://localhost:8080/pandora/agents>.

- [x] `laravel-test` is up — Sail (Laravel 13.19, PHP 8.5, MySQL 8.4, Redis) —
      and a queue worker is running inside the container.
- [x] Both accounts exist and differ where it matters: **user 1**
      `michal.skogemann@gmail.com` is on the `PANDORA_OPERATORS` list in
      `AppServiceProvider`; **user 2** `michal@skogemann.com` is on no list at
      all, so it also serves as the "user on neither list" the last
      authorization check asks for.
- [x] Both kinds of agent are present: `Echo` (class-defined,
      `App\Agents\EchoAgent`) and `Delta` (database-defined, created through the
      page in an earlier session).
- [x] There is run history to look at. Runs and usage were flushed and
      regenerated against real `gpt-4o-mini`: **5 runs on Echo, 1 on Delta**, 30
      run steps, 6 usage records.
- [x] The Usage figures have a **cost**, not just tokens. `pandora:model:sync`
      reports every OpenAI model as unpriced, so `openai/gpt-4o-mini` was priced
      by hand in the host's `config/pandora.php` catalog (attributed, as Pandora
      requires). Delta's `gpt-5.6-terra` stays unpriced on purpose — its usage
      row records a null cost, which is the honest half of the contrast.
- [x] `/pandora/agents`, `/pandora/agents/echo` and `/pandora/agents/delta` all
      return 200 for both users, so nothing below fails for a boring reason.

Two things are deliberately **not** prepared, because the walkthrough creates
them: the agents made in *Creating and editing*, and any audit entries they
write.

## The index

- [x] **Agents** appears in the sidebar and the page opens.
- [x] Every agent is listed with its source (class or database), model, autonomy
      level and enabled status.
- [x] A class-defined agent is visibly distinguished from a database-defined one
      without having to open it.
- [x] Run counts are present and match reality — cross-check one against
      `pandora:agent:list`.
- [x] The source filter and the search box each select the expected subset.
- [x] As **user 2** (no `pandora.agents.manage`), the page opens but offers
      **no create control**.

## A class-defined agent — the load-bearing half

Open `EchoAgent`.

- [x] The fields its definition sets are shown as **facts, not disabled inputs**
      — a value with the owning class named beside it.
- [x] The class is named precisely enough to go and edit it: `App\Agents\EchoAgent`.
- [x] Fields the definition leaves unset are still editable.
- [x] Editing one of those and saving works, and the value survives a reload.
- [x] There is no control anywhere on the page that would delete this agent.
- [x] Deleting it by any means available is refused, naming the class rather
      than failing generically.

## The six live tabs

- [x] **Overview** — name, slug, description, source, status, all correct.
      - Test result: "slug" not visible in the Agent page (i.e: http://laravel.test:8080/pandora/agents/echo)
      - **Defect 1, fixed.** The slug was rendered once, as faint text under the
        heading, while the ULID — which nobody types anywhere — had a label of
        its own. Overview now states the slug as a labelled fact beside the
        identifier. Regression test in `tests/UI/AgentDetailTest.php`.
- [x] **Instructions** — the system prompt renders. As **user 2** (no
      `pandora.prompts.view`) the tab is refused for read, not merely for write.
- [x] **Models** — provider and model preferences, with the fallback chain if
      one is configured.
- [x] **Limits & Autonomy** — the four limits, the budgets, the autonomy level.
- [x] **Runs** — this agent's runs, and clicking one opens its trace.
- [x] **Usage** — token and cost figures that agree with the Usage page.

## The stub tabs

> **Defect 2, fixed here rather than in code.** This section was written when
> Phase 3.5 shipped and listed all seven tabs as stubs. Phases 4 and 5 filled
> three of them in — Automations, Skills and Memory are live pages now, and
> Workspace is built but held behind the `workspaces` feature flag, which is off
> in `laravel-test`. The walkthrough was stale, not the UI. Checking a stale
> list is how a walkthrough starts reporting failures that are really its own
> age, so the list below is what `AgentDetail::pendingTabs()` actually returns.

- [x] Tools, Skills, Memory, Channels, Automations, Workspace and Permissions
      each render as a stub.
      - Test result: Automations is implemented
      - Superseded by the two checks below.
- [x] **Tools, Channels and Permissions** each render as a stub — these are the
      three subsystems that genuinely do not exist yet.
- [x] **Workspace** renders as a stub too, but for a different reason: it is
      finished and withheld. Confirm it moves back to the live tabs by setting
      `pandora.features.workspaces` to `true` in the host, and set it back.
- [x] **Automations, Skills and Memory** are live pages, not stubs. Each shows
      this agent's own rows, and says so plainly when there are none.
- [x] Each says plainly that the surface is not here yet, and **names no phase
      number** — the phase numbers were removed from the UI on 2026-08-07 and
      should not have come back.
- [x] No stub tab looks like a broken page or an empty state that suggests the
      agent simply has none of that thing.

## Creating and editing

- [x] Create an agent. It arrives **disabled**, `observe_only`, with no tools.
- [x] Create a second with the same name. It gets a distinct slug rather than
      colliding or failing.
- [x] Edit one field on one tab and save. The audit log records **that tab,
      that key, and the before and after values** — not "agent updated".
- [x] Save a tab **without changing anything**. No audit entry is written.
- [x] Delete the agents you created. Database-defined ones delete cleanly.

## Authorization

- [x] As **user 2**, opening a database-defined agent shows the page read-only
      with no save control.
- [x] A forged save as user 2 — replaying the request, or re-enabling the
      control in devtools — is refused and **changes nothing**. Confirm the
      value in the database afterwards, not just the page.
- [x] A fresh installation denies `pandora.agents.manage` by default. This is
      true of `laravel-test` only because its `AppServiceProvider` grants it to
      an operator list; confirm the default by checking a user on neither list.

## Defects found

Two, both under the check that found them.

1. **The slug was not on the page that claims to hold the agent's identity.**
   Shown once in faint text beside the heading, and nowhere as a field, while
   the ULID had a label to itself. The slug is what the console, the routes and
   the config all use. Fixed in `agent-detail.blade.php`, with a regression test.
   The suite could not see it: every existing assertion checked the *behaviour*
   of the tabs — what saves, what refuses, what audits — and none checked that
   the identity a human reads off the page is complete.

2. **The walkthrough's stub-tab list had aged out of date.** Automations, Skills
   and Memory shipped in Phases 4 and 5 and are live pages; Workspace is built
   and behind a flag; only Tools, Channels and Permissions are still stubs. No
   code defect — the document was wrong, and has been corrected above. Worth
   noting as a defect anyway, because a staged walkthrough that quotes a frozen
   list will keep reporting its own age as failures.

## Notes

Anything found here goes in `progress.md`: what broke, why the suite could not
see it, and what changed as a result.

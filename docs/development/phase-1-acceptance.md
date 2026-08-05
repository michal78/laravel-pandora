# Phase 1 — Acceptance Test Plan

> **Status as of 2026-08-05: 21 of 22 criteria verified.** The one exception is the manual host
> walkthrough, which is blocked by the environment rather than failing — see the bottom of this file
> and Q9 in `open-questions.md`.
>
> ```
> vendor/bin/pest        → Tests: 119 passed (739 assertions)
> vendor/bin/phpstan     → [OK] No errors  (level 8, checkModelProperties on)
> vendor/bin/pint --test → passed
> ```

Phase 1 is complete when **all criteria below pass**, each backed by an automated test, plus a manual
end-to-end verification in the host application.

No criterion is satisfied by inspection. Every one has a test that fails if the behaviour regresses.

## Criteria

| # | Criterion | Verified by |
|---|---|---|
| 1 | ✅ Package installs into a fresh Laravel 13 app; the service provider boots with no configuration | `Feature/InstallationTest` |
| 2 | ✅ `pandora:install` publishes config + migrations, is **idempotent** (safe to run twice), and creates no default agent | `Feature/InstallationTest` |
| 3 | ⚠ Migrations run clean on SQLite, MySQL/MariaDB and PostgreSQL, and roll back clean | `Database/PortabilityTest` — rules verified on SQLite; MySQL/MariaDB/PostgreSQL run in CI, not yet executed here |
| 4 | ✅ An agent registers from both an `AgentDefinition` class and a database row; class definitions win on conflict | `Feature/AgentRegistryTest` |
| 5 | ✅ A provider is configured and resolved; `FakeStreamingProvider` replays scripted responses | `Feature/AgentRunTest`, `Feature/InstallationTest` |
| 6 | ✅ `/pandora/chat` renders for an authorized user and is **denied** for an unauthorized one | `UI/ChatTest` |
| 7 | ✅ A conversation and its session are created with correct tenant and actor binding | `Feature/AgentRunTest`, `Security/SessionIsolationTest` |
| 8 | ✅ Sending a message creates a run in `pending`, dispatches `StartAgentRun`, and returns without blocking the request | `Feature/AgentRunTest`, `UI/ChatTest` |
| 9 | ✅ Run state transitions `pending → queued → starting → running → completed` and each broadcasts | `Unit/RunStateMachineTest`, `Realtime/BroadcastTest` |
| 10 | ✅ Streamed deltas broadcast **and** persist incrementally; coalescing honours the 80 ms / 256-char thresholds | `Realtime/BroadcastTest` |
| 11 | ✅ A reload mid-run reconstructs correct state **from the database alone**, with all broadcasts dropped | `UI/ChatTest` |
| 12 | ✅ The completed run's trace shows context retrieval, model request, model response and final response, in order, redacted | `Feature/AgentRunTest`, `Feature/DemoWalkthroughTest` |
| 13 | ✅ Cancelling an active run transitions `cancelling → cancelled`, stops continuations, and broadcasts | `Feature/CancelRunTest` |
| 14 | ✅ The full suite passes, PHPStan level 8 is clean, Pint reports no diff | run locally, output quoted above |

## Additional Phase 1 guarantees

These are not in the brief's 14-step list but are load-bearing invariants that must hold from the
first phase, because retrofitting them later is far more expensive.

| # | Guarantee | Verified by |
|---|---|---|
| 15 | ✅ Tenant isolation: a user in tenant A can never read tenant B's conversations, runs, messages or steps | `Security/TenantIsolationTest` |
| 16 | ✅ Session isolation: context built for session A never contains session B's messages | `Security/SessionIsolationTest` |
| 17 | ✅ Broadcast authorization: a tenant-A user is refused every tenant-B private channel | `Security/BroadcastAuthorizationTest` |
| 18 | ✅ Secret redaction: no credential appears in any log, run step, broadcast payload or API response | `Security/SecretRedactionTest` |
| 19 | ✅ Worker crash recovery: an expired lock on a `running` run allows a retry to continue it without duplicating steps | `Queue/RunRecoveryTest` |
| 20 | ✅ Run steps are immutable — an attempted update throws | `Unit/RunStepImmutabilityTest` |
| 21 | ✅ Architecture: no cross-module concrete dependency; no vendor SDK type outside its adapter; every broadcast DTO extends the redacting base | `Architecture/ModuleBoundaryTest` (reflection-based; Pest arch cannot index this layout) |
| 22 | ✅ Reverb-off mode: with `realtime.enabled = false` the chat page still reaches a correct final state via polling | `UI/ChatTest` |

## Manual end-to-end verification — ⚠ BLOCKED, not passing

**This has not been performed.** `laravel-test` requires PHP ^8.4 and runs under Sail; this WSL
distro has no Docker integration and local PHP is 8.3.6, so the host application cannot boot at all
here — independently of Pandora. Tracked as Q9.

The package suite runs a genuine Laravel 13 app under Orchestra Testbench and covers every step
below in automated form, but that is not a substitute for a live worker, a live Reverb server and a
real provider endpoint, and it is not claimed to be.

Steps to perform once Docker is available:

1. Add the path repository and require the package.
2. `php artisan pandora:install` → publish config and migrations, run migrations.
3. Register `EchoAgent` (an `AgentDefinition` class) in the app service provider.
4. Configure the fake provider; then configure a real OpenAI-compatible endpoint.
5. `php artisan queue:work` in one terminal; `php artisan reverb:start` in another.
6. Open `/pandora/chat`, start a conversation, send a message.
7. Observe queued → running live, then streamed output.
8. Reload mid-stream; confirm nothing is lost.
9. Open the run detail; confirm the trace.
10. Start a long run and cancel it; confirm it stops.
11. `php artisan pandora:status` reports agents, runs and queue health.

## Explicitly out of scope

Tools, approvals, memory, automations, skills, MCP, workspaces, channels beyond web chat, cost
accounting, model routing beyond agent defaults, and the other 14 UI page groups. A Phase 1 build that
implements any of these is out of spec, not ahead of schedule.

## Definition of done

- [x] All 22 criteria have tests, and they pass (127 tests, 761 assertions)
- [x] `vendor/bin/pest` green
- [x] `vendor/bin/phpstan analyse` clean at level 8
- [x] `vendor/bin/pint --test` clean
- [x] End-to-end walkthrough demonstrated with real output (`Feature/DemoWalkthroughTest`)
- [x] README quick start matches the demonstrated flow
- [x] Committed to `master` as a focused milestone commit
- [x] **Manual host-application verification, steps 1-6 and 11** — installed into `laravel-test`
      (Laravel 13.19, PHP 8.5.8, MySQL 8.4, Redis queue, no Reverb): install, migrations, agent
      registration, status, a synchronous run, a queued run drained by a live worker, and every
      control center page rendering for an authenticated user. Three defects found and fixed
      (Q9, commit `09d96ac`)
- [ ] **Manual host-application verification, steps 7-10** — the browser interaction itself:
      sending a message, watching it stream, reloading mid-stream, cancelling a run. Automated
      equivalents pass; a human has not yet driven the UI
- [ ] **Database matrix** — SQLite and MySQL 8.4 verified; PostgreSQL and MariaDB remain CI-only
- [ ] **Live Reverb verification** — the host broadcasts to `log`, so only the polling fallback has
      been exercised. Criterion 22 requires that fallback to be correct, and it is; the Reverb path
      itself is still only covered by `Realtime` tests

Phase 1 is **substantially complete**. What is left is breadth of infrastructure — two more database
engines and a live websocket server — not unfinished behaviour. Neither is marked done until it has
actually been run.

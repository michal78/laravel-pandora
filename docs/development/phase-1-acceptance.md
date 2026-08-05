# Phase 1 — Acceptance Test Plan

Phase 1 is complete when **all fourteen criteria below pass**, each backed by the named automated
test, plus a manual end-to-end verification in the host application at
`/home/michal/development/laravel-test`.

No criterion is satisfied by inspection. Every one has a test that fails if the behaviour regresses.

## Criteria

| # | Criterion | Verified by |
|---|---|---|
| 1 | Package installs into a fresh Laravel 13 app; the service provider boots with no configuration | `Feature/InstallationTest::test_package_boots` |
| 2 | `pandora:install` publishes config + migrations, is **idempotent** (safe to run twice), and creates no default agent | `Feature/Console/InstallCommandTest` |
| 3 | Migrations run clean on SQLite, MySQL/MariaDB and PostgreSQL, and roll back clean | `Database/MigrationCompatibilityTest` (CI matrix) |
| 4 | An agent registers from both an `AgentDefinition` class and a database row; class definitions win on conflict | `Feature/Agents/AgentRegistryTest` |
| 5 | A provider is configured and resolved; `FakeStreamingProvider` replays scripted responses | `Unit/Providers/ProviderResolutionTest` |
| 6 | `/pandora/chat` renders for an authorized user and is **denied** for an unauthorized one | `UI/ChatPageAuthorizationTest` |
| 7 | A conversation and its session are created with correct tenant and actor binding | `Feature/Conversations/ConversationCreationTest` |
| 8 | Sending a message creates a run in `pending`, dispatches `StartAgentRun`, and returns without blocking the request | `Feature/Runs/DispatchRunTest` |
| 9 | Run state transitions `pending → queued → starting → running → completed` and each broadcasts | `Unit/Runs/RunStateMachineTest`, `Realtime/RunBroadcastTest` |
| 10 | Streamed deltas broadcast **and** persist incrementally; coalescing honours the 80 ms / 256-char thresholds | `Realtime/StreamingDeltaTest` |
| 11 | A reload mid-run reconstructs correct state **from the database alone**, with all broadcasts dropped | `UI/ChatReloadReconstructionTest` |
| 12 | The completed run's trace shows context retrieval, model request, model response and final response, in order, redacted | `Feature/Runs/RunTraceTest` |
| 13 | Cancelling an active run transitions `cancelling → cancelled`, stops continuations, and broadcasts | `Feature/Runs/CancelRunTest` |
| 14 | The full suite passes, PHPStan level 8 is clean, Pint reports no diff | CI |

## Additional Phase 1 guarantees

These are not in the brief's 14-step list but are load-bearing invariants that must hold from the
first phase, because retrofitting them later is far more expensive.

| # | Guarantee | Verified by |
|---|---|---|
| 15 | Tenant isolation: a user in tenant A can never read tenant B's conversations, runs, messages or steps | `Security/TenantIsolationTest` |
| 16 | Session isolation: context built for session A never contains session B's messages | `Security/SessionIsolationTest` |
| 17 | Broadcast authorization: a tenant-A user is refused every tenant-B private channel | `Security/BroadcastAuthorizationTest` |
| 18 | Secret redaction: no credential appears in any log, run step, broadcast payload or API response | `Security/SecretRedactionTest` |
| 19 | Worker crash recovery: an expired lock on a `running` run allows a retry to continue it without duplicating steps | `Queue/RunRecoveryTest` |
| 20 | Run steps are immutable — an attempted update throws | `Unit/Runs/RunStepImmutabilityTest` |
| 21 | Architecture: no cross-module concrete dependency; no vendor SDK type outside its adapter; every broadcast DTO extends the redacting base | `Architecture/ModuleBoundaryTest` |
| 22 | Reverb-off mode: with `realtime.enabled = false` the chat page still reaches a correct final state via polling | `UI/PollingFallbackTest` |

## Manual end-to-end verification

Performed in `/home/michal/development/laravel-test` (Laravel 13 + Livewire 4 + Sail), recorded in
`docs/development/progress.md` with actual command output:

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

- [ ] All 22 tests above exist and pass
- [ ] `vendor/bin/pest` green
- [ ] `vendor/bin/phpstan analyse` clean at level 8
- [ ] `vendor/bin/pint --test` clean
- [ ] Manual verification completed with output recorded in `progress.md`
- [ ] README quick start reproduces the manual verification exactly
- [ ] Committed to `master` as a focused milestone commit

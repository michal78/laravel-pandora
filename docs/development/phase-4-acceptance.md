# Phase 4 — Acceptance Test Plan

> **Status as of 2026-08-06: 26 of 26 criteria verified.**
>
> ```
> vendor/bin/pest        -> Tests: 937 passed (3,205 assertions)   [SQLite]
>                        -> MySQL 8.4 · MariaDB 11 · PostgreSQL 17 green in CI
> vendor/bin/phpstan     -> [OK] No errors  (level 8, checkModelProperties on)
> vendor/bin/pint --test -> passed
> ```
>
> Nothing below is ticked on the strength of code existing; each criterion is ticked only when the
> named automated test asserts it and that test passes.

Every phase before this one runs because a human pressed something. Phase 4 is the first that runs
because a clock did, and that changes what "correct" means. A chat page that renders twice is
annoying; an automation that fires twice refunds the customer twice.

Two properties dominate the acceptance bar:

**An occurrence fires exactly once.** Two schedulers, a queue retry, a duplicated webhook delivery
and a replayed event all converge on one guard: a deterministic idempotency key, uniquely indexed
with the automation, where the insert *is* the claim. Nothing downstream is trusted to notice a
duplicate, because by then the model has already been called.

**An automation can never widen what an agent may do.** The automation carries an autonomy level,
but it is clamped to the agent's on every run. Otherwise the Automations page becomes a privilege
escalation surface: anyone who can schedule an `observe_only` agent could schedule it to act.

## Scope

`Automation` with six trigger types · `NextRun` in the automation's own timezone · one Laravel
scheduler entry · `AutomationScheduler` claiming due rows · `AutomationDispatcher` running the
condition → concurrency → autonomy → idempotency sequence · `AutomationRun` occurrence history ·
signed replay-protected webhooks · `Pandora::on()` event bindings · `Observation` proposals and
promotion · Automations index and detail · the agent's Automations tab ·
`pandora:automation:list` / `:run` / `:tick`.

## Design decisions taken for this phase

| Decision | Choice | Rationale |
|---|---|---|
| The double-fire guard | A unique insert into `automation_runs`, not a `last_run_at` check | Checking-then-writing is a race whose window is a database round trip, and it fails exactly under the load that made you run two schedulers. |
| The idempotency key | Deterministic from `(automation, occurrence timestamp)` | Two schedulers computing the same due occurrence compute the same key. A random key would make both inserts succeed. |
| A refused occurrence | Still an `automation_runs` row, with a reason | "It didn't run" and "it ran and did nothing" are different incidents. A silence cannot be told apart from a broken scheduler. |
| Webhook endpoints | The automation row *is* the endpoint | A separate endpoints table lets you build an endpoint pointing at nothing, and nobody needs two endpoints for one automation. Deviates from the Phase 0 sketch in `database-model.md`, which is updated. |
| Webhook replay | Rejected on a unique `(automation, signature)` index | A nonce the server remembers is the only replay defence that survives a load balancer. Timestamp tolerance alone lets a 4-minute-old request through twice. |
| Autonomy | The automation's level is clamped to the agent's, never the reverse | Otherwise scheduling is privilege escalation. |
| Autonomy budget | Runs per rolling window, per automation | A budget in tokens is already enforced by `BudgetGuard`. What autonomy needs bounded is *how often it wakes*, which no token budget catches. |
| Budget exhaustion | Disables the automation and notifies | ADR-0009. An automation that merely skipped would keep trying forever and nobody would learn it was broken. |
| Misfire | Explicit policy, defaulting to `skip` | A worker down for six hours must not wake to 360 queued runs. Catching up is sometimes right, so it is a choice — but not the default. |
| Concurrency | Explicit policy, defaulting to `skip` | An hourly automation whose run takes 90 minutes otherwise accumulates workers until the queue dies. |
| Conditions | Named, config-registered, class-exact | Same rule as tools: a condition that could be an arbitrary callable from a database row is remote code execution with extra steps. |
| Event bindings | Both code (`Pandora::on()`) and database rows | Code for what belongs in version control; rows for what an operator adds at 3am. |
| Which events are listened to | Only classes some binding names, cached | A wildcard listener on every application event is a tax on every request in the host. |
| Agent-proposed work | An `Observation` a human promotes | Feature-parity classes autonomous promotion as Future for a reason: an agent that can schedule itself has no leash. |
| Manual run | Trigger type `manual`, and it bypasses the schedule but not the autonomy clamp | An operator pressing Run is a human act; it is not permission for the agent to exceed its level. |

## Criteria

| # | Criterion | Verified by |
|---|---|---|
| 1 | ✅ `NextRun` computes cron occurrences in the automation's own timezone, not the server's | `Automation/NextRunTest` |
| 2 | ✅ Interval and one-off triggers compute a next occurrence; a fired one-off schedules nothing further | `Automation/NextRunTest` |
| 3 | ✅ A DST transition neither skips nor repeats a daily occurrence | `Automation/NextRunTest` |
| 4 | ✅ The scheduler selects exactly the enabled automations whose `next_run_at` has passed | `Automation/SchedulerTest` |
| 5 | ✅ **Two schedulers running simultaneously over the same due automation produce exactly one run** | `Automation/SchedulerTest` |
| 6 | ✅ A queue retry of `RunAutomation` for the same occurrence produces no second run | `Automation/IdempotencyTest` |
| 7 | ✅ Each occurrence writes an `automation_runs` row, including when it is refused, with the reason | `Automation/SchedulerTest` |
| 8 | ✅ `next_run_at` advances past the occurrence just claimed, so a slow run is not re-claimed | `Automation/SchedulerTest` |
| 9 | ✅ Misfire `skip` drops missed occurrences; `run_once` catches up with exactly one; `run_all` is bounded by the configured cap | `Automation/MisfireTest` |
| 10 | ✅ Concurrency `skip` refuses while a run of the same automation is live; `allow` does not | `Automation/ConcurrencyTest` |
| 11 | ✅ A condition that evaluates false records a skipped occurrence and creates no run | `Automation/ConditionTest` |
| 12 | ✅ A condition naming something not in the configured registry refuses the occurrence rather than executing anything | `Automation/ConditionTest` |
| 13 | ✅ An automation run is created with an autonomous trigger type and the automation's context | `Automation/SchedulerTest` |
| 14 | ✅ **An automation cannot raise the autonomy of its agent — the effective level is the lower of the two** | `Automation/AutonomyTest` |
| 15 | ✅ An `observe_only` effective level denies a mutating tool call inside the resulting run | `Automation/AutonomyTest` |
| 16 | ✅ **An automation exhausting its autonomy budget disables itself, records it, and notifies an admin** | `Automation/AutonomyTest` |
| 17 | ✅ Consecutive failures past the retry policy's limit disable the automation | `Automation/RetryTest` |
| 18 | ✅ A code-registered `Pandora::on(Event::class)->run('agent')` binding creates a run when the event fires | `Automation/EventTriggerTest` |
| 19 | ✅ A database automation bound to an event class fires on that event, and only that event | `Automation/EventTriggerTest` |
| 20 | ✅ An event no binding names causes no listener work | `Automation/EventTriggerTest` |
| 21 | ✅ A correctly signed webhook creates a run and answers 202 with the run id | `Automation/WebhookTest` |
| 22 | ✅ **A replayed webhook — identical signature — is rejected and creates no second run** | `Automation/WebhookTest` |
| 23 | ✅ A wrong signature, an absent signature, a stale timestamp and a disabled automation are each rejected without creating a run | `Automation/WebhookTest` |
| 24 | ✅ The `propose_follow_up` tool writes a pending observation and schedules nothing | `Automation/ObservationTest` |
| 25 | ✅ Promoting an observation requires `pandora.automations.manage` and produces a disabled one-off automation | `Automation/ObservationTest` |
| 26 | ✅ **A tenant cannot see, run, edit or delete another tenant's automation, and a foreign webhook slug answers 404** | `Automation/TenancyTest` |

Test files: `Automation/NextRunTest` · `SchedulerTest` · `MisfireTest` · `ConcurrencyTest` ·
`ConditionTest` · `IdempotencyTest` · `AutonomyTest` · `RetryTest` · `EventTriggerTest` ·
`WebhookTest` · `ObservationTest` · `TenancyTest`, plus `UI/AutomationsPageTest`,
`UI/AutomationDetailTest`, `UI/NavigationTest` and `UI/AgentDetailTest`. 158 tests across the
automation and automation-UI files.

Supporting behaviour asserted by the same files, not counted separately: the index lists schedules
with next and last run; a manual run is recorded as `manual` and still clamped; enable and disable
are audited; the agent's Automations tab lists its automations; `pandora:automation:list` and
`:run` behave; and the sidebar reaches the page over HTTP.

## Audit actions this phase must produce

`automation.created` · `automation.updated` · `automation.deleted` · `automation.enabled` ·
`automation.disabled` · `automation.fired` · `automation.refused` · `automation.budget_exhausted`
(severity `warning`) · `webhook.rejected` (severity `warning`) · `observation.proposed` ·
`observation.promoted` · `observation.dismissed`

## Explicitly out of scope

Autonomous promotion of an observation into an automation — Future, by ADR-0009 and the parity
matrix. A visual schedule/DAG editor. Delivery of an automation's result to a channel: `delivery` is
stored on the row and honoured in Phase 7, when there is somewhere to deliver to. Per-automation
provider overrides, which belong with the agent.

## Definition of done

- [x] All 26 criteria have tests, and they pass
- [x] `vendor/bin/pest` green — 937 passed, 3,205 assertions, on all four engines
- [x] `vendor/bin/phpstan analyse` clean at level 8
- [x] `vendor/bin/pint --test` clean
- [x] `docs/development/progress.md`, `docs/roadmap.md`, `docs/architecture/database-model.md`,
      `docs/architecture/overview.md`, `docs/guides/automations.md` and `CHANGELOG.md` updated
- [x] **A human drives the page in a host application** — done 2026-08-06 against `laravel-test`;
      all twenty checks in `phase-4-walkthrough.md` pass, including a real cron firing a real
      automation. It found a defect the suite could not: a replayed webhook left no evidence
      anywhere. Two more were found while preparing it — a fatal `TypeError` on any host using
      immutable dates, and a date reported as changed on every save.

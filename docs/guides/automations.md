# Automations

> An automation starts a run without anybody typing — on a schedule, on a Laravel event, or on a
> signed webhook. Everything else in Pandora runs because a person pressed something; this is the
> part that does not, which is why it is the part with a leash on it.

Read `docs/adr/0009-bounded-autonomy.md` first if you have not. The short version: proactive
behaviour is genuinely useful and genuinely the most dangerous thing to install into an application
serving real customers, so Pandora reproduces the capability and removes the ambiguity. Every
autonomous action is attributable to a trigger, a policy decision and a budget.

## 1. The one thing you have to set up

Pandora registers its own scheduler entry. You add nothing to your application's `routes/console.php`
or `Kernel`. But something has to call Laravel's scheduler, which on most hosts is one cron line:

```
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

If that line is missing, **every automation will look broken and none of them will be**. The
Automations page says so when nothing has ever fired, and `pandora:status` reports when the scheduler
was last heard from, because this is by far the most common cause of "the automation doesn't work".

To drive the tick yourself instead, set `automation.schedule.enabled` to `false` and run
`php artisan pandora:automation:tick` on your own cadence.

## 2. Anatomy

| Field | What it decides |
|---|---|
| `trigger_type` | What wakes it: `one_off` · `cron` · `interval` · `event` · `webhook` · `heartbeat` |
| `timezone` | The zone occurrences are computed in — **not** the server's |
| `condition` | A named check, defined in your config, evaluated before a run is created |
| `prompt` | What the agent is asked. Empty means "decide whether anything needs doing, and report" |
| `autonomy_level` | How far the agent may go — **clamped to the agent's own level** |
| `autonomy_budget_runs` | How many times it may wake per window before it turns itself off |
| `concurrency_policy` | What to do when the previous run has not finished |
| `misfire_policy` | What to do about occurrences that were due while nothing was running |
| `retry_policy` | How many consecutive failures before it disables itself |

Every automation binds to exactly one agent, and inherits that agent's model, tools, instructions and
limits. An automation is a *schedule*, not a second kind of agent.

## 3. Timezones

Cron expressions are evaluated in the automation's own timezone. A "9am daily" report configured by
somebody in Copenhagen, stored against a server running in UTC, moves by an hour twice a year — and
because it is right for months at a time, the person who configured it experiences that as Pandora
being unreliable rather than as a timezone bug.

Set the timezone to the one the person who owns the schedule thinks in. Across a daylight saving
boundary the occurrence stays at 09:00 local and moves in UTC, which is the correct answer.

## 4. Autonomy, and why an automation can never widen it

An automation carries an autonomy level, and the level a run actually gets is **the lower of the
automation's and the agent's**:

```
agent: suggest          automation: act_within_policy   →  run: suggest
agent: act_within_policy automation: observe_only        →  run: observe_only
```

If it worked the other way, the Automations page would be a privilege escalation surface: anybody who
could schedule an `observe_only` agent could schedule it to act. To give an automation more rope,
raise the *agent's* level — deliberately, on the agent's own page.

The clamp is enforced in `ToolGatekeeper`, not in `ToolPolicy`. A policy is the layer you replace;
binding your own must not silently remove the leash.

| Effective level | A mutating tool call |
|---|---|
| `observe_only` | Denied. The agent reads and reports. |
| `suggest` | Denied. The agent may `propose_follow_up` instead. |
| `act_with_approval` | Pauses for a human, whatever the tool's risk level and whatever the policy waived. |
| `act_within_policy` | Proceeds; the tool policy and approvals decide as usual. |

A run started by a person carries **no** autonomy level, and that is meaningful rather than missing:
somebody is watching, and the tool policy and approvals are the boundary.

## 5. The autonomy budget

`BudgetGuard` already bounds what a run may *spend*. What it cannot catch is an automation that wakes
every minute and returns immediately: each run is cheap, the total is not, and no per-run token limit
is ever reached.

So autonomy is budgeted in **occurrences per rolling window**. Exhausting it does not skip the
occurrence — it disables the automation and notifies whoever you nominated in
`automation.autonomy.notify`. An automation that merely skipped would keep trying forever and nobody
would learn it was broken.

Only occurrences that became runs are charged. A condition that keeps returning false cannot burn a
healthy automation's budget.

## 6. Exactly once

Two schedulers, a queue retry, a duplicated webhook delivery and a replayed event all converge on one
guard: an occurrence has a deterministic idempotency key derived from `(automation, occurrence
timestamp)`, that key is uniquely indexed with the automation, and **the insert is the claim**.

Nothing downstream re-checks, because by the time anything downstream runs the model has been called.

The thing everybody writes first — `if ($automation->last_run_at < $due)` — is a check-then-act race
whose window is a database round trip. It fails precisely under the load that made you run two
schedulers.

## 7. Conditions

A condition is **named** in the automation and **defined** in your configuration:

```php
// config/pandora.php
'automation' => [
    'conditions' => [
        'unshipped_orders' => fn (array $arguments): bool =>
            Order::whereNull('shipped_at')->where('placed_at', '<', now()->subDay())->exists(),
    ],
],
```

Same rule as tools, jobs and readable config keys: the database says *which*, your version-controlled
configuration says *what*. A callable read out of a database row is remote code execution with extra
steps, and an automations page is exactly the surface an attacker would want it on.

A name that is not registered **refuses** the occurrence. It does not evaluate true and it does not
evaluate false — an automation whose condition was renamed out from under it must stop, not guess.

## 8. Event triggers

Two ways, and both are wanted.

**In code**, for what belongs in version control:

```php
// A service provider's boot()
Pandora::on(OrderShipped::class)
    ->when(fn (OrderShipped $e) => $e->order->isInternational())
    ->map(fn (OrderShipped $e) => ['reference' => $e->order->reference])
    ->autonomy(AutonomyLevel::ObserveOnly)
    ->run('logistics');
```

**In the database**, for what an operator adds at 3am: an automation of trigger type `event` with an
`event_class`.

`map()` is not optional in spirit. Without it the agent is told only the event's class name — which is
the safe default, because an event object is application internals and frequently carries a whole
Eloquent model. Serialising one into a prompt is how a customer's address reaches a model request
nobody meant to send.

Listeners are attached only for classes something actually names. Pandora never registers a wildcard
listener, so an application with no event automations pays nothing.

## 9. Webhooks

Each webhook automation *is* its own endpoint:

```
POST /pandora/webhooks/{slug}
X-Pandora-Signature: t=1754400000,v1=<hex sha256 hmac>
```

`v1` is `hash_hmac('sha256', "{timestamp}.{raw body}", $secret)`. The timestamp is inside the MAC, so
it cannot be rewritten, and a delivery outside `webhooks.tolerance_seconds` is refused.

**Timestamp tolerance is not replay protection.** The window has to be generous enough to survive
clock skew, and inside it the same request can be sent as often as anybody likes. Replay is refused by
a unique `(automation, signature)` insert — the only defence that holds behind a load balancer, where
no single process sees every delivery.

| Status | Meaning |
|---|---|
| `202` | Accepted; the run is queued. Not `200` — the work is not done |
| `401` | Signature missing, malformed, wrong, or stale |
| `404` | No such automation, disabled, no secret, or another tenant's |
| `409` | Already delivered — counted on the delivery it duplicates, not stored as a second row |
| `413` | Body over `webhooks.max_payload_bytes` |

The 404 covers four different situations on purpose. A caller learning which one applied is being
handed an oracle for enumerating your automations.

Secrets are generated in the control center, stored encrypted, hidden from serialisation, and shown
**once**. Rotating invalidates every signature made with the old one, so update the sender first.

Routes sit outside the control center's middleware: an inbound webhook has no session, no CSRF token
and no authenticated user, and asking it for any of those only means an integrator disabling
middleware until it works. The signature is the authentication.

## 10. Misfire and concurrency

Both default to `skip`, and both defaults exist because the alternative fails quietly.

**Misfire** — a worker down for six hours must not come back to three hundred and sixty queued runs,
every one of them stale, every one costing money to discover it is stale. `run_once` catches up with
exactly one; `run_all` is bounded by `misfire.max_catch_up`, because an unbounded catch-up is the
outage twice and the second time it is self-inflicted.

**Concurrency** — an hourly automation whose run takes ninety minutes accumulates workers under
`allow` until the queue stops moving, and the only symptom is that everything else got slow. The
policy applies to runs *this automation* started, so an automation sharing an agent with the chat page
is never blocked by somebody typing.

## 11. The goal queue

An agent may propose work for itself with the `propose_follow_up` tool. It writes a pending
observation and schedules nothing. Promotion is a human act behind `pandora.automations.manage`, on
the Automations page, and it produces a **disabled one-off automation at `observe_only`**:

- Disabled, because approving the idea is not approving the schedule.
- One-off, because the agent's suggested cron is advisory — it proposes *when*, you decide *whether*.
- `observe_only`, because approving an idea is not approving the agent acting on it.

The prompt is copied verbatim. Paraphrasing it would mean the thing that runs is not the thing that
was reviewed.

The tool is `low` risk despite being about scheduling, because it changes nothing — which is what lets
an `observe_only` agent still use it. That is exactly what `observe_only` should mean: watch, and tell
me.

## 12. Reading the history

Every occurrence is a row, **including the ones that produced no run**, with a reason. "It never
fired" and "it fired and declined" are different incidents, and a silence is indistinguishable from a
scheduler that stopped last Tuesday.

An occurrence is what the automation **ran**. An inbound delivery refused before it got that far — a
wrong signature, a stale timestamp, a repeat — never became one, so it is on the **Webhook** tab
under Deliveries rather than in History. A replay is the awkward case: replay protection *is* a
unique insert, so the duplicate cannot be its own row, and it is counted on the delivery it
duplicates instead. `replay_count` climbing is how a sender with broken retry logic announces
itself.

| Outcome | What happened |
|---|---|
| `dispatched` | A run was created |
| `skipped` | The condition said no, or named something unregistered |
| `refused` | A policy said no — concurrency, autonomy budget, a disabled agent |
| `failed` | The dispatch itself failed |

## 13. Console

```bash
php artisan pandora:automation:list          # enabled ones, with next run in their own zone
php artisan pandora:automation:list --all    # including disabled
php artisan pandora:automation:run <slug>    # fire one occurrence now
php artisan pandora:automation:run <slug> --sync
php artisan pandora:automation:tick          # what the scheduler calls
```

A manual run bypasses the schedule and the misfire policy — you have decided the timing. It does not
bypass the condition, the concurrency policy or the autonomy clamp, because those describe what the
automation may do rather than when, and "I ran it by hand" is not permission for the agent to exceed
its level.

## 14. Abilities

| Ability | Grants |
|---|---|
| `pandora.access` | Reading the Automations page and an automation's history |
| `pandora.automations.manage` | Creating, editing, enabling, deleting, running, rotating secrets, promoting proposals |
| `pandora.prompts.view` | Reading and editing what an automation asks the agent |

`pandora.automations.manage` is **denied by default**, on a fresh installation, to everybody. An
automation acts unattended, so being able to create one is administrative by definition — which
means a fresh install shows you the Automations page in read-only mode and no way to change
anything until you say who may.

Grant it by defining a gate of the same name anywhere in your application. Pandora registers its
permissive fallbacks only where no gate exists, so yours always wins:

```php
// AppServiceProvider::boot()
Gate::define('pandora.automations.manage', fn (User $user) => $user->isAdmin());
Gate::define('pandora.prompts.view',       fn (User $user) => $user->isAdmin());
```

There is no configuration switch for this and deliberately so: who may schedule unattended work is
an authorization decision, and authorization decisions belong in your code where they are reviewed,
not in a config file where they are copied.

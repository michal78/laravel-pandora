# Execution Model

> Status: Phase 0 (discovery).

## 1. Core invariant

> **All state required to continue a run lives in the database. A run is resumable from its row and
> its steps alone, on any worker, at any later time.**

Everything below follows from that sentence. If a design choice would put continuation-critical state
in PHP memory, in a job payload, or in a cache entry, it is wrong.

## 2. Run states

| State | Meaning | Job in flight? | Next |
|---|---|---|---|
| `pending` | Created, not dispatched | no | `queued` |
| `queued` | `StartAgentRun` dispatched | yes | `starting`, `cancelled` |
| `starting` | Resolving agent, session, budgets | yes | `running`, `failed` |
| `running` | Executing an iteration | yes | any waiting or terminal state |
| `waiting_for_tool` | Tool executions outstanding | yes (tool jobs) | `running`, `failed`, `cancelling` |
| `waiting_for_approval` | Human decision required | **no** | `running`, `cancelled`, `failed` (expiry) |
| `waiting_for_user` | Clarification requested | **no** | `running`, `cancelled` |
| `paused` | Manually paused | **no** | `running`, `cancelled` |
| `cancelling` | Cancellation requested, in-flight work draining | maybe | `cancelled` |
| `completed` | Final answer produced | no | — |
| `failed` | Unrecoverable error | no | — |
| `timed_out` | Wall-clock or budget exceeded | no | — |
| `cancelled` | Cancellation complete | no | — |

The three states with **no job in flight** are the reason for this architecture. A run can wait three
days for an approval and consume nothing.

Transitions are validated by `RunStateMachine`, executed inside a transaction with `lockForUpdate()`
on the run row, and recorded. An invalid transition throws `InvalidRunTransition` — it never silently
no-ops.

## 3. Jobs

| Job | Queue | Responsibility |
|---|---|---|
| `StartAgentRun` | `pandora-agents` | Resolve agent/session/tenant/actor, authorize, seed budget, → `running`, dispatch first continuation |
| `ContinueAgentRun` | `pandora-agents` | **One** iteration: context → model → parse → dispatch tools or finish |
| `ExecuteToolCall` | `pandora-tools` | Execute one authorized tool call, persist result, continue if last |
| `ResumeApprovedRun` | `pandora-agents` | Consume approval decision, execute or reject, resume |
| `ProcessIncomingChannelMessage` | `pandora-interactive` | Normalise inbound channel message, resolve identity/session, create run |
| `RunAutomation` | `pandora-automation` | Evaluate conditions/idempotency/concurrency, create run |
| `SummarizeConversation` | `pandora-memory` | Produce summary memory item |
| `CurateMemory` | `pandora-memory` | Evaluate agent memory proposals |
| `GenerateEmbedding` | `pandora-memory` | Embed a memory item (optional) |
| `PrunePandoraData` | `pandora-maintenance` | Retention enforcement |
| `ProbeProviderHealth` | `pandora-maintenance` | Provider reachability + latency |

Queues are configurable and default to collapsing onto the application's default queue, so a plain
`php artisan queue:work` runs everything.

Each job is small, has a defined `tries`/`backoff`, implements `failed()` to move the run to a proper
terminal state, and is individually testable.

## 4. One iteration, precisely

```php
// ContinueAgentRun::handle() — narrative form
$lock = $this->runLock->acquire($runId);           // atomic cache lock, TTL > iteration timeout
if (! $lock) return;                               // another worker owns it; do nothing

$run = $this->runs->findForUpdate($runId);
$this->states->assertContinuable($run);            // throws if cancelled/terminal
$this->budgets->assert($run);                      // iterations, tools, tokens, money, wall clock

$context = $this->context->build($run);            // + memory retrieval
$run->steps()->append(StepType::ContextRetrieval, $context->trace());

[$provider, $model] = $this->router->resolve($run);
$run->steps()->append(StepType::ModelRequest, $request->redacted());

$response = $provider->stream($request, function (Delta $d) use ($run) {
    $this->messages->appendDelta($run->currentMessage(), $d);   // buffered DB write
    $this->realtime->delta($run, $d);                           // coalesced broadcast
});

$run->steps()->append(StepType::ModelResponse, $response->redacted());
$this->usage->record($run, $response->usage());

if ($response->isFinal()) {
    $this->states->transition($run, RunState::Completed);
    return;                                        // terminal — no continuation
}

foreach ($response->toolCalls() as $call) {
    $decision = $this->policy->evaluate($run, $call);
    match ($decision->outcome()) {
        Outcome::Deny            => $run->appendToolError($call, $decision->reason()),
        Outcome::RequireApproval => $this->approvals->request($run, $call, $decision),
        default                  => ExecuteToolCall::dispatch($run, $decision->call()),
    };
}

$this->states->transition($run, $this->approvals->pendingFor($run)
    ? RunState::WaitingForApproval
    : RunState::WaitingForTool);
```

`ExecuteToolCall` decrements an outstanding-calls counter atomically; the job that decrements it to
zero dispatches the next `ContinueAgentRun`. Exactly one continuation is dispatched.

## 5. Concurrency control

Three mechanisms, because one is not enough:

1. **Atomic cache lock** on `pandora:run:{id}` — fast path, prevents concurrent workers.
2. **Database ownership token** — `runs.owner_token` + `owner_expires_at`. Survives a cache flush and
   is the authority if the lock driver is unreliable.
3. **Row locking** — `lockForUpdate()` on every state transition.

The lock TTL always exceeds the per-iteration timeout, so a lock cannot expire under a live worker.
A stalled run (owner token expired, still `running`) is detected by the health check and recovered.

## 6. Budgets and limits

| Limit | Scope | On breach |
|---|---|---|
| `max_iterations` | run | `timed_out` |
| `max_tool_calls` | run | `timed_out` |
| `max_duration_seconds` | run wall clock | `timed_out` |
| token budget | run / conversation / agent / user / tenant / day / month | `timed_out` or refuse to start |
| monetary budget | same scopes | same |
| `max_delegation_depth` | run tree | delegation tool denied |
| duplicate tool call | run | call denied, model informed |
| autonomy budget | automation/heartbeat | automation disabled, admin notified |

Budgets are checked **before** the expensive operation and **recorded after** it. A run that would
exceed its budget never makes the call.

## 7. Resumption and failure

| Failure | Behaviour |
|---|---|
| Worker crash mid-iteration | Lock expires; queue retries `ContinueAgentRun`; at most the current iteration is lost. Steps already appended remain. |
| Queue retry | Idempotent by design — an iteration re-reads state and re-derives its work. Tool executions carry idempotency keys so a retried tool is not re-applied. |
| Provider timeout / 5xx | Classified retryable; exponential backoff; then failover to the next model in the chain; then `failed`. |
| Provider rate limit | Backoff honouring `Retry-After`; run stays `running`. |
| Approval pause | No job in flight. `ResumeApprovedRun` dispatched on decision. Expiry → `failed` with a specific reason. |
| Deploy / worker restart | Same as worker crash. |
| Poison job | After `tries`, `failed()` transitions the run to `failed`, records the error class + message, broadcasts, and surfaces it on the Runs page. |

**Cancellation** sets `cancel_requested_at` and transitions to `cancelling`. Every job checks the
flag at each safe point. In-flight tool executions are allowed to finish (killing them mid-write is
worse than letting them complete) and their results recorded, then the run becomes `cancelled`.
Cancellation propagates to child runs.

## 8. Delegation

A child run has `parent_run_id`, `delegation_depth = parent + 1`, a share of the parent's budget, and
effective abilities that are the **intersection** of parent and child agent permissions. The parent
enters `waiting_for_tool`. The child's structured result is appended to the parent as a tool result.
Cancelling a parent cancels its children.

## 9. Idempotency

- Runs created from a webhook, automation or channel message carry an `idempotency_key`, uniquely
  indexed with the tenant. A duplicate delivery returns the existing run.
- Tool executions carry a key derived from `(run, tool, canonicalised arguments, attempt)`.
- Automations carry a key derived from `(automation, scheduled occurrence)` so a double-fire from two
  schedulers produces one run.

## 10. Testability

- The state machine is a pure unit under test — every valid and invalid transition.
- `FakeProvider` / `FakeStreamingProvider` replay scripted responses including tool calls, errors,
  rate limits and malformed output. No test calls a paid API.
- Jobs are tested directly with `Bus::fake()` and by full round-trips on `sync` and `database` queues.
- Crash recovery is simulated by expiring a lock and re-dispatching.
- A provider contract test suite runs every adapter against identical expectations.

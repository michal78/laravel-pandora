# Realtime Model

> Status: Phase 0 (discovery).

## 1. Rule

> **Broadcasts are notifications. The database is the state.**

A client that has missed every broadcast since it connected must, on reload, reconstruct exactly the
correct state from the database alone. Every Livewire component is therefore written to render from
persisted state first; broadcasts only *invalidate* or *append*, they never carry state the database
does not already have.

This rule is what makes the system survive a Reverb restart, a laptop lid closing, a flaky mobile
connection and a deploy — none of which are exotic.

## 2. Channels

| Channel | Authorization |
|---|---|
| `private-pandora.tenant.{tenantId}` | actor belongs to tenant **and** has `pandora.access` |
| `private-pandora.user.{userId}` | actor id matches |
| `private-pandora.conversation.{conversationId}` | actor is a participant, or has `pandora.access` for the conversation's tenant |
| `private-pandora.run.{runId}` | actor may view the run (owner, or `pandora.runs.trace.view`) |
| `private-pandora.approvals.{userId}` | actor id matches **and** has `pandora.approvals.resolve` |
| `private-pandora.system` | `pandora.settings.manage` |

Every callback re-resolves the tenant server-side. A channel name is never trusted to imply
authorization — the ID in the channel name is an input, not a claim.

## 3. Event DTOs

Immutable, versioned, redacted. Payload shape:

```json
{
  "event": "pandora.run.status_changed",
  "version": 1,
  "occurred_at": "2026-08-05T09:14:22.418Z",
  "correlation_id": "01J...",
  "data": { "run_id": "01J...", "state": "waiting_for_approval", "previous_state": "running" }
}
```

Versioning is explicit so a cached browser asset from a previous deploy degrades predictably rather
than throwing.

| Event | Channels | Payload (redacted) |
|---|---|---|
| `RunQueued` | run, conversation | run id, agent, trigger |
| `RunStarted` | run, conversation | run id, provider, model |
| `RunStatusChanged` | run, conversation, tenant | state, previous state |
| `AssistantDeltaReceived` | conversation | message id, delta text, sequence |
| `AssistantMessageCompleted` | conversation | message id |
| `ToolCallRequested` | run, conversation | tool name, risk, **sanitized** arguments |
| `ToolCallStarted` / `ToolCallProgressed` / `ToolCallCompleted` / `ToolCallFailed` | run, conversation | execution id, status, sanitized result / safe error |
| `ApprovalRequested` | approvals.{user}, run, conversation | approval id, tool, risk, summary, sanitized args, expiry |
| `ApprovalResolved` | approvals.{user}, run, conversation | decision, resolver, comment |
| `PlanUpdated` | run, conversation | plan summary |
| `RunCompleted` / `RunFailed` / `RunCancelled` | run, conversation, tenant | usage summary; safe error message only |
| `WorkerHealthChanged` | system | component, status |

## 4. Never broadcast

- Provider API keys or any credential, in any form.
- System prompts, unless the actor holds `pandora.prompts.view`.
- Raw tool arguments or results for tools above `low` risk — the sanitized projection only.
- Exception traces or internal messages in production; a safe message and an error code.
- Memory contents outside the actor's scope.
- Any field the redaction filter has not passed.

Redaction happens when the DTO is constructed, not at serialisation. There is no code path that can
broadcast an unredacted payload, and an architecture test asserts every broadcast DTO extends the
redacting base class.

## 5. Delta coalescing

Broadcasting per token would produce thousands of Reverb messages per run. Deltas are buffered in the
streaming job and flushed on whichever comes first:

- 80 ms elapsed, or
- 256 characters accumulated, or
- a tool-call boundary / stream end.

The same buffer is flushed to the message row, so persisted state and broadcast state advance
together. Both thresholds are configurable; both defaults are chosen so that typing feels continuous
while message volume stays roughly two orders of magnitude below per-token.

Each delta carries a monotonic `sequence`. A client detecting a gap does not attempt repair — it
refetches the message from the database. Simple, and correct by construction.

## 6. Reconnection

1. Livewire component mounts and renders from the database. Always. This alone is a correct UI.
2. It subscribes to the relevant channels.
3. On `AssistantDeltaReceived`, it appends if the sequence is contiguous; otherwise it refetches.
4. On reconnect after any disconnect, it refetches the conversation tail and the run state
   unconditionally, then resumes appending.
5. A run in a non-terminal state with no broadcast for longer than the stall threshold triggers a
   poll — the safety net when Reverb is misconfigured or absent.

**Reverb is optional.** With `pandora.realtime.enabled = false`, the UI falls back to Livewire
polling. Slower, fully correct, no broadcasting infrastructure required. This matters for the many
applications that will try Pandora before setting up Reverb.

## 7. Livewire integration

- Chat thread: `#[On('echo-private:pandora.conversation.{id},...')]` listeners.
- Streaming text renders into a dedicated child component so a delta re-renders one small subtree,
  not the whole thread.
- Tool cards and approval cards are separate components subscribed to the run channel.
- Alpine handles only local, non-authoritative concerns: scroll pinning, composer state, collapse
  toggles, optimistic send. Nothing Alpine holds is ever a source of truth.
- Markdown is rendered server-side and sanitized. Model output is untrusted content and is never
  injected as raw HTML.

## 8. Testing

- `Event::fake()` assertions for every state transition.
- Channel authorization tests: a user from tenant A is refused every tenant-B channel.
- Redaction tests: a run with a `critical` tool and a secret-bearing argument broadcasts nothing
  sensitive — asserted against the full serialised payload, not a field list.
- Reconnection test: drop all broadcasts mid-run, reload the component, assert the rendered state
  matches the state produced by an uninterrupted run. This is the test that keeps rule §1 honest.

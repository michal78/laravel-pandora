# Terminology

Pandora uses these words precisely. Where a word is overloaded elsewhere in the industry, the
difference is stated. Code, database columns, events and UI copy all use these terms.

## Execution

**Agent** — a configured identity: instructions, provider/model preferences, permitted tools, memory
policy, approval policy, limits and bindings. An agent is a *definition*, never a running process.

**Run** — one bounded execution of an agent, from a trigger to a terminal state. The central unit of
work. A run is durable, queued, resumable, cancellable, budgeted and traced. Roughly: "a Laravel job
with a state machine and an audit trail."

**Run step** — one ordered, timed, typed record within a run (`model_request`, `tool_execution`,
`approval_request`, …). Steps are append-only. The step list *is* the trace.

**Turn** — one model request/response pair. A run contains one or more turns. Not a stored entity;
the word appears in documentation and in iteration limits.

**Iteration** — one pass of the execution loop (build context → call model → handle tool calls). The
iteration limit is a primary safety bound.

**Trigger** — what caused a run: user message, application code, Artisan command, schedule, Laravel
event, webhook, API call, channel message, delegation, or manual control-center action. Every run
records its trigger. There is no such thing as an untriggered run.

## Conversation

**Conversation** — a user-facing thread. Has participants, an assigned agent, a channel, and a
message history. Persists across many runs.

**Session** — *a security boundary.* Binds a tenant, an actor, an agent, a channel participant and an
origin. Context may never cross a session boundary.

> This is the term most likely to be misread. In the reference products a "session" is a routing
> selector that decides which history to load. In Pandora it is an isolation boundary that decides
> what a run is *allowed to see*. Two users in the same conversation have different sessions.

**Message** — a persisted item in a conversation, with a role (`user`, `assistant`, `system`, `tool`,
…) and a type. Visible in the UI. Distinct from a run step: messages are the conversation, steps are
the trace.

**Actor** — the entity a run acts on behalf of, for authorization. Usually a host `User`; may be a
system actor for automations. Resolved by a host-configurable `ActorResolver`.

**Tenant** — the isolation scope for data ownership. Resolved by a host-configurable `TenantResolver`.
In single-tenant apps this is a null tenant and all scoping is a no-op.

## Capability

**Tool** — an action available to an agent, implemented as a PHP class with a schema, a risk level and
an `authorize()` method. A tool is where an agent touches the application.

**Tool call** — the model's *request* to use a tool. Not yet an execution.

**Tool execution** — the persisted record of actually running a tool: arguments, sanitized arguments,
result, sanitized result, status, timing, retries, idempotency key, approver.

**Risk level** — `read_only` | `low` | `moderate` | `high` | `critical`. A required declaration on
every tool. Drives enablement defaults, approval defaults and UI presentation.

**Tool policy** — the evaluation that decides whether a requested tool call proceeds. Returns
`allow` | `deny` | `require_approval` | `require_confirmation` | `modify_arguments`.

**Approval** — a human decision gating a specific tool call. The run pauses to the database and
resumes from persisted state.

**Skill** — a Markdown instruction package teaching an agent to perform a task with existing tools.
**Skills are instructions, not code.** Nothing in a skill is ever executed.

**Extension** — a Composer package that registers providers, tools, skills, channels, triggers,
memory stores, MCP integrations, UI navigation or health checks against Pandora's contracts.

## Knowledge

**Context** — everything assembled for a model request. Built by an ordered pipeline of registered
`ContextProvider`s within a token budget, and recorded as a run step.

**Context provider** — a registered, scoped source of context (current user, tenant, route, relevant
models, workspace documents, conversation summary, recent messages, retrieved memories, trigger
payload, time and locale).

**Memory** — durable, scoped, curated knowledge that outlives a conversation. Distinct from context
(assembled per request) and from conversation history (the literal transcript).

**Memory item** — one memory record with a scope, type, provenance, confidence, sensitivity and
optional expiry.

**Workspace** — a sandboxed file area for an agent, on a configurable Storage disk, with a
canonicalised root that paths may never escape.

**Context file** — `AGENTS.md`, `CLAUDE.md`, `SOUL.md` or `PANDORA.md`, read only from explicitly
configured workspace roots, and always treated as untrusted input.

## Automation

**Automation** — a persisted, scheduled or event-driven definition that creates runs.

**Heartbeat** — a bounded, recurring automation that lets an agent evaluate whether anything needs
doing. Not a licence to act; the autonomy level decides that.

**Autonomy level** — `observe_only` | `suggest` | `act_with_approval` | `act_within_policy`. Governs
what an autonomously-triggered run may do.

**Delegation** — a parent run creating a child run on another agent, bounded by delegation depth and
an inherited budget, returning a structured result.

## Delivery

**Channel** — a medium through which a conversation happens. Built-in web chat is core; messaging
adapters are extensions.

**Broadcast event** — a versioned, redacted Reverb notification about a state change. **Notifications
only.** The database is authoritative; the UI must reconstruct correct state from a reload alone.

**Correlation ID** — an identifier threading a trigger through runs, jobs, steps, audit records and
logs. Present on every audit row.

## Words we avoid

| Avoided | Why | Use instead |
|---|---|---|
| "Thought" / "chain of thought" | Implies access to hidden model reasoning we may not have | "reasoning summary", when the provider exposes one |
| "Autonomous" (unqualified) | Suggests unbounded action | "autonomy level `X`" |
| "AgentService" / "PandoraManager" | God objects; the architecture is modular | The specific module class |
| "Session" meaning "routing key" | Collides with our security boundary | "conversation" or "channel thread" |
| "Sandbox" meaning "policy restriction" | Overstates the guarantee | "policy restriction" unless OS/container isolation is genuinely present |

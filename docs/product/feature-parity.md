# Feature Parity Matrix

> Status: Phase 0 (discovery). The **Pandora** column describes *intent and classification*, not
> shipped functionality. Implementation status is tracked in `docs/development/progress.md`.

## Research basis

Public documentation and public repositories only, read August 2026:

- OpenClaw — `docs.openclaw.ai` (Get Started, Install, Channels, Agents, Capabilities, ClawHub,
  Models, Platforms, Gateway & Ops, Reference); the Gateway → Security page was the single most
  informative source and is quoted in `security-model.md`.
- Hermes Agent — `hermes-agent.org`, `github.com/NousResearch/hermes-agent`, and the community
  documentation mirror `github.com/mudrii/hermes-agent-docs`.
- Hermes Studio — `github.com/JPeetz/Hermes-Studio` (README feature list), taken as representative of
  the *control-center surface* users now expect from a self-hosted agent platform.

**No source code, visual design, asset, wording or proprietary implementation detail was copied from
any of these projects.** This matrix records *product capabilities* in order to decide, independently,
what a Laravel-native framework should provide.

## Classification key

| Class | Meaning |
|---|---|
| **Core** | In the base `laravel-pandora` package. Supported, documented, tested. |
| **Official extension** | Separate first-party Composer package. Optional dependency; contracts live in core. |
| **Future** | Accepted as desirable, deliberately deferred past v1.0. Extension point exists; implementation does not. |
| **Unsupported** | Deliberately rejected. A reason is always given. |

---

## 1. Agents and conversation

| Capability | OpenClaw | Hermes | Pandora approach | Class |
|---|---|---|---|---|
| **Agents** | Named agents, per-agent workspace + isolated sessions, config-file defined | Single primary agent with persistent identity; sub-agents spawned per workstream | First-class `Agent` entity. Defined **two ways**: DB rows (editable in the control center) and `AgentDefinition` classes in the host app (version-controlled). Class definitions are authoritative and sync into the DB; DB-only fields hold operational overrides. | Core |
| **Agent profiles** | Identity files in workspace (`SOUL.md`, persona) | `SOUL.md` / persona / identity file editor | Instructions split into `system_instructions` (framework-owned, injected last, not user-editable by default) and `role_instructions` (the persona). Both are versioned; changes are audited. Identity Markdown files are a *context source*, not the storage format. | Core |
| **Conversations** | Sessions keyed by `sessionKey` routing selector | Multi-session with full history | `Conversation` entity: participants, agent assignment, channel, tags, pin, archive, fork (with `parent_conversation_id`), auto title generation, per-conversation provider/model override. | Core |
| **Sessions and threads** | `sessionKey`; `session.dmScope: per-channel-peer` to stop cross-user leakage | Per-sub-agent isolated conversation | `Session` is a **security boundary**, not a routing selector — this is our sharpest departure. A session binds (tenant, actor, agent, channel participant, origin). Context may never cross sessions. Enforced by a global scope plus explicit tests. | Core |
| **Streaming chat** | SSE / gateway push to Control UI | SSE streaming with tool-call rendering | Laravel Reverb broadcasting on private channels, with the DB as the authoritative store. Deltas are coalesced server-side before broadcast (see `realtime-model.md`). | Core |
| **Conversation forking / branching** | Not documented | Not documented | Fork a conversation at a message; child records `parent_conversation_id` + `forked_at_message_id`. | Core |
| **Sub-agents** | Multi-agent routing; role-based teams | Parallel sub-agents, each with own conversation + terminal | `DelegateToAgent` tool producing a child `Run` with `parent_run_id`, bounded `delegation_depth`, inherited budget, propagated cancellation, **structured** result. | Core |
| **Multi-agent delegation / crews** | Role-based teams for complex workflows | Named crews, per-member model, live activity feed | Deterministic parent→child delegation only. Crews/DAG orchestration deferred. | Core (delegation) / Future (crews, DAG workflows) |

## 2. Execution, tools and safety

| Capability | OpenClaw | Hermes | Pandora approach | Class |
|---|---|---|---|---|
| **Tool calls** | Built-in tool set, provider-native tool calling | Provider-native tool calling | `Tool` base class: name, description, JSON-schema input/output, risk level, idempotency, timeout, retry policy, required abilities, `authorize()`, `handle()`. Schema derived from typed input DTO + Laravel validation rules. | Core |
| **Tool permissions** | `tools.allow` / `tools.deny` global + per-agent; `tools.elevated` gate | Approval scopes | Layered: global registry enable → agent allow/deny list → tenant restriction → `ToolPolicy` evaluation returning `allow` / `deny` / `require_approval` / `require_confirmation` / `modify_arguments` → the tool's own `authorize()` against Laravel gates/policies. **All five layers must pass.** | Core |
| **Approval requests** | Exec approvals with context binding | Approval cards: once / per-session / always-allow, deny with receipts | First-class `Approval` entity. Run **pauses to the database** (`waiting_for_approval`) and resumes via a fresh job. Scopes: once / rest-of-run / remembered-policy (only where explicitly permitted). Expiry, comments, cancellation. Broadcast over Reverb. | Core |
| **Risk classification** | Implicit (`elevated` tools) | Implicit (dangerous commands) | Explicit five-level enum on every tool: `read_only`, `low`, `moderate`, `high`, `critical`. Drives defaults for enablement, approval and UI presentation. | Core |
| **Skills** | ClawHub marketplace, publish + curation + trust guidance | Auto-generated from experience; `agentskills.io` standard; 2,000+ registry | Markdown + YAML front-matter manifest, progressively loaded. Compatible-by-convention with the open Agent Skills layout. **Skills are instructions, never executable code**; embedded install instructions are never auto-run. Import/export/validate. Agent + tenant assignment. | Core (format, storage, loading, validation) / Future (agent-authored skills; remote registry install) |
| **Plugins / extensions** | ClawHub plugin marketplace | Plugin/memory-provider ecosystem | Composer packages registering through a service provider against published contracts. Control center *inspects* installed extensions via a manifest. **No remote marketplace install** — that would be arbitrary code execution driven from a web UI. | Core (discovery + manifest) / Unsupported (remote install) |
| **Shell / process execution** | `exec` tool, Docker sandbox, `none`/`ro`/`rw` filesystem modes | Terminal with PTY per sub-agent | `SandboxAdapter` contract in core; **no adapter shipped enabled**. A null adapter refuses to execute. Docker/process adapters are official extensions. Never on by default. | Official extension (adapters) / Core (contract + refusal) |
| **Browser automation** | Browser control profiles, SSRF policy, private networks blocked | Not a headline feature | Deferred. Heavy dependency, large attack surface, weak fit for a library that must install cleanly into any app. Contract shape reserved. | Future |
| **Sandboxing** | Docker sandbox with fs access modes | Container hardening | Contract + policy-level restriction in core (timeouts, budgets, allowlists, workspace containment). OS/container-level isolation via adapters. Honest documentation that policy ≠ containment. | Core (policy) / Official extension (containment) |
| **MCP client** | Supported | Supported (via Studio server management) | Register MCP servers, discover + cache tool schemas, per-agent permission, health, namespacing, timeouts. Remote tools are **untrusted by default** — including their descriptions, which are prompt-injection vectors. | Core |
| **MCP server** | Not documented as a server | Not documented as a server | Optional, authenticated, explicit allowlist of exposed tools. Nothing exposed by default. | Official extension |

## 3. Knowledge

| Capability | OpenClaw | Hermes | Pandora approach | Class |
|---|---|---|---|---|
| **Memory** | Markdown memory files in workspace | Persistent cross-session memory in `~/.hermes/`; wiki-linked; deepening user model | Scoped `MemoryItem` (global / tenant / user / agent / conversation / workspace) with type (user fact, agent-curated, summary, semantic doc, episodic, working), provenance, confidence, sensitivity, expiry. **Writes are deliberate and traceable** — conversation statements are never silently promoted to permanent memory. | Core |
| **Memory graph visualisation** | — | Force-directed wiki-link graph | Not in v1. Relationship edges are modelled so it remains possible. | Future |
| **Vector / semantic retrieval** | — | Memory providers | Default install requires **no** vector DB: database-backed lexical retrieval. `VectorStore` + `EmbeddingProvider` contracts; pgvector / Qdrant / Meilisearch / Typesense as extensions. | Core (contracts + lexical default) / Official extension (adapters) |
| **Workspace files** | Workspace root, Markdown/YAML files | File explorer, workspace per profile | Sandboxed workspace on a configurable Storage disk. Canonicalised paths, symlink-escape prevention, quotas, MIME restrictions. Host source tree is **never** exposed unless explicitly configured. | Core |
| **Context files** | `AGENTS.md`, workspace identity files | `SOUL.md`, `persona.md`, `CLAUDE.md` | Read `AGENTS.md`, `CLAUDE.md`, `SOUL.md`, `PANDORA.md` **only from explicitly configured workspace roots**, and only as untrusted context. | Core |
| **Context construction** | Implicit | Implicit | Explicit, registered, ordered `ContextProvider` pipeline with a token budget and a recorded `context_retrieval` run step showing exactly what was included and what was dropped. Attribute allowlisting; no automatic model serialisation. | Core |
| **Summarisation** | Session compaction | Session compaction | Queued `SummarizeConversation` job producing a `summary` memory item; the summary is a first-class context source. | Core |

## 4. Automation and triggers

| Capability | OpenClaw | Hermes | Pandora approach | Class |
|---|---|---|---|---|
| **Scheduled tasks** | Cron capability | Cron scheduler with delivery to any platform | `Automation` entity: cron / interval / one-off / Laravel scheduler expression, timezone, misfire + concurrency + retry policy, idempotency key, run history, next/last run, manual run. Driven by a single Laravel scheduler entry. | Core |
| **Event-triggered tasks** | Webhooks | — | `Pandora::on(SomeEvent::class)->run('agent')` — a Laravel event listener that queues a run with a mapped context payload. | Core |
| **Webhook triggers** | Supported | — | Signed, replay-protected, per-endpoint secret, idempotency key. | Core |
| **Heartbeats** | `HEARTBEAT.md` checklist; daemon acts proactively without a prompt | — | Reframed as **explicit intuition** — see ADR-0009. Autonomy levels: `observe_only`, `suggest`, `act_with_approval`, `act_within_policy`. Every wake has a recorded trigger, a policy decision, and a bounded budget. Never unbounded self-wake. | Core |
| **Conditional polling** | Implied | — | Automation with a condition callback evaluated before the run is created. | Core |
| **Goal queue / agent-proposed work** | Proactive checklist | Learning loop | Agent may *propose* follow-up work as a pending observation. Promotion to an automation requires a policy decision or a human. | Core (propose) / Future (autonomous promotion) |
| **Visual workflow / DAG editor** | — | SVG DAG editor, topological layout, cycle detection | Not in v1. Deterministic delegation covers the common case; a DAG editor is a product in itself. | Future |
| **Kanban / crew ops boards** | — | Kanban, ops dashboard, animated office | Rejected as scope. Pandora is a framework, not a work-management product. | Unsupported |

## 5. Providers and cost

| Capability | OpenClaw | Hermes | Pandora approach | Class |
|---|---|---|---|---|
| **Provider management** | Providers, failover, local services | Nous Portal, OpenRouter, OpenAI-compatible, local vLLM | Provider-neutral contracts; vendor responses normalised into Pandora DTOs. **No vendor SDK type ever crosses a public Pandora boundary.** Vendor SDKs are optional Composer deps. | Core |
| **Provider adapters** | Claude, GPT, Gemini, Grok, Mistral, DeepSeek, Ollama | OpenRouter (200+), OpenAI-compatible, vLLM | Officially planned: Anthropic, OpenAI, Gemini, OpenRouter, Ollama, generic OpenAI-compatible. | Core (OpenAI-compatible + Anthropic) / Official extension (rest) |
| **Optional adapters** | — | — | Azure OpenAI, Bedrock, Mistral, Groq, xAI, Together, DeepSeek, llama.cpp. | Official extension |
| **Model selection & fallback** | Failover configured | Per-crew-member model | Replaceable `ModelRouter`. v1 is **deterministic**: explicit → run override → conversation override → agent default → fallback chain → provider-outage failover. Capability/cost/latency-aware routing are extension points, not v1 behaviour. | Core (deterministic) / Future (optimising router) |
| **Model catalog & capabilities** | Model config | — | `Model` entity with context limit, modality support, tool-call support, structured-output support, pricing + pricing date. Syncable from provider where an API exists; seeded from config otherwise. | Core |
| **Provider health** | Diagnostics | — | `ProbeProviderHealth` job, latency + error-rate tracking, surfaced on the Health page and consumed by failover. | Core |
| **Usage & token accounting** | — | Token metrics, time-series charts | Normalised `UsageRecord`: input / output / cached / reasoning tokens, audio + image units, requests, duration. | Core |
| **Cost accounting** | — | Cost tracking per crew | Estimated cost with explicit currency, pricing source and pricing date. Documented as an **estimate** unless reconciled against provider billing. | Core |
| **Budgets** | — | — | Per run / conversation / agent / user / tenant / day / month. Breach stops or prevents execution per policy. | Core |
| **Credential management** | File permissions, encrypted config, blocks untrusted `.env` override | Local-only storage | Encrypted at rest via Laravel's encrypter; per-tenant and per-agent resolution through a `CredentialResolver`; never logged, broadcast, prompted or returned by the API. Rotation supported. | Core |

## 6. Channels and interfaces

| Capability | OpenClaw | Hermes | Pandora approach | Class |
|---|---|---|---|---|
| **Built-in web chat** | Web Control UI | Hermes Studio (React) | Blade + Livewire + Reverb. **Mandatory and first-party.** | Core |
| **Messaging channels** | WhatsApp, Telegram, Discord, iMessage, Slack, Signal, WebChat, +plugins | 20+ surfaces | `Channel` contract in core; **no messaging adapter ships in core.** Slack first as the reference extension. | Core (contract) / Official extension (adapters) |
| **Channel identity** | Pairing / allowlist / open / disabled DM policies | — | Channel identity is **never** application identity. Explicit account linking or a host-provided resolver is required before a channel message can act as a user. | Core |
| **Email channel** | — | Email surface | Extension. | Official extension |
| **Mobile / desktop native apps** | macOS, Windows, iOS, Android nodes, Canvas, camera, voice | PWA + Tailscale | Rejected. The control center is responsive; native clients are not a library's job. | Unsupported |
| **IDE integration** | — | VS Code, Zed, JetBrains via ACP | Rejected as out of scope. | Unsupported |
| **Voice / telephony** | Voice on mobile nodes | — | Deferred; `AudioProvider` / `TranscriptionProvider` contracts reserved. | Future |

## 7. Operations

| Capability | OpenClaw | Hermes | Pandora approach | Class |
|---|---|---|---|---|
| **Background jobs** | Daemon-managed | Node process | Laravel queues on six configurable, collapsible queues. Overlap prevention via atomic locks + DB run ownership. | Core |
| **Logs** | Session transcripts with redaction | Audit trail timeline | Application logging stays in Laravel's logger. Pandora adds structured *run traces* (`run_steps`) — a different thing, and kept separate. | Core |
| **Audit logs** | Redacted transcripts on disk | Chronological audit timeline | Append-only `audit_logs` for security-relevant actions with actor, tenant, IP, user agent, correlation ID, target, sanitized metadata. Conceptually separate from application logs. | Core |
| **Health monitoring** | Diagnostics, env checks, doctor-style tooling | System health panel (CPU/mem/disk) | Health page + `pandora:doctor`: queue, worker heartbeat, scheduler heartbeat, Reverb, DB, cache, provider probes, MCP probes, stalled runs, failed jobs, storage, version. Host **process** metrics (CPU/disk) are out of scope. | Core |
| **Import / export** | Marketplace publish; portable Markdown | — | Export agents, skills, automations, memory, conversations as versioned JSON. Import is validated and never executes anything. | Core |
| **Backup & retention** | Files on disk | — | Retention policies + `pandora:prune`. Right-to-delete support across all entities. Database backup is the host's concern. | Core |
| **API access** | Gateway RPC + CLI reference | REST | Optional, disableable, versioned `/api/pandora/v1` using API Resources. Internal Eloquent models are never exposed. | Core |
| **Authentication** | Bearer token / trusted proxy / Tailscale identity | Local | **Host application's guards only.** Pandora ships no auth. This is a deliberate inversion of the reference products' model. | Core |
| **Authorization** | Allowlists; explicitly *not* per-user authz within a gateway | — | Full Laravel gates + policies on every page, action, agent, tool, run and cost view. Fine-grained per-user authorization is exactly what the reference products decline to provide, and it is Pandora's central value. | Core |
| **Multi-tenancy** | Explicitly out of scope ("deploy separate gateways") | Out of scope | Tenancy **abstraction** (no bundled tenancy package). Every data-bearing table is tenant-scopable. Isolation is proven by tests. | Core |
| **Secret management** | fs permissions + encrypted config | Local-only | Encrypted values, a `SecretStore` contract, redaction filters applied to logs, traces, broadcasts and API output. | Core |
| **Control-center pages** | Chat, config, sessions, nodes | 30+ panels | 16 page groups — see `docs/architecture/overview.md`. Original Laravel-oriented design; no visual element derived from either product. | Core |
| **Themes** | — | 8 themes, light + dark | Light + dark only. | Core |

---

## Summary counts

| Class | Count |
|---|---|
| Core | 45 |
| Official extension | 9 |
| Future | 10 |
| Unsupported | 5 |

## The five explicit rejections

1. **Native mobile/desktop clients and IDE integrations** — not a library's responsibility.
2. **Remote marketplace installation of plugins** — arbitrary code execution driven from a web UI.
3. **Kanban / crew work-management boards** — a different product.
4. **Single-operator trust model** — directly contrary to Pandora's reason to exist.
5. **Filesystem as system of record** — incompatible with multi-tenancy, migrations, and Eloquent.

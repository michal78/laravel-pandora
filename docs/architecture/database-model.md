# Database Model

> Status: Phase 0 (discovery). Phase 1 ships only the tables marked **P1**.

## 1. Conventions

| Decision | Choice | Rationale |
|---|---|---|
| Primary key | **ULID**, `char(26)`, string PK | Time-sortable (good index locality, unlike UUIDv4), URL-safe, case-insensitive, no DB-specific type. UUIDv7 is equally good but has no native column type across all four engines; ULID stores portably as `char(26)` everywhere. ADR-0004. |
| Table prefix | `pandora_` | Avoids collisions in a host schema. Configurable. |
| Tenancy | nullable `tenant_id` (`string`, indexed) on every data-bearing table | Null = single-tenant. Global scope. Never a foreign key — the host owns the tenant table. |
| Timestamps | `timestamps()` everywhere; `softDeletes()` only where restore is meaningful | |
| JSON | `->json()` column type | Supported by all four engines. Portable queries use `whereJsonContains` sparingly; anything filtered often is promoted to a real column. |
| Enums | `string` columns + PHP backed enums | Native DB enums are painful to migrate and differ per engine. |
| Money | integer minor units + `currency` `char(3)` | Never floats. |
| Foreign keys | Within Pandora tables; **never** to host tables | Host user/tenant references are `string` + a configurable morph, so we never assume the host's key type. |
| Booleans | `boolean` with explicit defaults | SQLite/MySQL differences handled by Laravel. |

### Portability rules

- No stored procedures, triggers, materialised views, or engine-specific functions.
- No `RETURNING`, no `ON CONFLICT`, no `INSERT IGNORE` — use `updateOrCreate` / unique constraints.
- No full-text index in core (engines differ); lexical memory search uses portable `LIKE`/token
  matching, with better search available via extensions.
- Index name length kept under 64 characters (MySQL limit) — explicit short names on composites.
- `json` columns are never used in a `WHERE` on a hot path.
- **Key order inside a `json` column is not preserved.** MySQL's native JSON type normalises an
  object's keys; SQLite stores the text verbatim. Nothing may depend on the order a JSON object
  round-trips in, and a test that asserts it is asserting the engine, not the behaviour.
- **A value longer than its column is an error, not a truncation.** SQLite accepts an over-long
  value into `char(26)`; MySQL in strict mode refuses the insert. ULIDs are exactly 26 characters,
  including the hand-written ones in fixtures.
- Every migration runs on SQLite, MySQL, MariaDB and PostgreSQL in CI.

## 2. Tables

### Identity and configuration

**`pandora_agents`** — **P1**
`id` · `tenant_id?` · `name` · `slug` · `description?` · `avatar_path?` · `enabled` ·
`definition_class?` (set when class-defined) · `system_instructions?` · `role_instructions?` ·
`default_provider?` · `default_model?` · `fallback_models` json · `provider_options` json ·
`max_iterations` · `max_tool_calls` · `max_duration_seconds` · `context_budget_tokens` ·
`token_budget?` · `cost_budget_minor?` · `currency` · `autonomy_level` · `memory_policy` json ·
`tool_policy` json · `approval_policy` json · `workspace_id?` · `metadata` json · timestamps ·
soft deletes
Unique `(tenant_id, slug)`. Index `(tenant_id, enabled)`.

**`pandora_workspaces`** — P5
`id` · `tenant_id?` · `name` · `slug` · `disk` · `root_path` · `quota_bytes?` · `used_bytes` ·
`allowed_mime_types` json · `metadata` json · timestamps

**`pandora_providers`** / **`pandora_models`** — P3
Provider: `id` · `tenant_id?` · `key` · `label` · `adapter` · `base_url?` · `credential_id?` ·
`options` json · `enabled` · `health_status` · `health_checked_at?` · `latency_ms?` · timestamps
Model: `id` · `provider_key` · `key` · `label` · `context_limit` · `max_output_tokens` ·
`supports_tools` · `supports_streaming` · `supports_structured_output` · `supports_vision` ·
`supports_audio` · `input_price_minor_per_mtok?` · `output_price_minor_per_mtok?` ·
`cached_input_price_minor_per_mtok?` · `currency` · `pricing_source?` · `pricing_date?` ·
`deprecated_at?` · `enabled` · timestamps. Unique `(provider_key, key)`.

**`pandora_credentials`** — P3
`id` · `tenant_id?` · `agent_id?` · `provider_key` · `label` · `secret` (encrypted text) ·
`version` · `valid_from` · `valid_until?` · `last_verified_at?` · `status` · timestamps
No plaintext, ever. Never selected into an API resource.

### Conversation

**`pandora_conversations`** — **P1**
`id` · `tenant_id?` · `agent_id?` · `title?` · `channel` · `status` (active/archived) · `pinned` ·
`tags` json · `parent_conversation_id?` · `forked_at_message_id?` · `provider_override?` ·
`model_override?` · `created_by_type?` · `created_by_id?` · `last_activity_at` · `metadata` json ·
timestamps · soft deletes
Index `(tenant_id, status, last_activity_at)`, `(tenant_id, agent_id)`.

**`pandora_sessions`** — **P1** — *the security boundary*
`id` · `tenant_id?` · `conversation_id?` · `agent_id` · `actor_type?` · `actor_id?` · `channel` ·
`channel_participant_id?` · `origin` (web/api/automation/webhook/channel/delegation) ·
`isolation_key` · `expires_at?` · `metadata` json · timestamps
Unique `(tenant_id, isolation_key)`. `isolation_key` is a deterministic hash of
`(tenant, agent, actor, channel, participant, origin)` — the same tuple always maps to the same
session, and a different tuple never can.

**`pandora_conversation_participants`** — P1
`id` · `conversation_id` · `participant_type` · `participant_id` · `role` · `joined_at`
Unique `(conversation_id, participant_type, participant_id)`.

**`pandora_messages`** — **P1**
`id` · `tenant_id?` · `conversation_id` · `session_id?` · `run_id?` · `role` · `type` · `sequence` ·
`content?` (longtext) · `content_format` (text/markdown/json) · `structured` json · `attachments` json
· `tool_call_id?` · `usage` json · `streaming_state` (pending/streaming/complete/failed) ·
`author_type?` · `author_id?` · `metadata` json · timestamps
Unique `(conversation_id, sequence)`. Index `(tenant_id, conversation_id, id)`.

### Execution

**`pandora_runs`** — **P1**
`id` · `tenant_id?` · `agent_id` · `conversation_id?` · `session_id` · `parent_run_id?` ·
`delegation_depth` · `state` · `trigger_type` · `trigger_id?` · `correlation_id` ·
`idempotency_key?` · `actor_type?` · `actor_id?` · `provider_key?` · `model_key?` · `input?` ·
`output?` · `iterations` · `tool_calls_count` · `input_tokens` · `output_tokens` ·
`cost_minor` · `currency` · `owner_token?` · `owner_expires_at?` · `cancel_requested_at?` ·
`queued_at?` · `started_at?` · `finished_at?` · `deadline_at?` · `error_class?` ·
`error_message?` · `metadata` json · timestamps
Indexes: `(tenant_id, state, created_at)` · `(tenant_id, agent_id, created_at)` ·
`(conversation_id, created_at)` · `(parent_run_id)` · `(state, owner_expires_at)` (stall detection) ·
unique `(tenant_id, idempotency_key)`.

**`pandora_run_steps`** — **P1** — append-only
`id` · `tenant_id?` · `run_id` · `sequence` · `type` · `status` · `label?` · `payload` json
(redacted) · `raw_meta` json (redacted, admin-only) · `input_tokens?` · `output_tokens?` ·
`cost_minor?` · `started_at` · `finished_at?` · `duration_ms?` · `error_class?` · `error_message?` ·
`created_at`
Unique `(run_id, sequence)`. No `updated_at` — steps are never mutated. Enforced by a model trait
that throws on update, plus an architecture test.

**`pandora_tool_executions`** — P2
`id` · `tenant_id?` · `run_id` · `run_step_id?` · `tool_name` · `tool_version` · `tool_call_id` ·
`arguments` json · `sanitized_arguments` json · `arguments_modified` · `result` json ·
`sanitized_result` json · `status` · `risk_level` · `required_approval` · `approval_id?` ·
`approver_type?` · `approver_id?` · `idempotency_key` · `attempt` · `started_at?` · `finished_at?` ·
`duration_ms?` · `error_class?` · `error_message?` · `metadata` json · timestamps
Unique `(run_id, tool_call_id, attempt)`. Index `(tenant_id, tool_name, created_at)`.

**`pandora_approvals`** — P2
`id` · `tenant_id?` · `run_id` · `tool_execution_id?` · `tool_name` · `risk_level` · `summary` ·
`sanitized_arguments` json · `proposed_modifications` json? · `scope` (once/run/remembered) ·
`status` (pending/approved/denied/expired/cancelled) · `requested_by_type?` · `requested_by_id?` ·
`resolved_by_type?` · `resolved_by_id?` · `comment?` · `expires_at` · `resolved_at?` · timestamps
Index `(tenant_id, status, expires_at)`.

### Knowledge

**`pandora_memory_items`** — P5
`id` · `tenant_id?` · `scope` · `scope_id?` · `agent_id?` · `type` · `title?` · `content` ·
`structured` json · `source` · `source_run_id?` · `provenance` json · `confidence` · `sensitivity` ·
`status` (active/suggested/rejected/expired) · `expires_at?` · `embedding_id?` · `metadata` json ·
timestamps · soft deletes
Index `(tenant_id, scope, scope_id, status)`, `(tenant_id, agent_id, type)`.

**`pandora_embeddings`** — P5
`id` · `tenant_id?` · `owner_type` · `owner_id` · `provider_key` · `model_key` · `dimensions` ·
`vector` json (portable default; adapters use native types) · `content_hash` · timestamps

**`pandora_skills`** / **`pandora_skill_assignments`** — P5/P6
Skill: `id` · `tenant_id?` · `name` · `slug` · `version` · `author?` · `description` ·
`instructions` longtext · `manifest` json · `trigger_hints` json · `required_tools` json ·
`required_abilities` json · `files` json · `source` · `validation_status` · `validation_errors` json ·
`enabled` · timestamps. Unique `(tenant_id, slug, version)`.

### Automation

**`pandora_automations`** — **P4**
`id` · `tenant_id?` · `agent_id` · `name` · `slug` · `description?` · `trigger_type` (one_off /
cron / interval / event / webhook / heartbeat) · `cron_expression?` · `interval_seconds?` ·
`run_at?` · `timezone` · `event_class?` · `condition` json? · `prompt?` · `context` json ·
`delivery` json · `concurrency_policy` · `misfire_policy` · `retry_policy` json ·
`autonomy_level` · `autonomy_budget_runs?` · `autonomy_budget_window_seconds` ·
`webhook_secret?` (encrypted) · `enabled` · `last_run_at?` · `next_run_at?` · `last_run_id?` ·
`consecutive_failures` · `disabled_at?` · `disabled_reason?` · `metadata` json · timestamps ·
soft deletes
Index `(enabled, next_run_at)` — the scheduler's only query. Index `(enabled, event_class)` — the
event dispatcher's. Unique `(tenant_id, slug)`.

`webhook_secret` is on this row rather than in a separate `pandora_webhook_endpoints` table, which
the Phase 0 sketch had proposed. An endpoint that is not an automation points at nothing, and nobody
needs two endpoints for one automation. The automation **is** the endpoint.

**`pandora_automation_runs`** — **P4**
`id` · `tenant_id?` · `automation_id` · `run_id?` · `scheduled_for` · `status` (claimed /
dispatched / skipped / refused / failed) · `reason?` · `idempotency_key` · `error?` ·
`metadata` json · timestamps. Unique `(automation_id, idempotency_key)` — the double-fire guard.

The insert **is** the claim. A refused or skipped occurrence is still written, because a silence
cannot be told apart from a scheduler that stopped running a week ago.

**`pandora_observations`** — **P4**
`id` · `tenant_id?` · `agent_id` · `run_id?` · `title` · `proposal` · `rationale?` ·
`suggested_cron?` · `status` (pending / promoted / dismissed / expired) · `automation_id?` ·
`resolved_by_type?` · `resolved_by_id?` · `resolved_at?` · `comment?` · `expires_at?` ·
`metadata` json · timestamps
The goal queue. Deliberately inert: nothing leaves `pending` without a human.

### Integration

**`pandora_channels`** / **`pandora_channel_identities`** — P7
Identity links a channel-side participant to a host user only through an **explicit** linking record
with a verification timestamp. Unique `(channel_id, external_id)`.

**`pandora_mcp_servers`** / **`pandora_mcp_tools`** — P6
Server: transport, endpoint, credential, enabled, health, last discovery.
Tool: server, name, namespaced name, schema json, schema hash, approved, approved_at.
A changed `schema_hash` clears `approved` — a silently-mutated remote schema must be re-approved.

**`pandora_webhook_deliveries`** — **P4**
`id` · `tenant_id?` · `automation_id` · `run_id?` · `signature` · `status` (accepted / rejected) ·
`reason?` · `source_ip?` · `payload_bytes` · `payload` json (redacted) · timestamps
Unique `(automation_id, signature)` — the replay nonce. Timestamp tolerance alone is not a replay
defence: the window has to survive clock skew, and inside it a request can be resent freely.
Rejections are stored too — a stream of them is the earliest sign of a one-sided secret rotation.

`pandora_webhook_endpoints` from the Phase 0 sketch was not built; see `pandora_automations` above.

### Accounting and audit

**`pandora_usage_records`** — P3
`id` · `tenant_id?` · `run_id?` · `run_step_id?` · `agent_id?` · `conversation_id?` · `actor_type?` ·
`actor_id?` · `provider_key` · `model_key` · `operation` · `input_tokens` · `output_tokens` ·
`cached_input_tokens` · `cached_output_tokens` · `reasoning_tokens` · `audio_units` · `image_units` ·
`requests` · `duration_ms` · `cost_minor` · `currency` · `pricing_source?` · `pricing_date?` ·
`occurred_on` (date) · timestamps
Index `(tenant_id, occurred_on)` · `(tenant_id, agent_id, occurred_on)` · `(provider_key, model_key,
occurred_on)`. `occurred_on` as a real date column keeps the Usage page portable and fast.

**`pandora_budgets`** — P3
`id` · `tenant_id?` · `scope` · `scope_id?` · `period` (run/day/month/total) · `token_limit?` ·
`cost_limit_minor?` · `currency` · `action` (warn/block) · `enabled` · timestamps

**`pandora_audit_logs`** — P2 — append-only
`id` · `tenant_id?` · `correlation_id?` · `actor_type?` · `actor_id?` · `action` · `target_type?` ·
`target_id?` · `run_id?` · `severity` · `ip?` · `user_agent?` · `metadata` json (sanitized) ·
`created_at`
Index `(tenant_id, created_at)` · `(tenant_id, action, created_at)` · `(correlation_id)` ·
`(target_type, target_id)`. No `updated_at`; update throws.

**`pandora_settings`** — P1
`id` · `tenant_id?` · `key` · `value` json · `updated_by_type?` · `updated_by_id?` · timestamps
Unique `(tenant_id, key)`. **Runtime settings only.** Deployment configuration stays in
`config/pandora.php` — see ADR-0010.

## 3. Phase 1 subset

Nine tables: `agents`, `conversations`, `sessions`, `conversation_participants`, `messages`, `runs`,
`run_steps`, `settings`, `audit_logs`.

Enough for the full vertical slice, and every one of them is a table the later phases build on rather
than replace.

## 4. Retention

| Table | Default | Configurable |
|---|---|---|
| `run_steps` | 90 days | yes |
| `runs` | 365 days | yes |
| `messages` | with conversation | yes |
| `usage_records` | 730 days | yes |
| `audit_logs` | 730 days, never auto-pruned below the configured legal minimum | yes |
| `webhook_deliveries` | 30 days | yes |

`pandora:prune` enforces these in chunks, respecting foreign keys, on `pandora-maintenance`.

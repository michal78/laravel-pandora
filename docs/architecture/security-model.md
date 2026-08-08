# Security Model

> Status: Phase 0 (discovery). Controls described here are the design of record; see
> `docs/development/progress.md` for what is implemented and `tests/Security/` for what is proven.

## 1. Statement of position

OpenClaw's security documentation is admirably direct: it "is not a hostile multi-tenant security
boundary for multiple adversarial users sharing one agent or gateway," and for adversarial scenarios
recommends deploying separate gateways under distinct OS users. That is the correct engineering
answer *for a personal agent daemon*.

Pandora cannot make that statement, because Pandora is installed into applications that serve
mutually-untrusting users. **Everything in this document exists because we chose the harder trust
model.**

We are equally direct about a limit: **prompt injection is not solved, and Pandora does not claim to
solve it.** The model will at some point be persuaded to try something it should not. Pandora's design
assumes that will happen and constrains what a persuaded model can reach.

## 2. Trust boundaries

```
┌──────────────────────────────────────────────────────────────┐
│ TRUSTED — deployment-controlled, version-controlled          │
│   config/pandora.php · AgentDefinition classes · Tool classes│
│   Laravel policies · migrations · framework system prompt    │
└──────────────────────────────────────────────────────────────┘
      ▲ only a deploy changes this
┌──────────────────────────────────────────────────────────────┐
│ SEMI-TRUSTED — admin-authored at runtime, audited            │
│   DB agent config · tool policies · automations · MCP servers│
│   provider credentials · workspace roots                     │
└──────────────────────────────────────────────────────────────┘
      ▲ requires an authorized admin + an audit record
┌──────────────────────────────────────────────────────────────┐
│ UNTRUSTED — assume hostile, always                           │
│   user messages · model output · tool output · retrieved docs│
│   memory contents · imported skills · MCP tool descriptions  │
│   channel messages · uploads · context files · HTTP responses│
└──────────────────────────────────────────────────────────────┘
```

**The load-bearing rule: model output is untrusted input.** A tool call is a *request*, never an
instruction. It is validated, policy-checked and authorized exactly as if it had arrived as an
unauthenticated HTTP request from the internet — because in effect it did.

## 3. Threat model

| # | Threat | Control |
|---|---|---|
| T1 | Injected instructions in a document/webpage/tool result cause a destructive tool call | Tool calls are authorized against the *actor's* abilities, not the agent's. `high`/`critical` tools require approval. Untrusted content is delimited and labelled in the prompt. Approval UI shows the real arguments. |
| T2 | Cross-tenant data leak | Tenant column on every data-bearing table + global scope + `TenantResolver`. Direct-ID lookups are always tenant-scoped. Explicit tests. |
| T3 | Cross-session context leak (two users, one conversation; shared channel inbox) | Session is a security boundary keyed on `(tenant, agent, actor, channel, participant, origin)`. Context and memory retrieval are session-scoped. **Channel identity is never application identity**: an unlinked participant gets no actor, no session and no run, and nothing infers a host user from a channel-supplied email, username or id (ADR-0015). Linking needs a code issued into the channel *and* redemption in an authenticated host session; a re-link bumps an epoch inside the isolation key, so a reassigned handle inherits nothing. Verified by `Channels/SessionIsolationTest`, `UnlinkedIdentityTest`, `LinkRedemptionTest`, `LinkRevocationTest`, `TenancyTest`. |
| T4 | Provider credential exfiltration via prompt or tool output | Credentials resolve at HTTP-call time inside the adapter. Never in context, never in a step payload, never broadcast, never in an API resource. Redaction filter on logs, traces, broadcasts, API output. |
| T5 | Workspace path traversal / symlink escape | Canonicalise, then assert the resolved real path is a descendant of the canonical root. Reject symlinks resolving outside. Disk-level root confinement as a second layer. |
| T6 | SSRF via the HTTP tool or a fetched URL | Host allowlist (deny by default). Resolve DNS then block private/link-local/loopback/metadata ranges. Re-validate after every redirect. Cap redirects, size and time. |
| T7 | Runaway cost or infinite loop | Iteration limit, tool-call limit, token budget, monetary budget, wall-clock timeout, duplicate tool-call detection, delegation depth, autonomy budget. All enforced in the loop, all persisted. |
| T8 | Privilege escalation through delegation | Child run's effective abilities are the **intersection** of parent and child agent permissions. Delegation never widens authority. Verified by `Delegation/IntersectionTest`. |
| T9 | Malicious imported skill | Skills are instructions only. Never executed. Import validates the manifest, strips nothing silently, and surfaces warnings. Embedded install instructions are never auto-run. |
| T10 | Hostile MCP server (tool *descriptions* are an injection vector) | Remote tools untrusted until explicitly approved **per agent, per tool**. Namespaced, with resolution split by origin so a remote tool cannot resolve where a core one is expected. Descriptions bounded, escaped, marked foreign, never in an instruction position — and **inside the approval hash**, so rewriting one clears approval and fails the tool closed (ADR-0014). Verified by `Mcp/UntrustedDescriptionTest`, `SchemaHashTest`, `NamespaceTest`, `ApprovalTest`. |
| T11 | Broadcast eavesdropping | Private channels with authorization callbacks. Payloads redacted before broadcast. Never broadcast system prompts, secrets, raw tool arguments for sensitive tools, or exception dumps in production. |
| T12 | Webhook forgery / replay | HMAC signature, timestamp window, nonce store, per-endpoint secret, idempotency key. |
| T13 | Unauthorized control-center access | Every page and action behind a gate. No implicit "authenticated ⇒ admin". Separate abilities for prompts, tool I/O, costs and audit logs. |
| T14 | Approval bypass via a race | Approval resolution and run resumption are transactional under the run lock. An approval is consumed exactly once; the tool call is re-validated at execution time. |
| T15 | Mass assignment / unsafe deserialisation | No `$guarded = []`. Explicit fillable. Payloads stored as JSON of scalars; no PHP serialisation of user-influenced data. |

## 4. Authorization model

### Layers a tool call must clear

```
1. Registry        — is the tool registered and enabled at all?
2. Agent           — is it in this agent's allowlist and not its denylist?
3. Tenant          — is it permitted for this tenant?
4. ToolPolicy      — allow | deny | require_approval | require_confirmation | modify_arguments
5. Tool::authorize — the tool's own check against Laravel gates/policies for the *actor*
```

All five must pass. Layer 5 is the one that matters most to host developers: it is ordinary Laravel
authorization, written against the acting user, and it is what makes an agent unable to do something
the user could not do themselves.

### Abilities

Registered gates, each independently assignable — the design refuses the assumption that an
authenticated user is an administrator:

`pandora.access` · `pandora.chat` · `pandora.chat.agent.{slug}` · `pandora.prompts.view` ·
`pandora.tools.io.view` · `pandora.approvals.resolve` · `pandora.agents.manage` ·
`pandora.tools.manage` · `pandora.providers.manage` · `pandora.automations.manage` ·
`pandora.memory.manage` · `pandora.workspaces.access` · `pandora.usage.view` · `pandora.costs.view` ·
`pandora.audit.view` · `pandora.settings.manage` · `pandora.runs.trace.view` · `pandora.mcp.manage`

The host may override every one via configuration callbacks or by binding its own resolver.

### Argument modification

`modify_arguments` is a real capability (clamp a refund amount, force a tenant filter) and therefore
a real risk. It is always: recorded as an audit entry, shown in the approval UI as a diff, and
included in the run trace. Silent argument rewriting is forbidden.

## 5. Secrets

- Encrypted at rest with Laravel's encrypter; a `SecretStore` contract allows KMS/Vault extensions.
- Resolved lazily, per-request, inside the provider adapter — never held on a serialised job payload.
- Redaction applied at four egress points: logs, run steps, broadcasts, API resources.
- The API and UI expose credential *status* (present / valid / last verified), never values.
- `pandora:doctor` warns if secrets appear in a published config file committed to the repository.

## 6. Prompt-injection defences (layered, not absolute)

1. **Least authority** — the agent acts as the actor; it cannot exceed the actor's abilities.
2. **Approval gates** on `high`/`critical` risk, showing real arguments to a human.
3. **Delimiting and labelling** untrusted content in the prompt, with a standing framework
   instruction that content inside those boundaries is data, not instructions.
4. **Tool allowlists per agent** — a support agent has no shell, no HTTP, no SQL.
5. **Output validation** — tool arguments are schema- and rule-validated before they reach code.
6. **Egress control** — HTTP allowlists, workspace containment, no arbitrary file or process access.
7. **Budgets** — a persuaded agent runs out of iterations, tokens and money.
8. **Audit** — everything attempted is recorded, whether or not it succeeded.

None of these prevents a model being persuaded. Together they bound the blast radius. That is the
honest claim, and it is the only one we make.

## 7. Multi-tenancy

Pandora bundles no tenancy package. It defines:

```php
interface TenantResolver { public function current(): ?TenantContext; }
interface ActorResolver  { public function current(): ?ActorContext; }
```

Single-tenant applications get a null tenant and every scope becomes a no-op — no configuration
required. Multi-tenant applications bind their own resolvers. Every data-bearing table carries a
nullable `tenant_id`; a global scope applies it; queued jobs carry the tenant context explicitly
rather than re-resolving from a request that no longer exists.

## 8. Data protection

Retention policies per entity, `pandora:prune`, cascade-correct deletion for right-to-delete
requests, versioned JSON export, and sensitivity classification on memory items with approval before
storing sensitive facts.

**Memory (Phase 5).** The scope of a retrieval is derived from the run's session before any provider
or tool is consulted, and no tool exposes a parameter that could widen it — `recall` takes a search
string and nothing else. A vector store is an accelerator and never an authority: every candidate it
proposes is re-filtered against the same constraint in the database, so an index Pandora does not
control cannot surface anything the database would have hidden. Content that looks like a credential
is refused outright; every claim about a person is held for a human. Forgetting hard-deletes the
vector and soft-deletes the row, because a soft-deleted row with a live vector is still findable by
the path that matters. Export is gated and audited at `warning` — one call returns everything an
agent believes about a person.

**Workspaces (Phase 5).** Containment is checked after `realpath()` and on every operation, never
against the string a caller passed: `../` has many spellings and a symlink has none. Reads *and*
writes are checked, an escaping symlink is omitted from listings as well as refused, and a refusal
names only the relative path the caller supplied — saying where a symlink pointed confirms both that
the file exists and where the root is. A containment violation is audited at `critical`.

## 9. What we will not claim

- That prompt injection is solved.
- That policy restriction is sandboxing. Without a container/OS boundary, a shell tool is only as
  contained as the PHP process. The docs say so wherever a sandbox adapter is discussed.
- That estimated cost is billed cost.
- That an audit log is tamper-proof against someone with database write access.

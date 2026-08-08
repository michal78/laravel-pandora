# Phase 6 — Acceptance Test Plan

> **Status: delegation done, MCP not started. 13 of 30 criteria verified.**
>
> Nothing below is ticked on the strength of code existing; each criterion is ticked only when the
> named automated test asserts it and that test passes.
>
> Criteria 1–13 (delegation) are verified and the suite is green on every commit. Criteria 14–30
> (MCP) have no code and no tests: `src/Mcp` does not exist.
>
> **Two defects reached the walkthrough that the suite could not have caught**, and both are worth
> reading before writing the MCP half:
>
> - A run with no conversation could not see its own tool loop, so every delegated child repeated
>   one call until its iteration budget ended it. The suite scripted children that answered on
>   their first turn, so nothing ever asked what a child could remember. Now
>   `Delegation/ChildMemoryTest`.
> - A refused call recorded nothing an operator could read — the reason existed only in a result
>   blob or not at all. Delegation refusals are correct, bounded and were invisible.
>
> The pattern in both: the guard was right, the run was wrong, and nothing failed loudly enough to
> notice. A criterion asserting a refusal is *recorded* is worth as much as one asserting it
> happened.

Every phase so far has widened what Pandora can *do*. Phase 6 is the first that widens who is
allowed to ask — an agent may now call another agent, and a tool may now live on a machine that is
not ours and be described by someone we have never met.

Both halves fail the same way. Delegation is a privilege problem: the moment a run can start another
run, the interesting question stops being "may this actor do this?" and becomes "may this actor do
this *through* something else?" A support agent denied the shell does not need the shell if it can
ask an agent that has one. MCP is a trust problem with the same shape: a remote tool's *description*
is text written by a third party that lands in our prompt, and a tool schema that changed between
approval and call is a tool nobody approved.

Three properties dominate the acceptance bar:

**Delegation is an intersection, never a union.** A child run's effective abilities are the overlap
of the parent's and the child agent's, computed at delegation time and persisted on the child run so
that a trace can later explain what it was allowed to do and why. If delegation could ever produce
an ability the parent lacked, then every permission boundary in the product is decorative: the way
around it is one hop long.

**Everything a remote server says is untrusted content, including the parts that look like
configuration.** A tool name, a namespace, a JSON schema and above all a description are attacker
input (T10). They are hashed at approval, re-hashed at call time, escaped at render time, and
bounded in length. A description is not metadata — it is a sentence we voluntarily paste into a
prompt.

**A budget spent by a child is spent by the parent.** Depth, iterations, tokens, money and wall
clock are properties of the run *tree*, not the run. A limit that resets per child is not a limit;
it is a multiplier, and T7 is the threat that pays for it.

## Scope

`DelegateToAgent` · child runs with `parent_run_id` and `delegation_depth` · ability intersection ·
budget inheritance · cancellation propagation · structured results · MCP client with transports,
discovery, schema caching and hashing, per-agent permissions, namespacing and health · optional
authenticated Pandora MCP server with an explicit exposure allowlist · MCP UI · the agent's
**Permissions** tab · skill discovery from MCP · `pandora:mcp:list`, `pandora:mcp:discover`,
`pandora:mcp:approve`.

## Design decisions taken for this phase

| Decision | Choice | Rationale |
|---|---|---|
| Delegation mechanism | An ordinary tool (`DelegateToAgent`), authorized against the actor like any other (ADR-0007) | A second privileged path into run creation is a second place to get authorization wrong. An agent that may not delegate simply is not given the tool. |
| Effective abilities | The **intersection** of parent run and child agent, computed once at delegation and persisted on the child run | T8. Recomputing per tool call invites the two sides to drift; persisting makes the trace answer "why was this allowed" without re-deriving history. |
| Which agents may be called | An explicit per-agent allowlist, empty by default | Same rule as tools: an agent reachable by omission is an agent nobody chose to expose. "Any enabled agent" is a graph where one weak node is every node. |
| Budget | The child draws from the parent's **remaining** budget and its spend is debited to the parent's tree | A fresh budget per child turns a depth limit into a cost multiplier. |
| Depth | `max_delegation_depth` denies the *tool*, and the parent continues with a tool error | Failing the whole run makes a bounded refusal look like an outage, and teaches operators to raise the limit. |
| Cycles | Bounded by depth, and a run whose ancestry already contains the target agent is refused outright | Depth alone terminates A→B→A, but it terminates it by spending the whole budget first. |
| Cancellation | Propagates parent → children, never child → parent | Cancelling a delegate must not kill the conversation that asked for it. |
| The child's result | Returned as a structured tool result, and treated as **untrusted content** | A sub-agent that read a hostile page returns a hostile string. Delegation output enters the parent's prompt through the same door as any tool result. |
| Parallel delegation | Out of this phase — one child at a time, the parent in `waiting_for_tool` | Fan-out multiplies budget accounting, cancellation and partial-failure semantics simultaneously. Sequential first, and no contract that forbids fan-out later. |
| MCP transports | HTTP/SSE enabled; **stdio disabled by default** and gated behind explicit configuration | stdio means executing a local binary chosen by a database row. That may be reasonable on a developer machine and is never reasonable by default. |
| MCP credentials | Stored encrypted, resolved by the Phase 3 credential resolver | A second secret store is a second thing to leak. |
| Discovery | Runs on a queue, writes `pandora_mcp_tools`, and **approves nothing** | Discovery is a read of an untrusted source. Anything that both discovers and enables is a remote-controlled permission grant. |
| Approval granularity | Per agent, per tool — never per server | "Trust this server" is a blanket that keeps covering tools added after it was issued. |
| Schema hash | Canonical JSON over name, namespaced name, description **and** input schema | Hashing only the schema misses the injection vector: a server may keep its parameters and rewrite its description into an instruction. |
| A changed hash | Clears `approved`, records at `warning`, and the call fails closed until re-approved | The alternative — call it and warn — is approval that means nothing after the first mutation. |
| Namespacing | `server.tool`, reserved separator, and a remote tool can never resolve where a core tool name is expected | Shadowing `request_approval` is the whole game. |
| Remote descriptions | Length-bounded, escaped on render, and never interpolated into an instruction position in the prompt | They are third-party text displayed to operators and shown to a model; both are injection surfaces. |
| Remote tool failure | An ordinary tool error inside the run, with a timeout and a response size cap | A remote server that hangs must cost one tool call, not one worker. |
| Server health | Probed like a provider; an unhealthy server's tools are unavailable rather than slow | Unchanged from Phase 3, and right for the same reason. |
| The Pandora MCP server | Off by default, authenticated, and exposes only what an explicit allowlist names | Nothing is exposed by being installed. |
| Exposure and authorization | The server authorizes every exposed call against the **actor behind the token**, not the token's mere validity | An exposure allowlist decides what exists; the ability check decides who may call it. Skipping the second makes the token a superuser. |
| MCP primitives | Tools only. Resources, prompts and sampling are out | Sampling in particular inverts the trust direction — a remote server asking us to spend a model call on its behalf is a budget hole with a protocol around it. |
| Skills from MCP | Discovered skills are instructions, still never executed (ADR-0008), and land unapproved | The second source Phase 5 anticipated. A skill from a remote server is exactly the untrusted-instruction case the ADR exists for. |

## Criteria

| # | Criterion | Verified by |
|---|---|---|
| 1 ✅ | A child run persists `parent_run_id` and `delegation_depth = parent + 1`, with `origin` = delegation | `Delegation/ChildRunTest` |
| 2 ✅ | **A child's effective abilities are the intersection of parent and child agent — an ability the parent lacks is absent from the child** | `Delegation/IntersectionTest` |
| 3 ✅ | **A tool denied to the parent cannot be called by the child, even when the child agent allows it** | `Delegation/IntersectionTest` |
| 4 ✅ | The intersection is persisted on the child run and reproduced in its trace | `Delegation/IntersectionTest` |
| 5 ✅ | An agent absent from the parent agent's delegation allowlist cannot be delegated to; the default allowlist is empty | `Delegation/AllowlistTest` |
| 6 ✅ | **Delegation beyond `max_delegation_depth` denies the tool and the parent continues with a tool error** | `Delegation/DepthTest` |
| 7 ✅ | A delegation whose ancestry already contains the target agent is refused before the child run is created | `Delegation/CycleTest` |
| 8 ✅ | **A child's token and monetary spend is debited to the parent's tree and cannot exceed the parent's remaining budget** | `Delegation/BudgetTest` |
| 9 ✅ | A child that exhausts the shared budget ends `budget_exhausted` and the parent is told, rather than the tree continuing | `Delegation/BudgetTest` |
| 10 ✅ | The parent enters `waiting_for_tool` and resumes with the child's structured result appended as a tool result | `Delegation/LifecycleTest` |
| 11 ✅ | **Cancelling a parent cancels its children, transitively; cancelling a child never cancels the parent** | `Delegation/CancellationTest` |
| 12 ✅ | A child's result is redacted and treated as untrusted content on the way into the parent's context | `Delegation/UntrustedResultTest` |
| 13 ✅ | A delegated run is attributable — the trace names the initiating actor, not the parent agent | `Delegation/AttributionTest` |
| 14 | An MCP server persists with transport, endpoint, encrypted credential and health; the credential is never readable through the UI or API | `Mcp/ServerTest` |
| 15 | **stdio transport is refused unless explicitly enabled in configuration** | `Mcp/TransportTest` |
| 16 | Discovery writes `pandora_mcp_tools` with schema and hash and leaves every tool **unapproved** | `Mcp/DiscoveryTest` |
| 17 | **An unapproved remote tool is not offered to the model and is refused if called** | `Mcp/ApprovalTest` |
| 18 | Approval is per agent per tool — approving for one agent grants nothing to another | `Mcp/ApprovalTest` |
| 19 | **A changed schema hash clears approval, records `mcp.schema_changed`, and the tool fails closed until re-approved** | `Mcp/SchemaHashTest` |
| 20 | **The hash covers the description — a server that changes only its description invalidates approval** | `Mcp/SchemaHashTest` |
| 21 | **A remote tool cannot shadow or be resolved as a core tool, whatever it names itself** | `Mcp/NamespaceTest` |
| 22 | A remote description is length-bounded and escaped where rendered; it never occupies an instruction position in the prompt | `Mcp/UntrustedDescriptionTest` |
| 23 | A remote call that hangs fails on timeout as a tool error, and an oversized response is truncated and refused, without failing the worker | `Mcp/RemoteFailureTest` |
| 24 | An unhealthy server's tools are unavailable, and the run says so rather than waiting | `Mcp/HealthTest` |
| 25 | Remote tool calls are recorded as tool executions with arguments and results redacted like any other | `Mcp/AuditTest` |
| 26 | **The MCP server is disabled by default and exposes nothing that the allowlist does not name** | `McpServer/ExposureTest` |
| 27 | **An authenticated MCP server call is authorized against the actor behind the token — a valid token for an actor lacking the ability is refused** | `McpServer/AuthorizationTest` |
| 28 | An MCP server call cannot reach another tenant's data, whatever it asks for | `McpServer/TenancyTest` |
| 29 | A skill discovered from MCP lands unapproved, is stored as instructions only, and is never executed | `Mcp/SkillDiscoveryTest` |
| 30 | `pandora:mcp:list`, `:discover` and `:approve` behave, and `:approve` refuses a tool whose hash has changed since discovery | `Mcp/CommandsTest` |

Test files: `Delegation/ChildRunTest` · `IntersectionTest` · `AllowlistTest` · `DepthTest` ·
`CycleTest` · `BudgetTest` · `LifecycleTest` · `CancellationTest` · `UntrustedResultTest` ·
`AttributionTest` · `ChildMemoryTest` (a child must be able to see its own tool loop — a run with
no conversation has no message history, and the amnesia reads as a budget failure); `Mcp/ServerTest` · `TransportTest` · `DiscoveryTest` · `ApprovalTest` ·
`SchemaHashTest` · `NamespaceTest` · `UntrustedDescriptionTest` · `RemoteFailureTest` ·
`HealthTest` · `AuditTest` · `SkillDiscoveryTest` · `CommandsTest`; `McpServer/ExposureTest` ·
`AuthorizationTest` · `TenancyTest`; plus `UI/McpPageTest` and additions to `UI/AgentDetailTest`
for the Permissions tab.

A `FakeMcpServer` — scripted responses, mutating schemas, hostile descriptions, hangs and oversized
payloads — is a deliverable of this phase, not a test fixture detail. Every criterion above that
concerns a remote server is worthless if the only server it was ever run against was well behaved.

## Audit actions this phase must produce

`delegation.started` · `delegation.completed` · `delegation.denied` (severity `warning` — an ability
intersection or an allowlist refused it) · `delegation.depth_exceeded` (severity `warning`) ·
`delegation.cycle_refused` (severity `warning`) · `run.cancellation_propagated` ·
`mcp.server_registered` · `mcp.discovery_completed` · `mcp.tool_approved` · `mcp.tool_revoked` ·
`mcp.schema_changed` (severity `warning` — approval was cleared by something the remote end did) ·
`mcp.call_failed` · `mcp.server_unreachable` (severity `warning`) · `mcp.exposure_denied` (severity
`warning` — someone with a valid token asked for something not exposed)

## Explicitly out of scope

Parallel or fan-out delegation, and any agent-to-agent protocol richer than one call and one
structured return. Delegation across tenants, in any direction. MCP resources, prompts, sampling,
elicitation and roots — tools only. Automatic approval of anything discovered, including under a
"trusted server" flag. An MCP marketplace, registry or auto-installer. Remote *agents* (an MCP
server exposing something that runs its own loop); the exposure allowlist names tools. Long-lived
delegation — a child outliving its parent's run.

## Definition of done

- [ ] All 30 criteria have tests, and they pass
- [ ] `vendor/bin/pest` green on all four engines, including the pgvector leg
- [ ] `vendor/bin/phpstan analyse` clean at level 8, with no ignores and no baseline entries
- [ ] `vendor/bin/pint --test` clean
- [ ] `docs/development/progress.md`, `docs/roadmap.md`, `docs/architecture/database-model.md`,
      `docs/architecture/security-model.md` (T8 and T10 rows now point at tests), a new
      `docs/guides/delegation.md`, `docs/guides/mcp.md` and `CHANGELOG.md` updated
- [ ] An ADR for the MCP trust boundary — what is hashed, what clears approval, and why the server
      is off by default
- [ ] **A human drives the pages in a host application**, against a `phase-6-walkthrough.md`,
      including the check the suite structurally cannot make: a real MCP server, changed after
      approval, whose tool stops working until a person looks at what changed

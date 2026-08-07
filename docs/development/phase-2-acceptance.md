# Phase 2 — Acceptance Test Plan

> **Status as of 2026-08-07: 36 of 36 criteria verified, and the host walkthrough is complete.**
> It found four defects — see `phase-2-walkthrough.md`. The database matrix closed on
> 2026-08-06 — the full suite now passes on MySQL 8.4, MariaDB 11 and PostgreSQL 17, and making the
> matrix genuine found three defects (see `progress.md`).
>
> ```
> vendor/bin/pest        -> Tests: 431 passed (1,580 assertions)
> vendor/bin/phpstan     -> [OK] No errors  (level 8, checkModelProperties on)
> vendor/bin/pint --test -> passed
> ```
>
> Nothing below is ticked on the strength of code existing; each criterion is ticked only when the
> named automated test asserts it and that test passes.

Phase 2 gives an agent hands. Every safety property of the system is won or lost here, so the
acceptance bar is deliberately higher than Phase 1's: the majority of the criteria below are
*negative* — they assert that something does **not** happen.

## Scope

Tool contract with typed input and schema generated from validation rules · registry with groups,
aliases, versioning and deprecation · the five authorization layers · `ToolPolicy` with all five
outcomes and audited argument modification · `ExecuteToolCall` with idempotency and duplicate
detection · risk levels · `Approval` with once/run/remembered scopes, expiry and comments · pause to
`waiting_for_approval` and resume via `ResumeApprovedRun` · `AskUser` and the `waiting_for_user`
resume path · live tool and approval cards · `pandora_tool_executions` and `pandora_approvals` ·
full audit coverage · Tools and Approvals UI pages · the built-in low-risk tool set.

## Design decisions taken for this phase

| Decision | Choice | Rationale |
|---|---|---|
| Input typing | A validated `ToolInput` bag by default; a per-tool input DTO class is optional via `$input` | Full typing where it earns its keep, no ceremony for a two-field tool. ADR-0007 is satisfied either way. |
| Schema source | Generated from `rules()`; unsupported rules throw at **registration** | One source of truth. A wrong schema is worse than a loud failure. |
| Outstanding tool calls | Derived by counting non-terminal `tool_executions` inside a transaction that locks the run row | Race-free without a counter column that can drift after a retry. |
| Argument modification | Always recorded, always diffed, never silent | `modify_arguments` is a real capability and therefore a real risk. |
| Tool authorization subject | The **actor**, never the agent | ADR-0007. The single most important safety property in the system. |

## Criteria

| # | Criterion | Verified by |
|---|---|---|
| 1 | ✅ A tool's JSON schema is generated from its Laravel validation rules and matches them | `Tools/SchemaGenerationTest` |
| 2 | ✅ A validation rule the generator does not support throws at registration, naming the rule | `Tools/SchemaGenerationTest` |
| 3 | ✅ Model-supplied arguments failing validation are rejected before any tool code runs, and the model is told why | `Tools/ToolValidationTest` |
| 4 | ✅ The registry resolves by name, alias and version; a deprecated tool still resolves but is flagged | `Tools/ToolRegistryTest` |
| 5 | ✅ An unregistered tool name requested by the model is denied (layer 1) | `Tools/ToolGatekeeperTest` |
| 6 | ✅ A tool absent from the agent's allowlist, or present in its denylist, is denied (layer 2) | `Tools/ToolGatekeeperTest` |
| 7 | ✅ A tool not permitted for the tenant is denied (layer 3) | `Security/ToolTenantIsolationTest` |
| 8 | ✅ Each of the five `ToolPolicy` outcomes takes effect (layer 4) | `Tools/ToolPolicyTest` |
| 9 | ✅ **A tool the acting user's gates forbid is denied even when the agent, tenant and policy all allow it** (layer 5) | `Security/ToolAuthorizationTest` |
| 10 | ✅ A system actor carrying no `Authorizable` is denied any tool whose authorization depends on a user | `Security/ToolAuthorizationTest` |
| 11 | ✅ Argument modification is applied, recorded as a diff on the trace, and audited | `Tools/ToolPolicyTest`, `UI/RunTraceTest`, `Feature/ToolAuditTest` |
| 12 | ✅ A `high`/`critical` risk tool pauses the run to `waiting_for_approval` with **no job in flight** | `Approvals/ApprovalPauseTest` |
| 13 | ✅ A paused run survives a simulated worker restart and still resumes correctly on approval | `Approvals/ApprovalPauseTest` |
| 14 | ✅ Approval resumes the run and executes the tool with the **approved** arguments | `Approvals/ApprovalResolutionTest` |
| 15 | ✅ Denial records a tool error, informs the model, and the run continues rather than failing | `Approvals/ApprovalResolutionTest` |
| 16 | ✅ **Two concurrent approvals of the same request consume it exactly once and execute the tool once** (T14) | `Security/ApprovalRaceTest` |
| 17 | ✅ The tool call is re-validated and re-authorized at execution time, not only at request time | `Security/ApprovalRaceTest` |
| 18 | ✅ `once` / `run` / `remembered` approval scopes each behave as documented | `Approvals/ApprovalScopeTest` |
| 19 | ✅ An expired approval moves the run to `failed` with a specific reason, not a generic error | `Approvals/ApprovalResolutionTest` |
| 20 | ✅ A user without `pandora.approvals.resolve` cannot resolve an approval, in the UI or the manager | `Security/ApprovalAuthorizationTest` |
| 21 | ✅ A duplicate identical tool call within one run is denied and the model is informed | `Tools/DuplicateCallTest` |
| 22 | ✅ A retried `ExecuteToolCall` does not re-apply the tool's side effect (idempotency key) | `Queue/ToolIdempotencyTest` |
| 23 | ✅ Exactly one `ContinueAgentRun` is dispatched after N parallel tool calls complete | `Queue/ToolIdempotencyTest` |
| 24 | ✅ Exceeding `max_tool_calls` terminates the run as `timed_out` | `Feature/ToolLoopTest` |
| 25 | ✅ Tool results are persisted as tool-role messages and replayed into the next model request with their `tool_call_id` | `Feature/ToolLoopTest` |
| 26 | ✅ A full loop — model requests a tool, tool runs, result returns, model answers — completes end to end | `Feature/ToolLoopTest` |
| 27 | ✅ `AskUser` pauses the run to `waiting_for_user` with no job in flight, and a reply resumes it | `Feature/AskUserTest` |
| 28 | ✅ Every built-in tool executes, and each refuses what it is documented to refuse | `Tools/BuiltInToolsTest` |
| 29 | ✅ Raw tool arguments and results are hidden from a user without `pandora.tools.io.view` | `UI/ToolsPageTest`, `UI/ApprovalsPageTest`, `UI/ToolCardTest` |
| 30 | ✅ Tool arguments and results are redacted in steps, broadcasts and the audit log | `Security/ToolIoVisibilityTest` |
| 31 | ✅ The Tools and Approvals pages render for an authorized user and are denied for an unauthorized one | `UI/ToolsPageTest`, `UI/ApprovalsPageTest` |
| 32 | ✅ Tool and approval cards appear live in chat and reconstruct correctly from the database after a reload | `UI/ToolCardTest` |
| 33 | ✅ The run trace renders tool request, execution, result, approval request and approval response in order | `UI/RunTraceTest` |
| 34 | ✅ Every tool and approval action produces the documented audit entry | `Feature/ToolAuditTest` |
| 35 | ✅ Cancelling a run with tool executions in flight lets them finish, records their results, then cancels | `Feature/CancelDuringToolTest` |
| 36 | ✅ The full suite passes, PHPStan level 8 is clean, Pint reports no diff | run locally, output quoted in `progress.md` |

## Audit actions this phase must produce

`tool.requested` · `tool.denied` · `tool.arguments_modified` · `tool.executed` · `tool.failed` ·
`approval.requested` · `approval.approved` · `approval.denied` · `approval.expired`

Each carries the run id, the tool name, the risk level, and sanitized arguments — never raw ones.

## Explicitly out of scope

Memory, automations, skills, MCP, delegation, workspaces, channels beyond web, multi-provider
routing, cost accounting. `DelegateToAgent` is Phase 6 and is **not** a built-in tool here, however
tempting the symmetry.

## Definition of done

- [x] All 36 criteria have tests, and they pass
- [x] `vendor/bin/pest` green — 431 passed, 1,580 assertions
- [x] `vendor/bin/phpstan analyse` clean at level 8
- [x] `vendor/bin/pint --test` clean
- [x] `docs/guides/tools.md` written
- [x] `docs/development/progress.md`, `docs/roadmap.md` and `CHANGELOG.md` updated
- [x] Committed to `master` as focused milestone commits
- [x] **Database matrix** — closed 2026-08-06. The whole suite passes on SQLite, MySQL 8.4,
      MariaDB 11 and PostgreSQL 17. It had been an argument rather than a run for longer than
      anybody realised: the three engine jobs were running SQLite, because `TestCase` hardcoded the
      connection and overrode the workflow's `DB_CONNECTION`.
- [x] **Host-application walkthrough** — driven 2026-08-07. A human granted the tools, watched a
      call pause, approved it from the Approvals page and saw the run resume; the notification it
      sent arrived in Mailpit. Every check in `phase-2-walkthrough.md` passes, and getting there
      found four defects, three now fixed with regression tests and one logged as a contract change
      (see below).

Phase 2 is **complete**, and the walkthrough was not the formality the automated column made it look
like. Four defects came out of it, and the reason none was reachable by the suite is worth keeping:
three of them needed **a real model free to answer wrongly**. `FakeProvider` calls whatever a test
tells it to call, so no test could watch a model asked for "the configured notification name" reach
for the email address in the user's sentence — and then be told it was not authorized.

One item is carried forward rather than closed: `send_notification` and four other built-ins resolve
a name against a host allowlist that ships empty, and a tool with nothing allowlisted is refused to
everybody with a message about permissions. The fix is a way for a tool to declare itself
*unavailable, with a reason* — kept out of what is advertised to the model, and shown as such on the
Tools page — rather than another string change. Tracked in `phase-2-walkthrough.md` defect 2.

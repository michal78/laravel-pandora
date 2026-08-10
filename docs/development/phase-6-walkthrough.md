# Phase 6 — Host Walkthrough

> Status: **the delegation half is driven.** 2026-08-08 headlessly, finding four
> defects, all fixed and covered; the remaining browser checks driven
> 2026-08-10, finding five more (5–9). Depth, cycle, cancellation and the audit
> actions all pass. Two of the new findings are structural rather than
> cosmetic: there is no audit page at all (6), and a failed delegation puts the
> parent into a retry loop that ends the run (7).
>
> The MCP half is **not driven at all** — see `## MCP — not driven` below. It
> needs a real MCP server you control, so it is the one section here with a
> setup cost beyond the host application.

Every walkthrough so far has found something the suite could not. This one found
the most expensive kind yet: a delegation that was refused *correctly*, bounded
*correctly*, reported to the parent *correctly*, and produced nothing but a
timed-out child and a misleading sentence about budgets. Nothing threw. Nothing
was logged at `error`. The suite was green throughout and stayed green while the
bug was live, because every delegation test scripted a child that answered on
its first turn.

Run against `laravel-test`, or any host application, with a real model and a
real queue worker.

## Before you start

Six things, none of which the package can do for a host. The first two cost an
hour each on the first run of this document:

- [x] **Restart the queue worker after every change to package source.**
      `queue:work` is a long-lived process that loaded its classes at boot. A
      symlinked path repository updates the files instantly and the worker keeps
      running the old code, so a fix appears not to work and the next hour is
      spent re-fixing it. `pkill -f queue:work` and start it again.
- [x] **`vendor:publish --tag=pandora-config --force`, or add the new keys by
      hand.** A published `config/pandora.php` is a snapshot: one published
      before Phase 6 has no `delegation` block at all, and no
      `RunToolLoopProvider` in `context.providers`. The package appends that
      provider when your list omits it, so this cannot break a run any more —
      but the `delegation` block still governs `max_depth`,
      `child_timeout_seconds` and `max_result_length`, and its absence means you
      are testing the code defaults rather than your configuration.
      *Check the same thing in `vendor/orchestra/testbench-core/laravel/config/`
      if you run the package suite: a stale published config there shadows the
      package's own, and the suite will quietly stop exercising what you ship.*
- [x] `vendor:publish --tag=pandora-migrations` again, then `migrate`. Phase 6
      adds `effective_tools`, `delegated_tool_execution_id`, `parent_run_id` and
      `delegation_depth` to runs, and a delegation policy to agents.
- [x] **Two agents, and an allowlist naming one of them.** The delegation
      allowlist is empty by default, so an agent that has never been given one
      cannot delegate to anything and says so correctly. In `laravel-test` these
      are `coordinator` and `researcher`.
- [x] **The parent must hold every tool the child needs.** Abilities are the
      *intersection* of the two, so a tool the parent lacks is absent from the
      child however the child agent is configured. A child that appears to have
      no tools is usually a parent that was never given them.
- [x] **Run the agent as a user, not as the system actor.**
      `pandora:agent:run` uses a system actor and no conversation. That is a
      legitimate way to run an agent, but `ask_user` is refused for it — which
      looks like a delegation failure and is not one. Drive delegation from
      chat, or from `AgentRunner::forUser()`.

### Prepared on 2026-08-08

`laravel-test` on Sail (PHP 8.5, MySQL, Redis), real `gpt-4o-mini`, one queue
worker, four agents registered: `coordinator`, `researcher`, `echo`, `delta`.
`coordinator` holds `delegate_to_agent` and `lookup_order` and may delegate to
`researcher`; `researcher` holds `lookup_order` and `inspect_run_status`.

## Delegation — the work actually getting done

Driven headlessly through `AgentRunner::forUser()` with a live model.

- [x] Ask the coordinator to have the researcher investigate something only the
      researcher's tools can answer. It calls `delegate_to_agent`.
- [x] A **child run exists**, with `parent_run_id` set, `delegation_depth = 1`
      and trigger `delegation`.
- [x] The child's **`effective_tools` is the intersection**, persisted on the
      run — here `["inspect_run_status","lookup_order"]`, which is the
      researcher's list narrowed by what the coordinator holds.
- [x] The child has **no conversation**, deliberately, and still completes a
      multi-step tool loop: it calls a tool, reads the result, and answers.
      *(This is the check that failed. See Defect 1.)*
- [x] The parent parks in `waiting_for_tool` holding no job while the child
      runs, and its tool-call row stays open.
- [x] The child's answer comes back as a **tool result**, and the parent's final
      message reports what the researcher found rather than what it guessed.
- [x] The **run detail page** shows the delegation in both directions: the
      parent lists its children with their state, the child names the agent that
      asked and links back up, and the child shows the tools it was allowed to
      call. *(This did not exist. See Defect 4. Driven by rendering the
      component against the live run rather than by clicking — the links
      themselves are still unclicked.)*
- [x] The audit log records `delegation.started` and `delegation.completed`, both
      on the parent. *(Verified in the audit table against the live run, not yet
      on the audit page.)*
- [x] **The child recovers from its own repetition.** In the live run the model
      repeated one `lookup_order` call, was refused as a duplicate, *read the
      refusal*, and answered from the result it already had — one
      `tool.denied` at `notice`, then a completed run. That single denial is the
      whole of Defect 1 in miniature: the guard always worked, and what was
      missing was the model being able to see it.

## Refusals — the part that must not look like an outage

- [x] A refused delegation leaves the parent **running**, with a tool error, and
      the parent reports what it could not do. Verified live against a target
      not on the allowlist.
- [x] The refusal reaches an operator: the tool execution row carries an
      `error_message`, and the run detail renders it. *(This is the check that
      failed. See Defect 2.)*
- [x] Delegating past `max_depth` denies the tool and names the limit.
      *(Driven live 2026-08-10 on a three-level chain against `max_depth = 2`:
      "Delegation is limited to 2 levels and this run is already at level 2. Do
      the remaining work yourself, or report what you cannot do." The run
      **completed** — refused, reported, carried on.)*
- [x] Delegating to an agent already in the ancestry is refused outright.
      *(Driven live 2026-08-10: "The agent [coordinator] is already running
      higher up in this chain of work. Delegating back to it would loop." The
      child still completed.)*
- [x] Cancelling a parent cancels its children; cancelling a child leaves the
      parent alone. *(Driven in a browser 2026-08-10, and it needed the button
      built first — see Finding 5. Both halves hold, and the second half has a
      consequence the check does not describe: Finding 7.)*
- [x] The audit log shows `delegation.denied`, `delegation.depth_exceeded` and
      `delegation.cycle_refused`, all at `warning`. *(All three confirmed at
      `warning` in `pandora_audit_log` 2026-08-10. **Not confirmed on a page,
      because there is no audit page** — Finding 6.)*

## What this walkthrough found

**Defect 1 — a delegated child could not see its own tool loop. Fixed.**
`RecentMessagesProvider` was the only source of prior-turn history and it
returns nothing without a conversation. A child has none by design, so it
rebuilt its context from scratch every iteration: it called `lookup_order`,
could not see the result, called it again, was refused as a duplicate, could not
see that either, and repeated until its iteration budget ended the run. The
parent was then told the shared budget was exhausted — true, and completely
misleading. Every autonomous trigger (schedule, webhook, event, console) creates
conversation-less runs too and had the same amnesia. Now
`RunToolLoopProvider`, and `Delegation/ChildMemoryTest`.

**Defect 2 — refusals recorded nothing an operator could read. Fixed.**
Three paths wrote a failure without a reason: a denial at execution time kept
the decision the row was created with (so a denied call read as `decided_by:
tool` with no reason — the shape of an *allowed* call), a tool returning a
failure rather than throwing never wrote `error_message`, and a child ending
badly closed its parent's call with an empty error. Every delegation refusal
went through the second path.

**Defect 3 — a finished call still claimed to be waiting. Fixed.**
`awaitDelegate` overwrote the `decision_reason` that `Delegator` had already
written, and the second wording outlived the wait — so a *failed* delegation
displayed "Delegated to Researcher. Waiting for its answer." beside an empty
error.

**Defect 4 — delegation was invisible in the control center. Fixed.**
A child run reached from the runs list named no parent, no asking agent and no
intersection: the columns were on the row and on no page. The run detail now
carries both directions, and an empty intersection says so in words rather than
rendering as blank space, because "allowed nothing" and "not delegated" are
different facts. Covered by `UI/DelegationTraceTest`.

**Not a defect, but it cost an hour twice:** a long-lived queue worker serving
stale code, and a published config shadowing the package's own. Both are in
*Before you start* now.

### The browser half, driven 2026-08-10

**What held.** The two refusals are the best-written messages in the product.
Both name the limit, both tell the model what to do instead, both land in the
audit log at `warning` with the refusal reason in metadata, and in both cases
**the run completes** rather than failing — refused, reported, carried on.
Cancelling a parent cancels its children, and it cascades further than one
level: cancelling the middle run of a three-deep chain took its own child with
it. `delegation.completed` turned out to be sound too — it means *the delegation
concluded*, carries the child's terminal state in metadata, and rises to
`warning` when the child did not succeed.

**Finding 5 — cancellation was a button that only existed on one page. Fixed.**
The check asks for cancellation to be driven in a browser, and the runs list —
the page an operator actually watches — had no Cancel control, no agent name,
truncated run ids at 12 characters, and no polling, so a live run's state only
changed if you reloaded. The check could not be driven as written. `RunsIndex`
now carries the whole run id, the agent, a per-row Cancel on the same
`RunCanceller` path as the detail page, and `wire:poll` while any run on the
page is non-terminal — stopping once none is, because a list of finished runs
that re-queries every 2.5 seconds is load with no question behind it. Covered by
`UI/RunsIndexActionsTest`, which *clicks* — the Phase 8 walkthrough's finding 10
was an Edit button that did nothing behind thirteen tests that only rendered the
page, and this check was flagged in this very document as "a button, and the
button is the part nobody tests".

**Finding 6 — the audit log is written everywhere and readable nowhere.**
Eight Livewire components inject `AuditLogger` and write to it. Not one reads
`AuditLog` back, there is no route, no page and no nav entry. The check above
was marked "(Not driven — browser)" as though a browser could drive it; it never
could. Everything the audit log records — every delegation refusal, every
approval decision, every channel identity change — is reachable only by opening
the database. For a table whose purpose is to be read after something has gone
wrong by the person who was not there when it did, that is the whole feature
missing, not a page missing. Phase 9's threat work should treat it as such.

**Finding 7 — a dead-end tool result becomes a retry storm that kills the run.**
Seen four times on 2026-08-10, from three different causes: a child that failed
on a provider error, a delegation refused as a cycle, and a child cancelled from
the browser. The shape is identical every time. The delegation produces no
usable result, the model reissues the identical call, and the duplicate-call
guard refuses it with:

> This exact call was already made in this run. Use the result you already have,
> or call with different arguments.

**There is no result to use.** The first call failed. So the model retries,
is refused again, and loops — six times in one run, eleven in another — until
`pandora.budget_exceeded` or the deadline ends it. The parent then dies as
`failed` or `timed_out` having never reported the thing it actually knew, which
was that its delegate could not do the work.

Each piece is defensible alone. The duplicate guard is right that the call was
repeated; the refusal is right that it was refused. Together they form a trap
with no exit, and the message is the reason: it asserts a result exists. It
should distinguish a repeated call that *succeeded* — where "use what you have"
is sound advice — from one that failed, where the only useful instruction is to
stop retrying and report. This is the same lesson as Defect 1: the guard always
worked, and what was missing was the model being able to act on what it said.

**Finding 8 — cancelling a child leaves the parent stranded, not informed.**
The check says cancelling a child leaves the parent alone, and it does: the
parent stayed `waiting_for_tool` and was not cancelled. But it is never told
*why* its delegate stopped. It re-delegated, spawning a second child; that one
failed; then Finding 7's loop took over and the parent ended `timed_out` several
minutes later. An operator who cancels a child in order to stop some work
watches a new one start, and the run they intervened in dies of a timeout rather
than reporting "the work I delegated was cancelled". Cancellation of a child
should close the parent's open tool call with that sentence.

**Finding 9 — no OpenAI reasoning model can be used with tools.** Found
incidentally: a delegated child configured with a reasoning model failed with
*"Function tools with reasoning_effort are not supported for … in
/v1/chat/completions. To use function tools, use /v1/responses."*
`OpenAiCompatibleProvider` posts to `/chat/completions` and nothing else, so
this is not a property of that one agent — every reasoning model is unusable
with any tool, on any agent, and the failure arrives from the provider rather
than from a check of ours. Not a Phase 6 defect; recorded here because this is
where it surfaced, and it belongs to Phase 9's provider work.

**Worth noting about failures generally:** all three runs in the first chain
carried an empty `error` on the `runs` row while `pandora_audit_log` held the
error code and class. The same gap was seen during Phase 8 on 2026-08-09. An
operator reading the runs table sees a failed run and no reason.

## Not in this walkthrough

The MCP half — servers, discovery, schema hashing, approval, the Pandora MCP
server and the agent's Permissions tab.

*Corrected 2026-08-10: this section used to say "none of it is built" and that
criteria 14–30 remained open. Both were true when it was written and neither is
true now — all 30 criteria are verified, `src/Mcp/` holds discovery, schema
hashing, approval and a health probe, and `/pandora/mcp` is a real page. What is
still missing is the driving, which is what `## MCP — not driven` below is for.*

## MCP — not driven

> Every box below is unticked. The delegation half of this document was driven on
> 2026-08-08 and found four defects; this half has not been driven at all, and
> the phase is not done until it has.

The check the suite structurally cannot make is the last one: **a real server,
changed after approval, whose tool stops working until a person looks at what
changed.** `FakeMcpServer` proves we handle a rewritten description; it cannot
prove that an operator meets that situation and understands it.

### Before you start

- [ ] **`vendor:publish --tag=pandora-config --force`.** A published config from
      before this phase has no `mcp` block at all, so the client is off, no
      transport is enabled and the server does not exist. All three read as
      "MCP is broken" rather than "MCP is not configured".
      *And check `vendor/orchestra/testbench-core/laravel/config/` if you run the
      package suite — a stale published config there shadows the package's own.
      It has reappeared twice.*
- [ ] `vendor:publish --tag=pandora-migrations`, then **delete the duplicates it
      just made**, then `migrate`. Three new tables.

      The publish re-copies the *whole* directory with fresh timestamps, so
      every migration you already ran arrives a second time under a new name and
      `migrate` dies trying to add a column that exists. Keep only the files you
      do not already have:

      ```bash
      ls database/migrations | grep "$(date +%Y_%m_%d)"   # what just landed
      # remove all but the genuinely new ones, then:
      php artisan migrate
      ```

      This bites on every phase that adds a table, and it bit while writing this
      document.
- [ ] **A real MCP server you control**, so you can change it mid-walkthrough.
      Anything that speaks `tools/list` and `tools/call` over HTTP will do.
- [ ] `pandora.mcp.client.enabled` true, and a credential in the encrypted store
      if your server wants one.

### Registering and discovering

- [ ] Register a server. The row takes a namespace, an endpoint and the NAME of a
      credential — confirm there is nowhere on it to paste a token.
- [ ] `php artisan pandora:mcp:discover`. It reports what it found and says
      **"Nothing was approved."**
- [ ] `/pandora/mcp` lists the server, its health, and every tool as approved for
      **nobody**.
- [ ] The description shown is the server's own, marked as remote. Put markup and
      an "ignore previous instructions" sentence in one on your server, rediscover,
      and confirm the page renders it as text.

### Approving

- [ ] `pandora:mcp:approve <tool> <agent>`. The agent's **Permissions** tab now
      lists it.
- [ ] Ask the agent to use it. It works, and the call appears as an ordinary tool
      execution on the run trace with its arguments redacted.
- [ ] A **second agent** cannot call it. Approval is per agent.
- [ ] Approve with `--hash=` and a hash you made up. Refused.

### The one that matters

- [ ] **Change the tool's description on your server** — keep every parameter
      identical. Rediscover.
- [ ] The approval is gone. `pandora:mcp:list --tools` says unapproved; the page
      says the tool changed and approvals were cleared; the audit log has
      `mcp.schema_changed` at `warning` naming the description as what moved.
- [ ] The agent can no longer call it, and says so rather than failing oddly.
- [ ] Re-approve. It works again. **Was it obvious what had changed and why you
      were being asked?** If not, that is the finding.

### When the server misbehaves

- [ ] Stop the server. The agent's call fails as a tool error and the run
      continues. Two failed probes later its tools are not offered at all.
- [ ] Make it hang. One tool call is lost, not one worker.
- [ ] Make it return something enormous. Refused on size.
- [ ] Confirm the model was never told your hostname, port or credential error —
      only that the tool was unavailable.

### stdio

- [ ] Register a stdio server without enabling the transport. Refused, naming
      `pandora.mcp.transports.stdio.enabled`.
- [ ] Enable it, point it at a real local MCP binary, confirm it works, then turn
      it back off.

### Being a server

- [ ] With `pandora.mcp.server.enabled` false, the endpoint 404s.
- [ ] Enable it with an empty allowlist: `tools/list` returns nothing.
- [ ] Expose one tool. It is listed and callable by a user who holds its ability.
- [ ] **Call it as a user who does not hold the ability.** Refused, and
      `mcp.exposure_denied` is recorded at `warning`. This is the check that
      distinguishes a token from an authorization.
- [ ] Ask for a tool that is not exposed. The same answer as one that does not
      exist.
- [ ] Expose `inspect_run_status` deliberately and call it. It refuses cleanly,
      saying it needs a run — not a stack trace.

### What this found

*Fill this in. If it found nothing, say so — a walkthrough with an empty findings
section is indistinguishable from one nobody ran.*

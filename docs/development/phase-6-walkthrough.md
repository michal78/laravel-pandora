# Phase 6 — Host Walkthrough

> Status: **both halves are driven.** Delegation on 2026-08-08 headlessly
> (four defects) and 2026-08-10 in the browser (five more, 5–9); MCP on
> 2026-08-10 against real servers (six more, 10–15). Fifteen findings, thirteen
> fixed. Two remain open and are named as such: there is no audit page at all
> (6), and a failed delegation puts the parent into a retry loop that ends the
> run (7).
>
> The MCP half needed a real MCP server you control, which is why it was the
> last section here to be driven. Both servers used are described under
> `## MCP` below and were the cheapest part of the exercise.

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

## Not in the delegation half

The MCP half — servers, discovery, schema hashing, approval, the Pandora MCP
server and the agent's Permissions tab. It is the `## MCP` section below, and
as of 2026-08-10 it is driven too.

*Corrected twice. This section first said "none of it is built" and that
criteria 14–30 remained open; that was true when written and stopped being true
when `src/Mcp/` was built. It then said the MCP half was undriven; that stopped
being true on 2026-08-10.*

## MCP

> Driven 2026-08-10, against a real HTTP MCP server and a real stdio one, both
> written for this document so they could be changed mid-run. Six findings
> (10–15), all fixed. Two of them — a namespace separator no provider will
> accept, and arguments dropped by validation before they reached the wire —
> meant this half of the phase had never worked outside the test suite.

The check the suite structurally cannot make is the last one: **a real server,
changed after approval, whose tool stops working until a person looks at what
changed.** `FakeMcpServer` proves we handle a rewritten description; it cannot
prove that an operator meets that situation and understands it.

### Before you start

- [x] **`vendor:publish --tag=pandora-config --force`.** A published config from
      before this phase has no `mcp` block at all, so the client is off, no
      transport is enabled and the server does not exist. All three read as
      "MCP is broken" rather than "MCP is not configured".
      *And check `vendor/orchestra/testbench-core/laravel/config/` if you run the
      package suite — a stale published config there shadows the package's own.
      It has reappeared twice.*
- [x] `vendor:publish --tag=pandora-migrations`, then **delete the duplicates it
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
- [x] **A real MCP server you control**, so you can change it mid-walkthrough.
      Anything that speaks `tools/list` and `tools/call` over HTTP will do.
- [x] `pandora.mcp.client.enabled` true, and a credential in the encrypted store
      if your server wants one.

### Registering and discovering

- [x] Register a server. The row takes a namespace, an endpoint and the NAME of a
      credential — confirm there is nowhere on it to paste a token.
- [x] `php artisan pandora:mcp:discover`. It reports what it found and says
      **"Nothing was approved."**
- [x] `/pandora/mcp` lists the server, its health, and every tool as approved for
      **nobody**.
- [x] The description shown is the server's own, marked as remote. Put markup and
      an "ignore previous instructions" sentence in one on your server, rediscover,
      and confirm the page renders it as text.

### Approving

- [x] `pandora:mcp:approve <tool> <agent>`. The agent's **Permissions** tab now
      lists it.
- [x] Ask the agent to use it. It works, and the call appears as an ordinary tool
      execution on the run trace with its arguments redacted.
- [x] A **second agent** cannot call it. Approval is per agent.
- [x] Approve with `--hash=` and a hash you made up. Refused.

### The one that matters

- [x] **Change the tool's description on your server** — keep every parameter
      identical. Rediscover.
- [x] The approval is gone. `pandora:mcp:list --tools` says unapproved; the page
      says the tool changed and approvals were cleared; the audit log has
      `mcp.schema_changed` at `warning` naming the description as what moved.
- [x] The agent can no longer call it, and says so rather than failing oddly.
- [x] Re-approve. It works again. **Was it obvious what had changed and why you
      were being asked?** If not, that is the finding.

### When the server misbehaves

- [x] Stop the server. The agent's call fails as a tool error and the run
      continues. Two failed probes later its tools are not offered at all.
- [x] Make it hang. One tool call is lost, not one worker.
- [x] Make it return something enormous. Refused on size.
- [x] Confirm the model was never told your hostname, port or credential error —
      only that the tool was unavailable.

### stdio

- [x] Register a stdio server without enabling the transport. Refused, naming
      `pandora.mcp.transports.stdio.enabled`.
- [x] Enable it, point it at a real local MCP binary, confirm it works, then turn
      it back off.

### Being a server

- [x] With `pandora.mcp.server.enabled` false, the endpoint 404s.
- [x] Enable it with an empty allowlist: `tools/list` returns nothing.
- [x] Expose one tool. It is listed and callable by a user who holds its ability.
- [x] **Call it as a user who does not hold the ability.** Refused, and
      `mcp.exposure_denied` is recorded at `warning`. This is the check that
      distinguishes a token from an authorization.
- [x] Ask for a tool that is not exposed. The same answer as one that does not
      exist.
- [x] Expose `inspect_run_status` deliberately and call it. It refuses cleanly,
      saying it needs a run — not a stack trace.

### What this found

Driven 2026-08-10 against a real HTTP MCP server and a real stdio one, both
written for this document and both controllable mid-run. Six findings, all
fixed. Two of them meant the MCP client half **had never worked at all** outside
the test suite, and neither could have been found by adding another test of the
same kind.

**Finding 10 — the namespace separator was illegal in every provider's function
name.** The default was `.`, and OpenAI and Anthropic both hold function names
to `^[a-zA-Z0-9_-]+$`. So the first time an approved remote tool was advertised,
the provider answered

```
400 Invalid 'tools[0].function.name': string does not match pattern.
```

and the run failed with *"The AI provider could not complete this request."* —
a sentence naming neither MCP, nor the tool, nor the name that was rejected. The
`runs` row carried an empty `error`, as Finding 8 already noted. Every remote
tool on every agent, on both major providers, with the shipped configuration.

The separator has two constraints and only one of them had been thought about.
It must not appear in any core tool name — the reserved-separator invariant a
test already asserted — *and* it must be legal in a provider's function-name
grammar. `-` satisfies both; `_` fails the first, because core tool names are
full of underscores. Changed to `-`, and the test that would have caught it now
asserts the grammar directly rather than the constant.

`FakeProvider` accepts any name a tool cares to have, which is why 1,795 tests
were green over a feature that could not execute once.

**Finding 11 — remote tool arguments were silently dropped.** `RemoteTool`
declares `rules()` as `['arguments' => 'nullable|array']` and `handle()` falls
back to `$input->toArray()`. But a model forms its call against the schema the
*server* advertised, so its arguments are top-level `invoice_id`, and Laravel's
validator returns only the keys it has rules for. Every remote call reached the
far end as `{"name":"lookup_invoice","arguments":{}}`.

Nothing failed. The tool succeeded, the execution row was written, the run
completed, the audit entry said `outcome: allow`, and the server answered a
question nobody had asked — `Invoice UNKNOWN: 4 800,00 DKK`. Only the request
log on the far side showed it, which is the one place no test looks.

Every existing test built its `ToolInput` by hand as `['arguments' => [...]]`,
so all of them skipped validation — the only step that could lose anything. The
new test goes `ToolCall → ToolGatekeeper::evaluate → handle → the wire`, and
fails on the old code. The fix is a `carriesUndeclaredArguments()` hook on
`Tool`, false everywhere in core and true only here, because a remote tool's
arguments were never ours to declare.

**Finding 12 — the page answered "who may call this tool" with a ULID.** The
Approved-for column printed `$approval->agent_id`. The tests asserted a fixture
ULID, so they asserted the bug. Now the agent's slug, falling back to the key
when an approval outlives its agent — which is a real state and still needs
revoking.

**Finding 13 — "Changed" is not a diff.** The check that matters is whether an
operator meeting a rewritten tool understands what happened. They did not, and
said so: the page said *"Changed 2 minutes ago. Approvals were cleared."* and
nothing else, in a full-width notice jammed into the narrow tool column that
broke the row apart. It showed the new description and had no idea what the old
one was, so the person being asked to re-approve a sentence a stranger rewrote
could not see the sentence it replaced.

`previous_description` now persists on the tool row when — and only when — the
description moved, so the page shows a struck-through before and a green after,
and says *"Its description changed"* or *"Its description is unchanged, so what
moved is a parameter."* Those are opposite situations and an operator needs to
be told which one they are in. The notice moved next to the description; the
tool column keeps a small Changed tag.

**Finding 14 — the page that showed the diff could not act on it.** It offered
Revoke and no Approve, so an operator who read the diff and decided it was fine
went to a terminal to retype what they had just been looking at. Added, gated on
`mcp.manage` like Revoke, naming one agent — "approve this tool" is not a thing
that can be said — and re-deriving the hash server-side rather than carrying one
through the browser.

**A configuration gap, not a defect: the server's default middleware
authenticates nobody.** `'middleware' => ['api']` in a stock Laravel application
resolves a null actor, so `tools/list` works and every single `tools/call` comes
back `Not authorized to call this tool.` with `mcp.exposure_denied` and
`reason: no actor`. That reads as a broken server and is an unconfigured one.
The config comment and the guide now say so and name `['api', 'auth:sanctum']`.

**Finding 15 — the published config shadowed the package's, and this time the
cause was found.** `vendor/orchestra/testbench-core/laravel/config/pandora.php`
was back for the third time, so the entire MCP suite was exercising a separator
the package no longer shipped. Deleted — and then it reappeared *within this
session*, which is what finally made it findable.

`pandora:install` publishes the config as well as the migrations, and
`InstallationTest` runs it sixteen times. It had a helper to delete the
published migrations afterwards and nothing at all for the config, so every
full-suite run left one behind and the next run silently used it. Nothing
fails: `mergeConfigFrom()` merges one level deep, so the snapshot's top-level
arrays replace the package's outright, and a key added to the package quietly
does not exist. Twice was a coincidence, three times was a thing that needed a
guard, and four times in one day was a bug with an address. An `afterEach` in
that file now removes it, and a full run leaves the tree clean.

**What held up.** Everything else, and some of it under real hostility. The
`<script>` and *"ignore previous instructions"* description rendered as visible
text with no alert. Two unpublishable tool names — `../../etc/passwd` and
`ledger.shadow` — were skipped rather than sanitised, every time. Discovery
approved nothing and said so. A made-up `--hash` was refused with both hashes
printed. A second agent was never offered the tool at all. A stopped server
became a tool error and the run continued; two failed probes took its tools out
of circulation entirely; a hang cost one call and cost the worker nothing; 600 KB
was refused unread. The model was told *"That tool is not available right now."*
while the operator got `HTTP 401` — the server's own error text, which named its
hostname and port, reached nobody. stdio refused until enabled, worked against a
real local binary, and refused again when turned off. And the check the whole
server half exists for passed: the same tool, the same endpoint, two
authenticated users, one gets data and one gets `mcp.exposure_denied` at
`warning` with their user id, IP and agent on the row.

**One environment hazard worth writing down.** `pkill -f queue:work` on the host
does not kill the worker running inside the Sail container. Three workers
accumulated on three different versions of the package source, and jobs went to
whichever grabbed them first — so the same run alternated between working,
denying the tool at the registry layer, and calling with empty arguments, with
no code change in between. Half an hour went into chasing a race that was three
processes. Kill it where it runs:
`sail exec laravel.test pkill -f queue:work`.

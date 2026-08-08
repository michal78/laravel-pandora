# Phase 6 — Host Walkthrough

> Status: **the delegation half driven 2026-08-08, headlessly, and it found
> three defects.** All three are fixed and covered. The browser checks — the run
> detail, the child's trace, the audit page — are **not** driven yet and are
> marked as such below.
>
> The MCP half of Phase 6 has no code, so it has no checks here.

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
- [ ] The **run detail page** shows the delegation, links parent to child, and
      the child's trace is reachable from it. *(Not driven — browser.)*
- [ ] The audit log shows `delegation.started` and `delegation.completed`.
      *(Not driven — browser.)*

## Refusals — the part that must not look like an outage

- [x] A refused delegation leaves the parent **running**, with a tool error, and
      the parent reports what it could not do. Verified live against a target
      not on the allowlist.
- [x] The refusal reaches an operator: the tool execution row carries an
      `error_message`, and the run detail renders it. *(This is the check that
      failed. See Defect 2.)*
- [ ] Delegating past `max_depth` denies the tool and names the limit.
      *(Not driven live — covered by `Delegation/DepthTest`.)*
- [ ] Delegating to an agent already in the ancestry is refused outright.
      *(Not driven live — covered by `Delegation/CycleTest`.)*
- [ ] Cancelling a parent cancels its children; cancelling a child leaves the
      parent alone. *(Not driven live — covered by
      `Delegation/CancellationTest`. Worth driving in a browser: cancellation is
      a button, and the button is the part nobody tests.)*
- [ ] The audit log shows `delegation.denied`, `delegation.depth_exceeded` and
      `delegation.cycle_refused`, all at `warning`. *(Not driven — browser.)*

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

**Not a defect, but it cost an hour twice:** a long-lived queue worker serving
stale code, and a published config shadowing the package's own. Both are in
*Before you start* now.

## Not in this walkthrough

The MCP half — servers, discovery, schema hashing, approval, the Pandora MCP
server and the agent's Permissions tab. None of it is built. Criteria 14–30 of
`phase-6-acceptance.md` remain open, and this document grows a second half when
they close.

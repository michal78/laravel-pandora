# Phase 2 — Host Walkthrough

> Status: **driven 2026-08-07 — every check passes, and it found four defects.**
> 35 of 36 acceptance criteria in `phase-2-acceptance.md` were already verified
> by automated test; this was the outstanding one, a human driving the tool and
> approval surfaces in a real host. Three defects are fixed with regression
> tests; one is logged deliberately (defect 2) because the right fix is a
> contract change, not a patch.
>
> Driven against `laravel-test` — Laravel 13, PHP 8.5.8, MySQL 8.4, Redis queue,
> `BROADCAST_CONNECTION=log`, real OpenAI `gpt-4o-mini`.
>
> The pattern held: not one of the four was reachable from the package suite,
> and three of them surfaced only because a **real model was free to answer
> wrongly**. `FakeProvider` calls whatever the test tells it to call, so no test
> could have watched a model guess an email address where a configured name was
> required.

Phase 2 gives an agent hands, so most of what matters here is *negative*: the
checks below are mostly about something failing to happen. A tool surface that
works is not the claim. A tool surface that refuses correctly is.

Run against `laravel-test`. Two accounts are needed and the distinction is
load-bearing: **user 1** is an operator and holds every ability; **user 2**
holds only `access` and `chat`. A check that says "as user 2" proves nothing if
you run it as user 1.

A queue worker must be running.

## Before you start

- [x] The walkthrough agent has tools granted. An empty allowlist means no tools
      at all, and an agent that was never given `request_approval` cannot pause
      for one — which reads exactly like the pause being broken.
      *(`EchoAgent` grants all eleven built-ins.)*
- [x] At least one granted tool is `high` or `critical` risk, or nothing on the
      Approvals page will ever appear. `send_notification` and
      `request_approval` are the two built-ins that qualify.
      *(Both granted.)*
- [x] `maxIterations` is high enough for a tool loop. Raised from 3 to 6 on
      2026-08-07: a run that calls two tools and then answers needs three
      iterations, and hitting the cap terminates as `timed_out`, which reads
      like a broken tool loop rather than a budget doing its job.
- [x] The queue worker was restarted **after** the last change to the agent
      class. A worker holds its code in memory; one started beforehand runs the
      old definition and the walkthrough measures the wrong thing.
- [x] **The tool allowlists are populated.** This is the one that will waste an
      afternoon if it is skipped. Five of the eleven built-ins —
      `query_records`, `read_config`, `dispatch_job`, `emit_event` and
      `send_notification` — read a host allowlist that ships **empty**, and a
      tool with nothing allowlisted is refused to everybody. Granting the tool
      to the agent is not enough and neither is holding every ability. Configure
      `pandora.tools.resources`, `readable_config`, `jobs`, `events` and
      `notifications` before starting; `laravel-test` gained a
      `WalkthroughJob`, `WalkthroughEvent` and `WalkthroughNotice` on
      2026-08-07 for exactly this. Verify with a real `evaluate()` call rather
      than by reading the config, and expect `send_notification` to come back
      `RequireApproval` rather than `Allow` — that is the pause working.

## The Tools page

- [x] **Tools** appears in the sidebar and the page opens.
- [x] Every registered tool is listed with its group, version and risk level.
- [x] Risk is legible at a glance — critical does not look like low.
- [x] A tool that requires approval says so on the row, before anyone runs it.
- [x] As **user 2**, the page either refuses or shows no control that would let
      them change anything.

## A tool actually running

- [x] Ask the agent something that makes it use a low-risk tool
      (`inspect_run_status` — "how much budget is left on this run?").
- [x] A tool card appears in the conversation **while the run is live**, not
      only after it finishes.
- [x] The card names the tool and shows its arguments.
- [x] The run's trace shows the tool call, the result, and the model's answer
      built on it — in order.
- [x] Ask a question needing two tools. Both appear, and exactly one answer
      follows — not two runs racing.

## Approval

The claim this phase rests on: a paused run holds **no job in flight**.

- [x] Ask the agent to do something requiring a high-risk tool. The run stops
      and says it is waiting for approval, naming what it wants to do.
      *(Driven with `request_approval`, not `send_notification` — see defect 2:
      the notification tool cannot reach approval in a host that has allowlisted
      no notifications, which is the shipped default.)*
- [x] The chat shows an approval card with the tool and the exact arguments.
      *(Named the proposal, labelled **Critical risk**, offered Approve and
      Deny.)*
- [x] **Approvals** in the sidebar shows the pending request.
- [x] **Restart the queue worker while the run is paused.** Nothing is lost, no
      error appears, and the request is still pending.
      *(`docker compose restart laravel.test` then start a fresh worker.)*
- [x] Approve it. The run resumes and the tool executes with the arguments that
      were shown — not different ones.
- [x] The whole thing is in the audit log: requested, approved, executed.

## Approval, refused

- [x] Trigger another approval and **deny** it. The run continues and the agent
      reports it was not allowed — the run does **not** fail.
- [x] The denial is audited with whoever denied it.
- [x] As **user 2**, the Approvals page offers no approve or deny control, and
      a pending request cannot be resolved by them. *(Criterion 20 — the one
      that matters most on this page.)*
- [x] Log in as user 1 in one browser and user 2 in another. User 2 cannot
      resolve the request even with the page open in front of them.

## Scopes

- [x] Approve with scope **once**. Ask for the same thing again — it asks again.
- [x] Approve with scope **run**. The same tool in the same run does not ask a
      second time.
- [x] Approve with scope **remembered**. A new conversation does not ask.
- [x] Nothing in the UI offers a scope that would outlive what the operator
      could reasonably have meant.

## Asking a question back

- [x] Ask the agent something ambiguous enough that it uses `ask_user`. The run
      parks and the header says it is waiting for you.
- [x] Answer it. The **same** run resumes — a second run does not start beside
      it, and the header clears.
      *(This is the third Phase 5 defect. It is checked here too because this is
      the phase that built the path.)*
- [x] The trace shows one run containing the question and the answer.

## Refusals that should be quiet

- [x] Ask the agent to use a tool it does not have. It says it cannot, and no
      tool card appears.
- [x] Ask it to run the same tool with identical arguments twice in one run.
      The duplicate is refused and the agent is told, rather than the side
      effect happening twice.

## Defects found

**1. A conversation silently changed agent on reload.** (Symptom: an agent that
answered using a tool on the first message insisting, for every message after,
that it had no tools.)

`Chat::mount()` seeded the picker from `availableAgents()->first()` — ordered by
name — and never consulted `conversations.agent_id`. `agentSlug` is empty on
every fresh mount, so opening an existing conversation, or merely reloading one,
repointed it at whichever agent sorted first. In `laravel-test` that is `Delta`,
a database-defined agent with no tools; the conversation had been started with
`Echo`, which grants eleven.

Every message after the reload therefore ran with a different agent's
instructions, tools, model, autonomy level and budgets, while the conversation
row went on naming the original. The two never reconciled.

The picker was already rendered `disabled` once a conversation existed, so the
intent — a conversation belongs to the agent it was started with — was there
from the beginning. The lock simply froze the wrong value. Worse, `agentSlug` is
a public Livewire property: `disabled` is a courtesy to the operator and stops
no crafted request, so the agent could be swapped mid-conversation over the
wire regardless.

The fix seeds `agentSlug` from the conversation's bound agent, decides the agent
in `resolveAgent()` from the conversation rather than from the round trip, and
renders it as a stated fact (`pd-locked`) rather than a disabled `<select>` —
the same reasoning Phase 3.5 applies to class-managed fields, where a disabled
input says "broken" and a named value says where the answer comes from.

The suite could not see it, and the reason is a single line of setup:
`tests/UI/ChatTest.php` registered exactly **one** agent, and drove its runs
through `AgentRunner->agent($conversation->agent)` rather than through the
picker. With one agent, `->first()` is never observably wrong. The three
regression tests each add a second agent sorting before `Echo`, and all three
fail without the fix.

**This is a Phase 1 surface defect, found during the Phase 2 walkthrough.** It is
recorded here because this is where it surfaced, and noted in
`phase-1-walkthrough.md` because that is the phase that owns the chat page.

**2. A refusal that blamed the operator for a configuration gap.** (*You are not
authorized to use [send_notification]* — shown to an operator holding every
ability.)

`SendNotificationTool::authorize()` resolves the requested notification from
`pandora.tools.notifications` and returns `false` when it is not found. A host
that has allowlisted none — the shipped default, and `laravel-test` — therefore
refuses the tool to **everybody**, and `ToolGatekeeper` renders every layer-5
denial as the same sentence. The refusal is correct. The reason given is not,
and it sends the operator into gates, abilities and `AppServiceProvider` looking
for something that was never the problem.

Note the shape, because it is the second instance: Phase 5 defect 2 was
`RememberTool` refused to everybody because `authorize()` returned `false` for a
reason nothing surfaced. A `bool` cannot distinguish *you may not* from *this is
not configured*, and the operator has now paid for that twice.

The tension in the fix, and why it was not applied on the spot: the generic
message is **deliberate** toward the model — a layer-5 denial should not explain
itself to something that may be under injection. Only the operator's copy of it
is wrong. The fix is therefore to keep the model-facing sentence exactly as it
is and give the tool a way to record an operator-facing reason on the execution
record and the trace. That retires the class rather than the instance.

**It is not one tool.** Configuring the host to get past it showed the scope:
`query_records`, `read_config`, `dispatch_job`, `emit_event` and
`send_notification` all read an allowlist that ships empty, so **five of the
eleven built-ins cannot function in a fresh installation** and every one of them
refuses with the same misleading sentence. `pandora:tool:list` and the Tools
page both advertise all eleven as though they worked. An operator's first
encounter with most of the built-in tool set is therefore a permissions error
that is not about permissions.

The empty defaults are right — implicit access is how a support agent ends up
with a shell. What is missing is any way to tell *not configured* from *not
allowed*, at rest, before a run fails.

That widens the recommended fix rather than changing it: a tool should be able
to declare itself **unavailable with a reason**, that reason should keep it out
of what is advertised to the model, and the Tools page should show it greyed
with the reason attached. Then a fresh install shows five tools plainly marked
*needs configuration* instead of eleven that look ready and five that lie.

**Status: logged, not fixed.** Deferred deliberately to keep the walkthrough
moving; see `progress.md`.

**3. An empty assistant bubble while a run is parked.** (Cosmetic, and reported
as "annoying" rather than as a bug — which is how this kind survives.)

`MessageWriter::assistantPlaceholder()` creates the assistant message with empty
content before the model is called, so that a reload mid-request finds something
to render. The thread rendered it unconditionally, so an assistant bubble with
nothing in it appeared as soon as a run started — and when the run parked at an
approval, nothing ever filled it. The blank sat there for as long as the
approval was pending, which is unbounded.

The fix skips an assistant message whose content is still empty. The run status
badge and the tool and approval cards already say what is happening, so nothing
is lost by not drawing an empty box. Two tests cover it: one that the empty
placeholder is not rendered, one that the bubble appears the moment it has a
single character of content — the second is the guard that stops the fix from
becoming "streamed messages never appear".

## Notes

Anything found here goes in `progress.md`: what broke, why the suite could not
see it, and what changed as a result.

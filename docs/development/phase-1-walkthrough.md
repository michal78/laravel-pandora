# Phase 1 — Host Walkthrough

> Status: **complete — 20 of 20 checks pass.** Driven 2026-08-07, and the two
> checks added afterwards driven 2026-08-10. The two additions came from a
> defect the Phase 2 walkthrough found in this phase's own chat page; they were
> fixed and covered by regression tests, and are now confirmed in a browser
> too. All 22
> acceptance criteria in `phase-1-acceptance.md` were already verified by
> automated test. The console half of this walkthrough was performed on
> 2026-08-05 and is recorded in Q9 — it found three defects. The browser half
> ran on 2026-08-07 against `laravel-test` (Laravel 13, PHP 8.5.8, MySQL 8.4,
> Redis queue, `BROADCAST_CONNECTION=log`, real OpenAI `gpt-4o-mini`) and found
> none.

Q9 already verified live: `pandora:install`, the migrations on MySQL 8.4,
`pandora:status`, `pandora:agent:list`, a synchronous console run, a queued run
drained by a real worker, and every page rendering for an authenticated user
while redirecting a guest. None of that is repeated below.

What is left is the part that needs a browser: streaming, a reload against a
half-finished run, the trace, and cancellation. These are the four claims Phase
1 rests on that `Livewire::test()` cannot make, because it has no connection to
hold open, no page to reload, and no worker racing it.

Run against `laravel-test` as **user 1** (an operator) unless a check says
otherwise. A queue worker must be running or nothing here completes.

## Chat and streaming

- [x] `/pandora/chat` opens and the conversation list renders.
- [x] Sending a message returns the composer immediately — the request does not
      hang while the run happens.
- [x] The run's state is visible and moves: queued → running → completed. It is
      not one silent pause followed by a finished answer.
- [x] The answer arrives **incrementally**, not in a single block.
      *(`BROADCAST_CONNECTION=log`, so this is the polling fallback — criterion
      22. Streaming over Reverb is a Phase 9 item.)*
- [x] A second message in the same conversation carries the first exchange as
      context — the agent can refer back to it.
- [x] The agent named above the thread is **the one the conversation was started
      with**, and it is stated rather than offered as a dropdown.
      *(Driven 2026-08-10. The picker is a dropdown **before** the first
      message — which is correct, that is the choice being made — and becomes
      plain text once the conversation exists. The check is about a
      conversation that has one, and it holds.)*
- [x] After a reload, the same agent is still named, and the next message is
      answered by it — check `runs.agent_id`, not the badge.
      *(Added 2026-08-07. Both fail against the code this walkthrough was
      originally driven on — see the Phase 2 defect. Driven 2026-08-10 against
      `delta`: runs `01kznx5s5pkqp8r1g0q11s2wqg` and, after a reload,
      `01kznx81y8n7w5pb605wg4b618` — same conversation, both
      `agent_id=01kzdwgca5mcx9wcwdhzkxbaxb`, confirmed in the database rather
      than from the badge.)*

## A reload mid-run

The claim: the database is authoritative and every broadcast is disposable.

- [x] Send a message that takes a few seconds, then **reload the page while it
      is still running**. The conversation comes back with everything produced
      so far and no duplicate text.
- [x] The run finishes correctly after the reload, without a second worker
      being needed and without restarting from the top.
- [x] Open the same conversation in a second tab mid-run. Both tabs converge on
      the same final state.

## The trace

- [x] Opening a completed run shows its steps in order: context retrieval,
      model request, model response, final response.
- [x] Timings and token counts are present and plausible against a real clock.
- [x] **No credential appears anywhere in the trace** — not in the model
      request, not in headers, not in any payload. The host's key begins `sk-`;
      search the page for it.

## Cancellation

- [x] Start a run and press stop. It moves to cancelling, then cancelled.
- [x] It **stays** cancelled — no continuation arrives seconds later and
      finishes the answer anyway.
- [x] The cancelled run's trace shows where it stopped rather than looking like
      a failure.
- [x] The conversation is usable immediately afterwards: a new message starts a
      fresh run.

## Guests and other users

- [x] Logged out, `/pandora` redirects rather than rendering anything.
- [x] As **user 2** (not an operator), chat works and the conversation list
      contains none of user 1's conversations.
- [x] User 2 opening one of user 1's conversation URLs directly is refused.

## Defects found

**One, and it was found by the next walkthrough rather than this one.** The
Phase 2 walkthrough, driven the same day, found that `Chat::mount()` repointed a
conversation at the alphabetically first agent on every reload — a defect in the
chat page, which is Phase 1's surface. It is written up in full in
`phase-2-walkthrough.md`.

This walkthrough drove a reload mid-run and passed, because it asked whether the
*message* survived and never asked *who was answering*. The check "a second
message carries the first exchange as context" passes perfectly well when the
second message is answered by a different agent. Two checks were added below to
close that.

Otherwise a clean sheet, and the reason is worth stating rather than celebrating:
and the reason is worth stating rather than celebrating: Phase 1's three defects
were found by the *console* half in Q9 back in August, and the two that mattered
— `run()` not waiting, and the layout reading its stylesheet with `__DIR__` —
were exactly the kind this half would otherwise have caught. The browser half
ran clean because the surface it drives had already been fixed under it.

The reload check is the one to keep. Nothing in the package suite can hold a
connection open, throw the page away mid-run, and ask whether the database alone
can reconstruct the truth. It can.

## Notes

Anything found here goes in `progress.md` with the same honesty Phases 4 and 5
used: what broke, why the suite could not see it, and what changed as a result.

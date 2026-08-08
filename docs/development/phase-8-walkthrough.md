# Phase 8 — Host Walkthrough

> Status: **not driven.** Every box below is unticked, and criterion 33 in
> `phase-8-acceptance.md` stays open until a human has driven it against
> `laravel-test` and a real Slack workspace.
>
> Criteria 1–32 are verified by automated test, including 19 tests in
> `laravel-pandora-slack`'s own suite running against core through Composer.
> What none of that can tell you is the thing this phase is actually about:
> whether the refusal a real person meets, the first time they message an agent
> from Slack, tells them what to do next.

Every walkthrough so far has found something the suite could not. Phase 1's
found three defects, Phase 4's found three, Phase 5's found a Memory page
serving everyone's mail on an installation whose retrieval scoping was proven by
twenty-eight passing criteria, and Phase 6's found a delegated child that could
not see its own tool loop while nothing threw and nothing logged. Expect this one
to find something too, and write down what it was.

Two phases are also owed walkthroughs (Q10). If you are driving this one, drive
those too — that is what the deferral was for.

Run against `laravel-test`, or any host application, with a real Slack app.

## Before you start

The same landmines as Phases 6 and 7, plus one new one:

- [ ] **Restart the queue worker after every change to package source.**
      `queue:work` loaded its classes at boot. A symlinked path repository
      updates the files instantly and the worker keeps serving the old code, so
      a correct fix looks like it did nothing.
- [ ] **`vendor:publish --tag=pandora-config --force`, or add the new keys by
      hand.** A published `config/pandora.php` is a snapshot. One published
      before this phase has no `channels` block and no `features.channels`, and
      the sidebar entry simply will not appear — which reads exactly like a
      routing bug. Note what `--force` does to migrations before you run it
      (see the Phase 7 walkthrough's warning).
- [ ] **`php artisan migrate`** for `0001_01_01_000029_create_pandora_channel_tables`.
- [ ] **Slack needs a public URL.** `ngrok http 8000` or equivalent. Slack will
      not deliver to `localhost`, and the endpoint refuses everything until
      `SLACK_SIGNING_SECRET` is set, so a misconfigured tunnel and a missing
      secret look identical from the Slack side (both are "your URL didn't
      respond").

## 1. Install the extension

- [ ] Add the path repository and `composer require michal78/laravel-pandora-slack`.
- [ ] `php artisan pandora:extension:list` names Slack, its version, and what it
      declares.
- [ ] The **Extensions** page shows the same, and shows `channels: slack` under
      both *declares* and *registers*.
- [ ] **Nothing is connected.** No channel account exists, and the Channels page
      says so. Installing granted the right to offer a capability and nothing
      else.

## 2. Register a workspace

- [ ] Create a Slack app: `chat:write`, an Events subscription for `message.im`,
      request URL pointing at `/pandora/slack/events`.
- [ ] Slack's URL verification succeeds. If it does not, check the signing
      secret before anything else — an unverifiable request is refused, and the
      refusal is deliberately terse.
- [ ] Store the bot token: `app(CredentialManager::class)->issue('channel.slack.acme', $token)`.
- [ ] **Channels → Register a workspace.** Slack, a name, the team id, the
      credential key, and an agent.
- [ ] It is created **disabled**. Confirm that, then enable it.

## 3. The refusal — the reason this walkthrough exists

- [ ] From a Slack account nobody has linked, DM the bot something an agent would
      happily answer.
- [ ] **Nothing happens on the Pandora side**: no run, no session, no
      conversation. Check the Runs page, not just the reply.
- [ ] The reply tells you how to link. **Read it as a stranger would.** Is the
      instruction followable without knowing anything about Pandora? Is it clear
      where "sign in" means? Write down what you actually thought.
- [ ] Message twice more. You are refused all three times and answered once —
      the delivery rows show three inbound refusals.
- [ ] The Channels page shows the identity as *not linked — messages refused*.

## 4. Link

- [ ] Send `link`. A code arrives in the channel.
- [ ] Sign in to the host application and redeem it at
      `/pandora/channels/link`. **Was the URL discoverable?** If you had to be
      told it, that is a finding.
- [ ] Message the agent again. It answers, in the thread, as you.
- [ ] The run's actor is your host user — not the agent, not a system actor.
- [ ] Ask it to do something your user is *not* permitted to do. It is refused
      on your abilities, not the agent's.

## 5. Two people, one channel

- [ ] Link a second Slack account to a second host user.
- [ ] Tell the agent something private from account A.
- [ ] Ask about it from account B. **It does not know.** Two sessions, two
      isolation keys.

## 6. Failure and revocation

- [ ] Revoke the Slack bot token, or point `SLACK_API_BASE` at nothing. Message
      the agent.
- [ ] The run completes and the reply is a **recorded delivery failure** —
      visible on the Channels page, and not re-routed anywhere.
- [ ] Restore the token. Unlink the identity from the Channels page.
- [ ] Message again: refused, immediately.
- [ ] Link the same Slack account to a **different** host user. Ask about what
      the first user told the agent. **It does not know** — a new link is a new
      boundary, not a restoration.

## 7. Approvals

- [ ] Give the agent a tool your approval policy gates, and ask for it from
      Slack.
- [ ] The channel is told an approval is waiting. **There is no way to approve
      it from Slack**, including by replying "yes".
- [ ] Approve it in the control center. The run resumes and the answer arrives
      in the channel.

## 8. The Extensions page, honestly

- [ ] Break the extension deliberately — rename a class its service provider
      references, or point its autoload prefix at nothing.
- [ ] The Extensions page **still renders**, still names the package, and shows
      the declared-versus-registered difference.
- [ ] There is no install, update or upgrade control anywhere on it.

## What to write down

For each finding: what you expected, what happened, and whether the suite could
have caught it. That last column is the one that improves the next phase — a
defect a test could have caught is a missing test, and a defect no test could
have caught is why this document exists.

# Phase 8 — Host Walkthrough

> Status: **partially driven, 2026-08-09**, against `laravel-test` and a real
> Slack workspace (`T0BNV8RM7MZ`). Sections 1–4 driven; 5 and 7 not; 6 and 8
> pending. Nine findings, three fixed during the run. Criterion 33 stays open.
>
> - **Sections 1–4 driven.** Install, register, refuse a stranger, link, and
>   answer as the linked user. Findings 1–7 came from these.
> - **Section 5 not driven — no second Slack account was available.** Two
>   people on one channel account is therefore **unproven**, in either
>   direction. It is the claim a single-account walkthrough cannot make.
> - **Section 6 and 8 pending.**
> - **Section 7 blocked** by finding 9, which is a core defect rather than a
>   channel one.
>
> What the boundary got right, it got right from the first message. Every
> defect below is on the path *out* — what a person is told, and whether it
> reaches them.
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

## Findings

### 1. The refusal a stranger is owed never arrived (`invalid_thread_ts`)

**Expected.** An unlinked Slack account DMs the agent, is refused, and is told
in the channel how to link.

**What happened.** The inbound half was perfect — `refused / identity_not_linked`,
no identity, no run, no session. The *reply* failed:
`Slack refused the message: invalid_thread_ts`. A stranger meets silence, and
the one message the whole phase is built to deliver is the one that bounced.

**Cause.** Two identifiers conflated in the adapter. `SlackEventController`
filled `InboundMessage::externalMessageId` from `client_msg_id` — a
client-generated UUID — falling back to `ts`. `ChannelInbox` carries that value
back out as `OutboundMessage::replyToExternalId` (`ChannelInbox.php:350`), and
`SlackChannel` puts it straight into `thread_ts`. Slack's threading anchor must
be a message timestamp, so `chat.postMessage` refused the delivery outright.
`ts` is the only Slack value that is both stable enough to deduplicate on and
valid to reply to; `client_msg_id` is neither, and is absent from whole classes
of message event besides.

**Fixed.** Prefer `ts` in the controller, and treat an unthreadable anchor as a
reason to drop the threading rather than the message —
`SlackChannel::threadAnchor()` now sends `thread_ts` only when it matches
`\d+\.\d+`. A reply in the conversation instead of the thread is cosmetic; a
reply that never lands is silence.

**Could the suite have caught it?** Yes, and it should have. Both halves were
tested and both passed: the inbound test asserted a round-trip of whatever it
was handed, and the outbound test only ever used a well-formed `ts` of its own
making. Nothing asserted the two against each other. The new test in
`SlackEventTest` drives a signed stranger event through to a faked
`chat.postMessage` and asserts `thread_ts` is the event's `ts` — it fails
against the old code. **A defect that survives every test of its parts is a
missing test of the seam.**

### 2. A disabled account refuses in total silence

**Observed** before enabling the workspace: the first message recorded
`refused / account_disabled` with no identity, no run — and no reply of any
kind. Defensible, since an operator has not yet consented to this workspace
speaking, and the alternative leaks the existence of a registration to anyone
who guesses a team id.

**Then observed again, unprompted, which is the part that matters.** Midway
through the walkthrough the driver replaced the account with a fresh one and
reported the agent had stopped answering. The cause was this exact behaviour:
new accounts are created disabled, and a disabled account is silent. Somebody
who had already read and recorded this finding still lost time to it. The
silence is not merely worse-reading — it is indistinguishable from a broken
integration, and the Channels page shows `enabled/disabled` as a quiet state
pill rather than as the reason nothing is happening.

Not fixed: the refusal-to-speak is deliberate. What is missing is a signal to
the **operator**, who is already authenticated and owed one — the Channels page
should say that a disabled account is refusing messages, in the place the
deliveries are listed, rather than leaving the state pill to be inferred from.

**Related, same incident.** Removing an account cascades its identities away, so
every linked person becomes a stranger and must re-link. Correct — an identity
is meaningless without its account — but nothing warns before the removal that
this is what it costs.

### 3. The refusal window is spent on an attempt, not on a delivery

**Expected.** After fixing finding 1, the next message from the unlinked account
is refused *and* answered.

**What happened.** Refused, with no outbound row at all. Correct by the code as
written: `ChannelInbox.php:212` charges a one-per-`instruction_interval_seconds`
(default 600) rate limit before calling `reply()`, and the earlier bounced
attempt had already spent the slot. Nine minutes later the stranger was still
inside the window.

**Why it matters beyond this session.** The two defects compound into total
silence. Any delivery failure on a first refusal — a revoked token, a Slack
outage, a thread anchor Slack dislikes — silences a stranger for ten minutes
with nothing to indicate why, and they will read that as the agent ignoring
them. The limit itself is right: instructions aimed at somebody else's channel
are exactly the flood worth preventing. **What is wrong is charging for an
attempt.** The slot should be spent when the channel accepts the message, so a
failed delivery leaves the stranger able to be told next time.

**Not fixed during the walkthrough** — it moves the limiter across the
`reply()`/`DeliveryResult` boundary and deserves its own change with a test
that fails a delivery and asserts the next message is still answered. Cleared
by hand (`RateLimiter::clear`) to continue.

**Could the suite have caught it?** Only with a test that pairs a failing
delivery with a follow-up message. Nothing exercised that combination, because
until finding 1 there was no reason to think a refusal could fail to send.

### 4. "Send test" cannot work for a Slack account

`ChannelsIndex::sendTest()` builds an `OutboundMessage` with no
`conversationExternalId`, and `SlackChannel::directChannel()` falls back only to
a `default_channel` in account settings — which nothing sets and no form asks
for. The result is a delivery row reading *No Slack conversation to reply in.*
A control that offers an operator a check it can never pass is worse than no
control, because the failure looks like a broken channel.

**Fix.** Fall back to the identity's `external_id`: Slack's `chat.postMessage`
accepts a user id as `channel` and opens the DM. That makes the button work and
gives the adapter a way to reach a person with no prior conversation — which is
also what a proactive agent message would need.

**Could the suite have caught it?** Yes. `FakeChannel` records whatever it is
handed and never asked whether a real adapter could route it. A test that sent
to an identity with no conversation would have caught it in either package.

### 5. The linking instructions name no destination

**This is the finding the phase was written to surface.** Both messages a
stranger receives end at the point where they would need to know something they
cannot learn from inside Slack:

> Send "link" and I will give you a code to enter **while signed in**.
> Your linking code is R8V7Q6AQ. **Sign in to the application** and enter it
> there.

"The application" is not a place. No name, no URL, no indication it is a web
page rather than something in Slack.

Half of this is host configuration: `pandora.channels.linking.redeem_url` was
never set in `laravel-test`, and `codeMessage()` substitutes the generic phrase.
The config comment already says *"Null names the application generically, which
works and reads worse; set it"* — the walkthrough's contribution is that the
result is not merely worse-reading, it is **unfollowable**, and that a default
nobody sets is a default that ships.

The other half is not configuration at all: `instructions()` — the *first*
message any stranger sees — contains no URL even when `redeem_url` is set.
Setting the key cannot fix it.

**Fix.** Default `redeem_url` to `route('pandora.channels.link')` rather than
null: the package knows its own route, and an absolute URL is strictly better
than a generic noun. Then thread that URL through `instructions()` too, so the
first refusal names the destination instead of deferring it to a second
message the flood limiter may never let us send.

**Could the suite have caught it?** No, and this is why the document exists. The
string is asserted, the config key resolves, every criterion passes. No test can
notice that a sentence a human must act on does not say where to go.

### 6. Agent markdown is delivered raw into Slack

The first linked answer arrived as `You are currently interacting with the
**Delta** agent.` Slack's mrkdwn marks bold with single asterisks, so a model's
ordinary markdown renders literally. Cosmetic, but it is the kind of cosmetic
that makes an agent look broken to somebody who has never seen it work — and
lists, headings and code fences will be worse than bold.

Belongs in the adapter, not the core: markdown is the right thing for the model
to produce, and each channel knows its own dialect. `SlackChannel` should
translate on the way out.

### 7. "Not authorized" for a notification that was never configured

`send_notification` refused with *"You are not authorized to use
[send_notification]"* because `pandora.tools.notifications` was empty:
`authorize()` looks the name up, gets null, and returns false. Nothing was
authorized because nothing existed, and the operator was told they lacked
permission.

The tool's own docblock names this exact failure mode one method higher up —
it argues for an enum over a free string because otherwise a misspelled name
yields *"a permissions answer to a spelling question"*. The same class then
gives a permissions answer to a configuration question. `authorize()` should
distinguish "no such notification" from "not yours to send".

### 8. Three built-in tools were uncallable by a compliant model — **fixed**

**Expected.** `send_notification` reaches the approval machinery.

**What happened.** `[walkthrough_ping] does not accept a field called [0].` The
model sent `payload` as a positional list. It was right to: the schema said
`"type": ["array", "null"]`, and in JSON schema that is a list.

**Cause.** `RuleSchemaGenerator::TYPES` mapped both of Laravel's array rules to
JSON `array`. But `array` accepts a keyed map and `list` demands sequential
keys — two shapes, two words. Every tool taking named arguments through an
`array` rule advertised the wrong one: `send_notification`, `dispatch_job`,
`emit_event` and `query_records`. A model that followed the schema exactly
produced arguments the tool then refused.

**Fixed.** `array` maps to `object`, `list` to `array`; wildcard `field.*`
children still resolve to a list whatever the rule line says; and `min`/`max`
on an object emit `minProperties`/`maxProperties`.

**Could the suite have caught it?** It had every opportunity and asserted the
defect instead. The test named *"builds nested object schemas from dotted
rules"* asserted `items` — a list — and passed. **A test can encode the bug it
was written to prevent, and then defend it.** Nothing else noticed because no
host had ever configured a job, event or notification for an agent to call, so
these four tools had never been called with arguments in anger.

### 9. A message sent during an approval corrupts the run — **not fixed**

**What happened.** After approving `send_notification` in the control center,
the run failed:

> An assistant message with 'tool_calls' must be followed by tool messages
> responding to each 'tool_call_id'.

The channel got *"Something went wrong and I could not finish."*

**Cause.** The transcript, in order:

```
user       "Send me the walkthrough_ping notification…"
assistant  (tool_calls, awaiting approval)
user       "yes"                       ← sent while the run was paused
assistant  (tool_calls, second run)
tool       result for the FIRST call   ← appended at the tail
```

A paused run's conversation keeps accepting messages. When the approved tool
finally runs, its result is appended chronologically rather than adjacent to
the assistant message that requested it, and the provider rejects the whole
request. A second run reading the same conversation fails differently and for a
related reason: it sees an assistant `tool_calls` whose result does not exist
yet at all.

**It does not heal. This is the part that makes it urgent.** Driving section 6
an hour later, on the same Slack session, every run still failed with the same
error — four consecutive runs on conversation `…bz90s6`, which by then held
seven assistant messages carrying `tool_calls`. Once a conversation acquires
one unanswered tool call it is finished: every future message in it dies at the
provider, forever. The participant sees *"Something went wrong"* every time,
with no event marking the moment their session stopped working and no way back
from inside the channel. A durable, silent, unrecoverable break in the one
place we cannot see.

**Why it matters.** Step 7 instructs the driver to reply "yes", so the
walkthrough found this by following its own script — but "yes" is incidental.
*Any* message sent while a run awaits approval corrupts the transcript, and a
channel is exactly where somebody will keep typing at a silent bot. Two people
on one channel account would trigger it without either doing anything odd.

**Recommended fix, deliberately not applied here.** Normalise the transcript
where it is assembled for the provider: group each assistant's `tool_calls`
with its own results and push interleaved user messages after, *and*
synthesise a placeholder `tool` message for any call with no result yet.
Reordering alone cannot repair a message that does not exist. One place,
provider-agnostic, no change to what anyone is allowed to do.

A third option — refusing new messages while a run awaits approval — was
rejected: it makes the person outside the boundary absorb the cost of an
internal invariant, which is the failure mode this phase exists to prevent.

This is core run semantics and wants its own change with tests for both the
paused-run and second-run cases. **Step 7 is blocked on it and is recorded
undriven, not passed.**

### 10. The Edit button on the Channels list did nothing — **fixed**

Reported by the driver in three words, after every automated test of that page
passed. The edit form renders `@if ($account !== null && $form === $account->slug)`,
and `$account` is the *selected* account — set by **Inspect**. `startEditing()`
set `$form` and never `$selected`, so unless the row had already been inspected,
clicking Edit changed nothing on screen. Nobody's first move is to inspect a row
before editing it.

Fixed by selecting the row being edited, with a test that fails against the old
code. Thirteen tests covered that page — listing, registering, refusing,
unlinking, escaping a hostile display name, tenancy — and not one clicked Edit
without clicking Inspect first. **Tests exercise the paths their author was
thinking about; a person clicking buttons exercises the ones they were not.**

### 11. There is no "thinking" signal in the channel

Requested by the driver, and it belongs with findings 1, 2 and 9 rather than in
a feature list: this phase keeps producing silence that a person cannot
distinguish from being ignored. A run takes seconds, and Slack shows nothing
until the answer lands — the same nothing a disabled account shows, the same
nothing a bounced refusal shows, the same nothing a corrupted conversation
shows forever.

Slack has a typing indicator, and the `Channel` contract has no way to ask for
one. That is the gap: an optional capability a channel may implement and the
core may signal on run start, so an adapter that has one uses it and one that
does not degrades to today's behaviour.

### What worked, and should not be quietly lost

- The link page states the boundary to the person it applies to, in their
  terms: *"The code proves you hold that channel account; being signed in here
  proves you hold this one. Linking is the claim that those are the same
  person, so it needs both."*
- The inbound refusal path was correct from the first message and stayed
  correct through every defect above it: no identity, no run, no session for a
  stranger, and a durable row for each refusal.
- After linking, the run carried `trigger_type: channel` and
  `actor_type: App\Models\User` — the host user, not the agent and not a system
  actor — with the inbound and outbound deliveries both bound to that run.

## What to write down

For each finding: what you expected, what happened, and whether the suite could
have caught it. That last column is the one that improves the next phase — a
defect a test could have caught is a missing test, and a defect no test could
have caught is why this document exists.

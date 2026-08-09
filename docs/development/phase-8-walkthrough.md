# Phase 8 — Host Walkthrough

> Status: **partially driven, 2026-08-09**, against `laravel-test` and a real
> Slack workspace (`T0BNV8RM7MZ`). Sections 1–4, 6 and 8 driven; 5 and 7 not.
> Fifteen findings, six fixed during the run. Criterion 33 stays open.
>
> - **Sections 1–4 driven.** Install, register, refuse a stranger, link, and
>   answer as the linked user. Findings 1–7 came from these.
> - **Section 5 not driven — no second Slack account was available.** Two
>   people on one channel account **at the same time** is therefore unproven,
>   in either direction. It is the claim a single-account walkthrough cannot
>   make. Section 6's relink covers the same boundary *sequentially* and it
>   held; that is weaker evidence, not a substitute.
> - **Section 6 driven.** Revocation, unlink, refusal, and relink to a
>   different host user. The isolation claim held on both layers. Findings 12
>   and 13 came from this, and finding 5 was re-confirmed live.
> - **Section 8 driven, and its central claim failed.** A deliberately broken
>   extension takes the entire application down, so the page built to show you
>   a broken extension is the page you cannot reach. Finding 14. Its third
>   bullet — no install control — passes cleanly.
> - **Section 7 blocked** by finding 9, which is a core defect rather than a
>   channel one. Its first bullet — the channel is told an approval is waiting
>   — was observed working incidentally while setting up section 6.
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

**Confirmed live while driving section 6**, under the best conditions the
finding will ever get. `pandora.channels.linking.redeem_url` resolved to a real
absolute URL (`https://…/pandora/channels/link`), the account was enabled, the
identity had just been unlinked by an operator, and the refusal delivered
first time. The message was still:

> This account is not linked to a user yet, so I cannot act on it. Send "link"
> and I will give you a code to enter while signed in.

`ChannelInbox.php:403` is a two-line string literal with no destination in it
and no access to the configured URL. Signed in *where* remains unanswerable
from inside Slack even on an installation that has done everything right.

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

### 9. A message arriving mid-tool-call corrupts the conversation forever — **fixed**

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

**The approval is incidental too. Observed again, later the same day, with no
approval anywhere near it.** On a conversation four link-epochs old and one
minute into its life:

```
18:24:07  assistant  tool_calls  call_5Qoy…
18:24:08  user       "Hello?"                  ← one second later
18:24:10  tool       result for call_5Qoy…
```

An ordinary `recall` call, a **one-second** window, and the driver typing
"Hello?" because nothing appeared to be happening. Four consecutive runs then
died on that conversation, exactly as before.

This widens the finding substantially. The hazard is not "a message during an
approval pause", which is rare and involves a human decision somewhere. It is
**a message arriving between an assistant's `tool_calls` and its results**,
which is every tool call the agent ever makes — a window measured in the seconds
a tool takes to run, occurring on the completely ordinary path. An approval
merely widens it from seconds to minutes.

Two details sharpen the fix:

- **The tool result existed.** `call_5Qoy…` was answered at 18:24:10 and the
  provider still refused, because the answer was not *adjacent* to the request.
  Reordering alone would have saved this conversation; the placeholder
  synthesis is for the paused case only. The two halves of the recommended fix
  address genuinely different failures.
- **Finding 11 caused it.** The driver typed "Hello?" because a channel that is
  working and a channel that is broken look identical while a run is in
  flight. The missing typing indicator is not cosmetic — it is what makes a
  person generate the input that destroys their own session, permanently, in
  under a second.

**The fix.** `Pandora\Context\TranscriptNormaliser`, applied in
`ContextBuilder::build()` after every section has contributed — the last point
at which the transcript is still ours, and one place for every provider. It
walks the assembled messages and emits each assistant turn followed immediately
by one `tool` message per call it made:

- **Out of order.** The result is moved up to its request; whatever was
  interleaved is emitted after the group, in its original relative order.
  Nothing is dropped and nothing is invented. This alone would have saved both
  poisoned conversations.
- **Not there at all.** A call with no result — a run still parked on an
  approval — gets a synthesised placeholder saying so, because reordering
  cannot repair a message that was never written. The wording is truthful
  rather than an error: a model told the tool *failed* apologises for something
  that has not happened.

The two halves have their own tests in `tests/Feature/ToolContextReplayTest.php`,
because they repair different failures. The interleaving test is the captured
18:24:07 transcript verbatim.

`RecentMessagesProvider` lost its `dropOrphanedToolMessages()`, which was the
same problem solved partially and in the wrong place: it handled the recency
window cutting a loop in half, but not a conversation storing things in the
order they happened, and it answered an unanswered call by *stripping the
assistant's tool calls* — valid, but it threw away the record of what the agent
had asked for. Two of its tests were replaced rather than kept; the behaviour
they pinned was deliberately changed.

A third option — refusing new messages while a run awaits approval — was
rejected: it makes the person outside the boundary absorb the cost of an
internal invariant, which is the failure mode this phase exists to prevent.

**Note what this does not do.** It repairs the transcript on the way *out*, so
an already-poisoned conversation starts working again on its next message and
no data is rewritten. It does not stop the interleaving from happening, and it
does not make finding 11 any less urgent — the missing typing indicator is
still what makes a person type into that window.

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

### 12. The memory guard refused the secret; the approval card kept it

**Found by accident**, which is the only way this one was ever going to be
found. Setting up section 6 wanted a private fact for the agent to later not
remember, and the fact chosen was a passphrase. Three things then happened in a
single run, and they do not agree with each other:

1. `request_approval` fired at `critical` risk and the channel was told an
   approval was waiting — correct, and the first half of section 7 working.
2. The memory tool **refused to store it**: *"That was not stored: it looks
   like a credential or another secret, and those are never kept in memory."*
   Exactly right.
3. The approval record created seconds earlier persisted the secret in the
   clear:

```
sanitized_arguments = {"detail": "... their walkthrough passphrase, which is
'copper-lantern-84' ...", "summary": "I propose to remember a sensitive
passphrase for the user."}
```

**Cause.** `ToolCallCoordinator.php:350` builds `sanitized_arguments` with
`Redactor::redact()`, which decides by **key name** — `password`, `token`, and
so on. `request_approval` takes two free-text fields, `summary` and `detail`,
and a model explaining *why* it needs approval to store a secret will naturally
put the secret in the explanation. Neither key is sensitive, so the value passed
through verbatim.

`Redactor` already has the other half. `redactText()` exists for exactly this —
its own docblock calls it *"a belt-and-braces pass for values we could not catch
by key"* — and it is wired into `MemoryWriter`, `ContextBuilder`,
`RunStateMachine`, `RunFailer`, `DelegationCompleter` and `ExecuteToolCall`. It
is not wired into the one payload whose column name promises it has been
sanitized, and which is rendered on a card for an operator to read.

**Why it matters more than one leaked string.** `Approval.php:27` states the
contract in a comment: *"The card shows `summary` and `sanitized_arguments` —
never the raw ones."* The name and the comment both assert a guarantee the code
does not provide, so every future reader is entitled to believe secrets cannot
reach that column. And the leak is concentrated where it is worst: approvals are
the records most likely to be long-lived, exported, and read by someone other
than the person whose secret it is.

**Not fixed here.** Running `redactText()` over string values inside `redact()`
is one line and the wrong instinct to act on mid-walkthrough: it changes every
redacted payload in the system at once. It wants its own change, with a test
that puts a credential in a free-text argument and asserts it does not survive
into `sanitized_arguments`.

**Could the suite have caught it?** Only by asking a question nobody had asked:
whether two components that both classify secrets agree. The memory guard has
tests, the redactor has tests, and both pass — the same shape as finding 1,
where each half was tested and the seam between them was not. Here the seam is
not a data path but a *definition*: `MemoryWriter` and `Redactor` hold different
opinions about what a secret is, and the run consulted both.

### 13. An agent's question to a channel is never asked — **fixed**

**Reported by the driver as "the agent did not answer my first message."** It
had answered. Nobody could see it.

**What happened.** Freshly linked as the second host user, the first message was
*"What is my desk plant called?"*. Slack showed nothing. The transcript shows
the run reached a reply:

```
user       "What is my desk plant called?"
assistant  (tool_calls)
tool       "Nothing remembered about that."
assistant  (tool_calls)
tool       "What is the name of your desk plant?"
assistant  "What is the name of your desk plant?"     ← never delivered
```

The run (`…hsaytw`) is not failed and not errored. It is `waiting_for_user`,
with no `finished_at`, holding a question for somebody who was never told it was
asked. The driver, meeting silence, typed something else — which started a
*second* run, and that one completed and delivered normally. The first is still
parked.

**Cause, part one — the question is not sent.** `ChannelReplier::textFor()`
matches `Completed`, `Failed`, `TimedOut`, `Cancelled` and `WaitingForApproval`,
then `default => null`, and a null text returns before any delivery is built.
`WaitingForUser` falls into the default. This is deliberate machinery, not an
accident of an unreachable state: `RunStateMachine.php:39` comments *"
WaitingForUser is reachable because a tool may ASK something"*, `ToolResult`
documents the park, and `ResumeRunWithUserReply` exists to end it. Every piece
is built except the one that tells the person.

**Cause, part two — the reply cannot resume it.** `Pandora::reply()` is the way
back into a parked run, and its only caller is the web chat. `ChannelInbox`
never checks for a waiting run, so a channel message always starts a new one.
The parked run stays the conversation's active run and never reaches a terminal
state.

**The web UI already solved exactly this, and said why.**
`UI/Livewire/Chat.php:225`:

> A run that asked something is owed an answer, not a competitor. Left to start
> a fresh run, the parked one is never resumed and so never reaches a terminal
> state -- it stays the conversation's active run, and the header goes on
> reporting "Waiting for you" over the top of a conversation that has since
> moved on.

The hazard was understood, written down, and fixed on the surface where a
person can at least *see* the question they are failing to answer. On the
surface where they cannot see it, neither half is handled.

**Why it matters.** This is the phase's recurring failure in its purest form.
A disabled account is silent (finding 2), a bounced refusal is silent (finding
1), a corrupted conversation is silent (finding 9) — and now an agent that
politely asks a clarifying question is silent too. Four unrelated causes, one
indistinguishable symptom, and no way for the person outside the boundary to
tell them apart. It also makes an ordinary, well-behaved agent the trigger:
asking for clarification is the *correct* thing for a model to do when it does
not know something, so the better the agent behaves, the more often the channel
goes quiet.

**It was already the stated intent.** `ChannelReplier`'s own class docblock:
*"A pause -- waiting for an approval **or for the user** -- is also announced."*
Half of that sentence was true.

**Fixed, both halves together** — either alone makes things worse. Delivering
without resuming asks a question no answer can reach; resuming without
delivering answers a question nobody heard.

- `ChannelReplier` gained a `WaitingForUser` arm. It cannot use `$run->output`
  the way `Completed` does — a parked run's output is empty, because it has
  produced no answer and may never — so it reads the question from the
  assistant message `MessageWriter::question()` marked `awaiting_answer`.
- `ChannelInbox` checks for a run in `waiting_for_user` on the identity's
  conversation and routes the message to `Pandora::reply()` instead of starting
  a new run, recording the delivery against the resumed run and marking the
  audit row `resumed_run`.

The approval boundary is untouched and now has a test saying so: a message
arriving during a `waiting_for_approval` pause still starts a new run, because
that decision is not the channel's to carry (ADR-0015).

**Could the suite have caught it?** Yes, and the missing test was nameable
before it was written: drive a channel run whose tool parks it at
`waiting_for_user`, and assert the channel was told.

The state was not neglected. `ask_user` is a built-in tool with its own feature
test, `ChatTest` covers the web chat parking on it, and `AgentDetailTest` names
it in a tool policy. **Every surface tested it except the channel**, which is
finding 1's lesson again in a different place: both halves were covered and the
seam between them was not. The `default => null` is where it hid — **a match
over an enum with a default arm silently absorbs the states nobody thought
about**, and this one absorbed a state the codebase documents in four other
files, including the docblock of the class doing the absorbing.

The new `QuestionTest` deliberately drives the shipped `AskUserTool` rather than
a fixture. A fixture here would have proved that *some* tool parking a run gets
its question delivered, which is not the claim worth defending.

**Verified live in Slack afterwards**, which is the only verification this
document really trades in. Asked something it could not know, the agent's
`recall` came back empty, `ask_user` parked the run — and the delivery rows show
an outbound message on the *parked* run (`18:22:03`, run `yn2vy9`), where before
the fix there was no outbound row at all. The next inbound message a minute
later is recorded against **the same run**, not a new one.

**One consequence worth knowing.** While a question is pending, the next message
is an answer, so it is consumed as one. The driver happened to type `link` at
that moment and the agent dutifully recorded a bicycle called "link". This is
the intended behaviour rather than a defect — an already-linked identity has no
`link` command, so that text was always destined for the agent — but it does
mean a pending question takes precedence over anything else a person might be
trying to say. Worth remembering if channel-level commands are ever added for
linked participants.

### 14. A broken extension takes the whole host down, Extensions page included

**Expected**, and written into section 8 as a checklist item: break the
extension deliberately, and *"the Extensions page still renders, still names the
package, and shows the declared-versus-registered difference."*

**What happened.** `Pandora\Slack\SlackChannel` was renamed so the class its
service provider references no longer exists — the first of the two breaks
section 8 suggests. Every route in the application returned **HTTP 500**,
`/pandora/extensions` among them:

```
Illuminate\Contracts\Container\BindingResolutionException
Target class [Pandora\Slack\SlackChannel] does not exist.
  at Illuminate\Foundation\Application::boot()
```

`php artisan pandora:extension:list` — the command section 1 uses to name an
extension and what it declares — dies with the same error. **Both surfaces built
to tell an operator that an extension is broken are destroyed by an extension
being broken**, and the failure is total rather than confined: the Runs page,
the Channels page and the agent itself go with it.

**Cause.** `ChannelRegistry::register()` resolves eagerly. It accepts a class
string, immediately calls `$this->resolve($channel)`, and needs a live instance
because the key it files the adapter under comes from `$instance->key()`. That
resolution happens inside the extension's `boot()`, and a Laravel provider that
throws during boot takes the framework with it. Nothing about this is Slack's
fault — any extension registering a channel has the same shape, and so does
`ToolRegistry::register()` on the next line.

**Why the checklist item was right to expect better — the page says so itself.**
`ExtensionsIndex.php:23`:

> Nothing is booted to render this. The list comes from Composer's own
> `installed.json`, so an extension that would fatal on load still appears with
> its name and its declared capabilities — **which is the case an operator most
> needs the page for.**

The same promise is repeated to the operator in
`extensions-index.blade.php:9`. And every clause of it is *true about the page*:
`ExtensionDiscovery` reads `installed.json`, instantiates nothing, and would
have rendered the row correctly. The conclusion is false anyway, because the
fatal happens in `Application::boot()` — before routing, before the component,
before anything the page controls. **The reasoning is locally correct and
globally wrong**, which is why it survived review and a suite: nobody was
mistaken about the mechanism, they were mistaken about where the failure lands.

Declared-versus-registered is only meaningful when something declared has
*failed* to register — exactly the state that cannot be rendered. The page
reports the difference in every case except the one it was built for.

**Recommended fix, deliberately not applied here.** Make registration lazy: file
the class string under a key known without instantiating (an interface constant,
or an explicit key argument), and resolve on first use. A missing or broken
adapter then fails when a message is actually routed to it — where there is a
delivery row to record it against and an operator to read it — instead of at
boot, where it can only take everything with it. The Extensions page would then
render precisely the difference section 8 asks for.

This is not fully solvable from inside Pandora: an extension provider can throw
for reasons core never touches. But core currently *requires* every channel and
tool registration to instantiate at boot, so it converts the most ordinary
extension defect there is into a total outage. It should stop doing the part it
controls.

**Could the suite have caught it?** Only by testing an installation that is
broken, which no suite does — a package whose classes are missing does not
survive `composer dump-autoload`, so the scenario cannot exist in a green
checkout. The check is architectural rather than behavioural, and belongs where
`ChannelRegistry::register()` is documented.

**Note on debug mode.** This was observed with `APP_DEBUG=true`, which names the
missing class. With debug off — the setting section 8 implicitly assumes, since
it is asking what an *operator* sees — the same failure is a blank 500 across
the whole application with nothing to indicate that an extension is involved at
all, let alone which one.

### 15. Criterion 18's test configures its approval with a key that does nothing

**Found while writing the regression test for finding 13**, by copying the setup
from the test next door and having it not work.

`ApprovalNotificationTest` — the test behind criterion 18, *"a channel can say an
approval is waiting"* — configures its agent like this:

```php
'approval_policy' => ['require' => ['refund_order']],
```

`RiskBasedToolPolicy` reads four keys: `deny`, `require_approval`,
`require_confirmation`, `auto_approve`. **There is no `require`.** The line is
inert. The test passes because `RefundOrderTool` is `RiskLevel::High`, and a
high-risk tool pauses for a human at the gatekeeper's floor whatever any policy
says — the class docblock states exactly that: *"a `high` risk tool still pauses
for a human, because that floor is the gatekeeper's."*

So the right thing happens, for a reason the test does not name and does not
test. Copying that setup onto a `Low` risk tool produces no approval at all,
which is how it surfaced.

**What is actually unverified.** That an agent's `approval_policy` can raise the
bar for a tool whose risk would not otherwise pause it — the only case where the
policy does any work. Everything criterion 18 demonstrates would still pass with
`RiskBasedToolPolicy` deleted.

**Fixed here only where it was in the way**: the new `QuestionTest` uses
`require_approval` on a `Low` risk tool, so its approval genuinely comes from
the policy. `ApprovalNotificationTest` is left alone deliberately — correcting
its key changes what criterion 18 asserts, and that belongs in a change that
looks at the whole policy surface rather than in a walkthrough.

**Could the suite have caught it?** Not as written, and this is the third time
this phase has produced the same shape. Finding 8 had a test asserting the
defect it was named to prevent; finding 10 had thirteen tests that never clicked
Edit without clicking Inspect first; this one has a config key nobody typo-checks
because a silent no-op and a working feature look identical when the outcome is
right anyway. **A policy that reads unknown keys without complaint cannot be
tested into correctness** — `RiskBasedToolPolicy::lists()` should reject a key
it does not recognise, and then this would have been a fatal test error the day
it was written.

### What worked, and should not be quietly lost

- **Section 8's third bullet passes without qualification.** There is no
  install, update, upgrade or version-check control on the Extensions page, and
  the exclusion is argued rather than merely absent: *"a UI that can install
  code is a UI whose authorization bug is arbitrary execution"* (ADR-0016).
  Verified in the component and the view. The one thing section 8 asked the
  page not to do, it does not do.
- The link page states the boundary to the person it applies to, in their
  terms: *"The code proves you hold that channel account; being signed in here
  proves you hold this one. Linking is the claim that those are the same
  person, so it needs both."*
- The inbound refusal path was correct from the first message and stayed
  correct through every defect above it: no identity, no run, no session for a
  stranger, and a durable row for each refusal.
- **A relink is a new boundary, and it held on both layers at once.** Section
  6's last bullet was driven with a fact the agent demonstrably knew: linked as
  user 1, *"What is my desk plant called?"* answered *"Bartholomew"* — from a
  conversation transcript **and** a `scope=user`, `scope_id=App\Models\User#1`
  memory item. Unlinking cleared `linked_user_*`, `linked_at` **and
  `conversation_id`**, so the next person could not inherit the transcript;
  relinking to a different host user incremented `link_epoch` 2 → 3. Asked the
  same question as user 3, the agent did not know — and the memory item's
  `retrieval_count` was still `0` with `last_retrieved_at` null, so it was
  never fetched and silently declined. The boundary was enforced, not merely
  observed to hold.
- **The memory guard refuses credentials on content, not on configuration.**
  Asked to remember a passphrase, it stored nothing: *"That was not stored: it
  looks like a credential or another secret, and those are never kept in
  memory."* That it was right while the approval record beside it was wrong
  (finding 12) is what makes it worth naming.
- After linking, the run carried `trigger_type: channel` and
  `actor_type: App\Models\User` — the host user, not the agent and not a system
  actor — with the inbound and outbound deliveries both bound to that run.

## What to write down

For each finding: what you expected, what happened, and whether the suite could
have caught it. That last column is the one that improves the next phase — a
defect a test could have caught is a missing test, and a defect no test could
have caught is why this document exists.

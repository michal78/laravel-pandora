<?php

declare(strict_types=1);

namespace Pandora\Channels;

use Pandora\Channels\Data\OutboundMessage;
use Pandora\Messages\Enums\MessageRole;
use Pandora\Messages\Message;
use Pandora\Runs\Enums\RunState;
use Pandora\Runs\Enums\TriggerType;
use Pandora\Runs\Events\RunStateChanged;
use Pandora\Runs\Run;

/**
 * Sends a finished channel run's answer back where the question came from.
 *
 * Every terminal state is answered, not only success. A run that failed, timed
 * out or was cancelled still owes the person in the channel a sentence: silence
 * is indistinguishable from the agent ignoring them, and the whole reason
 * somebody messages a bot is that they expect a reply.
 *
 * Where the reply goes is decided entirely by what arrived: the conversation and
 * message identifiers were copied off the inbound message and stored on the run
 * when it was created. Nothing the model produced participates in routing. An
 * agent that read a hostile document cannot ask for its answer to be delivered
 * to a different channel, because there is no field it could put that in.
 *
 * A pause -- waiting for an approval or for the user -- is also announced, and
 * announced only. A channel can say that a decision is waiting; it cannot carry
 * the decision (ADR-0015).
 */
final class ChannelReplier
{
    public function __construct(
        private readonly ChannelDispatcher $dispatcher,
    ) {}

    public function handle(RunStateChanged $event): void
    {
        $run = $event->run;

        if ($run->trigger_type !== TriggerType::Channel) {
            return;
        }

        $text = $this->textFor($run, $event->to);

        if ($text === null) {
            return;
        }

        $context = $this->channelContext($run);

        if ($context === []) {
            return;
        }

        $identity = $this->identityFor($run);

        if ($identity === null) {
            return;
        }

        $account = $identity->account;

        if (! $account instanceof ChannelAccount) {
            return;
        }

        $this->dispatcher->send(new OutboundMessage(
            account: $account,
            identity: $identity,
            text: $text,
            conversationExternalId: $this->string($context, 'conversation_external_id'),
            replyToExternalId: $this->string($context, 'reply_to_external_id'),
            runId: (string) $run->getKey(),
        ));
    }

    private function textFor(Run $run, RunState $state): ?string
    {
        return match ($state) {
            RunState::Completed => $run->output === null || trim($run->output) === ''
                ? 'I finished, but produced no answer.'
                : $run->output,

            RunState::Failed, RunState::TimedOut => 'Something went wrong and I could not finish. '
                .'An operator can see what happened.',

            RunState::Cancelled => 'That request was cancelled.',

            // Notification only. The approval itself is resolved by a human
            // looking at the real arguments in the control center, which is the
            // thing a chat surface cannot faithfully reproduce.
            RunState::WaitingForApproval => 'I need approval before I can continue. '
                .'Someone with permission has to review it in the control center.',

            // The agent asked something. Unlike an approval -- which a channel
            // may only announce -- a question IS the channel's to carry, and
            // the next inbound message resumes the run holding it.
            RunState::WaitingForUser => $this->questionFor($run),

            default => null,
        };
    }

    /**
     * The question a parked run is waiting on.
     *
     * `MessageWriter::question()` records it as an assistant message on the run
     * and marks it `awaiting_answer`; the run's own `output` stays empty,
     * because the run has not produced an answer and may never.
     */
    private function questionFor(Run $run): ?string
    {
        if ($run->conversation_id === null) {
            return null;
        }

        /** @var Message|null $message */
        $message = Message::query()
            ->where('conversation_id', $run->conversation_id)
            ->where('run_id', $run->getKey())
            ->where('role', MessageRole::Assistant->value)
            ->latest('created_at')
            ->latest('id')
            ->first();

        if ($message === null) {
            return null;
        }

        $metadata = $message->metadata ?? [];

        if (($metadata['awaiting_answer'] ?? false) !== true) {
            return null;
        }

        $question = trim((string) $message->content);

        // A parked run with nothing to ask would leave the person waiting on a
        // question that was never put to them, which is the defect this arm
        // exists to close rather than a case to reproduce quietly.
        return $question === ''
            ? 'I need something from you before I can continue, but I could not say what.'
            : $question;
    }

    /**
     * @return array<string, mixed>
     */
    private function channelContext(Run $run): array
    {
        $metadata = $run->metadata ?? [];
        $context = $metadata['context'] ?? null;

        if (! is_array($context)) {
            return [];
        }

        $channel = $context['channel'] ?? null;

        return is_array($channel) ? $channel : [];
    }

    /**
     * The identity behind this run, found through its conversation.
     *
     * Deliberately not found through the participant string in the session key:
     * that carries a link epoch and is built for isolation rather than lookup,
     * and re-deriving an identity from it would make a formatting change a
     * routing change.
     */
    private function identityFor(Run $run): ?ChannelIdentity
    {
        if ($run->conversation_id === null) {
            return null;
        }

        /** @var ChannelIdentity|null $identity */
        $identity = ChannelIdentity::query()
            ->where('conversation_id', $run->conversation_id)
            ->first();

        // An unlinked identity is not answered here. The only things an
        // unlinked participant ever receives are a refusal and a code, both
        // sent by the inbox, and neither of them a run.
        return $identity?->isLinked() === true ? $identity : null;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function string(array $context, string $key): ?string
    {
        $value = $context[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}

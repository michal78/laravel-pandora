<?php

declare(strict_types=1);

namespace Pandora\Context;

use Pandora\Messages\Enums\MessageRole;
use Pandora\Providers\Data\ChatMessage;

/**
 * Put an assembled transcript into the shape every provider requires:
 * an assistant turn that requested tools is followed immediately by one tool
 * message per call it made, and by nothing else.
 *
 * A conversation is stored in the order things HAPPENED, which is not that
 * shape. A tool takes a second or two to run, and anything that arrives inside
 * that window -- a person typing "Hello?" at a bot that looks idle, a second
 * participant in a shared channel, a run parked on an approval for minutes --
 * is written between the request and its answer. The provider then refuses the
 * whole request:
 *
 * > An assistant message with 'tool_calls' must be followed by tool messages
 * > responding to each 'tool_call_id'.
 *
 * The refusal is not transient. The interleaved message is durable, so every
 * later run on that conversation is assembled the same way and dies the same
 * way, and the participant sees "something went wrong" forever with no way back
 * from inside the channel. This is the one place that shape is enforced, on the
 * way out to any provider, and it repairs two genuinely different failures:
 *
 * - **Out of order.** The results exist but are not adjacent. They are moved up
 *   to their request; whatever was interleaved is emitted after the group, in
 *   its original relative order. Nothing is dropped and nothing is invented.
 * - **Not there at all.** A call with no result -- a run still paused
 *   mid-approval -- gets a synthesised placeholder, because reordering cannot
 *   repair a message that does not exist.
 *
 * Notably NOT fixed by refusing messages while a run is busy: that would make
 * the person outside the boundary pay for an invariant that is ours to keep.
 */
final class TranscriptNormaliser
{
    /**
     * What a call with no answer yet is told to the model.
     *
     * Truthful rather than an error: the call may still be waiting on an
     * approval a human has not given, and a model told the tool FAILED will
     * apologise for something that has not happened.
     */
    public const PENDING_RESULT = 'No result yet: this tool call has not finished. '
        .'It may still be running, or waiting for a person to approve it.';

    /**
     * @param list<ChatMessage> $messages
     * @return list<ChatMessage>
     */
    public function normalise(array $messages): array
    {
        $results = $this->indexToolResults($messages);
        $consumed = [];
        $normalised = [];

        foreach ($messages as $message) {
            if ($message->role === MessageRole::Tool) {
                // Emitted with its request below, or an orphan whose request
                // fell outside the recency window -- which every provider
                // rejects just as firmly as the interleaving this class exists
                // to fix.
                continue;
            }

            $normalised[] = $message;

            if (! $message->requestsTools()) {
                continue;
            }

            foreach ($message->toolCalls as $call) {
                // First unconsumed answer wins. Ids are unique in practice;
                // tracking what has been used keeps a repeated id from being
                // attached to two different requests.
                $answer = null;

                foreach ($results[$call->id] ?? [] as $position) {
                    if (! isset($consumed[$position])) {
                        $answer = $messages[$position];
                        $consumed[$position] = true;

                        break;
                    }
                }

                $normalised[] = $answer ?? ChatMessage::tool(
                    $call->id,
                    self::PENDING_RESULT,
                    $call->name,
                );
            }
        }

        return $normalised;
    }

    /**
     * Positions of every tool result, by the call id it answers.
     *
     * @param list<ChatMessage> $messages
     * @return array<string, list<int>>
     */
    private function indexToolResults(array $messages): array
    {
        $results = [];

        foreach ($messages as $position => $message) {
            if ($message->role === MessageRole::Tool && $message->toolCallId !== null) {
                $results[$message->toolCallId][] = $position;
            }
        }

        return $results;
    }
}

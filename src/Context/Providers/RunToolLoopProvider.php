<?php

declare(strict_types=1);

namespace Pandora\Context\Providers;

use Pandora\Context\ContextRequest;
use Pandora\Context\ContextSection;
use Pandora\Contracts\ContextProvider;
use Pandora\Providers\Data\ChatMessage;
use Pandora\Providers\Data\ToolCall;
use Pandora\Tools\ToolExecution;

/**
 * The tool loop of a run that has no conversation.
 *
 * `RecentMessagesProvider` carries the loop for a run that belongs to a
 * conversation, and it does it better: message rows keep the assistant's own
 * words alongside its tool calls. But a run may have no conversation at all --
 * a delegated child, whose working notes are deliberately not threaded into
 * the parent's thread -- and for those runs no message is ever written.
 *
 * Without this provider such a run is amnesiac in a specific and expensive
 * way. Its context is rebuilt from scratch on every iteration, so it never
 * sees the call it just made or the answer it got, makes the identical call
 * again, is refused as a duplicate, cannot see that refusal either, and
 * repeats until the iteration budget ends the run. Every guard behaves
 * correctly throughout, which is what makes it hard to spot: nothing fails
 * except the work.
 *
 * The loop is reconstructed from the tool execution rows rather than
 * re-derived from the trace, because those rows are the record the rest of
 * the system already treats as authoritative -- and the assistant text between
 * calls is not stored anywhere for a conversation-less run, so a turn here is
 * a tool call and its answer, with nothing invented in between.
 */
final class RunToolLoopProvider implements ContextProvider
{
    public function key(): string
    {
        return 'run_tool_loop';
    }

    public function provide(ContextRequest $request): ?ContextSection
    {
        // A run with a conversation already has this history, in a richer
        // form. Contributing both would send every call twice.
        if ($request->run->conversation_id !== null) {
            return null;
        }

        /** @var int $limit */
        $limit = config('pandora.context.recent_messages.limit', 40);

        $executions = ToolExecution::query()
            ->where('run_id', $request->run->getKey())
            // Terminal only. An open call is one this run is still waiting on,
            // and a tool call with no answer is rejected by every provider --
            // the same orphan rule `RecentMessagesProvider` enforces when a
            // recency window cuts a loop in half.
            ->whereNotIn('status', ['pending', 'awaiting_approval', 'running'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();

        if ($executions->isEmpty()) {
            return null;
        }

        $messages = [];

        foreach ($executions as $execution) {
            // One call per assistant turn, rather than grouping a parallel
            // batch into one message. Nothing records which calls shared a
            // turn, and inventing that grouping would be a guess the provider
            // then has to honour; a sequence of single-call turns is accepted
            // everywhere and says only what is known.
            $messages[] = ChatMessage::assistantToolCalls('', [
                new ToolCall(
                    id: $execution->tool_call_id,
                    name: $execution->tool_name,
                    arguments: $execution->arguments ?? [],
                ),
            ]);

            // UNTRUSTED, exactly as in the conversation path: a tool result is
            // where a hostile page or a hostile delegate gets its sentence into
            // the prompt.
            $messages[] = ChatMessage::tool(
                $execution->tool_call_id,
                $execution->modelContent(),
                $execution->tool_name,
            );
        }

        return ContextSection::of($this->key(), $messages);
    }
}

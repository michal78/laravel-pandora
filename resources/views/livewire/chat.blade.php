{{--
    Chat.

    Rendered entirely from the database. Broadcasts trigger a re-render; they
    never carry state this view depends on. A poll is wired as a safety net and
    becomes the sole update mechanism when Reverb is disabled — in both cases
    the output is identical, because the source of truth is the same.
--}}
<div class="pd-chat"
     @if ($run && ! $run->state->isTerminal())
         wire:poll.{{ $pollIntervalMs }}ms="poll"
     @endif>

    {{-- Conversations --}}
    <div class="pd-card pd-chat-aside">
        <div class="pd-chat-aside-head">
            <x-pandora::button variant="primary" size="sm" block wire:click="startNewConversation">
                New conversation
            </x-pandora::button>
        </div>

        <div class="pd-chat-list">
            @forelse ($conversationList as $item)
                <button type="button"
                        class="pd-chat-item {{ $conversation?->getKey() === $item->getKey() ? 'is-active' : '' }}"
                        wire:key="conv-{{ $item->getKey() }}"
                        @if ($conversation?->getKey() === $item->getKey()) aria-current="true" @endif
                        wire:click="selectConversation('{{ $item->getKey() }}')">
                    <span class="pd-chat-item-title">{{ $item->title ?? 'Untitled conversation' }}</span>
                    <span class="pd-chat-item-meta">
                        {{ $item->last_activity_at?->diffForHumans() ?? 'No activity' }}
                    </span>
                </button>
            @empty
                <p class="pd-faint" style="padding: var(--pd-space-3); font-size: 12px;">
                    No conversations yet.
                </p>
            @endforelse
        </div>
    </div>

    {{-- Thread --}}
    <div class="pd-card pd-chat-main">
        <div class="pd-chat-head">
            {{-- A conversation belongs to the agent it was started with, so once
                 one exists the agent is a fact rather than a choice. Rendering
                 it as a disabled <select> said "broken"; naming it says where
                 the answers are coming from. Same reasoning as the class-managed
                 fields on the agent detail page. --}}
            @if ($conversation)
                <div class="pd-locked" style="max-width: 220px">
                    <span class="pd-locked-mark" aria-hidden="true">◆</span>{{ $conversation->agent?->name ?? 'Unknown agent' }}
                </div>
            @else
                <label class="pd-visually-hidden" for="pd-agent-select">Agent</label>
                <select id="pd-agent-select" class="pd-select" style="max-width: 220px" wire:model.live="agentSlug">
                    @forelse ($agents as $agent)
                        <option value="{{ $agent->slug }}">{{ $agent->name }}</option>
                    @empty
                        <option value="">No agents available</option>
                    @endforelse
                </select>
            @endif

            @if ($run)
                <x-pandora::status :state="$run->state" />

                @if (! $run->state->isTerminal())
                    <span class="pd-streaming" role="status">
                        <span class="pd-streaming-dot" aria-hidden="true"></span>
                        Working
                    </span>

                    <x-pandora::button variant="danger" size="sm" wire:click="cancelRun('{{ $run->getKey() }}')">
                        Stop
                    </x-pandora::button>
                @endif

                <x-pandora::button :href="route('pandora.runs.show', $run->getKey())"
                                   variant="ghost" size="sm" class="pd-row-end">
                    Inspect run
                </x-pandora::button>
            @endif
        </div>

        <div class="pd-chat-thread" id="pd-thread">
            @forelse ($messages as $message)
                @php
                    $isUser = $message->role === \Pandora\Messages\Enums\MessageRole::User;
                    $isError = $message->type === \Pandora\Messages\Enums\MessageType::Error;
                    $isTool = $message->role === \Pandora\Messages\Enums\MessageRole::Tool;
                @endphp

                {{--
                    A tool result is not conversation, so it does not look like
                    one. It is shown because a person watching an agent work
                    should be able to see what it touched -- and its content is
                    UNTRUSTED, so it is escaped like everything else.
                --}}
                @if ($isTool)
                    @php($execution = $toolExecutions[$message->tool_call_id] ?? null)

                    <div class="pd-tool-card" wire:key="msg-{{ $message->getKey() }}">
                        <div class="pd-tool-card-head">
                            <span class="pd-faint" aria-hidden="true">◧</span>
                            <span class="pd-tool-card-title pd-mono">
                                {{ $execution?->tool_name ?? ($message->metadata['tool'] ?? 'tool') }}
                            </span>
                            @if ($execution !== null)
                                <x-pandora::badge :tone="$execution->status->tone()">
                                    {{ $execution->status->label() }}
                                </x-pandora::badge>
                                @if ($execution->arguments_modified)
                                    <x-pandora::badge tone="warning">Arguments changed</x-pandora::badge>
                                @endif
                            @endif
                        </div>

                        <div class="pd-msg-content">{{ $message->content }}</div>

                        @if ($canViewToolIo && $execution?->sanitized_arguments)
                            <details class="pd-details">
                                <summary>Arguments</summary>
                                <div class="pd-payload">{{ json_encode($execution->sanitized_arguments, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</div>
                            </details>
                        @endif
                    </div>

                    @continue
                @endif

                {{-- An assistant message is created empty before the model is
                     called, so a reload mid-request finds something to render.
                     Rendering it while it is still empty produces a blank
                     bubble that says nothing -- and when the run parks for an
                     approval it never fills, so the blank sits there for as
                     long as the approval is pending. The run status and the
                     tool and approval cards already say what is happening. --}}
                @if (! $isUser && ! $isError && trim((string) $message->content) === '')
                    @continue
                @endif

                <div class="pd-msg {{ $isUser ? 'pd-msg-user' : '' }} {{ $isError ? 'pd-msg-error' : '' }}"
                     wire:key="msg-{{ $message->getKey() }}">
                    <div class="pd-msg-avatar" aria-hidden="true">
                        @if ($isUser)
                            You
                        @else
                            <x-pandora::brand variant="icon" class="pd-msg-mark" />
                        @endif
                    </div>

                    <div class="pd-msg-body">
                        {{-- Model output is untrusted content: escaped, never injected as HTML. --}}
                        <div class="pd-msg-content">{{ $message->content }}@if ($message->isStreaming())<span class="pd-cursor"></span>@endif</div>
                    </div>
                </div>
            @empty
                <x-pandora::empty-state title="Start a conversation">
                    Ask the agent something. The run is queued, executed by a worker, and streamed back here.
                </x-pandora::empty-state>
            @endforelse

            {{--
                Anything this conversation is waiting on a human for. Shown at
                the foot of the thread rather than inline, because it is the
                thing to act on and it should not scroll away among results.
            --}}
            @if ($approvalError !== null)
                <div class="pd-notice pd-notice-warning" role="status">{{ $approvalError }}</div>
            @endif

            @foreach ($pendingApprovals as $approval)
                <div class="pd-tool-card pd-tool-card-awaiting" wire:key="approval-{{ $approval->getKey() }}">
                    <div class="pd-tool-card-head">
                        <span class="pd-faint" aria-hidden="true">◉</span>
                        <span class="pd-tool-card-title">{{ $approval->summary }}</span>
                        <x-pandora::badge :tone="$approval->risk_level->tone()">
                            {{ $approval->risk_level->label() }} risk
                        </x-pandora::badge>
                    </div>

                    @if (! empty($approval->proposed_modifications))
                        <table class="pd-table pd-table-tight">
                            <thead>
                                <tr><th>Field</th><th>Requested</th><th>Will run as</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($approval->proposed_modifications as $change)
                                    <tr>
                                        <td class="pd-mono">{{ $change['field'] }}</td>
                                        <td class="pd-mono pd-diff-from">{{ json_encode($change['from']) }}</td>
                                        <td class="pd-mono pd-diff-to">{{ json_encode($change['to']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif

                    @if ($canResolveApprovals)
                        <div class="pd-row">
                            <button type="button" class="pd-btn pd-btn-primary pd-btn-sm"
                                    wire:click="approve('{{ $approval->getKey() }}')">
                                Approve
                            </button>
                            <button type="button" class="pd-btn pd-btn-danger pd-btn-sm"
                                    wire:click="denyApproval('{{ $approval->getKey() }}')">
                                Deny
                            </button>
                        </div>
                    @else
                        <p class="pd-faint">
                            Waiting for someone who can approve this. Nothing runs until they decide.
                        </p>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="pd-chat-foot">
            @error('composer')
                <p class="pd-notice pd-notice-warning" style="margin-bottom: var(--pd-space-3)">{{ $message }}</p>
            @enderror

            <form class="pd-composer" wire:submit="send">
                <label class="pd-visually-hidden" for="pd-composer">Message</label>
                <textarea id="pd-composer" class="pd-textarea"
                          rows="1"
                          placeholder="Send a message…"
                          wire:model="composer"
                          @keydown.enter.prevent="$wire.send()"></textarea>

                <x-pandora::button type="submit" variant="primary" :disabled="$agents->isEmpty()">
                    Send
                </x-pandora::button>
            </form>

            <p class="pd-composer-hint">
                @if ($agents->isEmpty())
                    No agents are registered. Register an <code>AgentDefinition</code> in
                    <code>config/pandora.php</code> to get started.
                @elseif (! $realtimeEnabled)
                    Realtime is disabled — updates arrive by polling every {{ $pollIntervalMs / 1000 }}s.
                @else
                    Enter to send. Runs execute on a queue worker and stream back live.
                @endif
            </p>
        </div>
    </div>
</div>

@push('scripts')
<script>
    // Keep the thread pinned to the newest message. Purely presentational —
    // nothing here is a source of truth.
    (function () {
        const thread = document.getElementById('pd-thread');
        if (!thread) return;

        const pin = () => { thread.scrollTop = thread.scrollHeight; };
        pin();
        new MutationObserver(pin).observe(thread, { childList: true, subtree: true, characterData: true });
    })();
</script>
@endpush

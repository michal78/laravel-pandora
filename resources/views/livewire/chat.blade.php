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
            <label class="pd-visually-hidden" for="pd-agent-select">Agent</label>
            <select id="pd-agent-select" class="pd-select" style="max-width: 220px" wire:model.live="agentSlug"
                    @if ($conversation) disabled @endif>
                @forelse ($agents as $agent)
                    <option value="{{ $agent->slug }}">{{ $agent->name }}</option>
                @empty
                    <option value="">No agents available</option>
                @endforelse
            </select>

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
                    $isUser = $message->role === \Pandora\Pandora\Messages\Enums\MessageRole::User;
                    $isError = $message->type === \Pandora\Pandora\Messages\Enums\MessageType::Error;
                @endphp

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

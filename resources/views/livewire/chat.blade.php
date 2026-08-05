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
            <button type="button" class="pd-btn pd-btn-primary pd-btn-sm" style="width:100%"
                    wire:click="startNewConversation">
                New conversation
            </button>
        </div>

        <div class="pd-chat-list">
            @forelse ($conversationList as $item)
                <button type="button"
                        class="pd-chat-item {{ $conversation?->getKey() === $item->getKey() ? 'is-active' : '' }}"
                        wire:key="conv-{{ $item->getKey() }}"
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
            <select class="pd-select" style="max-width: 220px" wire:model.live="agentSlug"
                    @if ($conversation) disabled @endif>
                @forelse ($agents as $agent)
                    <option value="{{ $agent->slug }}">{{ $agent->name }}</option>
                @empty
                    <option value="">No agents available</option>
                @endforelse
            </select>

            @if ($run)
                <span class="pd-badge pd-badge-{{ $run->state->tone() }} {{ $run->state->isTerminal() ? '' : 'is-live' }}">
                    {{ $run->state->label() }}
                </span>

                @if (! $run->state->isTerminal())
                    <button type="button" class="pd-btn pd-btn-sm pd-btn-danger"
                            wire:click="cancelRun('{{ $run->getKey() }}')">
                        Stop
                    </button>
                @endif

                <a href="{{ route('pandora.runs.show', $run->getKey()) }}"
                   class="pd-btn pd-btn-sm pd-btn-ghost pd-row-end">
                    Inspect run
                </a>
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
                        {{ $isUser ? 'You' : ($conversation?->agent?->name ? mb_substr($conversation->agent->name, 0, 1) : 'A') }}
                    </div>

                    <div class="pd-msg-body">
                        {{-- Model output is untrusted content: escaped, never injected as HTML. --}}
                        <div class="pd-msg-content">{{ $message->content }}@if ($message->isStreaming())<span class="pd-cursor"></span>@endif</div>
                    </div>
                </div>
            @empty
                <div class="pd-empty">
                    <div>
                        <p class="pd-empty-title">Start a conversation</p>
                        <p>Ask the agent something. The run is queued, executed by a worker, and streamed back here.</p>
                    </div>
                </div>
            @endforelse
        </div>

        <div class="pd-chat-foot">
            @error('composer')
                <p class="pd-notice pd-notice-warning" style="margin-bottom: var(--pd-space-3)">{{ $message }}</p>
            @enderror

            <form class="pd-composer" wire:submit="send">
                <textarea class="pd-textarea"
                          rows="1"
                          placeholder="Send a message…"
                          wire:model="composer"
                          @keydown.enter.prevent="$wire.send()"></textarea>

                <button type="submit" class="pd-btn pd-btn-primary"
                        @if ($agents->isEmpty()) disabled @endif>
                    Send
                </button>
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

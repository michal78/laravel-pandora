<div class="pd-stack">
    <div class="pd-grid pd-grid-stats">
        <div class="pd-card pd-stat">
            <div class="pd-stat-label">Agents</div>
            <div class="pd-stat-value">{{ $enabledAgentCount }}</div>
            <div class="pd-stat-meta">{{ $agentCount }} registered</div>
        </div>

        <div class="pd-card pd-stat">
            <div class="pd-stat-label">Active runs</div>
            <div class="pd-stat-value">{{ $activeRuns }}</div>
            <div class="pd-stat-meta">in flight or waiting</div>
        </div>

        <div class="pd-card pd-stat">
            <div class="pd-stat-label">Completed</div>
            <div class="pd-stat-value">{{ $completedRuns }}</div>
            <div class="pd-stat-meta">all time</div>
        </div>

        <div class="pd-card pd-stat">
            <div class="pd-stat-label">Failed</div>
            <div class="pd-stat-value">{{ $failedRuns }}</div>
            <div class="pd-stat-meta">failed or timed out</div>
        </div>

        <div class="pd-card pd-stat">
            <div class="pd-stat-label">Conversations</div>
            <div class="pd-stat-value">{{ $conversationCount }}</div>
            <div class="pd-stat-meta">active</div>
        </div>
    </div>

    @if ($enabledAgentCount === 0)
        <div class="pd-notice pd-notice-warning">
            <strong>No agents registered.</strong>
            Pandora deliberately creates none on install. Add an <code>AgentDefinition</code> class to
            <code>pandora.agents.definitions</code> to get started.
        </div>
    @endif

    <div class="pd-grid pd-grid-split">
        <x-pandora::card title="Recent runs" :padded="false">
            <x-slot:actions>
                <x-pandora::button :href="route('pandora.runs')" variant="ghost" size="sm">
                    View all
                </x-pandora::button>
            </x-slot:actions>

            <div class="pd-table-wrap">
                <table class="pd-table">
                    <thead>
                        <tr><th>Run</th><th>State</th><th>Started</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($recentRuns as $run)
                            <tr>
                                <td>
                                    <a href="{{ route('pandora.runs.show', $run->getKey()) }}" class="pd-mono pd-link">
                                        {{ \Illuminate\Support\Str::limit($run->getKey(), 10, '…') }}
                                    </a>
                                </td>
                                <td><x-pandora::status :state="$run->state" /></td>
                                <td class="pd-faint">{{ $run->created_at?->diffForHumans() }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="pd-faint">No runs yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-pandora::card>

        <x-pandora::card title="System">
            <div class="pd-stack">
                <div class="pd-row">
                    <span class="pd-muted">Version</span>
                    <span class="pd-row-end pd-mono">{{ $version }}</span>
                </div>
                <div class="pd-row">
                    <span class="pd-muted">Default provider</span>
                    <span class="pd-row-end pd-mono">{{ $defaultProvider }}</span>
                </div>
                <div class="pd-row">
                    <span class="pd-muted">Default model</span>
                    <span class="pd-row-end pd-mono">{{ $defaultModel }}</span>
                </div>
                <div class="pd-row">
                    <span class="pd-muted">Realtime</span>
                    <span class="pd-row-end">
                        <x-pandora::badge :tone="$realtimeEnabled ? 'success' : 'muted'">
                            {{ $realtimeEnabled ? 'Enabled' : 'Polling' }}
                        </x-pandora::badge>
                    </span>
                </div>
            </div>
        </x-pandora::card>
    </div>
</div>

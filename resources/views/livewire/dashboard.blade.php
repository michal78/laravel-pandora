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
        <div class="pd-card">
            <div class="pd-card-head">
                <h2 class="pd-card-title">Recent runs</h2>
                <a href="{{ route('pandora.runs') }}" class="pd-btn pd-btn-sm pd-btn-ghost">View all</a>
            </div>

            <div class="pd-table-wrap">
                <table class="pd-table">
                    <thead>
                        <tr><th>Run</th><th>State</th><th>Started</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($recentRuns as $run)
                            <tr>
                                <td>
                                    <a href="{{ route('pandora.runs.show', $run->getKey()) }}" class="pd-mono">
                                        {{ \Illuminate\Support\Str::limit($run->getKey(), 10, '…') }}
                                    </a>
                                </td>
                                <td>
                                    <span class="pd-badge pd-badge-{{ $run->state->tone() }}">
                                        {{ $run->state->label() }}
                                    </span>
                                </td>
                                <td class="pd-faint">{{ $run->created_at?->diffForHumans() }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="pd-faint">No runs yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="pd-card">
            <div class="pd-card-head">
                <h2 class="pd-card-title">System</h2>
            </div>
            <div class="pd-card-body pd-stack">
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
                        <span class="pd-badge pd-badge-{{ $realtimeEnabled ? 'success' : 'muted' }}">
                            {{ $realtimeEnabled ? 'Enabled' : 'Polling' }}
                        </span>
                    </span>
                </div>
            </div>
        </div>
    </div>
</div>

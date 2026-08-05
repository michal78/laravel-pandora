<div class="pd-stack">
    @if ($error !== null)
        <div class="pd-notice pd-notice-warning" role="status">{{ $error }}</div>
    @endif

    <x-pandora::card title="Approvals" :padded="false">
        <x-slot:actions>
            <label class="pd-visually-hidden" for="pd-status-filter">Filter by status</label>
            <select id="pd-status-filter" class="pd-select" style="max-width: 200px" wire:model.live="statusFilter">
                <option value="">All</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}">{{ $status->label() }}</option>
                @endforeach
            </select>
        </x-slot:actions>

        <div class="pd-table-wrap">
            <table class="pd-table">
                <thead>
                    <tr>
                        <th>Request</th><th>Tool</th><th>Risk</th><th>Status</th>
                        <th>Expires</th><th><span class="pd-visually-hidden">Decide</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($approvals as $approval)
                        <tr wire:key="approval-{{ $approval->getKey() }}">
                            <td>
                                <div>{{ $approval->summary }}</div>
                                <a href="{{ route('pandora.runs.show', $approval->run_id) }}"
                                   class="pd-mono pd-link pd-faint">
                                    {{ \Illuminate\Support\Str::limit($approval->run_id, 12, '…') }}
                                </a>
                            </td>
                            <td class="pd-mono pd-muted">{{ $approval->tool_name }}</td>
                            <td>
                                <x-pandora::badge :tone="$approval->risk_level->tone()">
                                    {{ $approval->risk_level->label() }}
                                </x-pandora::badge>
                            </td>
                            <td>
                                <x-pandora::badge :tone="$approval->status->tone()">
                                    {{ $approval->status->label() }}
                                </x-pandora::badge>
                                @if ($approval->kind->value === 'confirmation')
                                    <span class="pd-faint">confirmation</span>
                                @endif
                            </td>
                            <td class="pd-faint">
                                {{ $approval->isPending() ? $approval->expires_at->diffForHumans() : '—' }}
                            </td>
                            <td>
                                @if ($approval->isPending() && $canResolve)
                                    <button type="button" class="pd-btn pd-btn-sm"
                                            wire:click="select('{{ $approval->getKey() }}')">
                                        Review
                                    </button>
                                @elseif ($approval->isPending())
                                    <span class="pd-faint">Not yours to decide</span>
                                @else
                                    <span class="pd-faint">
                                        {{ $approval->resolved_at?->diffForHumans() ?? '—' }}
                                    </span>
                                @endif
                            </td>
                        </tr>

                        @if ($resolving === (string) $approval->getKey())
                            <tr wire:key="review-{{ $approval->getKey() }}">
                                <td colspan="6">
                                    <div class="pd-stack">
                                        @if ($canViewIo && $approval->sanitized_arguments !== null)
                                            <div>
                                                <h4 class="pd-h4">Arguments</h4>
                                                <pre class="pd-code">{{ json_encode($approval->sanitized_arguments, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                            </div>
                                        @endif

                                        @if (! empty($approval->proposed_modifications))
                                            <div>
                                                <h4 class="pd-h4">A policy changed these arguments</h4>
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
                                            </div>
                                        @endif

                                        @if (! empty($approval->metadata['reason']))
                                            <p class="pd-muted">{{ $approval->metadata['reason'] }}</p>
                                        @endif

                                        <label class="pd-label" for="pd-comment-{{ $approval->getKey() }}">
                                            Comment (recorded in the audit log)
                                        </label>
                                        <textarea id="pd-comment-{{ $approval->getKey() }}"
                                                  class="pd-input" rows="2"
                                                  wire:model="comment"></textarea>

                                        <div class="pd-row">
                                            <button type="button" class="pd-btn pd-btn-primary pd-btn-sm"
                                                    wire:click="approve('{{ $approval->getKey() }}', 'once')">
                                                Approve once
                                            </button>
                                            <button type="button" class="pd-btn pd-btn-sm"
                                                    wire:click="approve('{{ $approval->getKey() }}', 'run')">
                                                Approve for this run
                                            </button>
                                            @if ($allowRemembered)
                                                <button type="button" class="pd-btn pd-btn-sm"
                                                        wire:click="approve('{{ $approval->getKey() }}', 'remembered')">
                                                    Approve and remember
                                                </button>
                                            @endif
                                            <button type="button" class="pd-btn pd-btn-danger pd-btn-sm"
                                                    wire:click="deny('{{ $approval->getKey() }}')">
                                                Deny
                                            </button>
                                            <button type="button" class="pd-btn pd-btn-sm"
                                                    wire:click="select(null)">
                                                Cancel
                                            </button>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="6">
                                <x-pandora::empty-state title="Nothing is waiting on a decision"
                                                        :mark="$statusFilter === 'pending'">
                                    A run pauses here when it wants to use a high-risk tool. It holds no
                                    worker while it waits, so there is no hurry.
                                </x-pandora::empty-state>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-pandora::card>

    {{ $approvals->links() }}
</div>

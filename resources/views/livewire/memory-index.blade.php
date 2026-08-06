{{--
    What the agents believe, and what they would like to.

    The review queue is first and is not behind a filter. A suggestion nobody
    looks at is a memory that never becomes useful, and a queue that is hard to
    find is a queue that gets approved in bulk without reading.

    Content is shown in full. Truncating the one column that says what an agent
    will repeat about somebody would make the reviewer approve a preview.
--}}
<div class="pd-stack">
    @if ($error !== null)
        <div class="pd-notice pd-notice-danger">{{ $error }}</div>
    @endif

    @if ($notice !== null)
        <div class="pd-notice pd-notice-success">{{ $notice }}</div>
    @endif

    <div class="pd-grid pd-grid-stats">
        <div class="pd-card pd-stat">
            <div class="pd-stat-label">Active</div>
            <div class="pd-stat-value">{{ $counts['active'] }}</div>
            <div class="pd-stat-meta">retrievable now</div>
        </div>

        <div class="pd-card pd-stat">
            <div class="pd-stat-label">Awaiting review</div>
            <div class="pd-stat-value">{{ $counts['suggested'] }}</div>
            <div class="pd-stat-meta">no agent can read these</div>
        </div>

        <div class="pd-card pd-stat">
            <div class="pd-stat-label">Sensitive</div>
            <div class="pd-stat-value">{{ $counts['sensitive'] }}</div>
            <div class="pd-stat-meta">approved, and still sensitive</div>
        </div>

        <div class="pd-card pd-stat">
            <div class="pd-stat-label">Expired</div>
            <div class="pd-stat-value">{{ $counts['expired'] }}</div>
            <div class="pd-stat-meta">swept, kept for audit</div>
        </div>
    </div>

    @if ($awaitingReview->isNotEmpty())
        <x-pandora::card title="Awaiting review" :padded="false">
            <x-slot:actions>
                <span class="pd-muted">Not retrievable by any agent until approved</span>
            </x-slot:actions>

            <table class="pd-table">
                <thead>
                    <tr>
                        <th scope="col">Memory</th>
                        <th scope="col">Scope</th>
                        <th scope="col">Source</th>
                        <th scope="col">Proposed</th>
                        @if ($canManage)
                            <th scope="col"><span class="pd-visually-hidden">Actions</span></th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach ($awaitingReview as $item)
                        <tr>
                            <td>
                                @if ($item->title)
                                    <div class="pd-strong">{{ $item->title }}</div>
                                @endif
                                <div>{{ $item->content }}</div>
                                @if ($item->sensitivity->requiresApproval())
                                    <x-pandora::badge tone="warning">{{ $item->sensitivity->label() }}</x-pandora::badge>
                                @endif
                            </td>
                            <td>
                                {{ $item->scope->label() }}
                                @if ($item->scope_id)
                                    <div class="pd-muted pd-mono">{{ $item->scope_id }}</div>
                                @endif
                            </td>
                            <td>{{ $item->source->label() }}</td>
                            <td>{{ $item->created_at?->diffForHumans() }}</td>
                            @if ($canManage)
                                <td class="pd-actions">
                                    <button type="button" class="pd-btn pd-btn-primary"
                                            wire:click="approve('{{ $item->getKey() }}')">Approve</button>
                                    <button type="button" class="pd-btn"
                                            wire:click="reject('{{ $item->getKey() }}')">Reject</button>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-pandora::card>
    @endif

    <x-pandora::card title="Memory" :padded="false">
        <x-slot:actions>
            <label class="pd-visually-hidden" for="pd-memory-search">Search memory</label>
            <input id="pd-memory-search" type="search" class="pd-input" style="max-width: 220px"
                   placeholder="Search" wire:model.live.debounce.300ms="search">

            <label class="pd-visually-hidden" for="pd-memory-scope">Filter by scope</label>
            <select id="pd-memory-scope" class="pd-select" style="max-width: 160px" wire:model.live="scopeFilter">
                <option value="">All scopes</option>
                @foreach ($scopes as $scope)
                    <option value="{{ $scope->value }}">{{ $scope->label() }}</option>
                @endforeach
            </select>

            <label class="pd-visually-hidden" for="pd-memory-status">Filter by status</label>
            <select id="pd-memory-status" class="pd-select" style="max-width: 160px" wire:model.live="statusFilter">
                <option value="">Active</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}">{{ $status->label() }}</option>
                @endforeach
            </select>
        </x-slot:actions>

        @if ($items->isEmpty())
            <div class="pd-card-body pd-muted">
                Nothing here. Agents write memory through the <span class="pd-mono">remember</span> tool,
                and anything about a person waits for review before it is used.
            </div>
        @else
            <table class="pd-table">
                <thead>
                    <tr>
                        <th scope="col">Memory</th>
                        <th scope="col">Scope</th>
                        <th scope="col">Type</th>
                        <th scope="col">Used</th>
                        <th scope="col">Expires</th>
                        @if ($canManage)
                            <th scope="col"><span class="pd-visually-hidden">Actions</span></th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach ($items as $item)
                        <tr>
                            <td>
                                @if ($item->title)
                                    <div class="pd-strong">{{ $item->title }}</div>
                                @endif
                                <div>{{ $item->content }}</div>
                                <div class="pd-muted">
                                    {{ $item->source->label() }}
                                    @if ($item->confidence < 100)
                                        · {{ $item->confidence }}% confidence
                                    @endif
                                </div>
                            </td>
                            <td>
                                {{ $item->scope->label() }}
                                @if ($item->scope_id)
                                    <div class="pd-muted pd-mono">{{ $item->scope_id }}</div>
                                @endif
                            </td>
                            <td>{{ $item->type->label() }}</td>
                            <td>
                                {{-- How often an agent actually used this, which is the
                                     only honest signal about whether it was worth keeping. --}}
                                {{ $item->retrieval_count }}×
                                @if ($item->last_retrieved_at)
                                    <div class="pd-muted">{{ $item->last_retrieved_at->diffForHumans() }}</div>
                                @endif
                            </td>
                            <td>
                                {{ $item->expires_at?->diffForHumans() ?? 'Never' }}
                            </td>
                            @if ($canManage)
                                <td class="pd-actions">
                                    <button type="button" class="pd-btn pd-btn-danger"
                                            wire:click="forget('{{ $item->getKey() }}')"
                                            wire:confirm="Forget this memory and delete its vector?">Forget</button>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-pandora::card>
</div>

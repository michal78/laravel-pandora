<div class="pd-stack">
    @if ($staleModels->isNotEmpty())
        <x-pandora::card title="Pricing needs review">
            <p class="pd-muted">
                {{ $staleModels->count() }} model(s) were last priced more than
                {{ $staleAfterDays }} days ago. Costs are still estimated from
                these prices and are marked stale on every record they produce.
            </p>
            <p class="pd-mono pd-faint">
                {{ $staleModels->map(fn ($model) => $model->reference())->implode(', ') }}
            </p>
        </x-pandora::card>
    @endif

    @forelse ($connections as $connection)
        @php($status = $connection['health']->status)

        {{--
            One provider, shut until it is asked for — or until it has
            something to say. `<details>` rather than a Livewire toggle: no
            round-trip, no state to keep, and the browser gives keyboard
            operation and find-in-page for free.

            The summary carries the whole scanning answer. Collapsing a page
            that hides whether a credential is installed has moved the problem
            rather than solved it.
        --}}
        <details class="pd-card pd-provider" wire:key="provider-{{ $connection['key'] }}"
                 @if ($connection['attention'] !== []) open @endif>
            <summary class="pd-card-head pd-provider-summary">
                <span class="pd-provider-name">
                    <span class="pd-provider-marker" aria-hidden="true"></span>
                    <span class="pd-card-title">{{ $connection['key'] }}</span>
                    @if ($connection['is_default'])
                        <x-pandora::badge tone="info">Default</x-pandora::badge>
                    @endif
                </span>

                <span class="pd-provider-facts">
                    @if ($connection['attention'] !== [])
                        <span class="pd-provider-attention">{{ implode(' · ', $connection['attention']) }}</span>
                    @endif

                    <span class="pd-faint">{{ $connection['models']->count() }} {{ $connection['models']->count() === 1 ? 'model' : 'models' }}</span>

                    <x-pandora::badge :tone="$connection['has_credential'] ? 'success' : 'warning'">
                        {{ $connection['has_credential'] ? 'Key installed' : 'No key' }}
                    </x-pandora::badge>

                    <x-pandora::badge :tone="$status === 'healthy' ? 'success' : ($status === 'unknown' ? 'neutral' : 'danger')">
                        {{ ucfirst($status) }}
                    </x-pandora::badge>
                </span>
            </summary>

            <div class="pd-table-wrap">
                <table class="pd-table pd-table-kv">
                    <tbody>
                        <tr>
                            <th scope="row">Adapter</th>
                            <td class="pd-mono">{{ $connection['adapter'] }}</td>
                        </tr>
                        <tr>
                            <th scope="row">Endpoint</th>
                            <td class="pd-mono pd-faint">{{ $connection['base_url'] ?? '—' }}</td>
                        </tr>
                        <tr>
                            <th scope="row">Credential</th>
                            <td>
                                {{-- Presence, never value. --}}
                                @if ($connection['has_credential'])
                                    <x-pandora::badge tone="success">Configured</x-pandora::badge>
                                @else
                                    <x-pandora::badge tone="warning">None</x-pandora::badge>
                                @endif

                                @foreach ($canManage ? $connection['credentials'] : [] as $credential)
                                    <div class="pd-faint pd-mono">
                                        {{ $credential->source()->label() }}
                                        &middot; v{{ $credential->version }}
                                        &middot; {{ $credential->fingerprint }}
                                        @if (! $credential->isUsable())
                                            <x-pandora::badge tone="neutral">Retired</x-pandora::badge>
                                        @endif
                                    </div>
                                @endforeach
                            </td>
                        </tr>
                        @if ($connection['health']->latencyMs !== null)
                            <tr>
                                <th scope="row">Latency</th>
                                <td class="pd-mono">{{ $connection['health']->latencyMs }} ms</td>
                            </tr>
                        @endif
                        @if ($connection['health']->message !== null)
                            <tr>
                                <th scope="row">Last error</th>
                                <td class="pd-muted">{{ $connection['health']->message }}</td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>

            @if ($connection['models']->isNotEmpty())
                <div class="pd-table-wrap">
                    <table class="pd-table">
                        <thead>
                            <tr>
                                <th>Model</th><th>Context</th><th>Tools</th><th>Vision</th><th>Price</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($connection['models'] as $model)
                                <tr wire:key="model-{{ $model->id }}">
                                    <td class="pd-mono">
                                        {{ $model->model_key }}
                                        @unless ($model->isUsable())
                                            <x-pandora::badge tone="warning">Unavailable</x-pandora::badge>
                                        @endunless
                                    </td>
                                    <td class="pd-mono pd-faint">
                                        {{ $model->context_limit === null ? '—' : number_format($model->context_limit) }}
                                    </td>
                                    <td class="pd-muted">{{ $model->supports_tools ? 'yes' : '—' }}</td>
                                    <td class="pd-muted">{{ $model->supports_vision ? 'yes' : '—' }}</td>
                                    <td class="pd-muted">
                                        @if ($canManage)
                                            {{ $this->priceOf($model) }}
                                            @if ($model->pricingIsStale($staleAfterDays))
                                                <x-pandora::badge tone="warning">Stale</x-pandora::badge>
                                            @endif
                                        @else
                                            <span class="pd-faint">&mdash;</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </details>
    @empty
        <x-pandora::empty-state
            title="No providers configured"
            description="Add a connection under `providers.connections` in config/pandora.php." />
    @endforelse
</div>

<div class="pd-stack">
    <x-pandora::card title="Tools" :padded="false">
        <x-slot:actions>
            <label class="pd-visually-hidden" for="pd-group-filter">Filter by group</label>
            <select id="pd-group-filter" class="pd-select" style="max-width: 200px" wire:model.live="groupFilter">
                <option value="">All groups</option>
                @foreach ($groups as $group)
                    <option value="{{ $group }}">{{ \Illuminate\Support\Str::headline($group) }}</option>
                @endforeach
            </select>
        </x-slot:actions>

        <div class="pd-table-wrap">
            <table class="pd-table">
                <thead>
                    <tr>
                        <th>Tool</th><th>Group</th><th>Risk</th><th>Approval</th>
                        <th>Version</th><th>Description</th>
                        @if ($canViewSchemas)
                            <th><span class="pd-visually-hidden">Schema</span></th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($tools as $tool)
                        @php($reference = $tool->name() . '@' . $tool->version())
                        <tr wire:key="tool-{{ $reference }}">
                            <td>
                                <span class="pd-mono">{{ $tool->name() }}</span>
                                @if ($tool->deprecated() !== null)
                                    <x-pandora::badge tone="warning">Deprecated</x-pandora::badge>
                                @endif
                            </td>
                            <td class="pd-muted">{{ $tool->group() }}</td>
                            <td><x-pandora::badge :tone="$tool->risk()->tone()">{{ $tool->risk()->label() }}</x-pandora::badge></td>
                            <td class="pd-muted">
                                {{ $tool->risk()->requiresApprovalByDefault() ? 'Required' : '—' }}
                            </td>
                            <td class="pd-mono pd-faint">{{ $tool->version() }}</td>
                            <td class="pd-muted">
                                {{ \Illuminate\Support\Str::limit($tool->description(), 90) }}
                                @if ($tool->deprecated() !== null)
                                    <div class="pd-faint">{{ $tool->deprecated() }}</div>
                                @endif
                            </td>
                            @if ($canViewSchemas)
                                <td>
                                    <button type="button" class="pd-btn pd-btn-sm"
                                            wire:click="toggle('{{ $reference }}')"
                                            aria-expanded="{{ $expanded === $reference ? 'true' : 'false' }}">
                                        {{ $expanded === $reference ? 'Hide schema' : 'Schema' }}
                                    </button>
                                </td>
                            @endif
                        </tr>

                        @if ($canViewSchemas && $expanded === $reference)
                            <tr wire:key="schema-{{ $reference }}">
                                <td colspan="7">
                                    <pre class="pd-code">{{ $schemas[$reference] ?? '{}' }}</pre>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="7">
                                <x-pandora::empty-state title="No tools are installed" :mark="true">
                                    Register tool classes under <span class="pd-mono">tools.registered</span>
                                    in <span class="pd-mono">config/pandora.php</span>. Registering a tool
                                    installs it; each agent still has to be granted it.
                                </x-pandora::empty-state>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-pandora::card>

    @unless ($canViewSchemas)
        <p class="pd-faint">
            Argument schemas are hidden. They need the
            <span class="pd-mono">pandora.tools.io.view</span> ability.
        </p>
    @endunless
</div>

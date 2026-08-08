{{--
    Servers Pandora talks to, and what it has been allowed to call.

    Everything a server said is rendered as escaped text. Blade escapes by
    default and nothing here reaches for the raw form -- this is the one page
    whose content was written by a stranger, and a test asserts the absence of
    the unescaped syntax.
--}}
<div class="pd-stack">
    @if ($error !== null)
        <div class="pd-notice pd-notice-danger">{{ $error }}</div>
    @endif

    @if ($notice !== null)
        <div class="pd-notice pd-notice-success">{{ $notice }}</div>
    @endif

    @unless ($clientEnabled)
        <div class="pd-notice pd-notice-warning">
            The MCP client is disabled, so no remote tool is offered to any agent whatever is
            approved below. Set <span class="pd-mono">pandora.mcp.client.enabled</span> to turn it on.
        </div>
    @endunless

    <x-pandora::card title="MCP servers" :padded="false">
        @if ($servers->isEmpty())
            <div class="pd-card-body pd-muted">
                No MCP servers are registered. A server is operator configuration: its namespace,
                its endpoint and the name of a credential in your encrypted store. Nothing about
                one is chosen by an agent.
            </div>
        @else
            <table class="pd-table">
                <thead>
                    <tr>
                        <th scope="col">Server</th>
                        <th scope="col">Transport</th>
                        <th scope="col">Health</th>
                        <th scope="col">Tools</th>
                        <th scope="col"><span class="pd-visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($servers as $item)
                        <tr @class(['is-selected' => $server?->getKey() === $item->getKey()])>
                            <td>
                                <div class="pd-strong">{{ $item->name }}</div>
                                <div class="pd-muted pd-mono">{{ $item->namespace }}</div>
                            </td>
                            <td class="pd-mono">{{ $item->transport->label() }}</td>
                            <td>
                                <x-pandora::badge>{{ $item->health->label() }}</x-pandora::badge>
                                @unless ($item->enabled)
                                    <span class="pd-muted">disabled</span>
                                @endunless
                            </td>
                            <td>{{ $item->tools()->count() }}</td>
                            <td class="pd-actions">
                                <button type="button" class="pd-btn"
                                        wire:click="select('{{ $item->slug }}')">Inspect</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-pandora::card>

    @if ($server !== null)
        <x-pandora::card :title="$server->name" :padded="false">
            <x-slot:actions>
                @if ($canManage)
                    <button type="button" class="pd-btn" wire:click="discover"
                            title="Ask the server what it has now. Approves nothing.">Discover</button>
                @endif
            </x-slot:actions>

            <div class="pd-card-body">
                <p class="pd-help">
                    Discovery writes rows and approves nothing. Approval is per agent, per tool, and
                    it is approval of a specific description and schema -- if the server rewrites
                    either, the approval is cleared and the tool fails closed until a human looks at
                    what changed.
                </p>
            </div>

            @if ($tools->isEmpty())
                <div class="pd-card-body pd-muted">
                    Nothing discovered yet. Press <span class="pd-strong">Discover</span>.
                </div>
            @else
                <table class="pd-table">
                    <thead>
                        <tr>
                            <th scope="col">Tool</th>
                            <th scope="col">Description <span class="pd-faint">(written by the server)</span></th>
                            <th scope="col">Approved for</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($tools as $tool)
                            <tr>
                                <td>
                                    <div class="pd-mono">{{ $tool->namespaced_name }}</div>
                                    @unless ($tool->available)
                                        <div class="pd-muted">withdrawn by the server</div>
                                    @endunless
                                    @if ($tool->schema_changed_at !== null)
                                        <div class="pd-notice pd-notice-warning">
                                            Changed {{ $tool->schema_changed_at->diffForHumans() }}.
                                            Approvals were cleared.
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    {{-- Escaped, bounded, and never an instruction. --}}
                                    <span class="pd-muted">{{ $tool->boundedDescription() }}</span>
                                </td>
                                <td class="pd-actions">
                                    @php($live = $approvals[$tool->getKey()] ?? [])

                                    @if ($live === [])
                                        <span class="pd-muted">nobody</span>
                                    @else
                                        @foreach ($live as $approval)
                                            <span class="pd-mono">{{ $approval->agent_id }}</span>
                                            @if ($canManage)
                                                <button type="button" class="pd-btn pd-btn-danger"
                                                        wire:click="revoke('{{ $tool->getKey() }}', '{{ $approval->agent_id }}')"
                                                >Revoke</button>
                                            @endif
                                        @endforeach
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-pandora::card>
    @endif
</div>

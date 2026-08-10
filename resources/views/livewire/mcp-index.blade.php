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
                                        {{-- A tag here, the explanation next to the thing that
                                             changed. A full-width notice in this column pushed the
                                             row apart and still did not say what moved. --}}
                                        <span class="pd-badge pd-badge-warning">Changed</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($tool->schema_changed_at !== null)
                                        <div class="pd-notice pd-notice-warning">
                                            Changed {{ $tool->schema_changed_at->diffForHumans() }} by the
                                            server. Approvals were cleared and it stays unapproved until
                                            somebody approves <span class="pd-strong">this</span> version.
                                            @if ($tool->previous_description !== null)
                                                Its description changed:
                                            @else
                                                Its description is unchanged, so what moved is a parameter.
                                            @endif
                                        </div>
                                    @endif

                                    @if ($tool->previous_description !== null)
                                        {{-- Both halves escaped. This is third-party text and the
                                             old copy is no more trustworthy than the new one. --}}
                                        <div class="pd-mono pd-diff-from">{{ $tool->previous_description }}</div>
                                        <div class="pd-mono pd-diff-to">{{ $tool->boundedDescription() }}</div>
                                    @else
                                        {{-- Escaped, bounded, and never an instruction. --}}
                                        <span class="pd-muted">{{ $tool->boundedDescription() }}</span>
                                    @endif
                                </td>
                                <td class="pd-grants">
                                    @php($live = $approvals[$tool->getKey()] ?? [])
                                    @php($chosen = $approveFor[$tool->getKey()] ?? '')

                                    @if ($live === [])
                                        <div class="pd-muted">nobody</div>
                                    @else
                                        {{-- One grant per line. A run-on list of names and
                                             buttons reads as a row of controls; this reads as
                                             what it is, a list of who may call somebody else's
                                             tool. --}}
                                        <ul class="pd-grant-list">
                                            @foreach ($live as $approval)
                                                <li class="pd-grant">
                                                    <span class="pd-mono">{{ $agentNames[$approval->agent_id] ?? $approval->agent_id }}</span>
                                                    @if ($canManage)
                                                        <button type="button" class="pd-btn pd-btn-sm pd-btn-danger pd-btn-ghost"
                                                                wire:click="revoke('{{ $tool->getKey() }}', '{{ $approval->agent_id }}')"
                                                        >Revoke</button>
                                                    @endif
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif

                                    @if ($canManage)
                                        {{-- The page showed the diff; this is where a decision
                                             about it is made. Approval names one agent, because
                                             "approve this tool" is not a thing that can be said,
                                             and the button waits until one is named rather than
                                             offering an action that cannot be completed. --}}
                                        <div class="pd-grant-add">
                                            <label class="pd-visually-hidden" for="approve-{{ $tool->getKey() }}">
                                                Approve {{ $tool->namespaced_name }} for an agent
                                            </label>
                                            <select id="approve-{{ $tool->getKey() }}" class="pd-select pd-select-sm"
                                                    wire:model.live="approveFor.{{ $tool->getKey() }}">
                                                <option value="">Approve for…</option>
                                                @foreach ($agents as $agent)
                                                    <option value="{{ $agent->id }}">{{ $agent->slug }}</option>
                                                @endforeach
                                            </select>
                                            @if ($chosen !== '')
                                                <button type="button" class="pd-btn pd-btn-sm pd-btn-success"
                                                        wire:click="approve('{{ $tool->getKey() }}')"
                                                >Approve</button>
                                            @endif
                                        </div>
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

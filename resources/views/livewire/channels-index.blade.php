{{--
    The channels this installation is connected to, and who may speak through
    them.

    Everything a remote system said -- a display name, a workspace id -- is
    rendered as escaped text. Blade escapes by default and nothing here reaches
    for the raw form: a display name is a string a stranger chose, arriving on
    an authenticated admin page.

    Note what is missing: there is no control that links an identity to a user.
    An operator can only unlink. Handing out access on the strength of an
    administrator's belief about who owns a Slack handle is exactly the
    shortcut this design refuses.
--}}
<div class="pd-stack">
    @if ($error !== null)
        <div class="pd-notice pd-notice-danger">{{ $error }}</div>
    @endif

    @if ($notice !== null)
        <div class="pd-notice pd-notice-success">{{ $notice }}</div>
    @endif

    @if ($adapters === [])
        <div class="pd-notice pd-notice-warning">
            No channel adapter is installed. Pandora ships the contract and no messaging adapter:
            adapters are extensions, installed with <span class="pd-mono">composer require</span>.
        </div>
    @endif

    <x-pandora::card title="Channel accounts" :padded="false">
        <x-slot:actions>
            @if ($canManage && $adapters !== [])
                <button type="button" class="pd-btn" wire:click="startCreating">Register a workspace</button>
            @endif
        </x-slot:actions>

        @if ($form === 'create')
            <div class="pd-card-body">
                <form wire:submit="create" class="pd-stack">
                    <div class="pd-grid pd-grid-split">
                        <div class="pd-field">
                            <label class="pd-label" for="pd-ch-channel">Channel</label>
                            <select id="pd-ch-channel" class="pd-select" wire:model="formChannel">
                                @foreach ($adapters as $key => $adapter)
                                    <option value="{{ $key }}">{{ $adapter->name() }}</option>
                                @endforeach
                            </select>
                            @error('formChannel') <p class="pd-error">{{ $message }}</p> @enderror
                        </div>

                        <div class="pd-field">
                            <label class="pd-label" for="pd-ch-name">Name</label>
                            <input id="pd-ch-name" type="text" class="pd-input" wire:model="formName" autofocus>
                            @error('formName') <p class="pd-error">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="pd-grid pd-grid-split">
                        <div class="pd-field">
                            <label class="pd-label" for="pd-ch-external">Workspace id</label>
                            <input id="pd-ch-external" type="text" class="pd-input" wire:model="formExternalId">
                            @error('formExternalId') <p class="pd-error">{{ $message }}</p> @enderror
                            <p class="pd-help">
                                What the remote system calls this workspace or team. Inbound messages
                                are matched on it, and it cannot be changed afterwards.
                            </p>
                        </div>

                        <div class="pd-field">
                            <label class="pd-label" for="pd-ch-agent">Agent</label>
                            <select id="pd-ch-agent" class="pd-select" wire:model="formAgent">
                                <option value="">— none yet —</option>
                                @foreach ($agents as $agent)
                                    <option value="{{ $agent->getKey() }}">{{ $agent->name }}</option>
                                @endforeach
                            </select>
                            @error('formAgent') <p class="pd-error">{{ $message }}</p> @enderror
                            <p class="pd-help">Nothing arrives until an agent is bound and the account is enabled.</p>
                        </div>
                    </div>

                    <div class="pd-field">
                        <label class="pd-label" for="pd-ch-credential">Credential key <span class="pd-faint">(optional)</span></label>
                        <input id="pd-ch-credential" type="text" class="pd-input" wire:model="formCredentialKey"
                               placeholder="channel.slack.acme">
                        @error('formCredentialKey') <p class="pd-error">{{ $message }}</p> @enderror
                        <p class="pd-help">
                            The NAME of an entry in the encrypted credential store. The secret itself
                            never lives on this row and is never shown here.
                        </p>
                    </div>

                    <div class="pd-row">
                        <button type="submit" class="pd-btn pd-btn-primary">Register</button>
                        <button type="button" class="pd-btn pd-btn-ghost" wire:click="cancelForm">Cancel</button>
                    </div>
                </form>
            </div>
        @endif

        @if ($accounts->isEmpty())
            <div class="pd-card-body pd-muted">
                No channel accounts. An account says which remote workspace this installation talks
                to, which tenant its traffic belongs to, and which agent answers — all of it written
                by an operator, none of it decided by a message.
            </div>
        @else
            <table class="pd-table">
                <thead>
                    <tr>
                        <th scope="col">Account</th>
                        <th scope="col">Channel</th>
                        <th scope="col">Agent</th>
                        <th scope="col">State</th>
                        <th scope="col"><span class="pd-visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($accounts as $item)
                        <tr @class(['is-selected' => $account?->getKey() === $item->getKey()])>
                            <td>
                                <div class="pd-strong">{{ $item->name }}</div>
                                <div class="pd-muted pd-mono">{{ $item->external_id }}</div>
                            </td>
                            <td class="pd-mono">
                                {{ $item->channel }}
                                @if ($this->adapterMissing($item))
                                    <div class="pd-muted">adapter not installed</div>
                                @endif
                            </td>
                            <td>{{ $item->agent?->name ?? '—' }}</td>
                            <td>
                                <x-pandora::badge>{{ $item->enabled ? 'enabled' : 'disabled' }}</x-pandora::badge>
                            </td>
                            <td class="pd-actions">
                                <button type="button" class="pd-btn" wire:click="select('{{ $item->slug }}')">Inspect</button>
                                @if ($canManage)
                                    <button type="button" class="pd-btn" wire:click="startEditing('{{ $item->slug }}')">Edit</button>
                                    <button type="button" class="pd-btn" wire:click="toggle('{{ $item->slug }}')">
                                        {{ $item->enabled ? 'Disable' : 'Enable' }}
                                    </button>
                                    <button type="button" class="pd-btn pd-btn-danger" wire:click="delete('{{ $item->slug }}')">Remove</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-pandora::card>

    @if ($account !== null && $form === $account->slug)
        <x-pandora::card title="Edit {{ $account->name }}">
            <form wire:submit="save" class="pd-stack">
                {{--
                    The channel and the workspace id are shown and are not fields.
                    Both are what inbound traffic is matched on, so editing them
                    would silently re-point every identity beneath this row at a
                    workspace those people are not in.
                --}}
                <div class="pd-field">
                    <label class="pd-label">Connection</label>
                    <div class="pd-mono">{{ $account->channel }} · {{ $account->external_id }}</div>
                    <p class="pd-help">Fixed at registration. Register a second account to reach a different workspace.</p>
                </div>

                <div class="pd-grid pd-grid-split">
                    <div class="pd-field">
                        <label class="pd-label" for="pd-ch-edit-name">Name</label>
                        <input id="pd-ch-edit-name" type="text" class="pd-input" wire:model="formName">
                        @error('formName') <p class="pd-error">{{ $message }}</p> @enderror
                    </div>

                    <div class="pd-field">
                        <label class="pd-label" for="pd-ch-edit-agent">Agent</label>
                        <select id="pd-ch-edit-agent" class="pd-select" wire:model="formAgent">
                            <option value="">— none —</option>
                            @foreach ($agents as $agent)
                                <option value="{{ $agent->getKey() }}">{{ $agent->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="pd-field">
                    <label class="pd-label" for="pd-ch-edit-credential">Credential key</label>
                    <input id="pd-ch-edit-credential" type="text" class="pd-input" wire:model="formCredentialKey">
                </div>

                <div class="pd-row">
                    <button type="submit" class="pd-btn pd-btn-primary">Save</button>
                    <button type="button" class="pd-btn pd-btn-ghost" wire:click="cancelForm">Cancel</button>
                </div>
            </form>
        </x-pandora::card>
    @endif

    @if ($account !== null)
        <x-pandora::card title="Identities on {{ $account->name }}" :padded="false">
            <div class="pd-card-body">
                <p class="pd-help">
                    A channel identity is somebody in a remote system. It is not a user and never
                    becomes one: the only path to an account here is a linking code the participant
                    asks for in the channel and redeems while signed in. Until then their messages
                    are refused, and the refusals are recorded below.
                </p>
            </div>

            @if ($identities->isEmpty())
                <div class="pd-card-body pd-muted">Nobody has messaged this account yet.</div>
            @else
                <table class="pd-table">
                    <thead>
                        <tr>
                            <th scope="col">Participant</th>
                            <th scope="col">Linked to</th>
                            <th scope="col">Last seen</th>
                            <th scope="col"><span class="pd-visually-hidden">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($identities as $identity)
                            <tr>
                                <td>
                                    {{-- Escaped. A stranger chose this string. --}}
                                    <div class="pd-strong">{{ $identity->display_name ?? '—' }}</div>
                                    <div class="pd-muted pd-mono">{{ $identity->external_id }}</div>
                                </td>
                                <td>
                                    @if ($identity->isLinked())
                                        <span class="pd-mono">{{ $identity->linked_user_id }}</span>
                                        <div class="pd-muted">linked {{ $identity->linked_at?->diffForHumans() }}</div>
                                    @else
                                        <span class="pd-muted">not linked — messages refused</span>
                                    @endif
                                </td>
                                <td class="pd-muted">{{ $identity->last_seen_at?->diffForHumans() ?? '—' }}</td>
                                <td class="pd-actions">
                                    @if ($canManage)
                                        <button type="button" class="pd-btn"
                                                wire:click="sendTest('{{ $identity->getKey() }}')">Send test</button>
                                        @if ($identity->isLinked())
                                            <button type="button" class="pd-btn pd-btn-danger"
                                                    wire:click="unlink('{{ $identity->getKey() }}')">Unlink</button>
                                        @endif
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-pandora::card>

        <x-pandora::card title="Recent deliveries" :padded="false">
            @if ($deliveries->isEmpty())
                <div class="pd-card-body pd-muted">Nothing has crossed this account yet.</div>
            @else
                <table class="pd-table">
                    <thead>
                        <tr>
                            <th scope="col">When</th>
                            <th scope="col">Direction</th>
                            <th scope="col">Status</th>
                            <th scope="col">Detail</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($deliveries as $delivery)
                            <tr>
                                <td class="pd-muted">{{ $delivery->created_at?->diffForHumans() }}</td>
                                <td class="pd-mono">{{ $delivery->direction->value }}</td>
                                <td><x-pandora::badge>{{ $delivery->status->value }}</x-pandora::badge></td>
                                <td class="pd-muted">{{ $delivery->error ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-pandora::card>
    @endif
</div>

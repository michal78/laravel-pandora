{{--
    The files agents can reach.

    Browsing goes through the same containment rules an agent is subject to.
    A page that could show a file an agent cannot read would be a way to
    confirm what lives outside the root, and the whole point of the root is
    that nobody finds out.
--}}
<div class="pd-stack">
    @if ($error !== null)
        <div class="pd-notice pd-notice-danger">{{ $error }}</div>
    @endif

    @if ($notice !== null)
        <div class="pd-notice pd-notice-success">{{ $notice }}</div>
    @endif

    <x-pandora::card title="Workspaces" :padded="false">
        @if ($canManage && $form === '')
            <x-slot:actions>
                <button type="button" class="pd-btn pd-btn-primary"
                        wire:click="startCreating">New workspace</button>
            </x-slot:actions>
        @endif

        {{--
            Creating one. There is no path field, and that is the whole point:
            the form offers the roots an operator declared, by key, and the
            path is composed from whichever was chosen. A form with a path
            field is a form that accepts `/`.
        --}}
        @if ($form === 'create')
            <div class="pd-card-body" style="border-bottom: 1px solid var(--pd-border)">
                @if ($roots === [])
                    <p class="pd-muted">
                        No workspace roots are configured, so none can be created here. An
                        operator declares them in <span class="pd-mono">pandora.workspaces.roots</span>
                        -- a disk from your filesystems configuration, and a base prefix inside it.
                        An unconfigured allowlist permits nothing rather than everything.
                    </p>
                @else
                    <form wire:submit="create" class="pd-stack">
                        <div class="pd-field">
                            <label class="pd-label" for="pd-ws-root">Root</label>
                            <select id="pd-ws-root" class="pd-select" wire:model="rootKey">
                                @foreach ($roots as $key => $root)
                                    <option value="{{ $key }}">{{ $root->describe() }}</option>
                                @endforeach
                            </select>
                            @error('rootKey') <p class="pd-error">{{ $message }}</p> @enderror
                            <p class="pd-help">
                                Where the bytes land. The workspace gets its own prefix under this
                                root, one nothing else shares, and it cannot be changed afterwards.
                            </p>
                        </div>

                        <div class="pd-grid pd-grid-split">
                            <div class="pd-field">
                                <label class="pd-label" for="pd-ws-name">Name</label>
                                <input id="pd-ws-name" type="text" class="pd-input" wire:model="formName" autofocus>
                                @error('formName') <p class="pd-error">{{ $message }}</p> @enderror
                                <p class="pd-help">The slug and the prefix are derived from this.</p>
                            </div>

                            <div class="pd-field">
                                <label class="pd-label" for="pd-ws-description">Description <span class="pd-faint">(optional)</span></label>
                                <input id="pd-ws-description" type="text" class="pd-input" wire:model="formDescription">
                                @error('formDescription') <p class="pd-error">{{ $message }}</p> @enderror
                            </div>
                        </div>

                        <div class="pd-grid pd-grid-split">
                            <div class="pd-field">
                                <label class="pd-label" for="pd-ws-quota">Quota in bytes</label>
                                <input id="pd-ws-quota" type="number" min="0" class="pd-input" wire:model="formQuota">
                                @error('formQuota') <p class="pd-error">{{ $message }}</p> @enderror
                                <p class="pd-help">Empty means unlimited, which stays a decision rather than a default.</p>
                            </div>

                            <div class="pd-field">
                                <label class="pd-label" for="pd-ws-mimes">Allowed types <span class="pd-faint">(optional)</span></label>
                                <input id="pd-ws-mimes" type="text" class="pd-input" wire:model="formMimeTypes"
                                       placeholder="text/plain, application/pdf">
                                @error('formMimeTypes') <p class="pd-error">{{ $message }}</p> @enderror
                                <p class="pd-help">
                                    Comma-separated, matched on the type detected from the bytes and
                                    never the extension. Empty allows every type.
                                </p>
                            </div>
                        </div>

                        <div class="pd-row">
                            <button type="submit" class="pd-btn pd-btn-primary">Create workspace</button>
                            <button type="button" class="pd-btn pd-btn-ghost" wire:click="cancelForm">Cancel</button>
                        </div>
                    </form>
                @endif
            </div>
        @elseif ($editing !== null)
            <div class="pd-card-body" style="border-bottom: 1px solid var(--pd-border)">
                <form wire:submit="save" class="pd-stack">
                    {{--
                        Where the bytes are is shown and is not a field. Re-pointing a
                        root orphans every path already written, and on object storage
                        the move that would fix that does not exist: no rename, only a
                        copy of every object and a delete of every original, with no
                        transaction around the pair.
                    --}}
                    <div class="pd-field">
                        <label class="pd-label">Storage</label>
                        <div class="pd-locked">
                            <span class="pd-locked-mark" aria-hidden="true">◆</span>
                            <span class="pd-mono">{{ $editing->disk }}:{{ $editing->root_path }}</span>
                        </div>
                        <p class="pd-help">
                            Fixed for the life of the workspace. Files already written are named by
                            this path; moving it would leave them where nothing reads. Somewhere else
                            means a new workspace.
                        </p>
                    </div>

                    <div class="pd-grid pd-grid-split">
                        <div class="pd-field">
                            <label class="pd-label" for="pd-ws-edit-name">Name</label>
                            <input id="pd-ws-edit-name" type="text" class="pd-input" wire:model="formName">
                            @error('formName') <p class="pd-error">{{ $message }}</p> @enderror
                        </div>

                        <div class="pd-field">
                            <label class="pd-label" for="pd-ws-edit-description">Description</label>
                            <input id="pd-ws-edit-description" type="text" class="pd-input" wire:model="formDescription">
                            @error('formDescription') <p class="pd-error">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="pd-grid pd-grid-split">
                        <div class="pd-field">
                            <label class="pd-label" for="pd-ws-edit-quota">Quota in bytes</label>
                            <input id="pd-ws-edit-quota" type="number" min="0" class="pd-input" wire:model="formQuota">
                            @error('formQuota') <p class="pd-error">{{ $message }}</p> @enderror
                        </div>

                        <div class="pd-field">
                            <label class="pd-label" for="pd-ws-edit-mimes">Allowed types</label>
                            <input id="pd-ws-edit-mimes" type="text" class="pd-input" wire:model="formMimeTypes">
                            @error('formMimeTypes') <p class="pd-error">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="pd-row">
                        <button type="submit" class="pd-btn pd-btn-primary">Save</button>
                        <button type="button" class="pd-btn pd-btn-ghost" wire:click="cancelForm">Cancel</button>
                    </div>
                </form>
            </div>
        @endif

        @if ($workspaces->isEmpty())
            <div class="pd-card-body pd-muted">
                No workspaces. An agent without one can reach no files at all, which is
                the right default for an agent nobody has thought about yet.
            </div>
        @else
            <table class="pd-table">
                <thead>
                    <tr>
                        <th scope="col">Name</th>
                        <th scope="col">Disk</th>
                        <th scope="col">Used</th>
                        <th scope="col">Types</th>
                        <th scope="col"><span class="pd-visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($workspaces as $item)
                        <tr @class(['is-selected' => $workspace?->getKey() === $item->getKey()])>
                            <td>
                                <div class="pd-strong">{{ $item->name }}</div>
                                <div class="pd-muted pd-mono">{{ $item->root_path }}</div>
                            </td>
                            <td class="pd-mono">{{ $item->disk }}</td>
                            <td>
                                {{ number_format($item->used_bytes) }} bytes
                                @if ($item->hasQuota())
                                    <div class="pd-muted">
                                        of {{ number_format((int) $item->quota_bytes) }}
                                        · {{ number_format((int) $item->remainingBytes()) }} free
                                    </div>
                                @else
                                    <div class="pd-muted">no quota</div>
                                @endif
                            </td>
                            <td>
                                @if (($item->allowed_mime_types ?? []) === [])
                                    <span class="pd-muted">any</span>
                                @else
                                    @foreach ($item->allowed_mime_types as $mime)
                                        <x-pandora::badge>{{ $mime }}</x-pandora::badge>
                                    @endforeach
                                @endif
                            </td>
                            <td class="pd-actions">
                                <button type="button" class="pd-btn"
                                        wire:click="select('{{ $item->slug }}')">Browse</button>

                                @if ($canManage)
                                    <button type="button" class="pd-btn"
                                            wire:click="startEditing('{{ $item->slug }}')">Edit</button>

                                    {{--
                                        Removes the row and detaches every agent
                                        pointing at it. The files stay: deleting
                                        them is N calls with no transaction, and a
                                        partial failure leaves a half-emptied
                                        prefix under a row claiming it is gone.
                                    --}}
                                    <button type="button" class="pd-btn pd-btn-danger"
                                            wire:click="delete('{{ $item->slug }}')"
                                            wire:confirm="Remove this workspace? Agents using it lose access immediately. The files are left where they are, at {{ $item->disk }}:{{ $item->root_path }}."
                                    >Remove</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-pandora::card>

    @if ($workspace !== null)
        <x-pandora::card :title="$workspace->name" :padded="false">
            <x-slot:actions>
                @if ($path !== '')
                    <button type="button" class="pd-btn" wire:click="up">Up</button>
                @endif

                @if ($canManage)
                    <button type="button" class="pd-btn" wire:click="recount"
                            title="Recount from the filesystem. The counter is authoritative for enforcement; the disk is authoritative for truth.">Recount</button>
                @endif
            </x-slot:actions>

            <div class="pd-card-body">
                <span class="pd-mono">/{{ $path }}</span>
            </div>

            @if ($canManage && ! $unreachable)
                {{--
                    Written through the same `WorkspaceFiles` an agent writes
                    through, so the quota is reserved before the bytes land and
                    the MIME allowlist is matched on the detected type. An
                    upload that touched the disk directly would be a second way
                    in with its own idea of when a workspace is full.
                --}}
                <div class="pd-card-body" style="border-top: 1px solid var(--pd-border)">
                    <form wire:submit="uploadFile" class="pd-row">
                        <div class="pd-field" style="flex: 1">
                            <label class="pd-label" for="pd-ws-upload">
                                Upload into <span class="pd-mono">/{{ $path }}</span>
                            </label>
                            <input id="pd-ws-upload" type="file" class="pd-input" wire:model="file">
                            @error('file') <p class="pd-error">{{ $message }}</p> @enderror
                            <p class="pd-help">
                                Up to {{ number_format($maxUploadBytes / 1048576, 0) }} MB, and subject
                                to the workspace's quota and allowed types exactly as an agent's write
                                is. The filename is reduced to a bare name.
                            </p>
                        </div>

                        <button type="submit" class="pd-btn pd-btn-primary"
                                wire:loading.attr="disabled" wire:target="file,uploadFile">Upload</button>
                    </form>
                </div>
            @endif

            @if ($unreachable)
                {{--
                    A root that has moved or been unmounted. Said plainly: an
                    operator arriving to find out why an agent cannot read its
                    files should see the reason.
                --}}
                <div class="pd-card-body pd-notice pd-notice-warning">
                    The workspace root is missing or is not a directory. Nothing can be read or
                    written until it exists again.
                </div>
            @elseif ($entries === [])
                <div class="pd-card-body pd-muted">Empty.</div>
            @else
                <table class="pd-table">
                    <thead>
                        <tr>
                            <th scope="col">Path</th>
                            <th scope="col"><span class="pd-visually-hidden">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($entries as $entry)
                            <tr>
                                <td class="pd-mono">{{ $entry }}</td>
                                <td class="pd-actions">
                                    <button type="button" class="pd-btn"
                                            wire:click="browse('{{ $entry }}')">Open</button>

                                    {{--
                                        Offered for everything the store listed, because
                                        whether a listing entry is a file is a question for
                                        the store rather than for the page: object storage
                                        has no directories, so the same name can be a
                                        prefix on one disk and a file on another. A
                                        directory answers 404 and nothing is guessed here.

                                        Streamed through the app rather than presigned. A
                                        signed URL is a bearer token for one object until
                                        it expires, and the audit trail records that a link
                                        was made, not that the file left.
                                    --}}
                                    <a class="pd-btn"
                                       href="{{ route('pandora.workspaces.download', ['workspace' => $workspace->slug, 'path' => $entry]) }}">Download</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-pandora::card>
    @endif
</div>

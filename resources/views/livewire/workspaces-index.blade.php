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
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-pandora::card>
    @endif
</div>

{{--
    An inventory of what Composer installed.

    There is no install button here and there never will be one: extensions
    arrive through `composer require`, which means through a lockfile, a review
    and a deploy. Every one of those is a thing a web form would route around.

    Nothing on this page was booted to render it. The list comes from Composer's
    own installed.json, so a package that would fatal on load still appears --
    which is the package an operator most needs to see.
--}}
<div class="pd-stack">
    <x-pandora::card title="Installed extensions" :padded="false">
        <div class="pd-card-body">
            <p class="pd-help">
                Read from <span class="pd-mono">vendor/composer/installed.json</span>. Installing an
                extension lets it <em>offer</em> capabilities; it enables nothing. A channel arrives
                disabled, and a tool still clears the registry, the agent, the tenant and the
                actor's abilities before anything calls it.
            </p>
        </div>

        @if ($extensions === [])
            <div class="pd-card-body pd-muted">
                No installed package declares an <span class="pd-mono">extra.pandora</span> manifest.
            </div>
        @else
            <table class="pd-table">
                <thead>
                    <tr>
                        <th scope="col">Extension</th>
                        <th scope="col">Declares</th>
                        <th scope="col">Registers</th>
                        <th scope="col">Difference</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($extensions as $extension)
                        @php($manifest = $extension->manifest)
                        <tr>
                            <td>
                                {{-- Escaped and bounded: somebody else wrote this. --}}
                                <div class="pd-strong">{{ $manifest->name }}</div>
                                <div class="pd-muted pd-mono">{{ $manifest->package }} {{ $manifest->version }}</div>
                                @if ($manifest->description !== null)
                                    <div class="pd-muted">{{ $manifest->description }}</div>
                                @endif
                                @if ($manifest->documentation !== null)
                                    <div><a class="pd-link" href="{{ $manifest->documentation }}"
                                            rel="noopener noreferrer" target="_blank">Documentation</a></div>
                                @endif
                            </td>
                            <td>
                                @forelse ($manifest->provides as $type => $items)
                                    <div><span class="pd-muted">{{ $type }}:</span>
                                        <span class="pd-mono">{{ implode(', ', $items) }}</span></div>
                                @empty
                                    <span class="pd-muted">nothing</span>
                                @endforelse
                            </td>
                            <td>
                                @forelse ($extension->registered as $type => $items)
                                    <div><span class="pd-muted">{{ $type }}:</span>
                                        <span class="pd-mono">{{ implode(', ', $items) }}</span></div>
                                @empty
                                    <span class="pd-muted">nothing</span>
                                @endforelse
                            </td>
                            <td>
                                @if ($extension->matchesItsManifest())
                                    <span class="pd-muted">matches</span>
                                @else
                                    @foreach ($extension->missing as $type => $items)
                                        <div class="pd-notice pd-notice-warning">
                                            Declared, not registered — {{ $type }}:
                                            <span class="pd-mono">{{ implode(', ', $items) }}</span>
                                        </div>
                                    @endforeach
                                    @foreach ($extension->undeclared as $type => $items)
                                        <div class="pd-notice pd-notice-warning">
                                            Registered, not declared — {{ $type }}:
                                            <span class="pd-mono">{{ implode(', ', $items) }}</span>
                                        </div>
                                    @endforeach
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-pandora::card>
</div>

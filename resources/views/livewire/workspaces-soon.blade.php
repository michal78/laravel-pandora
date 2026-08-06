{{--
    Workspaces, held back.

    Shown rather than hidden, because an operator who reads the guides and then
    finds no such page has to work out whether it is missing or broken.
--}}
<div class="pd-stack">
    <x-pandora::card title="Workspaces">
        <p class="pd-muted">
            Agent file workspaces are coming in a later phase — a directory an agent
            may read and write inside, bounded by a root it cannot escape, a quota it
            cannot exceed and a list of types it cannot widen.
        </p>

        <p class="pd-muted">
            Until then no agent reaches any file through a workspace. That is the same
            position an agent is in by default: with nothing configured, it can reach
            nothing at all.
        </p>
    </x-pandora::card>
</div>

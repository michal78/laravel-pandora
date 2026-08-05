{{--
    Save / cancel, shown only while editing.

    Shared by every editable tab so that a tab added later cannot quietly ship
    without a cancel, or with a save that submits a different way.
--}}
@if ($canManage && $editing)
    <div class="pd-row" style="margin-top: var(--pd-space-3)">
        <button type="submit" class="pd-btn pd-btn-primary">Save changes</button>
        <button type="button" class="pd-btn pd-btn-ghost" wire:click="cancelEditing">Cancel</button>
    </div>
@endif

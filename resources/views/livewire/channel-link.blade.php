{{--
    Where a linking code is redeemed.

    The user this links is the signed-in one. There is no field on this page
    naming a user, because the whole security property is that whoever submits
    the form cannot choose who gets linked.
--}}
<div class="pd-stack">
    @if ($error !== null)
        <div class="pd-notice pd-notice-danger">{{ $error }}</div>
    @endif

    @if ($notice !== null)
        <div class="pd-notice pd-notice-success">{{ $notice }}</div>
    @endif

    <x-pandora::card title="Link a channel account">
        <form wire:submit="redeem" class="pd-stack">
            <div class="pd-field">
                <label class="pd-label" for="pd-link-code">Linking code</label>
                <input id="pd-link-code" type="text" class="pd-input pd-mono" wire:model="code"
                       autocomplete="off" autofocus placeholder="ABCD2345">
                @error('code') <p class="pd-error">{{ $message }}</p> @enderror
                <p class="pd-help">
                    Message the agent in the channel to get one. The code proves you hold that
                    channel account; being signed in here proves you hold this one. Linking is the
                    claim that those are the same person, so it needs both.
                </p>
            </div>

            <div class="pd-row">
                <button type="submit" class="pd-btn pd-btn-primary">Link</button>
            </div>
        </form>
    </x-pandora::card>

    <x-pandora::card title="Your linked accounts" :padded="false">
        @if ($identities->isEmpty())
            <div class="pd-card-body pd-muted">
                Nothing linked. Until an account is linked, messages from it are refused — an agent
                will not act for somebody this application has never authenticated.
            </div>
        @else
            <table class="pd-table">
                <thead>
                    <tr>
                        <th scope="col">Account</th>
                        <th scope="col">Linked</th>
                        <th scope="col"><span class="pd-visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($identities as $identity)
                        <tr>
                            <td>
                                <div class="pd-strong">{{ $identity->account?->name ?? '—' }}</div>
                                <div class="pd-muted pd-mono">{{ $identity->external_id }}</div>
                            </td>
                            <td class="pd-muted">{{ $identity->linked_at?->diffForHumans() }}</td>
                            <td class="pd-actions">
                                <button type="button" class="pd-btn pd-btn-danger"
                                        wire:click="unlink('{{ $identity->getKey() }}')">Unlink</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-pandora::card>
</div>

<?php

declare(strict_types=1);

namespace Pandora\UI\Livewire;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Component;
use Pandora\Channels\ChannelIdentity;
use Pandora\Channels\LinkCodes;
use Pandora\Exceptions\ChannelLinkDenied;
use Pandora\UI\Feature;
use Pandora\UI\PandoraGate;

/**
 * The second half of the linking evidence: a signed-in browser.
 *
 * This page is why the flow works. The code proves somebody controls a channel
 * account; arriving here signed in proves they control a host account; and the
 * user this links is taken from the guard, never from the request. There is no
 * field on this component naming a user, and `LinkCodes::redeem()` has no
 * parameter that could carry one — so a forged submission has nothing to forge.
 *
 * It deliberately requires only `pandora.access`, not a channels ability.
 * Linking your own Slack handle to your own account is not an administrative
 * act, and putting an operator in the loop for every link would push people
 * towards the shortcut this whole design exists to refuse.
 */
final class ChannelLink extends Component
{
    public string $code = '';

    public ?string $notice = null;

    public ?string $error = null;

    public function mount(): void
    {
        $this->guard();
    }

    public function redeem(LinkCodes $codes): void
    {
        $this->guard();

        $this->validate(
            ['code' => ['required', 'string', 'min:4', 'max:64']],
            attributes: ['code' => 'linking code'],
        );

        $user = auth(config('pandora.auth.guard'))->user();

        if (! $user instanceof Authorizable) {
            abort(403);
        }

        try {
            $identity = $codes->redeem($this->code, $user);
        } catch (ChannelLinkDenied $e) {
            $this->error = $e->getMessage();
            $this->notice = null;
            $this->code = '';

            return;
        }

        $this->code = '';
        $this->error = null;
        $this->notice = 'Linked. Messages from that account now act as you, with your permissions.';
        unset($identity);
    }

    /**
     * Break one of your own links.
     *
     * Scoped to the signed-in user's identities, so this cannot be used to
     * unlink somebody else — that is an operator action on the Channels page,
     * and it is audited as one.
     */
    public function unlink(string $identityId, LinkCodes $codes): void
    {
        $this->guard();

        $identity = $this->mine()->firstWhere('id', $identityId);

        if (! $identity instanceof ChannelIdentity) {
            return;
        }

        $codes->unlink($identity, 'user');

        $this->notice = 'Unlinked.';
        $this->error = null;
    }

    public function render(): View
    {
        return view('pandora::livewire.channel-link', [
            'identities' => $this->mine(),
        ])->layout('pandora::layouts.app', ['title' => 'Link a channel account']);
    }

    /** @return Collection<int, ChannelIdentity> */
    private function mine(): Collection
    {
        $user = auth(config('pandora.auth.guard'))->user();

        if (! $user instanceof Authorizable) {
            /** @var Collection<int, ChannelIdentity> $empty */
            $empty = new Collection;

            return $empty;
        }

        /** @var Collection<int, ChannelIdentity> $identities */
        $identities = ChannelIdentity::query()
            ->where('linked_user_type', $user::class)
            ->where('linked_user_id', (string) $user->getAuthIdentifier())
            ->orderBy('linked_at')
            ->get();

        return $identities;
    }

    private function guard(): void
    {
        if (Feature::disabled('channels')) {
            abort(404);
        }

        PandoraGate::authorize('access');
    }
}

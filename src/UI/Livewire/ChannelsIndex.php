<?php

declare(strict_types=1);

namespace Pandora\UI\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\Component;
use Pandora\Agents\Agent;
use Pandora\Audit\AuditLogger;
use Pandora\Channels\ChannelAccount;
use Pandora\Channels\ChannelDelivery;
use Pandora\Channels\ChannelDispatcher;
use Pandora\Channels\ChannelIdentity;
use Pandora\Channels\ChannelRegistry;
use Pandora\Channels\Data\OutboundMessage;
use Pandora\Channels\LinkCodes;
use Pandora\UI\Feature;
use Pandora\UI\PandoraGate;

/**
 * The channels this installation is connected to, and who is allowed to speak
 * through them.
 *
 * The page is arranged around the question an operator actually has, which is
 * not "is Slack configured" but *"who is that?"* — the identities table is the
 * centre of it, and the column that matters is whether a participant is linked
 * to a host user or is a stranger the agent has been refusing.
 *
 * Two things this page deliberately cannot do. It cannot link an identity to a
 * user: an administrator's belief about who owns a Slack handle is not
 * evidence, and making an operator screen an authentication mechanism is the
 * failure ADR-0015 exists to avoid. It offers *un*linking only, where the worst
 * outcome is somebody losing access rather than somebody gaining it.
 *
 * And it holds no secret. A channel names a credential key; the credential
 * lives in the encrypted store, the same arrangement MCP servers use.
 */
final class ChannelsIndex extends Component
{
    #[Url(as: 'account', except: '')]
    public string $selected = '';

    public ?string $notice = null;

    public ?string $error = null;

    /** Empty unless a form is open: 'create', or the slug being edited. */
    public string $form = '';

    public string $formChannel = '';

    public string $formName = '';

    public string $formExternalId = '';

    public string $formAgent = '';

    public string $formCredentialKey = '';

    public function mount(): void
    {
        $this->guard();
    }

    public function select(string $slug): void
    {
        $this->selected = $slug;
        $this->notice = null;
        $this->error = null;
    }

    public function startCreating(): void
    {
        $this->guardManage();

        $this->form = 'create';
        $this->formChannel = (string) (app(ChannelRegistry::class)->keys()[0] ?? '');
        $this->formName = '';
        $this->formExternalId = '';
        $this->formAgent = '';
        $this->formCredentialKey = '';
        $this->error = null;
        $this->resetValidation();
    }

    public function startEditing(string $slug): void
    {
        $this->guardManage();

        $account = $this->find($slug);

        if ($account === null) {
            return;
        }

        // Editing selects, because the form only renders beside the selected
        // account. Without this the button appears to do nothing at all unless
        // the row happened to be inspected first.
        $this->selected = $account->slug;
        $this->form = $account->slug;
        $this->formChannel = $account->channel;
        $this->formName = $account->name;
        $this->formExternalId = $account->external_id;
        $this->formAgent = (string) $account->agent_id;
        $this->formCredentialKey = (string) $account->credential_key;
        $this->error = null;
        $this->resetValidation();
    }

    public function cancelForm(): void
    {
        $this->form = '';
        $this->error = null;
        $this->resetValidation();
    }

    /**
     * Register a workspace on an installed channel.
     *
     * Created disabled, always. Enabling is a separate press, because the act
     * of writing down where a workspace is and the act of opening a door into
     * this installation are two different decisions and should feel like it.
     */
    public function create(AuditLogger $audit): void
    {
        $this->guardManage();

        $this->validate($this->rules(), attributes: $this->attributes());

        if (! app(ChannelRegistry::class)->has($this->formChannel)) {
            $this->error = 'No adapter is registered for that channel. Install the extension that provides it.';

            return;
        }

        $slug = Str::slug($this->formName);

        if ($slug === '' || $this->find($slug) !== null) {
            $this->error = 'That name is already in use, or does not produce a usable slug.';

            return;
        }

        /** @var ChannelAccount $account */
        $account = ChannelAccount::query()->create([
            'channel' => $this->formChannel,
            'name' => trim($this->formName),
            'slug' => $slug,
            'external_id' => trim($this->formExternalId),
            'agent_id' => $this->formAgent === '' ? null : $this->formAgent,
            'credential_key' => $this->formCredentialKey === '' ? null : trim($this->formCredentialKey),
            'enabled' => false,
        ]);

        $audit->record(
            action: 'channel.account_created',
            targetType: ChannelAccount::class,
            targetId: (string) $account->getKey(),
            metadata: ['channel' => $account->channel, 'slug' => $slug],
        );

        $this->form = '';
        $this->selected = $slug;
        $this->notice = 'Registered, and disabled. Bind an agent and enable it when you are ready.';
    }

    public function save(AuditLogger $audit): void
    {
        $this->guardManage();

        $account = $this->find($this->form);

        if ($account === null) {
            return;
        }

        $this->validate($this->rules(), attributes: $this->attributes());

        // The channel and the external id are left alone. Both are what inbound
        // traffic is matched on, so editing them would silently re-point every
        // identity beneath this row at a workspace those people are not in.
        $account->update([
            'name' => trim($this->formName),
            'agent_id' => $this->formAgent === '' ? null : $this->formAgent,
            'credential_key' => $this->formCredentialKey === '' ? null : trim($this->formCredentialKey),
        ]);

        $audit->record(
            action: 'channel.account_updated',
            targetType: ChannelAccount::class,
            targetId: (string) $account->getKey(),
            metadata: ['slug' => $account->slug, 'agent_id' => $account->agent_id],
        );

        $this->form = '';
        $this->notice = 'Saved.';
    }

    public function toggle(string $slug, AuditLogger $audit): void
    {
        $this->guardManage();

        $account = $this->find($slug);

        if ($account === null) {
            return;
        }

        $account->update(['enabled' => ! $account->enabled]);

        $audit->record(
            action: 'channel.account_updated',
            targetType: ChannelAccount::class,
            targetId: (string) $account->getKey(),
            metadata: ['slug' => $account->slug, 'enabled' => $account->enabled],
        );

        $this->notice = $account->enabled ? 'Enabled.' : 'Disabled. Nothing arrives or leaves through it now.';
    }

    public function delete(string $slug, AuditLogger $audit): void
    {
        $this->guardManage();

        $account = $this->find($slug);

        if ($account === null) {
            return;
        }

        $audit->record(
            action: 'channel.account_deleted',
            targetType: ChannelAccount::class,
            targetId: (string) $account->getKey(),
            severity: 'warning',
            metadata: ['slug' => $account->slug, 'channel' => $account->channel],
        );

        $account->identities()->delete();
        $account->delete();

        $this->selected = '';
        $this->notice = 'Removed, along with its identities. Every link it carried is gone.';
    }

    /**
     * Break a link from the operator side.
     *
     * The only identity operation this page has, and the direction is the
     * point: an operator can take access away and cannot hand it out.
     */
    public function unlink(string $identityId, LinkCodes $codes): void
    {
        $this->guardManage();

        /** @var ChannelIdentity|null $identity */
        $identity = ChannelIdentity::query()->find($identityId);

        if ($identity === null) {
            return;
        }

        $codes->unlink($identity, 'operator');

        $this->notice = 'Unlinked. Their next message is refused.';
    }

    /**
     * Send a message through the adapter and report what really happened.
     *
     * Goes through `ChannelDispatcher` rather than calling the adapter, so the
     * test exercises the same path a run does — including the delivery row. A
     * "test" that took a shortcut would be testing the shortcut.
     */
    public function sendTest(string $identityId, ChannelDispatcher $dispatcher, AuditLogger $audit): void
    {
        $this->guardManage();

        /** @var ChannelIdentity|null $identity */
        $identity = ChannelIdentity::query()->find($identityId);

        if ($identity === null) {
            return;
        }

        $account = $identity->account;

        if (! $account instanceof ChannelAccount) {
            return;
        }

        $result = $dispatcher->send(new OutboundMessage(
            account: $account,
            identity: $identity,
            text: 'Test message from Pandora.',
        ));

        $audit->record(
            action: 'channel.delivery_tested',
            targetType: ChannelAccount::class,
            targetId: (string) $account->getKey(),
            metadata: ['identity_id' => $identity->getKey(), 'delivered' => $result->delivered],
        );

        if ($result->delivered) {
            $this->notice = 'Delivered.';
            $this->error = null;
        } else {
            $this->error = 'Not delivered: '.$result->error;
            $this->notice = null;
        }
    }

    public function render(): View
    {
        $account = $this->find($this->selected);
        $registry = app(ChannelRegistry::class);

        return view('pandora::livewire.channels-index', [
            'accounts' => $this->accounts(),
            'account' => $account,
            'identities' => $account === null ? new Collection : $this->identitiesFor($account),
            'deliveries' => $account === null ? new Collection : $this->deliveriesFor($account),
            'adapters' => $registry->all(),
            'agents' => Agent::query()->orderBy('name')->get(),
            'canManage' => PandoraGate::allows('channels.manage'),
        ])->layout('pandora::layouts.app', ['title' => 'Channels']);
    }

    /**
     * Whether the adapter behind an account is actually installed.
     *
     * Shown rather than hidden: an account whose extension was removed is a
     * door that looks open in the database and is closed in reality, and that
     * discrepancy is exactly what an operator came here to find.
     */
    public function adapterMissing(ChannelAccount $account): bool
    {
        return ! app(ChannelRegistry::class)->has($account->channel);
    }

    /** @return Collection<int, ChannelAccount> */
    private function accounts(): Collection
    {
        /** @var Collection<int, ChannelAccount> $accounts */
        $accounts = ChannelAccount::query()->orderBy('name')->get();

        return $accounts;
    }

    /** @return Collection<int, ChannelIdentity> */
    private function identitiesFor(ChannelAccount $account): Collection
    {
        /** @var Collection<int, ChannelIdentity> $identities */
        $identities = ChannelIdentity::query()
            ->where('account_id', $account->getKey())
            ->orderByDesc('last_seen_at')
            ->limit(100)
            ->get();

        return $identities;
    }

    /** @return Collection<int, ChannelDelivery> */
    private function deliveriesFor(ChannelAccount $account): Collection
    {
        /** @var Collection<int, ChannelDelivery> $deliveries */
        $deliveries = ChannelDelivery::query()
            ->where('account_id', $account->getKey())
            ->orderByDesc('created_at')
            ->limit(25)
            ->get();

        return $deliveries;
    }

    private function find(string $slug): ?ChannelAccount
    {
        if ($slug === '') {
            return null;
        }

        /** @var ChannelAccount|null $account */
        $account = ChannelAccount::query()->where('slug', $slug)->first();

        return $account;
    }

    /**
     * @return array<string, list<string>>
     */
    private function rules(): array
    {
        return [
            'formChannel' => ['required', 'string', 'max:64'],
            'formName' => ['required', 'string', 'min:2', 'max:120'],
            'formExternalId' => ['required', 'string', 'max:191'],
            'formAgent' => ['nullable', 'string', 'max:26'],
            'formCredentialKey' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function attributes(): array
    {
        return [
            'formChannel' => 'channel',
            'formName' => 'name',
            'formExternalId' => 'workspace id',
            'formAgent' => 'agent',
            'formCredentialKey' => 'credential key',
        ];
    }

    private function guard(): void
    {
        if (Feature::disabled('channels')) {
            abort(404);
        }

        PandoraGate::authorize('channels.view');
    }

    private function guardManage(): void
    {
        $this->guard();

        PandoraGate::authorize('channels.manage');
    }
}

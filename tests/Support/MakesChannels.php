<?php

declare(strict_types=1);

namespace Pandora\Tests\Support;

use Pandora\Agents\Agent;
use Pandora\Channels\ChannelAccount;
use Pandora\Channels\ChannelIdentity;
use Pandora\Channels\ChannelRegistry;
use Pandora\Testing\FakeChannel;
use Pandora\Tests\Fixtures\TestUser;

/**
 * Scaffolding for channel tests.
 *
 * Real registry, real adapter, real rows. The properties under test here are
 * about what is written down and read back -- whether a link exists, which
 * tenant a message inherited, which session key a run got -- and a mock would
 * agree with the wrong column name without complaint.
 */
trait MakesChannels
{
    use MakesRuns;

    private ?FakeChannel $fakeChannel = null;

    public function fakeChannel(): FakeChannel
    {
        if ($this->fakeChannel === null) {
            $this->fakeChannel = new FakeChannel;
            app(ChannelRegistry::class)->register($this->fakeChannel);
        }

        return $this->fakeChannel;
    }

    /**
     * An account that is ready to carry a conversation: enabled, with an agent.
     *
     * Note that both are set explicitly. The model defaults to disabled and
     * unbound, which is the state a freshly created account is in and the state
     * every "it accepts nothing" test wants.
     *
     * @param array<string, mixed> $attributes
     */
    public function makeChannelAccount(array $attributes = [], ?Agent $agent = null): ChannelAccount
    {
        $agent ??= $this->makeAgent();

        /** @var ChannelAccount $account */
        $account = ChannelAccount::query()->create(array_merge([
            'channel' => $this->fakeChannel()->key(),
            'name' => 'Fake workspace',
            'slug' => 'fake-workspace-'.uniqid(),
            'external_id' => 'fake-workspace',
            'agent_id' => $agent->getKey(),
            'enabled' => true,
        ], $attributes));

        return $account;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function makeIdentity(
        ChannelAccount $account,
        string $externalId = 'U-stranger',
        ?TestUser $linkedTo = null,
        array $attributes = [],
    ): ChannelIdentity {
        /** @var ChannelIdentity $identity */
        $identity = ChannelIdentity::query()->create(array_merge([
            'tenant_id' => $account->tenant_id,
            'account_id' => $account->getKey(),
            'external_id' => $externalId,
            'display_name' => 'A Participant',
        ], $attributes));

        if ($linkedTo !== null) {
            $this->linkIdentity($identity, $linkedTo);
        }

        return $identity;
    }

    /**
     * Link without going through the code flow.
     *
     * For tests whose subject is something else. Anything asserting *how*
     * linking works must drive `LinkCodes` -- this shortcut writes the same
     * columns and proves nothing about the evidence that should have been
     * required to write them.
     */
    public function linkIdentity(ChannelIdentity $identity, TestUser $user): ChannelIdentity
    {
        $identity->forceFill([
            'linked_user_type' => $user::class,
            'linked_user_id' => (string) $user->getKey(),
            'linked_at' => now(),
            'link_epoch' => $identity->link_epoch + 1,
        ])->save();

        return $identity->refresh();
    }
}

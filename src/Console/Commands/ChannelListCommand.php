<?php

declare(strict_types=1);

namespace Pandora\Console\Commands;

use Illuminate\Console\Command;
use Pandora\Channels\ChannelAccount;
use Pandora\Channels\ChannelIdentity;
use Pandora\Channels\ChannelRegistry;

/**
 * What channels this installation has, and who is allowed to speak through
 * them.
 *
 * The counts are the point. "Eleven identities, two linked" is the state of a
 * workspace where nine people have messaged an agent and been refused, which is
 * correct behaviour and reads as a problem until you know that — so the command
 * says both numbers rather than one.
 */
final class ChannelListCommand extends Command
{
    protected $signature = 'pandora:channel:list
                            {account? : Only this account, by slug}
                            {--identities : List every identity rather than counting them}';

    protected $description = 'List the channel accounts Pandora knows about, and their identities';

    public function handle(ChannelRegistry $registry): int
    {
        /** @var string|null $slug */
        $slug = $this->argument('account');

        /** @var list<ChannelAccount> $accounts */
        $accounts = ChannelAccount::query()
            ->when($slug !== null, static fn ($query) => $query->where('slug', $slug))
            ->orderBy('name')
            ->get()
            ->all();

        if ($accounts === []) {
            $this->components->warn($slug === null
                ? 'No channel accounts are registered.'
                : "No channel account is registered with the slug [{$slug}].");

            $this->adapters($registry);

            return self::SUCCESS;
        }

        foreach ($accounts as $account) {
            $this->line('');
            $this->components->twoColumnDetail(
                '<options=bold>'.$account->name.'</>',
                $account->enabled ? 'enabled' : '<fg=red>disabled</>',
            );
            $this->components->twoColumnDetail('  slug', $account->slug);
            $this->components->twoColumnDetail('  channel', $account->channel
                .($registry->has($account->channel) ? '' : ' <fg=red>(no adapter installed)</>'));
            $this->components->twoColumnDetail('  workspace', $account->external_id);
            $this->components->twoColumnDetail('  agent', $account->agent?->slug ?? '<fg=yellow>none bound</>');

            $identities = ChannelIdentity::query()
                ->where('account_id', $account->getKey())
                ->orderBy('external_id')
                ->get();

            $linked = $identities->filter(static fn (ChannelIdentity $i): bool => $i->isLinked())->count();

            $this->components->twoColumnDetail(
                '  identities',
                $identities->count().' seen, '.$linked.' linked',
            );

            if (! $this->option('identities')) {
                continue;
            }

            foreach ($identities as $identity) {
                $this->components->twoColumnDetail(
                    '    '.$identity->external_id,
                    // The host user, or the plain fact that this person is
                    // being refused every time they write.
                    $identity->isLinked()
                        ? 'linked to '.$identity->linked_user_id
                        : '<fg=yellow>not linked — messages refused</>',
                );
            }
        }

        $this->line('');

        return self::SUCCESS;
    }

    private function adapters(ChannelRegistry $registry): void
    {
        $keys = $registry->keys();

        $this->components->info($keys === []
            ? 'No channel adapter is installed either. Adapters are extensions: composer require one.'
            : 'Installed adapters: '.implode(', ', $keys).'. Register a workspace to use one.');
    }
}

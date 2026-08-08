<?php

declare(strict_types=1);

namespace Pandora\Channels;

use Illuminate\Contracts\Container\Container;
use Pandora\Contracts\Channel;
use Pandora\Exceptions\InvalidConfiguration;

/**
 * The channel adapters this application has installed.
 *
 * Registration is deployment-controlled: adapters come from a service provider
 * -- usually an extension's -- and never from the database and never from a
 * model. Registering an adapter makes it *available*; it connects nothing.
 * An operator still has to create an account, point it at an agent and enable
 * it, and every one of those is a separate deliberate act (ADR-0016:
 * installation is not consent).
 */
final class ChannelRegistry
{
    /** @var array<string, Channel|class-string<Channel>> */
    private array $channels = [];

    /** @var array<string, Channel> */
    private array $resolved = [];

    public function __construct(
        private readonly Container $container,
    ) {}

    /**
     * @param Channel|class-string<Channel> $channel
     */
    public function register(Channel|string $channel): self
    {
        $instance = $this->resolve($channel);
        $key = $instance->key();

        if ($key === '') {
            throw InvalidConfiguration::make('A channel adapter must return a non-empty key().');
        }

        if (isset($this->channels[$key])) {
            throw InvalidConfiguration::make(
                "Channel [{$key}] is registered twice. Two adapters cannot share a key: the key is "
                    .'what an account row points at, so a collision silently reroutes somebody else\'s messages.',
            );
        }

        $this->channels[$key] = $channel;
        $this->resolved[$key] = $instance;

        return $this;
    }

    public function has(string $key): bool
    {
        return isset($this->channels[$key]);
    }

    /**
     * The adapter for a key, or null when nothing registered it.
     *
     * Null rather than an exception, because an account for an uninstalled
     * adapter is an ordinary state -- the extension was removed and the row
     * outlived it. The page shows the account as unavailable and no message
     * moves; that is better than a fatal on an inventory screen.
     */
    public function get(string $key): ?Channel
    {
        if (! isset($this->channels[$key])) {
            return null;
        }

        return $this->resolved[$key] ??= $this->resolve($this->channels[$key]);
    }

    /**
     * @return array<string, Channel>
     */
    public function all(): array
    {
        foreach (array_keys($this->channels) as $key) {
            $this->resolved[$key] ??= $this->resolve($this->channels[$key]);
        }

        return $this->resolved;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->channels);
    }

    /**
     * @param Channel|class-string<Channel> $channel
     */
    private function resolve(Channel|string $channel): Channel
    {
        if ($channel instanceof Channel) {
            return $channel;
        }

        $instance = $this->container->make($channel);

        if (! $instance instanceof Channel) {
            throw InvalidConfiguration::make(
                "Channel [{$channel}] must implement ".Channel::class.'.',
            );
        }

        return $instance;
    }
}

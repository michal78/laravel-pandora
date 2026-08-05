<?php

declare(strict_types=1);

namespace Pandora\Pandora\Providers;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\Factory;
use Pandora\Pandora\Contracts\ChatProvider;
use Pandora\Pandora\Contracts\Provider;
use Pandora\Pandora\Exceptions\InvalidConfiguration;

/**
 * Resolves configured providers, caching one instance per connection key.
 *
 * Custom adapters register through `extend()`, which is the extension point a
 * Composer package uses to add a provider without touching core.
 */
final class ProviderManager
{
    /** @var array<string, Provider> */
    private array $resolved = [];

    /** @var array<string, \Closure(array<string, mixed>, string): Provider> */
    private array $customAdapters = [];

    /**
     * @param array<string, array<string, mixed>> $connections
     */
    public function __construct(
        private readonly Container $container,
        private array $connections,
        private readonly string $default,
    ) {}

    /**
     * Register a custom adapter.
     *
     * @param \Closure(array<string, mixed>, string): Provider $factory
     */
    public function extend(string $adapter, \Closure $factory): void
    {
        $this->customAdapters[$adapter] = $factory;
    }

    /**
     * Register a connection at runtime (used by tests and by extensions).
     *
     * @param array<string, mixed> $config
     */
    public function addConnection(string $key, array $config): void
    {
        $this->connections[$key] = $config;
        unset($this->resolved[$key]);
    }

    public function default(): string
    {
        return $this->default;
    }

    public function provider(?string $key = null): Provider
    {
        $key ??= $this->default;

        return $this->resolved[$key] ??= $this->build($key);
    }

    public function chat(?string $key = null): ChatProvider
    {
        $provider = $this->provider($key);

        if (! $provider instanceof ChatProvider) {
            throw new InvalidConfiguration(
                "Provider [{$provider->key()}] does not support chat completions.",
            );
        }

        return $provider;
    }

    /**
     * @return list<string>
     */
    public function configuredKeys(): array
    {
        return array_keys($this->connections);
    }

    public function has(string $key): bool
    {
        return isset($this->connections[$key]);
    }

    private function build(string $key): Provider
    {
        $config = $this->connections[$key] ?? null;

        if ($config === null) {
            throw InvalidConfiguration::missingProvider($key);
        }

        /** @var string $adapter */
        $adapter = $config['adapter'] ?? throw InvalidConfiguration::unknownAdapter('(none)', $key);

        if (isset($this->customAdapters[$adapter])) {
            return ($this->customAdapters[$adapter])($config, $key);
        }

        return match ($adapter) {
            'fake' => new Adapters\FakeProvider($key),
            'openai-compatible' => new Adapters\OpenAiCompatibleProvider(
                key: $key,
                config: $config,
                http: $this->container->make(Factory::class),
            ),
            default => throw InvalidConfiguration::unknownAdapter($adapter, $key),
        };
    }
}

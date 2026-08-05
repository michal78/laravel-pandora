<?php

declare(strict_types=1);

namespace Pandora\Pandora\Tests;

use Illuminate\Support\Facades\Http;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Pandora\Pandora\PandoraServiceProvider;
use Pandora\Pandora\Providers\Adapters\FakeProvider;
use Pandora\Pandora\Providers\ProviderManager;
use Pandora\Pandora\Tests\Fixtures\TestUser;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        // The host's own tables (users, jobs, cache) plus Pandora's. Pandora
        // never migrates a host table, but its tests need one to authorize
        // against -- proving the package works with an ordinary Laravel user.
        $this->loadLaravelMigrations();
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // No test may reach a network. This is a hard guarantee rather than a
        // convention: any HTTP request without a matching fake now throws,
        // including one made by a code path nobody thought to fake. See
        // tests/Providers/NoLiveCallsTest.php.
        Http::preventStrayRequests();
    }

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            PandoraServiceProvider::class,
            LivewireServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $app['config']->set('auth.providers.users.model', TestUser::class);

        // Tests never call a paid API.
        $app['config']->set('pandora.providers.default', 'fake');
        $app['config']->set('pandora.models.default', 'fake-model');
        $app['config']->set('pandora.realtime.enabled', true);
        $app['config']->set('pandora.ui.enabled', true);
        $app['config']->set('pandora.routes.middleware', ['web']);

        // Flush immediately in tests so assertions do not have to wait out a
        // coalescing window that exists for production message volume.
        $app['config']->set('pandora.realtime.stream.flush_chars', 1);
        $app['config']->set('pandora.realtime.stream.flush_interval_ms', 0);
    }

    /**
     * The shared fake provider instance, so a test can script it and the run
     * under test resolves the same object.
     */
    protected function fakeProvider(): FakeProvider
    {
        /** @var FakeProvider $provider */
        $provider = $this->app->make(ProviderManager::class)->provider('fake');

        return $provider;
    }

    protected function actingAsUser(?TestUser $user = null): TestUser
    {
        $user ??= TestUser::create([
            'name' => 'Test User',
            'email' => 'user'.uniqid().'@example.test',
            'password' => 'secret',
        ]);

        $this->actingAs($user);

        return $user;
    }
}

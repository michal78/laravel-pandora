<?php

declare(strict_types=1);

namespace Pandora\Pandora;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Factory;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Pandora\Pandora\Agents\AgentRegistry;
use Pandora\Pandora\Agents\AgentRunner;
use Pandora\Pandora\Approvals\ApprovalManager;
use Pandora\Pandora\Audit\AuditLogger;
use Pandora\Pandora\Console\Commands\AgentListCommand;
use Pandora\Pandora\Console\Commands\AgentRunCommand;
use Pandora\Pandora\Console\Commands\InstallCommand;
use Pandora\Pandora\Console\Commands\ModelSyncCommand;
use Pandora\Pandora\Console\Commands\StatusCommand;
use Pandora\Pandora\Console\Commands\ToolListCommand;
use Pandora\Pandora\Context\ContextBuilder;
use Pandora\Pandora\Contracts\ActorResolver;
use Pandora\Pandora\Contracts\AgentDefinition;
use Pandora\Pandora\Contracts\ContextProvider;
use Pandora\Pandora\Contracts\CredentialResolver;
use Pandora\Pandora\Contracts\TenantResolver;
use Pandora\Pandora\Contracts\ToolPolicy;
use Pandora\Pandora\Conversations\ConversationManager;
use Pandora\Pandora\Conversations\SessionResolver;
use Pandora\Pandora\Core\Actor\ActorManager;
use Pandora\Pandora\Core\Actor\GuardActorResolver;
use Pandora\Pandora\Core\Tenancy\NullTenantResolver;
use Pandora\Pandora\Core\Tenancy\TenantManager;
use Pandora\Pandora\Messages\MessageWriter;
use Pandora\Pandora\Providers\Catalog\ModelCatalog;
use Pandora\Pandora\Providers\Credentials\CredentialManager;
use Pandora\Pandora\Providers\Credentials\DatabaseCredentialResolver;
use Pandora\Pandora\Providers\Health\ProviderHealthMonitor;
use Pandora\Pandora\Providers\ProviderManager;
use Pandora\Pandora\Realtime\RunBroadcaster;
use Pandora\Pandora\Runs\RunFactory;
use Pandora\Pandora\Runs\RunLock;
use Pandora\Pandora\Runs\RunStateMachine;
use Pandora\Pandora\Runs\RunStepRecorder;
use Pandora\Pandora\Support\CorrelationId;
use Pandora\Pandora\Support\Redactor;
use Pandora\Pandora\Tools\BuiltIn\BuiltInTools;
use Pandora\Pandora\Tools\Policies\RiskBasedToolPolicy;
use Pandora\Pandora\Tools\Schema\RuleSchemaGenerator;
use Pandora\Pandora\Tools\Tool;
use Pandora\Pandora\Tools\ToolCallCoordinator;
use Pandora\Pandora\Tools\ToolDiscovery;
use Pandora\Pandora\Tools\ToolGatekeeper;
use Pandora\Pandora\Tools\ToolRegistry;
use Pandora\Pandora\UI\Assets;
use Pandora\Pandora\UI\Http\AssetController;
use Pandora\Pandora\UI\Livewire\ApprovalsIndex;
use Pandora\Pandora\UI\Livewire\Chat;
use Pandora\Pandora\UI\Livewire\Dashboard;
use Pandora\Pandora\UI\Livewire\RunDetail;
use Pandora\Pandora\UI\Livewire\RunsIndex;
use Pandora\Pandora\UI\Livewire\ToolsIndex;
use Pandora\Pandora\UI\PandoraGate;

/**
 * Boots Pandora into a host application.
 *
 * Two installation modes are honoured here:
 *
 *  - Headless (the default beyond config): bindings, migrations, jobs, facade.
 *    No routes, no views, no Livewire.
 *  - Control center: additionally routes, views, Livewire components and
 *    broadcast channels.
 *
 * Nothing is forced on an application that has not opted in.
 */
final class PandoraServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/pandora.php', 'pandora');

        $this->registerSupport();
        $this->registerTenancy();
        $this->registerProviders();
        $this->registerAgents();
        $this->registerTools();
        $this->registerRuntime();

        $this->app->singleton(Pandora::class, static fn (Container $app): Pandora => new Pandora($app));
    }

    public function boot(): void
    {
        $this->registerPublishing();
        $this->registerCommands();
        $this->registerGates();
        $this->registerConfiguredAgents();
        $this->registerConfiguredTools();
        $this->registerRoutesAndUi();
    }

    // ---------------------------------------------------------------- register

    private function registerSupport(): void
    {
        $this->app->singleton(CorrelationId::class);

        $this->app->singleton(Redactor::class, static function (Container $app): Redactor {
            /** @var Config $config */
            $config = $app->make(Config::class);

            /** @var list<string> $keys */
            $keys = $config->get('pandora.security.redact_keys', []);
            /** @var string $placeholder */
            $placeholder = $config->get('pandora.security.redaction_placeholder', '[redacted]');

            return new Redactor($keys, $placeholder);
        });

        // The Pandora connection, which may differ from the application default.
        $this->app->bind('pandora.db', static function (Container $app): ConnectionInterface {
            /** @var Config $config */
            $config = $app->make(Config::class);
            /** @var string|null $connection */
            $connection = $config->get('pandora.database.connection');

            /** @var DatabaseManager $db */
            $db = $app->make(DatabaseManager::class);

            return $db->connection($connection);
        });
    }

    private function registerTenancy(): void
    {
        $this->app->singleton(TenantResolver::class, static function (Container $app): TenantResolver {
            /** @var Config $config */
            $config = $app->make(Config::class);
            /** @var class-string<TenantResolver> $class */
            $class = $config->get('pandora.tenancy.resolver', NullTenantResolver::class);

            return $app->make($class);
        });

        $this->app->singleton(TenantManager::class, static fn (Container $app): TenantManager => new TenantManager(
            $app->make(TenantResolver::class),
        ));

        $this->app->singleton(ActorResolver::class, static function (Container $app): ActorResolver {
            /** @var Config $config */
            $config = $app->make(Config::class);
            /** @var class-string<ActorResolver> $class */
            $class = $config->get('pandora.auth.actor_resolver', GuardActorResolver::class);

            if ($class === GuardActorResolver::class) {
                /** @var string|null $guard */
                $guard = $config->get('pandora.auth.guard');

                return new GuardActorResolver(
                    $app->make(Factory::class),
                    $guard,
                );
            }

            return $app->make($class);
        });

        $this->app->singleton(ActorManager::class, static fn (Container $app): ActorManager => new ActorManager(
            $app->make(ActorResolver::class),
        ));
    }

    private function registerProviders(): void
    {
        $this->app->singleton(CredentialResolver::class, static function (Container $app): CredentialResolver {
            /** @var class-string<CredentialResolver> $class */
            $class = $app->make(Config::class)->get(
                'pandora.providers.credentials.resolver',
                DatabaseCredentialResolver::class,
            );

            return $app->make($class);
        });

        $this->app->singleton(CredentialManager::class, static fn (Container $app): CredentialManager => new CredentialManager(
            $app->make(CredentialResolver::class),
            $app->make(TenantManager::class),
            $app->make(ActorManager::class),
            $app->make(AuditLogger::class),
            $app->make(Config::class),
        ));

        $this->app->singleton(ProviderHealthMonitor::class, static fn (Container $app): ProviderHealthMonitor => new ProviderHealthMonitor(
            $app->make(Config::class),
            $app->make(AuditLogger::class),
        ));

        $this->app->singleton(ModelCatalog::class, static fn (Container $app): ModelCatalog => new ModelCatalog(
            $app->make(Config::class),
        ));

        $this->app->singleton(ProviderManager::class, static function (Container $app): ProviderManager {
            /** @var Config $config */
            $config = $app->make(Config::class);

            /** @var array<string, array<string, mixed>> $connections */
            $connections = $config->get('pandora.providers.connections', []);
            /** @var string $default */
            $default = $config->get('pandora.providers.default', 'fake');

            return new ProviderManager(
                $app,
                $connections,
                $default,
                $app->make(CredentialManager::class),
            );
        });
    }

    private function registerAgents(): void
    {
        $this->app->singleton(AgentRegistry::class);

        $this->app->singleton(AgentRunner::class, static fn (Container $app): AgentRunner => new AgentRunner(
            $app,
            $app->make(AgentRegistry::class),
        ));
    }

    private function registerTools(): void
    {
        $this->app->singleton(RuleSchemaGenerator::class);

        // A singleton because registration is a boot-time act: a fresh
        // registry per resolution would mean a tool registered in boot() is
        // invisible to the run that needs it.
        $this->app->singleton(ToolRegistry::class, static fn (Container $app): ToolRegistry => new ToolRegistry(
            $app,
            $app->make(RuleSchemaGenerator::class),
        ));

        // An interface with a dull default: the interesting policy decisions
        // belong to the host, which binds its own.
        $this->app->singleton(ToolPolicy::class, static function (Container $app): ToolPolicy {
            /** @var class-string<ToolPolicy> $class */
            $class = $app->make(Config::class)->get('pandora.tools.policy', RiskBasedToolPolicy::class);

            return $app->make($class);
        });

        $this->app->singleton(ToolGatekeeper::class, static fn (Container $app): ToolGatekeeper => new ToolGatekeeper(
            $app->make(ToolRegistry::class),
            $app->make(ToolPolicy::class),
            $app->make(ValidationFactory::class),
            $app->make(Config::class),
        ));

        $this->app->singleton(ApprovalManager::class, static fn (Container $app): ApprovalManager => new ApprovalManager(
            $app->make('pandora.db'),
            $app->make(AuditLogger::class),
            $app->make(Config::class),
        ));

        $this->app->singleton(ToolCallCoordinator::class, static fn (Container $app): ToolCallCoordinator => new ToolCallCoordinator(
            $app->make(ToolRegistry::class),
            $app->make(ToolGatekeeper::class),
            $app->make(ApprovalManager::class),
            $app->make(RunStepRecorder::class),
            $app->make(AuditLogger::class),
            $app->make(Redactor::class),
            $app->make(MessageWriter::class),
            $app->make(RunBroadcaster::class),
        ));
    }

    private function registerRuntime(): void
    {
        $this->app->singleton(RunLock::class, static function (Container $app): RunLock {
            /** @var Config $config */
            $config = $app->make(Config::class);

            return new RunLock(
                $app->make(CacheFactory::class),
                $app->make('pandora.db'),
                (int) $config->get('pandora.runs.lock_ttl_seconds', 900),
            );
        });

        $this->app->singleton(ContextBuilder::class, static function (Container $app): ContextBuilder {
            /** @var Config $config */
            $config = $app->make(Config::class);
            /** @var list<class-string<ContextProvider>> $providers */
            $providers = $config->get('pandora.context.providers', []);

            return new ContextBuilder($app, $providers);
        });

        // Not a singleton: each run's writer owns a delta buffer, and sharing
        // one across concurrent runs in the same process would interleave them.
        $this->app->bind(MessageWriter::class, static function (Container $app): MessageWriter {
            /** @var Config $config */
            $config = $app->make(Config::class);

            return new MessageWriter(
                $app->make('pandora.db'),
                (int) $config->get('pandora.realtime.stream.flush_interval_ms', 80),
                (int) $config->get('pandora.realtime.stream.flush_chars', 256),
            );
        });

        // Bind the Pandora connection into the services that take one.
        foreach ([
            RunStateMachine::class,
            RunStepRecorder::class,
            RunFactory::class,
            ConversationManager::class,
            SessionResolver::class,
        ] as $class) {
            $this->app->when($class)
                ->needs(ConnectionInterface::class)
                ->give(static fn (Container $app): ConnectionInterface => $app->make('pandora.db'));
        }

        $this->app->singleton(AuditLogger::class, static fn (Container $app): AuditLogger => new AuditLogger(
            $app->make(TenantManager::class),
            $app->make(ActorManager::class),
            $app->make(CorrelationId::class),
            $app->make(Redactor::class),
            // Only when a request genuinely exists -- a worker has none, and
            // inventing values would make the audit log lie about origin.
            $app->runningInConsole() ? null : $app->make('request'),
        ));
    }

    // -------------------------------------------------------------------- boot

    private function registerPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/pandora.php' => config_path('pandora.php'),
        ], 'pandora-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'pandora-migrations');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/pandora'),
        ], 'pandora-views');

        // Brand assets. Publishing is an optimisation, not a requirement: the
        // control center serves the same files from the package when the host
        // has not published them.
        $this->publishes([
            Assets::directory() => public_path(Assets::PUBLIC_DIRECTORY),
        ], ['pandora-assets', 'laravel-assets']);
    }

    private function registerCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            InstallCommand::class,
            StatusCommand::class,
            AgentListCommand::class,
            AgentRunCommand::class,
            ToolListCommand::class,
            ModelSyncCommand::class,
        ]);
    }

    /**
     * Register a permissive default only where no gate already exists.
     *
     * The default grants `pandora.access` and `pandora.chat` to any
     * authenticated user, and DENIES every administrative ability. A host that
     * defines its own gate of the same name always wins -- we never override
     * an application's authorization decision.
     */
    private function registerGates(): void
    {
        /** @var Gate $gate */
        $gate = $this->app->make(Gate::class);

        /** @var array<string, string> $abilities */
        $abilities = $this->app->make(Config::class)->get('pandora.abilities', []);

        $permissive = ['access', 'chat'];

        foreach ($abilities as $key => $ability) {
            if ($gate->has($ability)) {
                continue;
            }

            $allow = in_array($key, $permissive, true);

            $gate->define($ability, static fn (mixed $user = null): bool => $allow && $user !== null);
        }

        PandoraGate::useAbilities($abilities);
    }

    /**
     * Register the tools this deployment has installed.
     *
     * Deliberately in boot() rather than register(): discovery touches the
     * filesystem, and schema generation runs the validator, neither of which
     * belongs in a container binding.
     */
    private function registerConfiguredTools(): void
    {
        /** @var Config $config */
        $config = $this->app->make(Config::class);

        /** @var list<class-string<Tool>> $tools */
        $tools = $config->get('pandora.tools.registered', []);

        // Registered, not granted: an agent still has to name each one.
        if ($config->get('pandora.tools.builtin.enabled', true) === true) {
            $tools = [...BuiltInTools::all(), ...$tools];
        }

        if ($config->get('pandora.tools.discovery.enabled') === true) {
            /** @var string $path */
            $path = $config->get('pandora.tools.discovery.path', '');
            $tools = [...$tools, ...ToolDiscovery::in($path)];
        }

        if ($tools === []) {
            return;
        }

        $this->app->make(ToolRegistry::class)->registerMany($tools);
    }

    private function registerConfiguredAgents(): void
    {
        /** @var list<class-string<AgentDefinition>> $definitions */
        $definitions = $this->app->make(Config::class)->get('pandora.agents.definitions', []);

        if ($definitions === []) {
            return;
        }

        // Registration only -- the database sync happens lazily on first use,
        // so booting Pandora never issues a query on requests that never
        // touch it.
        $this->app->make(AgentRegistry::class)->defineMany($definitions);
    }

    private function registerRoutesAndUi(): void
    {
        /** @var Config $config */
        $config = $this->app->make(Config::class);

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'pandora');

        if ($config->get('pandora.realtime.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/channels.php');
        }

        if (! $config->get('pandora.routes.enabled', true) || ! $config->get('pandora.ui.enabled', true)) {
            return;
        }

        if (! class_exists(Livewire::class)) {
            return;
        }

        $this->registerLivewireComponents();

        /** @var array<int, string> $middleware */
        $middleware = $config->get('pandora.routes.middleware', ['web']);
        /** @var string $prefix */
        $prefix = $config->get('pandora.routes.prefix', 'pandora');
        /** @var string|null $domain */
        $domain = $config->get('pandora.routes.domain');

        // Brand assets sit outside the control center's middleware stack on
        // purpose. They are public files with no application data in them, and
        // a favicon has to resolve on screens the user is not signed in to.
        Route::group(array_filter([
            'prefix' => $prefix,
            'middleware' => ['web'],
            'domain' => $domain,
            'as' => 'pandora.',
        ]), static function (): void {
            Route::get('assets/{path}', AssetController::class)
                ->where('path', '.*')
                ->name('asset');
        });

        Route::group(array_filter([
            'prefix' => $prefix,
            'middleware' => $middleware,
            'domain' => $domain,
            'as' => 'pandora.',
        ]), function (): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        });
    }

    private function registerLivewireComponents(): void
    {
        Livewire::component('pandora.chat', Chat::class);
        Livewire::component('pandora.dashboard', Dashboard::class);
        Livewire::component('pandora.run-detail', RunDetail::class);
        Livewire::component('pandora.runs-index', RunsIndex::class);
        Livewire::component('pandora.tools-index', ToolsIndex::class);
        Livewire::component('pandora.approvals-index', ApprovalsIndex::class);
    }
}

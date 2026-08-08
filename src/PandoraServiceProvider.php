<?php

declare(strict_types=1);

namespace Pandora;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Factory;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Pandora\Agents\AgentRegistry;
use Pandora\Agents\AgentRunner;
use Pandora\Approvals\ApprovalManager;
use Pandora\Audit\AuditLogger;
use Pandora\Automation\Automation;
use Pandora\Automation\AutomationDispatcher;
use Pandora\Automation\AutomationScheduler;
use Pandora\Automation\AutonomyBudget;
use Pandora\Automation\ConditionRegistry;
use Pandora\Automation\EventTriggerRegistry;
use Pandora\Automation\ObservationManager;
use Pandora\Automation\Schedule\NextRun;
use Pandora\Automation\Webhooks\WebhookReceiver;
use Pandora\Console\Commands\AgentListCommand;
use Pandora\Console\Commands\AgentRunCommand;
use Pandora\Console\Commands\AutomationListCommand;
use Pandora\Console\Commands\AutomationRunCommand;
use Pandora\Console\Commands\AutomationTickCommand;
use Pandora\Console\Commands\FlushCommand;
use Pandora\Console\Commands\InstallCommand;
use Pandora\Console\Commands\MemoryExportCommand;
use Pandora\Console\Commands\MemoryForgetCommand;
use Pandora\Console\Commands\MemorySweepCommand;
use Pandora\Console\Commands\ModelSyncCommand;
use Pandora\Console\Commands\ProviderTestCommand;
use Pandora\Console\Commands\StatusCommand;
use Pandora\Console\Commands\ToolListCommand;
use Pandora\Context\ContextBuilder;
use Pandora\Context\Providers\RunToolLoopProvider;
use Pandora\Contracts\ActorResolver;
use Pandora\Contracts\AgentDefinition;
use Pandora\Contracts\ContextProvider;
use Pandora\Contracts\CredentialResolver;
use Pandora\Contracts\EmbeddingProvider;
use Pandora\Contracts\ModelRouter;
use Pandora\Contracts\TenantResolver;
use Pandora\Contracts\ToolPolicy;
use Pandora\Contracts\VectorStore;
use Pandora\Conversations\ConversationManager;
use Pandora\Conversations\SessionResolver;
use Pandora\Core\Actor\ActorManager;
use Pandora\Core\Actor\GuardActorResolver;
use Pandora\Core\Tenancy\NullTenantResolver;
use Pandora\Core\Tenancy\TenantManager;
use Pandora\Delegation\DelegationCompleter;
use Pandora\Memory\Embeddings\HashEmbeddingProvider;
use Pandora\Memory\Embeddings\MemoryEmbedder;
use Pandora\Memory\MemoryCurator;
use Pandora\Memory\MemoryRetriever;
use Pandora\Memory\MemoryWriter;
use Pandora\Memory\SensitivityClassifier;
use Pandora\Memory\Vector\DatabaseVectorStore;
use Pandora\Memory\Vector\PgvectorStore;
use Pandora\Messages\MessageWriter;
use Pandora\Providers\Catalog\ModelCatalog;
use Pandora\Providers\Credentials\CredentialManager;
use Pandora\Providers\Credentials\DatabaseCredentialResolver;
use Pandora\Providers\Health\ProviderHealthMonitor;
use Pandora\Providers\ProviderManager;
use Pandora\Providers\Routing\DeterministicModelRouter;
use Pandora\Realtime\RunBroadcaster;
use Pandora\Runs\Events\RunStateChanged;
use Pandora\Runs\RunCanceller;
use Pandora\Runs\RunFactory;
use Pandora\Runs\RunLock;
use Pandora\Runs\RunStateMachine;
use Pandora\Runs\RunStepRecorder;
use Pandora\Support\CorrelationId;
use Pandora\Support\Redactor;
use Pandora\Tools\BuiltIn\BuiltInTools;
use Pandora\Tools\Policies\RiskBasedToolPolicy;
use Pandora\Tools\Schema\RuleSchemaGenerator;
use Pandora\Tools\Tool;
use Pandora\Tools\ToolCallCoordinator;
use Pandora\Tools\ToolDiscovery;
use Pandora\Tools\ToolGatekeeper;
use Pandora\Tools\ToolRegistry;
use Pandora\UI\Assets;
use Pandora\UI\Http\AssetController;
use Pandora\UI\Livewire\ApprovalsIndex;
use Pandora\UI\Livewire\Chat;
use Pandora\UI\Livewire\Dashboard;
use Pandora\UI\Livewire\MemoryIndex;
use Pandora\UI\Livewire\ProvidersIndex;
use Pandora\UI\Livewire\RunDetail;
use Pandora\UI\Livewire\RunsIndex;
use Pandora\UI\Livewire\ToolsIndex;
use Pandora\UI\Livewire\UsageIndex;
use Pandora\UI\Livewire\WorkspacesIndex;
use Pandora\UI\PandoraGate;
use Pandora\Usage\BudgetGuard;
use Pandora\Usage\UsageRecorder;

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
        $this->registerRouting();
        $this->registerAgents();
        $this->registerTools();
        $this->registerAutomation();
        $this->registerMemory();
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
        $this->registerAutomationSchedule();
        $this->registerAutomationTriggers();
        $this->registerDelegation();
        $this->registerRoutesAndUi();
    }

    /**
     * The listener that hands a finished child run back to its parent.
     *
     * Unconditional, unlike the automation listeners: a parent waiting on a
     * child is not an optional feature that a configuration flag could turn
     * off, it is the second half of a tool call that has already happened. A
     * deployment that disabled this would not be disabling delegation -- it
     * would be leaving parents parked forever.
     */
    private function registerDelegation(): void
    {
        /** @var Dispatcher $events */
        $events = $this->app->make(Dispatcher::class);

        $events->listen(RunStateChanged::class, [DelegationCompleter::class, 'handle']);
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

    private function registerRouting(): void
    {
        $this->app->singleton(ModelRouter::class, static function (Container $app): ModelRouter {
            /** @var class-string<ModelRouter> $class */
            $class = $app->make(Config::class)->get(
                'pandora.providers.router',
                DeterministicModelRouter::class,
            );

            return $app->make($class);
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

    private function registerAutomation(): void
    {
        $this->app->singleton(NextRun::class);

        $this->app->singleton(ConditionRegistry::class, static fn (Container $app): ConditionRegistry => new ConditionRegistry(
            $app,
            $app->make(Config::class),
        ));

        $this->app->singleton(AutonomyBudget::class, static fn (Container $app): AutonomyBudget => new AutonomyBudget(
            $app->make(Config::class),
            $app->make(AuditLogger::class),
            $app,
        ));

        $this->app->singleton(AutomationScheduler::class, static fn (Container $app): AutomationScheduler => new AutomationScheduler(
            $app->make(NextRun::class),
            $app->make(Config::class),
        ));

        $this->app->singleton(AutomationDispatcher::class, static fn (Container $app): AutomationDispatcher => new AutomationDispatcher(
            $app->make(AgentRunner::class),
            $app->make(ConditionRegistry::class),
            $app->make(AutonomyBudget::class),
            $app->make(AuditLogger::class),
            $app->make(TenantManager::class),
            $app->make(RunCanceller::class),
        ));

        $this->app->singleton(WebhookReceiver::class, static fn (Container $app): WebhookReceiver => new WebhookReceiver(
            $app->make(AutomationDispatcher::class),
            $app->make(AuditLogger::class),
            $app->make(Redactor::class),
            $app->make(Config::class),
        ));

        $this->app->singleton(ObservationManager::class, static fn (Container $app): ObservationManager => new ObservationManager(
            $app->make(AuditLogger::class),
            $app->make(ActorManager::class),
            $app->make(Config::class),
        ));

        // A singleton because `Pandora::on()` bindings are declared in a host's
        // boot(): a fresh registry per resolution would mean a binding
        // registered at boot is invisible to the event that needs it.
        $this->app->singleton(EventTriggerRegistry::class, static fn (Container $app): EventTriggerRegistry => new EventTriggerRegistry($app));
    }

    /**
     * Memory retrieval, embeddings and the optional vector store.
     *
     * The store is resolved lazily and may legitimately be null: a default
     * install has no vector database, and that is a supported production
     * configuration rather than a degraded one. `MemoryRetriever` works with
     * both halves absent.
     */
    private function registerMemory(): void
    {
        $this->app->singleton(EmbeddingProvider::class, static function (Container $app): EmbeddingProvider {
            /** @var Config $config */
            $config = $app->make(Config::class);

            /** @var class-string<EmbeddingProvider> $class */
            $class = $config->get('pandora.memory.embeddings.provider', HashEmbeddingProvider::class);

            return $app->make($class);
        });

        $this->app->singleton(HashEmbeddingProvider::class, static function (Container $app): HashEmbeddingProvider {
            /** @var Config $config */
            $config = $app->make(Config::class);
            /** @var int $dimensions */
            $dimensions = $config->get('pandora.memory.embeddings.dimensions', 256);

            return new HashEmbeddingProvider($dimensions);
        });

        // Bound through one factory so the retriever, the embedder and any
        // host code all agree on which store is in play. It legitimately
        // resolves to null: a default install has no vector database, and
        // that is a supported production configuration.
        $this->app->singleton(VectorStore::class, static fn (Container $app): ?VectorStore => self::makeVectorStore($app));

        $this->app->singleton(MemoryRetriever::class, static fn (Container $app): MemoryRetriever => new MemoryRetriever(
            $app->make(EmbeddingProvider::class),
            self::makeVectorStore($app),
            $app->make(AuditLogger::class),
        ));

        $this->app->singleton(SensitivityClassifier::class);

        $this->app->singleton(MemoryWriter::class, static fn (Container $app): MemoryWriter => new MemoryWriter(
            $app->make(Redactor::class),
            $app->make(AuditLogger::class),
            $app->make(SensitivityClassifier::class),
        ));

        $this->app->singleton(MemoryCurator::class, static fn (Container $app): MemoryCurator => new MemoryCurator(
            $app->make(AuditLogger::class),
            $app->make(MemoryEmbedder::class),
        ));

        $this->app->singleton(MemoryEmbedder::class, static fn (Container $app): MemoryEmbedder => new MemoryEmbedder(
            $app->make(EmbeddingProvider::class),
            // The embedder always has somewhere to write. With no accelerator
            // configured that is the portable column, which the database store
            // reads directly -- so embeddings are never orphaned by the
            // absence of an index.
            self::makeVectorStore($app) ?? new DatabaseVectorStore,
        ));
    }

    /**
     * Build the configured vector store, or null when there is none.
     *
     * A separate method rather than a closure so its nullable return type is
     * visible to static analysis at every call site -- the container erases
     * that, and "may be null" is the whole point of this one.
     */
    private static function makeVectorStore(Container $app): ?VectorStore
    {
        /** @var Config $config */
        $config = $app->make(Config::class);

        /** @var string|null $key */
        $key = $config->get('pandora.memory.vector_store');

        if ($key === null || $key === '') {
            return null;
        }

        /** @var array<string, mixed> $store */
        $store = $config->get('pandora.memory.stores.'.$key, []);
        /** @var string $driver */
        $driver = $store['driver'] ?? $key;

        /** @var string $prefix */
        $prefix = $config->get('pandora.database.table_prefix', 'pandora_');

        return match ($driver) {
            'pgvector' => new PgvectorStore(self::pandoraConnection($app), $prefix.'embeddings'),
            'database' => new DatabaseVectorStore(
                is_int($store['scan_limit'] ?? null) ? $store['scan_limit'] : 5000,
            ),
            // An unrecognised name is null rather than an exception: a typo in
            // configuration should cost recall, not availability.
            default => null,
        };
    }

    private static function pandoraConnection(Container $app): Connection
    {
        /** @var Connection $connection */
        $connection = $app->make('pandora.db');

        return $connection;
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

            // A run's memory of its own tool loop is not a preference, so this
            // one provider is appended when the configured list omits it. The
            // list is otherwise honoured exactly as written, order included.
            //
            // The case this exists for is an upgrade: a host that published
            // `config/pandora.php` before this provider existed keeps a list
            // that predates it, and the symptom is not an error but a run
            // repeating one tool call until its budget dies. Nothing in a log
            // says why. An operator who genuinely wants it gone can remove it
            // from the container binding; leaving it out of a config file is
            // not a way to ask for that.
            if (! in_array(RunToolLoopProvider::class, $providers, true)) {
                $providers[] = RunToolLoopProvider::class;
            }

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
        // The flush command needs the concrete Connection: schema inspection
        // is not on the interface.
        $this->app->when(FlushCommand::class)
            ->needs(Connection::class)
            ->give(static fn (Container $app): Connection => $app->make('pandora.db'));

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

        $this->app->singleton(UsageRecorder::class, static fn (Container $app): UsageRecorder => new UsageRecorder(
            $app->make(ModelCatalog::class),
            $app->make(ActorManager::class),
        ));

        $this->app->singleton(BudgetGuard::class, static fn (Container $app): BudgetGuard => new BudgetGuard(
            $app->make(Config::class),
            $app->make(AuditLogger::class),
        ));

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

        // `publishesMigrations`, not `publishes`: Laravel rewrites the filename
        // prefix to the moment of publishing. The packaged files are named
        // `0001_01_01_*` so they sort among themselves, and a host that
        // received those names verbatim could never order its own migrations
        // relative to Pandora's -- everything it wrote would run afterwards,
        // whatever it was called.
        $this->publishesMigrations([
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
            AutomationListCommand::class,
            AutomationRunCommand::class,
            AutomationTickCommand::class,
            ToolListCommand::class,
            ModelSyncCommand::class,
            ProviderTestCommand::class,
            FlushCommand::class,
            MemoryForgetCommand::class,
            MemoryExportCommand::class,
            MemorySweepCommand::class,
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

        // Deny-by-default for everything administrative. `usage.view` is the
        // exception: knowing how many tokens the application spent is not an
        // administrative act and cannot cause harm, whereas knowing what they
        // COST is commercially sensitive -- so `costs.view` stays denied.
        // Without this, a fresh installation shows a page every user is
        // refused, which teaches people to ignore authorization errors.
        $permissive = ['access', 'chat', 'usage.view'];

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

    /**
     * The one scheduler entry that drives every automation.
     *
     * Registered here rather than documented as something a host must add to
     * its own Kernel: an installation step that is easy to skip and produces
     * no error when skipped is an installation step that will be skipped, and
     * the symptom -- automations that simply never run -- looks like a bug in
     * Pandora rather than a missing line in the host.
     */
    private function registerAutomationSchedule(): void
    {
        /** @var Config $config */
        $config = $this->app->make(Config::class);

        if ($config->get('pandora.automation.enabled', true) !== true
            || $config->get('pandora.automation.schedule.enabled', true) !== true) {
            return;
        }

        $this->app->booted(static function (Container $app): void {
            if (! $app->bound(Schedule::class)) {
                return;
            }

            $app->make(Schedule::class)
                ->command(AutomationTickCommand::class)
                ->everyMinute()
                // Overlap protection is belt-and-braces: the occurrence claim
                // already makes a double tick harmless. This just stops a
                // pathologically slow tick from stacking.
                ->withoutOverlapping()
                ->runInBackground();
        });
    }

    /**
     * Attach event listeners and the webhook route.
     *
     * Listeners are attached only for classes some binding names, so an
     * application with no event automations pays nothing. The alternative --
     * a wildcard listener -- would be a tax on every event the host
     * dispatches, forever.
     */
    private function registerAutomationTriggers(): void
    {
        /** @var Config $config */
        $config = $this->app->make(Config::class);

        if ($config->get('pandora.automation.enabled', true) !== true) {
            return;
        }

        // In booted() so a host's own boot() has already declared its
        // `Pandora::on()` bindings. Boot order is not something a package gets
        // to insist on, so late additions re-attach themselves.
        $this->app->booted(static function (Container $app): void {
            $app->make(EventTriggerRegistry::class)->listen();
        });

        // Keep the cached event-class list honest. An operator who adds an
        // event automation and finds it never fires would reasonably conclude
        // the feature is broken.
        Automation::saved(static function (Automation $automation): void {
            app(EventTriggerRegistry::class)->flush();
        });

        Automation::deleted(static function (Automation $automation): void {
            app(EventTriggerRegistry::class)->flush();
        });

        if ($config->get('pandora.automation.webhooks.enabled', true) !== true
            || $config->get('pandora.routes.enabled', true) !== true) {
            return;
        }

        /** @var string $prefix */
        $prefix = $config->get('pandora.routes.prefix', 'pandora');
        /** @var string $path */
        $path = $config->get('pandora.automation.webhooks.path', 'webhooks');
        /** @var array<int, string> $middleware */
        $middleware = $config->get('pandora.automation.webhooks.middleware', []);
        /** @var string|null $domain */
        $domain = $config->get('pandora.routes.domain');

        Route::group(array_filter([
            'prefix' => $prefix.'/'.$path,
            'middleware' => $middleware,
            'domain' => $domain,
            'as' => 'pandora.webhooks.',
        ]), function (): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/webhooks.php');
        });
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
        Livewire::component('pandora.providers-index', ProvidersIndex::class);
        Livewire::component('pandora.usage-index', UsageIndex::class);
        Livewire::component('pandora.memory-index', MemoryIndex::class);
        Livewire::component('pandora.workspaces-index', WorkspacesIndex::class);
    }
}

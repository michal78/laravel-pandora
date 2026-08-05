<?php

declare(strict_types=1);
use Pandora\Pandora\Context\Providers\EnvironmentContextProvider;
use Pandora\Pandora\Context\Providers\RecentMessagesProvider;
use Pandora\Pandora\Context\Providers\SystemInstructionsProvider;
use Pandora\Pandora\Core\Actor\GuardActorResolver;
use Pandora\Pandora\Core\Tenancy\NullTenantResolver;
use Pandora\Pandora\Tools\Policies\RiskBasedToolPolicy;

/*
|--------------------------------------------------------------------------
| Pandora configuration
|--------------------------------------------------------------------------
|
| This file holds DEPLOYMENT configuration -- values that belong in version
| control and should change only through a code review and a deploy.
|
| Runtime settings an operator may need to change without a deploy (enabling
| an agent, adjusting a budget) live in the `pandora_settings` table and are
| edited in the control center. See docs/adr/0010-config-vs-database-settings.md
| for where the line is drawn and why.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Routes and UI
    |--------------------------------------------------------------------------
    |
    | Pandora installs in two modes. Headless (the default) registers no routes,
    | views or Livewire components -- you use agents, tools and jobs from your
    | own code. Enabling `ui` adds the control center.
    |
    */

    'routes' => [
        'enabled' => env('PANDORA_ROUTES_ENABLED', true),
        'prefix' => env('PANDORA_ROUTE_PREFIX', 'pandora'),
        'domain' => env('PANDORA_ROUTE_DOMAIN'),
        'middleware' => ['web', 'auth'],
    ],

    'ui' => [
        'enabled' => env('PANDORA_UI_ENABLED', true),
        'brand' => env('PANDORA_UI_BRAND', 'Pandora'),
        'theme' => env('PANDORA_UI_THEME', 'system'), // light | dark | system
    ],

    'api' => [
        'enabled' => env('PANDORA_API_ENABLED', false),
        'prefix' => env('PANDORA_API_PREFIX', 'api/pandora/v1'),
        'middleware' => ['api'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication and authorization
    |--------------------------------------------------------------------------
    |
    | Pandora ships no authentication. It integrates with your application's
    | existing guards and user model.
    |
    | Never assume an authenticated user is an administrator: each ability below
    | is granted independently. Bind your own callbacks in a service provider, or
    | define gates of the same name.
    |
    */

    'auth' => [
        'guard' => env('PANDORA_GUARD'), // null = the application default
        'actor_resolver' => GuardActorResolver::class,
    ],

    'abilities' => [
        'access' => 'pandora.access',
        'chat' => 'pandora.chat',
        'prompts.view' => 'pandora.prompts.view',
        'tools.io.view' => 'pandora.tools.io.view',
        'approvals.resolve' => 'pandora.approvals.resolve',
        'agents.manage' => 'pandora.agents.manage',
        'tools.manage' => 'pandora.tools.manage',
        'providers.manage' => 'pandora.providers.manage',
        'automations.manage' => 'pandora.automations.manage',
        'memory.manage' => 'pandora.memory.manage',
        'workspaces.access' => 'pandora.workspaces.access',
        'usage.view' => 'pandora.usage.view',
        'costs.view' => 'pandora.costs.view',
        'audit.view' => 'pandora.audit.view',
        'settings.manage' => 'pandora.settings.manage',
        'runs.trace.view' => 'pandora.runs.trace.view',
        'mcp.manage' => 'pandora.mcp.manage',
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenancy
    |--------------------------------------------------------------------------
    |
    | Pandora bundles no tenancy package. Single-tenant applications need to
    | change nothing: the null resolver makes every tenant scope a no-op.
    |
    | Multi-tenant applications bind their own resolver returning the current
    | TenantContext. Every Pandora table carries a nullable `tenant_id`.
    |
    */

    'tenancy' => [
        'enabled' => env('PANDORA_TENANCY_ENABLED', false),
        'resolver' => NullTenantResolver::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Database
    |--------------------------------------------------------------------------
    */

    'database' => [
        'connection' => env('PANDORA_DB_CONNECTION'), // null = application default
        'table_prefix' => env('PANDORA_TABLE_PREFIX', 'pandora_'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    |
    | Credentials belong in the environment, never in this file. Pandora reads
    | them here only so a deployment can wire a provider without a database row;
    | per-tenant and per-agent credentials (Phase 3) are stored encrypted.
    |
    */

    'providers' => [

        'default' => env('PANDORA_PROVIDER', 'fake'),

        'connections' => [

            'fake' => [
                'adapter' => 'fake',

                // Milliseconds between streamed chunks. Zero is instant, which
                // is what tests want; a small value makes streaming visible in
                // the browser before any provider credentials exist.
                'chunk_delay_ms' => (int) env('PANDORA_FAKE_CHUNK_DELAY_MS', 0),
            ],

            'openai' => [
                'adapter' => 'openai-compatible',
                'base_url' => env('PANDORA_OPENAI_BASE_URL', 'https://api.openai.com/v1'),
                'api_key' => env('PANDORA_OPENAI_API_KEY'),
                'organization' => env('PANDORA_OPENAI_ORGANIZATION'),
                'timeout' => 120,
                'connect_timeout' => 10,
            ],

            'ollama' => [
                'adapter' => 'openai-compatible',
                'base_url' => env('PANDORA_OLLAMA_BASE_URL', 'http://localhost:11434/v1'),
                'api_key' => env('PANDORA_OLLAMA_API_KEY', 'ollama'),
                'timeout' => 300,
                'connect_timeout' => 10,
            ],

            'openrouter' => [
                'adapter' => 'openai-compatible',
                'base_url' => env('PANDORA_OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
                'api_key' => env('PANDORA_OPENROUTER_API_KEY'),
                'timeout' => 120,
                'connect_timeout' => 10,
            ],
        ],
    ],

    'models' => [
        'default' => env('PANDORA_MODEL', 'fake-model'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Agents
    |--------------------------------------------------------------------------
    |
    | `definitions` lists AgentDefinition classes. Class definitions are
    | authoritative: they sync into the database on boot and win over
    | database-only edits to the same fields.
    |
    | The installer deliberately creates no default agent.
    |
    */

    'agents' => [

        'definitions' => [
            // App\Agents\SupportAgent::class,
        ],

        'discovery' => [
            'enabled' => env('PANDORA_AGENT_DISCOVERY', false),
            'path' => app_path('Agents'),
        ],

        'defaults' => [
            'max_iterations' => 12,
            'max_tool_calls' => 30,
            'max_duration_seconds' => 600,
            'context_budget_tokens' => 24000,
            'autonomy_level' => 'suggest',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tools
    |--------------------------------------------------------------------------
    |
    | A tool is where an agent touches your application, so registration is
    | deployment configuration: tools come from this file or from a discovery
    | path, never from the database and never from a model.
    |
    | Registration does not grant anything. A tool listed here is merely
    | INSTALLED; an agent still needs it in its allowlist, the tenant must
    | permit it, the policy must allow it, and the tool's own authorize() must
    | pass against the acting user. See docs/architecture/security-model.md.
    |
    */

    'tools' => [

        'registered' => [
            // App\Tools\LookupOrder::class,
        ],

        'discovery' => [
            'enabled' => env('PANDORA_TOOL_DISCOVERY', false),
            'path' => app_path('Tools'),
        ],

        // Authorization layer 4. Bind your own to express the decisions that
        // matter to your application -- clamp a refund, force a tenant filter,
        // refuse a tool outside business hours. The default reads each agent's
        // `approval_policy` and otherwise raises no objection.
        'policy' => RiskBasedToolPolicy::class,

        // Built-in tools. Each is low risk by construction; they are listed
        // separately so a deployment can drop the lot with one line.
        'builtin' => [
            'enabled' => env('PANDORA_BUILTIN_TOOLS', true),
        ],

        // Tools available to every agent without being named in its allowlist.
        // Deliberately empty: implicit access is how a support agent ends up
        // with a shell nobody remembers granting.
        'always_available' => [],

        // Per-tenant restrictions -- authorization layer 3. Keyed by tenant id;
        // a tenant absent from this list is unrestricted, a tenant present may
        // use only what it names.
        'tenants' => [
            // 'tenant-id' => ['allow' => ['lookup_order'], 'deny' => []],
        ],

        // How many identical calls a run may make before the duplicate guard
        // refuses. Catches the loop where a model re-asks the same question
        // because it did not like the answer.
        'duplicate_threshold' => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | Approvals
    |--------------------------------------------------------------------------
    |
    | High and critical risk tools pause the run for a human decision. The run
    | holds no worker while it waits, so a pause costs nothing and an expiry
    | window can be generous.
    |
    */

    'approvals' => [
        'expires_after_minutes' => 60 * 24,

        // Risk levels requiring approval regardless of any policy that would
        // otherwise allow them. A policy may demand MORE than this; nothing
        // short of an explicit `allow` outcome may demand less.
        'required_for' => ['high', 'critical'],

        // Whether `remembered` scope is offered at all. Remembering an
        // approval trades safety for convenience; some deployments should not
        // have the option.
        'allow_remembered' => env('PANDORA_ALLOW_REMEMBERED_APPROVALS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Context
    |--------------------------------------------------------------------------
    |
    | The ordered pipeline assembling each model request. Providers run in the
    | order listed and share the agent's context token budget; when the budget
    | is exhausted the remaining providers are dropped and the omission is
    | recorded on the run trace rather than silently swallowed.
    |
    */

    'context' => [
        'providers' => [
            SystemInstructionsProvider::class,
            EnvironmentContextProvider::class,
            RecentMessagesProvider::class,
        ],

        'recent_messages' => [
            'limit' => 40,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queues
    |--------------------------------------------------------------------------
    |
    | Defaults collapse onto your application's default queue, so a plain
    | `php artisan queue:work` runs everything. Split them when you want
    | interactive work to overtake batch work.
    |
    */

    'queues' => [
        'connection' => env('PANDORA_QUEUE_CONNECTION'), // null = application default
        'interactive' => env('PANDORA_QUEUE_INTERACTIVE', null),
        'agents' => env('PANDORA_QUEUE_AGENTS', null),
        'tools' => env('PANDORA_QUEUE_TOOLS', null),
        'automation' => env('PANDORA_QUEUE_AUTOMATION', null),
        'memory' => env('PANDORA_QUEUE_MEMORY', null),
        'maintenance' => env('PANDORA_QUEUE_MAINTENANCE', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Realtime
    |--------------------------------------------------------------------------
    |
    | Reverb is optional. The database is authoritative and broadcasts are only
    | notifications, so disabling this leaves the UI correct -- it simply polls
    | instead. See docs/architecture/realtime-model.md.
    |
    */

    'realtime' => [
        'enabled' => env('PANDORA_REALTIME_ENABLED', true),
        'connection' => env('PANDORA_BROADCAST_CONNECTION'), // null = application default
        'channel_prefix' => 'pandora',

        // Delta coalescing. Broadcasting per token floods Reverb; these
        // thresholds keep typing continuous at ~1% of the message volume.
        'stream' => [
            'flush_interval_ms' => 80,
            'flush_chars' => 256,
        ],

        // How long the UI waits without a broadcast before polling as a
        // safety net (also the polling interval when realtime is disabled).
        'poll_interval_ms' => 2500,
    ],

    /*
    |--------------------------------------------------------------------------
    | Runs
    |--------------------------------------------------------------------------
    */

    'runs' => [
        // Lock TTL must exceed the per-iteration timeout so a lock can never
        // expire while a worker is still legitimately holding it.
        'lock_ttl_seconds' => 900,
        'iteration_timeout_seconds' => 300,
        'stalled_after_seconds' => 1800,
        'tries' => 3,
        'backoff' => [5, 15, 60],
    ],

    /*
    |--------------------------------------------------------------------------
    | Security
    |--------------------------------------------------------------------------
    |
    | Keys whose values are redacted from logs, run traces, broadcasts and API
    | responses. Matching is case-insensitive substring against the key name.
    |
    */

    'security' => [
        'redact_keys' => [
            'password', 'secret', 'token', 'api_key', 'apikey', 'authorization',
            'credential', 'private_key', 'access_key', 'refresh_token', 'bearer',
            'session', 'cookie', 'signature', 'passphrase', 'pin', 'cvv',
        ],
        'redaction_placeholder' => '[redacted]',

        // Show internal exception messages to authorized administrators.
        // Never enable in production without understanding what it exposes.
        'expose_internal_errors' => env('PANDORA_EXPOSE_INTERNAL_ERRORS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Days to keep each record type. `null` disables pruning for that type.
    | Audit logs are deliberately long-lived and never pruned below this floor.
    |
    */

    'retention' => [
        'run_steps' => 90,
        'runs' => 365,
        'usage_records' => 730,
        'audit_logs' => 730,
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    */

    'logging' => [
        'channel' => env('PANDORA_LOG_CHANNEL'), // null = application default
    ],
];

<?php

declare(strict_types=1);
use Pandora\Automation\Notifications\AutomationDisabled;
use Pandora\Context\Providers\ContextFilesProvider;
use Pandora\Context\Providers\EnvironmentContextProvider;
use Pandora\Context\Providers\MemoryContextProvider;
use Pandora\Context\Providers\RecentMessagesProvider;
use Pandora\Context\Providers\RunToolLoopProvider;
use Pandora\Context\Providers\SystemInstructionsProvider;
use Pandora\Core\Actor\GuardActorResolver;
use Pandora\Core\Tenancy\NullTenantResolver;
use Pandora\Memory\Embeddings\HashEmbeddingProvider;
use Pandora\Providers\Credentials\DatabaseCredentialResolver;
use Pandora\Providers\Routing\DeterministicModelRouter;
use Pandora\Tools\Policies\RiskBasedToolPolicy;

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
    | Features
    |--------------------------------------------------------------------------
    |
    | Surfaces that are built but not yet released. The engine behind a disabled
    | feature stays present and stays tested -- what a flag withdraws is the way
    | in, not the code -- so turning one on is a decision rather than a port.
    |
    | 'workspaces' holds back the agent file workspace UI. Released in Phase 7
    | and on by default; set it false to withdraw the surface again, which
    | withdraws it from everybody -- an operator holding every ability included,
    | because this is not a question about who is asking.
    |
    */

    'features' => [
        'workspaces' => env('PANDORA_FEATURE_WORKSPACES', true),
        'channels' => env('PANDORA_FEATURE_CHANNELS', true),
        'extensions' => env('PANDORA_FEATURE_EXTENSIONS', true),
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
        'channels.view' => 'pandora.channels.view',
        'channels.manage' => 'pandora.channels.manage',
        'extensions.view' => 'pandora.extensions.view',
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

        /*
        | Credential resolution, in order: per-agent, per-tenant, deployment,
        | this file, the environment. Stored credentials are encrypted with the
        | application key and are never readable through the control center.
        |
        | Bind your own resolver to read from a secrets manager instead.
        */
        'credentials' => [
            'resolver' => DatabaseCredentialResolver::class,
            'database' => env('PANDORA_DB_CREDENTIALS', true),

            // How long a superseded credential keeps working after a rotation.
            // Zero would fail every request already in flight.
            'rotation_grace_minutes' => (int) env('PANDORA_CREDENTIAL_GRACE_MINUTES', 60),
        ],

        /*
        | Health probes. `ProbeProviderHealth` runs on the maintenance queue
        | and records reachability and latency; the router skips a degraded
        | provider when walking a fallback chain.
        |
        | Degradation takes a RUN of failures because a provider that flapped
        | on every transient timeout would scatter runs across models for no
        | reason anybody could later explain.
        */
        'health' => [
            'enabled' => env('PANDORA_PROVIDER_HEALTH', true),
            'failure_threshold' => (int) env('PANDORA_PROVIDER_FAILURE_THRESHOLD', 3),
        ],

        /*
        | Routing. The default is deterministic and explainable (ADR-0006):
        | explicit call, run override, conversation override, agent default,
        | configured default -- then the agent's fallback chain in order.
        |
        | Bind your own ModelRouter for cost- or latency-aware routing; every
        | hop it chooses is still recorded on the run trace.
        */
        'router' => DeterministicModelRouter::class,

        /*
        | A rate limit is the one failure worth waiting out before moving on:
        | the model that was asked for is usually still the right one. Every
        | other retryable failure fails over immediately.
        */
        'retry' => [
            'rate_limit_attempts' => (int) env('PANDORA_RATE_LIMIT_ATTEMPTS', 2),
            'delay_ms' => (int) env('PANDORA_RATE_LIMIT_DELAY_MS', 500),
        ],

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

            'anthropic' => [
                'adapter' => 'anthropic',
                'base_url' => env('PANDORA_ANTHROPIC_BASE_URL', 'https://api.anthropic.com/v1'),
                'api_key' => env('PANDORA_ANTHROPIC_API_KEY'),
                'version' => env('PANDORA_ANTHROPIC_VERSION', '2023-06-01'),

                // The Messages API requires max_tokens on every request, so a
                // default belongs here rather than being guessed at call time.
                'max_tokens' => (int) env('PANDORA_ANTHROPIC_MAX_TOKENS', 4096),
                'timeout' => 120,
                'connect_timeout' => 10,
            ],

            'gemini' => [
                'adapter' => 'gemini',
                'base_url' => env('PANDORA_GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
                'api_key' => env('PANDORA_GEMINI_API_KEY'),
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

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | The catalog records what each model can do, how large it is and what it
    | costs. `pandora:model:sync` fills in what a provider's own API reports;
    | PRICING can only come from here, because no vendor exposes prices through
    | an API and a price nobody typed on purpose is a guess.
    |
    | A priced entry must state `pricing_source` and `pricing_date`. Pandora
    | refuses an unattributed price rather than storing one, because six months
    | later nobody can tell whether it was ever right. An unpriced model is
    | perfectly usable -- its runs simply record no cost, which is honest,
    | where a cost of zero would not be.
    |
    */

    'models' => [

        'default' => env('PANDORA_MODEL', 'fake-model'),

        // How long a price is trusted before it is flagged as stale in the
        // control center. It is not ignored -- an operator is told.
        'pricing_stale_after_days' => (int) env('PANDORA_PRICING_STALE_DAYS', 90),

        /*
        | An allowlist per tenant, applied to the candidate set BEFORE routing
        | so a fallback chain cannot walk out of it. A tenant with no entry is
        | unrestricted. `provider/*` permits a whole provider.
        |
        |     'acme' => ['openai/gpt-4o-mini', 'anthropic/*'],
        */
        'tenant_restrictions' => [],

        'catalog' => [
            // [
            //     'provider' => 'openai',
            //     'key' => 'gpt-4o-mini',
            //     'display_name' => 'GPT-4o mini',
            //     'context_limit' => 128000,
            //     'max_output_tokens' => 16384,
            //     'capabilities' => ['streaming', 'tools', 'structured_output', 'vision'],
            //     'input_price' => 0.15,          // per million tokens
            //     'output_price' => 0.60,
            //     'cached_input_price' => 0.075,
            //     'currency' => 'USD',
            //     'pricing_source' => 'https://openai.com/api/pricing',
            //     'pricing_date' => '2026-08-05',
            // ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Budgets
    |--------------------------------------------------------------------------
    |
    | Spend limits, checked BEFORE each model request. A budget checked after
    | the response is an accounting record; this one actually stops a run.
    |
    | Run-scope limits come from the agent row (`token_budget`,
    | `cost_budget_minor`); the wider scopes are configured here. Null means no
    | limit. Costs are in MINOR units of the currency -- cents, not dollars.
    |
    | An unpriced model contributes nothing to a COST budget, because its cost
    | is null rather than zero. Use a token budget where prices are unknown.
    |
    */

    'budgets' => [

        // day | week | month | forever
        'period' => env('PANDORA_BUDGET_PERIOD', 'month'),

        'conversation' => ['tokens' => null, 'cost_minor' => null],
        'agent' => ['tokens' => null, 'cost_minor' => null],
        'tenant' => ['tokens' => null, 'cost_minor' => null],
        'global' => ['tokens' => null, 'cost_minor' => null],
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
    | Delegation
    |--------------------------------------------------------------------------
    |
    | An agent calling another agent. Which agents may be called is a per-agent
    | allowlist on the agent row, empty by default -- nothing here grants that.
    | These are the limits on the run TREE, which is the unit that matters: a
    | depth limit that reset per child would be a cost multiplier wearing a
    | limit's name.
    |
    | The child's abilities are always the intersection of the parent run's and
    | the child agent's. That is not configurable, because a switch that turned
    | it off would make every permission boundary in the product decorative.
    |
    */

    'delegation' => [

        /*
         * How deep a run tree may go. A root run is depth 0, so the default
         * permits a parent, a child and a grandchild -- and denies the tool at
         * the next hop rather than failing the run, so a bounded refusal does
         * not read to an operator as an outage.
         */
        'max_depth' => env('PANDORA_MAX_DELEGATION_DEPTH', 2),

        /*
         * How long a parent will wait for a child before giving up on it. The
         * parent holds no job while it waits, so this is a ceiling on a stuck
         * tree rather than on a worker. Null falls back to the parent's own
         * remaining wall clock, which is the tighter bound in most cases.
         */
        'child_timeout_seconds' => env('PANDORA_DELEGATION_TIMEOUT', null),

        /*
         * The largest child result, in characters, that will be handed back to
         * the parent. A sub-agent that read a hostile page returns a hostile
         * string, and it enters the parent's prompt through the same door as
         * any other tool result -- bounded, redacted, and never in an
         * instruction position.
         */
        'max_result_length' => 8000,
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

        // Built-in tools. Registered, not granted: an agent still has to name
        // each one in its allowlist. Set to false to install none of them.
        'builtin' => [
            'enabled' => env('PANDORA_BUILTIN_TOOLS', true),
        ],

        /*
        | Resources readable by the `query_records` tool.
        |
        | The model names a RESOURCE; you decide what that means. Nothing is
        | readable until it appears here, `authorize` runs against the acting
        | user, and `scope` is where an ownership or tenant constraint goes so
        | that it applies whatever the model asked for.
        |
        | 'orders' => [
        |     'model' => App\Models\Order::class,
        |     'fields' => ['id', 'reference', 'status', 'total'],
        |     'filterable' => ['reference', 'status'],
        |     'max_results' => 25,
        |     'authorize' => fn ($user) => $user->can('viewAny', App\Models\Order::class),
        |     'scope' => fn ($query, $user) => $query->where('user_id', $user->id),
        | ],
        */
        'resources' => [],

        // Exact configuration keys readable by `read_config`. Exact, not
        // prefixes: `services.*` would hand over every third-party credential
        // in the application.
        'readable_config' => [],

        /*
        | Jobs, events and notifications available to agents. Each is named,
        | class-exact, and declares which arguments it accepts -- a model picks
        | from this list and never supplies a class name.
        |
        | 'send_receipt' => [
        |     'class' => App\Jobs\SendReceipt::class,
        |     'arguments' => ['orderId'],
        |     'authorize' => fn ($user, array $arguments) => $user->can('create', Receipt::class),
        | ],
        */
        'jobs' => [],
        'events' => [],
        'notifications' => [],

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
    | Automation
    |--------------------------------------------------------------------------
    |
    | Everything that starts a run without a human in the moment. See ADR-0009:
    | the capability is real and useful, and it is leashed on purpose.
    |
    | One Laravel scheduler entry drives all of it. Add nothing to your own
    | Kernel -- registering `pandora:automation:tick` is what `enabled` below
    | does, and a second entry would double every occurrence's chance of
    | racing (it would still fire once, but you would be paying to find out).
    |
    */

    'automation' => [

        'enabled' => env('PANDORA_AUTOMATION_ENABLED', true),

        // Whether Pandora registers its own every-minute scheduler entry.
        // Turn this off only if you drive `pandora:automation:tick` yourself.
        'schedule' => [
            'enabled' => env('PANDORA_AUTOMATION_SCHEDULE', true),
        ],

        // How many due automations one tick claims. A cap, not a target: it
        // bounds the work a single minute can create when a backlog exists.
        'batch_size' => 100,

        'misfire' => [
            // The ceiling on `run_all`. An unbounded catch-up after a six-hour
            // outage is the outage twice, and the second time it costs money.
            'max_catch_up' => 12,

            // How far past `next_run_at` still counts as "on time" rather than
            // a misfire. Wider than one tick, so a slow minute is not an event.
            'grace_seconds' => 120,
        ],

        'autonomy' => [
            // The default budget for a new automation: how many times it may
            // wake per window. A token budget does not catch an automation
            // that wakes every minute and returns immediately.
            'default_budget_runs' => 24,
            'default_window_seconds' => 86400,

            // Who hears about an automation that disabled itself. Each entry
            // is a class-string of a Notifiable, or an email address. Empty
            // means the audit log is the only record -- which is a choice, but
            // make it on purpose.
            'notify' => [
                // 'ops@example.com',
            ],

            // The notification sent. Bind your own to route it somewhere else.
            'notification' => AutomationDisabled::class,
        ],

        'retry' => [
            // The default retry policy stamped on a new automation.
            'disable_after_failures' => 5,
        ],

        /*
        | Conditional polling.
        |
        | An automation names a CONDITION; you decide what that means. Same
        | rule as tools and jobs: a condition is registered here, class-exact
        | or a closure defined in this file, and never a callable read out of a
        | database row -- that is remote code execution with extra steps.
        |
        | The closure receives the automation's condition arguments and returns
        | a boolean. Returning false records a skipped occurrence and creates
        | no run.
        |
        | 'unshipped_orders' => fn (array $arguments): bool =>
        |     App\Models\Order::whereNull('shipped_at')->exists(),
        */
        'conditions' => [],

        'webhooks' => [
            'enabled' => env('PANDORA_WEBHOOKS_ENABLED', true),

            // Registered under the Pandora route prefix, OUTSIDE the control
            // center's middleware: an inbound webhook has no session and must
            // not be asked for one. Authentication is the signature.
            'path' => 'webhooks',
            'middleware' => [],

            // How far a delivery's timestamp may be from ours. Generous enough
            // for clock skew, short enough that a captured request is stale
            // before it is useful. Replay is refused by the nonce regardless.
            'tolerance_seconds' => 300,

            // A body larger than this is refused before it is parsed.
            'max_payload_bytes' => 65536,

            'signature_header' => 'X-Pandora-Signature',
        ],

        // What a proposed observation is worth before it goes stale. An agent
        // suggestion nobody looked at for a month is not a decision anyone
        // should still be making from memory.
        'observations' => [
            'expire_after_days' => 30,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | MCP
    |--------------------------------------------------------------------------
    |
    | Model Context Protocol: tools that live on a machine you do not own,
    | described by someone you have never met. See ADR-0014 for the trust
    | boundary; the short version is that everything a server says is untrusted
    | content, including the parts that look like configuration.
    |
    | Nothing here approves anything. Discovery writes rows and leaves every
    | tool unapproved, per agent, until a human says otherwise -- there is no
    | auto-approve key and no "trusted server" flag, because anything that both
    | discovers and enables is a remote-controlled permission grant.
    |
    */

    'mcp' => [

        'client' => [
            'enabled' => env('PANDORA_MCP_ENABLED', false),

            // How long one remote call may take, and how much of a response
            // is read before it is refused. A server that hangs must cost one
            // tool call, not one worker.
            'timeout_seconds' => (int) env('PANDORA_MCP_TIMEOUT', 30),
            'max_response_bytes' => (int) env('PANDORA_MCP_MAX_RESPONSE', 262144), // 256 KB

            // A remote description is third-party text shown to a model and to
            // an operator. Bounded on the way in, escaped on the way out, and
            // never placed where an instruction goes.
            'max_description_length' => 2000,

            // The separator between a server's namespace and a remote tool
            // name. Reserved: a core tool may not contain it, so no remote
            // name can be mistaken for one.
            'namespace_separator' => '.',
        ],

        'transports' => [
            'http' => ['enabled' => true],
            'sse' => ['enabled' => true],

            /*
            | stdio means executing a local binary named by a database row.
            | That is reasonable on a developer machine and is never reasonable
            | by default: it turns write access to one table into arbitrary
            | local execution. Enabling it is a deliberate act, and the refusal
            | names this key so nobody has to guess.
            */
            'stdio' => ['enabled' => env('PANDORA_MCP_STDIO', false)],
        ],

        /*
        | The Pandora MCP server -- Pandora exposing ITS tools to somebody
        | else's agent. Off by default: installing a package should expose
        | nothing.
        |
        | `exposed_tools` is an allowlist and an empty one means nothing is
        | served. It decides what EXISTS; it does not decide who may call it.
        | Every call is separately authorized against the actor behind the
        | token, because skipping that makes the token a superuser.
        */
        'server' => [
            'enabled' => env('PANDORA_MCP_SERVER_ENABLED', false),
            'path' => env('PANDORA_MCP_SERVER_PATH', 'mcp'),
            'middleware' => ['api'],
            'exposed_tools' => [],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Workspaces
    |--------------------------------------------------------------------------
    |
    | The roots a workspace may be created under, and nothing else is one. Each
    | entry names a disk from your `filesystems.php` and a base prefix inside
    | it; the control center offers these by key and derives the rest.
    |
    | An operator declares WHERE workspaces may live. A workspace created in
    | the UI supplies only a name, and its root is composed as
    | `<base>/<tenant>/<slug>` -- so the form has no path field, because a form
    | with a path field is a form that accepts `/`.
    |
    | An empty list means no workspace can be created through the UI at all.
    | That is the correct failure direction for an allowlist: unconfigured
    | means nothing is permitted, not everything.
    |
    | Credentials are never here. A disk names them and `filesystems.php` holds
    | them (ADR-0013); Pandora stores no endpoint, key or secret of its own.
    |
    | Only a local root ships, and that is not a preference for local storage --
    | it is the only disk every Laravel application is guaranteed to have. A
    | shipped `s3` root would offer an operator a choice that fails the moment
    | somebody picks it, on every host that never configured that disk. Add the
    | object root once the disk behind it exists:
    |
    |   'bucket' => [
    |       'label' => 'Object storage',
    |       'disk' => 's3',            // any S3-compatible disk: AWS, Spaces,
    |       'base_prefix' => 'workspaces',  // Hetzner, MinIO, R2
    |   ],
    |
    */

    'workspaces' => [
        'roots' => [
            'local' => [
                'label' => 'Local storage',
                'disk' => env('PANDORA_WORKSPACE_DISK', 'local'),
                'base_prefix' => env('PANDORA_WORKSPACE_PREFIX', 'pandora-workspaces'),
            ],
        ],

        // The default quota offered by the creation form, in bytes. Null
        // offers unlimited, which stays a deliberate choice rather than what
        // happens when nobody types a number.
        'default_quota_bytes' => 104857600, // 100 MB

        // The largest file the control center will accept in one upload. Not
        // the quota -- the quota is what a workspace may hold in total, this
        // is what one request may carry into the worker's memory at once.
        // Declared here rather than left to `upload_max_filesize`, because the
        // PHP limit is a deployment accident and this is a policy.
        'max_upload_bytes' => 26214400, // 25 MB

        // How much of a file `read_file` may put in front of the model at
        // once. A workspace is allowed to hold a file larger than the model's
        // context and larger than the worker's memory; the read is bounded and
        // the truncation is reported, because a model given a silently cut-off
        // file reasons confidently about the half it got.
        'max_read_bytes' => 65536, // 64 KB

        // The most `write_file` may write in one call. Not a memory bound -- a
        // model cannot emit enough to matter -- but a bound on how much of a
        // workspace's remaining quota one confused call can consume.
        'max_write_bytes' => 1048576, // 1 MB
    ],

    /*
    |--------------------------------------------------------------------------
    | Channels
    |--------------------------------------------------------------------------
    |
    | A channel is a medium a conversation happens through. Pandora ships the
    | contract and no messaging adapter: adapters are extensions, installed with
    | `composer require`, and installing one connects nothing (ADR-0016).
    |
    | Nothing here decides who anybody is. A channel tells us that a participant
    | typed something; which host user that is -- if any -- comes from the link
    | table and from nowhere else, because the alternative is treating a remote
    | workspace administrator's assertion as a credential (ADR-0015).
    |
    */

    'channels' => [

        // Adapters registered at boot. An extension's service provider is the
        // usual way in; this list exists so a host application can register one
        // without writing a provider.
        'adapters' => [
            // Vendor\Slack\SlackChannel::class,
        ],

        'linking' => [
            /*
            | The word a participant sends to ask for a code. The code goes back
            | into the channel, to them, and is redeemed while signed in to this
            | application -- one half proves they hold the channel account, the
            | other proves they hold the host account, and linking is the claim
            | that those are the same person.
            */
            'command' => env('PANDORA_CHANNEL_LINK_COMMAND', 'link'),

            // Where the reply tells them to go. Null names the application
            // generically, which works and reads worse; set it.
            'redeem_url' => env('PANDORA_CHANNEL_LINK_URL'),

            // Short, because it is a credential that grants an identity, and
            // because a code that outlives the conversation it appeared in is a
            // code somebody finds in a scrollback.
            'code_ttl_seconds' => 900,
            'code_length' => 8,

            // Per identity, per hour. Asking for a new code invalidates the
            // last one, so this bounds how fast the code space can be walked.
            'max_codes_per_hour' => 5,

            // Per redeeming user, per hour.
            'max_attempts_per_hour' => 10,

            // How often an unlinked participant is told how to link. They are
            // refused every time regardless; this only bounds how often we
            // answer, so a stranger cannot aim our instructions at their own
            // channel as a flood.
            'instruction_interval_seconds' => 600,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Extensions
    |--------------------------------------------------------------------------
    |
    | Pandora INSPECTS what Composer installed. It never acquires anything:
    | there is no marketplace, no remote install and no update check, and those
    | are excluded rather than deferred (ADR-0016). A UI that can install code
    | is a UI whose authorization bug is arbitrary execution.
    |
    | A package declares itself an extension with an `extra.pandora` block in
    | its own `composer.json`:
    |
    |   "extra": {
    |       "pandora": {
    |           "name": "Slack",
    |           "description": "Slack as a Pandora channel.",
    |           "provides": { "channels": ["slack"] },
    |           "requires": { "pandora": "^1.0" },
    |           "documentation": "https://example.com/docs"
    |       }
    |   }
    |
    | It is read from Composer's own `installed.json`, so nothing is autoloaded
    | and no extension code runs to render the page -- which is what lets the
    | control center describe a package that would fatal if it were booted.
    |
    */

    'extensions' => [
        // Overridable so a test can point at a fixture. In a real installation
        // the default is the only correct answer.
        'installed_json' => env('PANDORA_INSTALLED_JSON'),
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
            ContextFilesProvider::class,
            // Memory before recent messages: when the budget runs out, the
            // thing worth keeping is what the agent knows and would otherwise
            // never recall, not the tail of a transcript the user was present
            // for and can repeat.
            MemoryContextProvider::class,
            RecentMessagesProvider::class,
            // Only ever contributes for a run with no conversation, where no
            // messages exist to recall. Last, and adjacent to the provider it
            // stands in for, so the two are read together.
            RunToolLoopProvider::class,
        ],

        'recent_messages' => [
            'limit' => 40,
        ],

        /*
        | Context files are read ONLY from the roots listed here, after the
        | path has been resolved with realpath(). An empty list permits
        | nothing -- an allowlist that falls open when unconfigured is not an
        | allowlist. Paths themselves live on the agent, which is edited in a
        | browser, so they are treated as untrusted all the way down.
        |
        | A root may also name an object store, written `disk:<name>/<prefix>`
        | -- `disk:spaces/handbooks`. Roots authorise their own kind only: a
        | filesystem root never vouches for a bucket key, and a bucket prefix
        | never vouches for a file on disk.
        */
        'files' => [
            'roots' => [],
            'max_bytes' => 65536,

            /*
             * How long a context file's BODY may be reused, given its ETag has
             * not changed. This is not a staleness window: the store is asked
             * on every read whether the object moved, and a changed one is
             * re-fetched immediately. It bounds how long the bytes are kept,
             * not how current they are.
             */
            'cache_ttl_seconds' => 86400,
        ],

        /*
        | A summary is regenerated once this many messages have been added
        | since the last one -- not per request. Per-request summarisation
        | costs a model call every turn and makes the same conversation
        | produce different context twice.
        */
        'summarisation' => [
            'threshold' => 20,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Memory
    |--------------------------------------------------------------------------
    |
    | Retrieval is lexical and needs nothing installed -- no vector database,
    | no search extension, no full-text index. That is the shipped path, and it
    | works identically on SQLite, MySQL, MariaDB and PostgreSQL.
    |
    | A vector store is an ACCELERATOR. It changes the order results come back
    | in; it never changes which results are visible, because every candidate
    | it proposes is re-filtered against the session's scope before anything is
    | returned. Leaving `vector_store` null is a supported production
    | configuration, not a degraded one.
    |
    */

    'memory' => [
        // null = lexical retrieval only. 'database' is the portable
        // brute-force store; 'pgvector' uses the PostgreSQL extension and
        // degrades to lexical wherever it is not installed.
        'vector_store' => env('PANDORA_VECTOR_STORE'),

        'stores' => [
            'database' => [
                'driver' => 'database',
                // How many stored vectors to compare in PHP. A million-row
                // table loaded into memory is an outage, not a slow query.
                'scan_limit' => 5000,
            ],
            'pgvector' => [
                'driver' => 'pgvector',
            ],
        ],

        'embeddings' => [
            // The default provider is offline and deterministic. It is not a
            // language model: it hashes tokens into buckets, which is enough
            // to exercise the whole vector path honestly in every environment
            // including CI. Point this at a real provider for semantics.
            'provider' => HashEmbeddingProvider::class,

            // Must match the vector column pgvector was migrated with. A
            // change here needs a migration and a re-embed -- two vector
            // spaces in one column makes every distance meaningless.
            'dimensions' => env('PANDORA_EMBEDDING_DIMENSIONS', 256),
        ],

        'retrieval' => [
            // Returned to the caller.
            'limit' => 10,

            // Rows considered for ranking before the limit applies. Bounded so
            // a broad query cannot pull a whole tenant's memory into PHP.
            'candidate_limit' => 200,
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

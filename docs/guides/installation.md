# Installation

## Requirements

| | |
|---|---|
| PHP | 8.3+ |
| Laravel | 13 |
| Queue | any Laravel queue driver |
| Livewire 4 | optional — required only for the control center |
| Reverb | optional — streaming; the UI polls correctly without it |
| Redis / Horizon | optional — never required by the core runtime |
| Vector database | optional — never required; default memory retrieval is lexical |

## Install

```bash
composer require michal78/laravel-pandora
php artisan pandora:install
```

The installer is **idempotent** — safe to run repeatedly. It publishes config and migrations, offers
to migrate, and explains the remaining setup. It deliberately creates **no default agent**.

## Two installation modes

### Headless framework mode

Use agents, tools and jobs from your own code with no routes, views or Livewire.

```php
// config/pandora.php
'ui'     => ['enabled' => false],
'routes' => ['enabled' => false],
```

### Full control-center mode (default)

Adds routes at `/pandora`, the Livewire pages, the views and the broadcast channels. Requires
`livewire/livewire`.

## Queue

Pandora is queue-first: a web request may *start* a run but never performs one.

```bash
php artisan queue:work
```

Queue names default to `null`, meaning everything collapses onto your default queue. Split them when
interactive work should overtake batch work:

```env
PANDORA_QUEUE_INTERACTIVE=pandora-interactive
PANDORA_QUEUE_AGENTS=pandora-agents
PANDORA_QUEUE_TOOLS=pandora-tools
```

## Realtime (optional)

```bash
composer require laravel/reverb
php artisan reverb:install
php artisan reverb:start
```

Because the database is authoritative and broadcasts are only notifications, disabling Reverb leaves
the UI **correct** — it polls instead of streaming:

```env
PANDORA_REALTIME_ENABLED=false
```

## Configure a provider

The default is the built-in `fake` provider, which needs no credentials and is useful for confirming
the installation works before any key exists.

```env
PANDORA_PROVIDER=openai
PANDORA_OPENAI_API_KEY=sk-...
PANDORA_MODEL=gpt-4o-mini
```

Any OpenAI-compatible endpoint works through the same adapter — Ollama, OpenRouter, vLLM,
llama.cpp, LM Studio:

```env
PANDORA_PROVIDER=ollama
PANDORA_OLLAMA_BASE_URL=http://localhost:11434/v1
PANDORA_MODEL=llama3.2
```

## Authorization

Pandora ships **no authentication** and integrates with your existing guards. It defines a gate per
ability. By default any authenticated user may `access` and `chat`, and **every administrative
ability is denied**. Define gates of the same name to take control — yours always win:

```php
// app/Providers/AppServiceProvider.php
Gate::define('pandora.access',        fn (User $user) => $user->hasTeamAccess());
Gate::define('pandora.agents.manage', fn (User $user) => $user->isAdmin());
Gate::define('pandora.costs.view',    fn (User $user) => $user->isAdmin());
```

## Verify

```bash
php artisan pandora:status
php artisan pandora:agent:list
```

## Changing the namespace

The placeholder namespace is `Pandora\Pandora\`. To change it, edit two keys in `composer.json`
(`autoload.psr-4` and `extra.laravel`), run a project-wide replace of `Pandora\Pandora` and
`composer dump-autoload`. No namespace string is hard-coded in config, views or migrations.

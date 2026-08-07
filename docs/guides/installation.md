# Installation

## Requirements

| | |
|---|---|
| PHP | 8.3+ |
| Laravel | 13 |
| Database | MySQL 8+, MariaDB 11+, PostgreSQL 14+ — or SQLite for development (see below) |
| Queue | any Laravel queue driver |
| Livewire 4 | optional — required only for the control center |
| Reverb | optional — streaming; the UI polls correctly without it |
| Redis / Horizon | optional — never required by the core runtime |
| Vector database | optional — never required; default memory retrieval is lexical |

### A word about SQLite

Pandora **supports SQLite and always will**. It is Laravel's default for a new application, its own
test suite runs on it, and a package that could not be installed into a fresh `laravel new` app would
be a package nobody evaluates. Every migration and every portability rule in
`docs/architecture/database-model.md` exists to keep all four engines working.

For **production**, prefer a server engine — and the reason is specific to what Pandora does rather
than a general preference. The execution model is concurrent by design: queue workers take row locks
on runs, the automation scheduler claims occurrences with a unique insert, and webhook replay
protection depends on two processes racing the same index and exactly one winning. SQLite serialises
writers across the whole database, so under more than one worker those paths do not fail cleanly —
they surface as `database is locked`.

Use SQLite for development, tests, CI and single-worker deployments. Use MySQL, MariaDB or
PostgreSQL where more than one thing runs at once.

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

The control center needs no build step and no bundler. Its brand assets are served from the package
by default; publish them so your web server serves them instead:

```bash
php artisan vendor:publish --tag=pandora-assets
```

Files land in `public/vendor/pandora`, and published copies always win over the packaged ones. See
[visual identity](../visual-identity.md) for theming and how to substitute your own brand.

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

The namespace is `Pandora\`, PSR-4 rooted at `src/`. To change it, edit two keys in `composer.json`
(`autoload.psr-4` and `extra.laravel`), run a project-wide replace of `Pandora\` and
`composer dump-autoload`. No namespace string is hard-coded in config, views or migrations.

---

## Verified installation: a worked example

Pandora was installed into a real Laravel 13 application to prove these instructions. The
environment, for reference:

| | |
|---|---|
| Laravel | 13.19 |
| PHP | 8.5.8 (Sail container) |
| Database | MySQL 8.4 |
| Queue / cache | Redis |
| Broadcasting | `log` — no Reverb, so the UI runs on its polling fallback |

### Local path installation

While developing the package alongside an application, consume it through a Composer `path`
repository rather than copying it into `vendor/`:

```jsonc
// composer.json
"repositories": [
    {
        "name": "laravel-pandora",
        "type": "path",
        "url": "/absolute/path/to/laravel-pandora",
        "options": { "symlink": true }
    }
],
"require": {
    "michal78/laravel-pandora": "@dev"
}
```

Under Laravel Sail the package must also be visible **inside** the container, or Composer cannot
resolve the path. Mount it at the same absolute path in `compose.yaml`:

```yaml
services:
    laravel.test:
        volumes:
            - '.:/var/www/html'
            - '/absolute/path/to/laravel-pandora:/absolute/path/to/laravel-pandora'
```

Then `sail up -d` to apply the mount and `sail composer update michal78/laravel-pandora`.

### The sequence

```bash
sail composer update michal78/laravel-pandora
sail artisan pandora:install
sail artisan migrate
sail artisan pandora:status
```

Register an agent class in `config/pandora.php` under `agents.definitions`, then confirm it:

```bash
sail artisan pandora:agent:list
sail artisan pandora:agent:run echo "Hello" --trace
```

### Seeing streaming without a provider key

The built-in `fake` provider echoes the last user message when its script is exhausted, so a fresh
installation works before any credentials exist. It streams instantly by default, which is what
tests want but makes the browser look static. Pace it:

```dotenv
PANDORA_FAKE_CHUNK_DELAY_MS=60
```

### A worker is required

The chat UI dispatches runs to the queue — a web request is never held open for a run. Nothing will
appear to happen until a worker is running:

```bash
sail artisan queue:work
```

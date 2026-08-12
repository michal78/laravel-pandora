<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="resources/dist/logos/laravel-pandora-dark.svg">
    <source media="(prefers-color-scheme: light)" srcset="resources/dist/logos/laravel-pandora-light.svg">
    <img alt="Pandora — agentic framework for Laravel" src="resources/dist/logos/laravel-pandora-light.svg" width="520">
  </picture>
</p>

<p align="center">
  <strong>An agentic framework for Laravel applications.</strong><br>
  Agents, tools, approvals, automations and memory — under explicit, auditable policy,<br>
  with a Livewire control center for operating them.
</p>

<p align="center">
  <a href="https://packagist.org/packages/michal78/laravel-pandora"><img alt="Latest version" src="https://img.shields.io/packagist/v/michal78/laravel-pandora.svg?style=flat-square&color=5B46D9&label=packagist"></a>
  <a href="https://github.com/michal78/laravel-pandora/actions/workflows/tests.yml"><img alt="Tests" src="https://img.shields.io/github/actions/workflow/status/michal78/laravel-pandora/tests.yml?branch=master&style=flat-square&label=tests"></a>
  <a href="https://github.com/michal78/laravel-pandora/actions/workflows/static-analysis.yml"><img alt="PHPStan level 8" src="https://img.shields.io/badge/phpstan-level%208-5B46D9?style=flat-square"></a>
  <a href="https://github.com/michal78/laravel-pandora/actions/workflows/code-style.yml"><img alt="Code style" src="https://img.shields.io/github/actions/workflow/status/michal78/laravel-pandora/code-style.yml?branch=master&style=flat-square&label=pint"></a>
  <br>
  <a href="https://packagist.org/packages/michal78/laravel-pandora"><img alt="PHP version" src="https://img.shields.io/packagist/dependency-v/michal78/laravel-pandora/php?style=flat-square&color=777BB4&label=php"></a>
  <img alt="Laravel 13" src="https://img.shields.io/badge/laravel-13.x-FF2D20?style=flat-square">
  <a href="https://packagist.org/packages/michal78/laravel-pandora"><img alt="Downloads" src="https://img.shields.io/packagist/dt/michal78/laravel-pandora.svg?style=flat-square&color=5B46D9"></a>
  <a href="LICENSE.md"><img alt="License" src="https://img.shields.io/packagist/l/michal78/laravel-pandora.svg?style=flat-square&color=5B46D9"></a>
</p>

<p align="center">
  <a href="docs/guides/installation.md">Installation</a> ·
  <a href="docs/guides/quick-start.md">Quick start</a> ·
  <a href="docs/architecture/overview.md">Architecture</a> ·
  <a href="docs/architecture/security-model.md">Security model</a> ·
  <a href="docs/roadmap.md">Roadmap</a>
</p>

---

> ### ⚠ v0.1.0 — first published release, and deliberately `0.x`
>
> **What works today:** define an agent, start a conversation, dispatch a queued run, stream it over
> Reverb, persist an immutable trace, reload without losing anything, cancel it, and inspect it in
> the control center — with **tools** under five layers of authorization and human approval gates on
> the risky ones, **multi-provider routing** with failover and budgets, **automations** on four
> engines, **scoped memory and context** that works with no vector database installed,
> **delegation and MCP** where nothing remote is approved by discovering it, **workspaces** on local
> or S3-compatible storage, and **messaging channels** where an unlinked identity gets no run, no
> session and no seat.
>
> **What does not exist yet:** release hardening — the full threat-model sweep, performance tests and
> the example application (Phase 9, at 3 of 34 criteria). See [`docs/roadmap.md`](docs/roadmap.md).
>
> **Verified by** 1,756 tests across SQLite, MySQL 8.4, MariaDB 11, PostgreSQL 17, pgvector and MinIO
> · PHPStan level 8, no baseline · Pint.
>
> **Before depending on this, read
> [`docs/product/support-statement.md`](docs/product/support-statement.md)** — it names what is
> supported, what is excluded by design, and what ships *known untested*. The API is usable and in
> use; it is not yet promised. `1.0.0` is defined as the day Phase 9's criteria are met, not a date.

---

## Why

Two kinds of thing exist today, and neither fits a Laravel application.

**Personal agent daemons** (OpenClaw, Hermes Agent) are excellent — for one operator, on one machine.
OpenClaw's own security documentation is explicit that it "is not a hostile multi-tenant security
boundary." You cannot put one in front of your customers.

**LLM SDKs** give you `->chat()` and leave durability, approvals, tenancy, auditing, cost control and
resumability as an exercise for the reader.

Pandora takes the capabilities of the first and gives them the trust, tenancy and authorization model
of the framework you already run on. An agent run is a **durable, queued, resumable, authorized,
audited unit of work** — closer to a job with a state machine than to a chat request.

## The idea in one screen

Expose an application action. It is an ordinary PHP class, with ordinary Laravel authorization:

```php
final class IssueRefund extends Tool
{
    public function name(): string
    {
        return 'issue_refund';
    }

    public function description(): string
    {
        return 'Issue a refund for an existing order.';
    }

    public function rules(): array
    {
        // The JSON schema shown to the model is generated from these, so the
        // interface it is told about and the one enforced cannot drift.
        return [
            'order_id' => 'required|string|exists:orders,id',
            'amount_minor' => 'required|integer|min:1|max:100000',
        ];
    }

    public function risk(): RiskLevel
    {
        return RiskLevel::High;     // → requires human approval by default
    }

    public function authorize(ToolInput $input, ToolContext $context): bool
    {
        // The *acting user's* policy. An agent can never exceed the person it acts for.
        return Gate::forUser($context->user())
            ->allows('refund', Order::find($input->string('order_id')));
    }

    public function handle(ToolInput $input, ToolContext $context): ToolResult
    {
        $this->refunds->issue($input->string('order_id'), $input->integer('amount_minor'));

        return ToolResult::success('Refund issued.');
    }
}
```

Register it, then grant it — registering installs a tool; each agent must still be given it:

```php
// config/pandora.php
'tools' => ['registered' => [App\Tools\IssueRefund::class]],
```

Give it to an agent, and run it:

```php
$run = Pandora::agent('support')
    ->forUser($user)
    ->withContext(['order_id' => $order->getKey()])
    ->stream()
    ->dispatch('Help this customer resolve their order problem.');
```

The request returns immediately. A queue worker performs the run. The browser streams it over Reverb.
The refund pauses for a human approval — costing nothing while it waits, surviving a deploy — then
resumes. Every step is in the trace. Every decision is in the audit log.

## What makes it different

| | |
|---|---|
| **Multi-tenant and multi-user from the first migration** | Not a single-operator daemon. Tenant and session isolation are tested, not assumed. |
| **Authorized against the actor, not the agent** | A tool call is checked against the acting user's Laravel gates and policies. An agent cannot do what the user could not. |
| **Runs pause for free** | An approval pause holds no worker and no memory. It can wait three days across two deploys. |
| **Resumable by construction** | All continuation state is in the database. A worker crash loses at most one iteration. |
| **Memory needs no vector database** | Lexical retrieval is the shipped path across all four databases. A vector store is an accelerator, never an authority. |
| **Reverb is optional** | The database is authoritative; broadcasts are notifications. Turn realtime off and polling is still correct. |
| **Redis and Horizon are optional** | The core runtime needs a plain Laravel queue and nothing else. |
| **Nothing is forced** | Headless mode installs no routes, no views, no Livewire. |

## Requirements

| | |
|---|---|
| **PHP** | 8.3 · 8.4 |
| **Laravel** | 13.x |
| **Database** | SQLite · MySQL 8.4 · MariaDB 11 · PostgreSQL 17 — all in CI |
| **Queue** | any Laravel queue backend |
| **Optional** | Livewire 4 (control center) · Reverb (streaming) · Redis/Horizon · pgvector |

## Installation

```bash
composer require michal78/laravel-pandora
php artisan pandora:install
php artisan queue:work
```

That is the headless install — agents, tools and runs from your own code, with no routes and no
frontend. **The control center at `/pandora` is Livewire, which Pandora suggests rather than
requires**, so add it if you want the UI:

```bash
composer require livewire/livewire
```

Without it, no `/pandora` route is registered at all — the page 404s rather than erroring, and
`php artisan pandora:status` tells you which of the two you have.

Want the unstable branch instead? `development` is where everything lands before it is released, and
Composer will take it without changing your `minimum-stability` — requiring a `dev-` version sets the
stability flag for that package on its own:

```bash
composer require michal78/laravel-pandora:dev-development
```

The installer is idempotent, publishes config and migrations, explains Reverb / queue / scheduler
setup, and **deliberately creates no default agent.**

Then define an agent and run it:

```php
final class SupportAgent implements AgentDefinition
{
    public function define(AgentBlueprint $agent): AgentBlueprint
    {
        return $agent
            ->name('Support')
            ->instructions('Help customers resolve support issues.')
            ->model('openai', 'gpt-4o-mini');
    }
}
```

```bash
php artisan pandora:agent:run support "Where is order 1234?" --trace
```

## Control center

A Livewire control center ships with the package and looks finished the moment it is installed —
its own logo, palette and design tokens, light and dark themes resolved before the first paint, and
every colour meeting WCAG AA on the surface it sits on. Retheme it by overriding one layer of custom
properties; replace the mark by editing one Blade component.

See [`docs/visual-identity.md`](docs/visual-identity.md) and
[`docs/brand-guide.md`](docs/brand-guide.md).

```php
// config/pandora.php
'ui' => ['brand' => 'Acme Agents', 'theme' => 'dark'],
```

## Documentation

**Guides** — [installation](docs/guides/installation.md) ·
[quick start](docs/guides/quick-start.md) · [agents](docs/guides/agents.md) ·
[tools](docs/guides/tools.md) · [providers](docs/guides/providers.md) ·
[automations](docs/guides/automations.md) · [memory](docs/guides/memory.md) ·
[workspaces](docs/guides/workspaces.md) · [MCP](docs/guides/mcp.md) ·
[channels](docs/guides/channels.md) ·
[writing extensions](docs/guides/writing-extensions.md)

**Architecture** — [overview](docs/architecture/overview.md) ·
[security model](docs/architecture/security-model.md) ·
[execution model](docs/architecture/execution-model.md) ·
[provider model](docs/architecture/provider-model.md) ·
[database model](docs/architecture/database-model.md) ·
[realtime model](docs/architecture/realtime-model.md)

**Product** — [vision](docs/product/vision.md) ·
[feature parity](docs/product/feature-parity.md) ·
[terminology](docs/product/terminology.md)

**Decisions** — [ADRs](docs/adr/) — each with the alternatives and why they lost

**Delivery** — [roadmap](docs/roadmap.md) · [changelog](CHANGELOG.md) ·
[acceptance plans](docs/development/) · [progress log](docs/development/progress.md) ·
[open questions](docs/development/open-questions.md)

## Security

Pandora executes model-directed actions inside your application. Read
[`docs/architecture/security-model.md`](docs/architecture/security-model.md) before deploying it.

**We do not claim to solve prompt injection.** We build layered controls that bound what an injected
instruction can reach: least authority, approval gates, tool allowlists, argument validation, egress
control, budgets and audit. See [`SECURITY.md`](SECURITY.md) for the full statement of limitations —
including where policy restriction is *not* sandboxing.

## Contributing

See [`CONTRIBUTING.md`](CONTRIBUTING.md). Read the ADRs first — most design questions are already
answered there, including the ones where we chose the harder option on purpose.

## Acknowledgements

OpenClaw and Hermes Agent shaped what users now expect from a self-hosted agent platform, and studying
them publicly informed [our parity matrix](docs/product/feature-parity.md). Pandora shares no code,
assets, wording or implementation details with either project — every capability here is an
independent Laravel-native reimplementation.

## License

MIT. See [`LICENSE.md`](LICENSE.md).

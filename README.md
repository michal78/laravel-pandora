# Pandora

**An agentic framework for Laravel applications.**

Pandora lets your application expose its own actions to LLM-driven agents under explicit, auditable
policy — and ships a Livewire control center for operating them.

> ## ⚠ Pre-release — Phase 1 (kernel vertical slice)
>
> **What works today:** define an agent, start a conversation, dispatch a queued run, stream it over
> Reverb, persist an immutable trace, reload without losing anything, cancel it, and inspect it in
> the control center. Verified by 119 tests (739 assertions), PHPStan level 8, Pint.
>
> **What does not exist yet:** tools, approvals, memory, automations, skills, MCP and messaging
> channels. Those are Phases 2–7 — see [`docs/roadmap.md`](docs/roadmap.md). The examples further
> down that use tools describe the *design of record*, not shipped code, and are marked as such.
>
> Two acceptance items remain open, both needing infrastructure this machine lacks: the manual
> walkthrough against a live worker + Reverb, and the MySQL/MariaDB/PostgreSQL matrix. See
> [`docs/development/phase-1-acceptance.md`](docs/development/phase-1-acceptance.md).
>
> The license is **provisional** pending owner confirmation — see [`LICENSE.md`](LICENSE.md).

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

> The tool below is **Phase 2 design**, shown because it is the point of the whole architecture.
> The run that follows it works today.

Expose an application action. It is an ordinary PHP class, with ordinary Laravel authorization:

```php
final class IssueRefund extends Tool
{
    public function description(): string
    {
        return 'Issue a refund for an existing order.';
    }

    public function risk(): Risk
    {
        return Risk::High;          // → requires human approval by default
    }

    public function authorize(ToolInput $input): bool
    {
        // The *acting user's* policy. An agent can never exceed the person it acts for.
        return $this->actor()->can('refund', $input->order());
    }

    public function handle(ToolInput $input): ToolResult
    {
        return ToolResult::ok($this->refunds->issue($input->order(), $input->amount()));
    }
}
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
| **Reverb is optional** | The database is authoritative; broadcasts are notifications. Turn realtime off and polling is still correct. |
| **Redis, Horizon and vector databases are optional** | The core runtime needs a plain Laravel queue and nothing else. |
| **Nothing is forced** | Headless mode installs no routes, no views, no Livewire. |

## Requirements

PHP 8.3+ · Laravel 13 · any Laravel queue backend
Optional: Livewire 4 (control center) · Reverb (streaming) · Redis/Horizon · a vector store

## Installation

```bash
composer require michal78/laravel-pandora
php artisan pandora:install
php artisan queue:work
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

Full walkthrough: [installation](docs/guides/installation.md) ·
[quick start](docs/guides/quick-start.md)

## Documentation

**Product** — [vision](docs/product/vision.md) ·
[feature parity](docs/product/feature-parity.md) ·
[terminology](docs/product/terminology.md)

**Architecture** — [overview](docs/architecture/overview.md) ·
[security model](docs/architecture/security-model.md) ·
[execution model](docs/architecture/execution-model.md) ·
[provider model](docs/architecture/provider-model.md) ·
[database model](docs/architecture/database-model.md) ·
[realtime model](docs/architecture/realtime-model.md)

**Decisions** — [ADRs](docs/adr/) — 13 decisions, each with the alternatives and why they lost

**Guides** — [installation](docs/guides/installation.md) ·
[quick start](docs/guides/quick-start.md)

**Delivery** — [roadmap](docs/roadmap.md) ·
[Phase 1 acceptance plan](docs/development/phase-1-acceptance.md) ·
[progress log](docs/development/progress.md) ·
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

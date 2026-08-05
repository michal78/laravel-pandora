# Providers, models and costs

Pandora talks to model providers through adapters. Everything above an adapter
speaks Pandora's own DTOs, so switching provider is configuration rather than a
rewrite, and a vendor's minor release cannot become a breaking change for your
application.

## The one rule

> **No vendor SDK type ever crosses an adapter boundary.**

An architecture test enforces it. The reason is not purity: a leaked vendor
type means the day OpenAI renames a field, your application changes.

## Configuring a provider

Credentials belong in the environment. `config/pandora.php` reads them; nothing
else does.

```dotenv
PANDORA_PROVIDER=anthropic
PANDORA_MODEL=claude-sonnet-4-5
PANDORA_ANTHROPIC_API_KEY=sk-ant-...
```

Four adapters ship in core:

| Adapter | Speaks to |
|---|---|
| `anthropic` | Anthropic's Messages API |
| `gemini` | Google's Gemini API |
| `openai-compatible` | OpenAI, Ollama, OpenRouter, vLLM, llama.cpp, LM Studio, and most self-hosted servers |
| `fake` | Tests, and a first look at the UI before you have any key at all |

Adding one is a config entry:

```php
'connections' => [
    'groq' => [
        'adapter' => 'openai-compatible',
        'base_url' => 'https://api.groq.com/openai/v1',
        'api_key' => env('PANDORA_GROQ_API_KEY'),
    ],
],
```

Check it end to end without writing any code:

```bash
php artisan pandora:provider:test groq --model=llama-3.3-70b-versatile
```

That command is the only thing in Pandora that makes a real, billable call. It
prints the answer, the token counts, the round-trip time — and on a failure,
the *classification*, because "rate limited" and "you have no credit left" look
identical from the outside and need completely different responses from you.

## Credentials

Resolution order, first match wins:

```
per-agent  →  per-tenant  →  deployment-wide  →  config/pandora.php
```

The first three are rows in `pandora_provider_credentials`, encrypted with your
application key. The last is where the environment is read — deliberately, and
only there: `env()` returns null once a deployment caches its config, so a
package that read the environment anywhere else would break in production and
work everywhere you tested it.

```php
use Pandora\Pandora\Providers\Credentials\CredentialManager;

$credentials = app(CredentialManager::class);

// Deployment-wide.
$credentials->issue('anthropic', 'sk-ant-...');

// Just for one tenant, or one agent.
$credentials->issue('anthropic', 'sk-ant-...', tenantId: 'acme');
$credentials->issue('anthropic', 'sk-ant-...', agentId: $agent->id);
```

### Rotation

```php
$credentials->rotate('anthropic', 'sk-ant-new-key...');
```

The new key is live immediately. The old one keeps working for
`providers.credentials.rotation_grace_minutes` (60 by default), because
revoking in the same instant would fail every request already in flight and
every worker holding a resolved value — turning a routine rotation into an
incident.

For a leaked key, skip the grace window:

```php
$credentials->revoke($credential);
```

### What you can see

Never the value. The control center shows a **fingerprint** — a hash prefix —
and the scope. That is enough to tell two keys apart and to check whether
staging and production share one, and not enough to use either.

This is not decoration. A credential is resolved inside the method that builds
the HTTP request and dropped when it returns; it is never on a job payload,
never in a run step, never in a broadcast, never in an audit entry. Attempting
to `serialize()` a resolved credential throws. `tests/Security/SecretLeakTest`
drives a real run and then reads every durable artefact looking for the key.

## The model catalog

`pandora_models` records what each model can do, how large it is and what it
costs.

```bash
php artisan pandora:model:sync
```

Two sources feed it, and neither overwrites the other:

- **A provider sync** knows which models exist. Gemini also reports real token
  limits; OpenAI and Anthropic report little more than ids.
- **Your configuration** is the only thing that may set a price. No vendor
  exposes prices through an API, so a sync that wrote to a pricing column could
  only ever be destroying something a human typed.

```php
'models' => [
    'catalog' => [
        [
            'provider' => 'anthropic',
            'key' => 'claude-sonnet-4-5',
            'context_limit' => 200000,
            'capabilities' => ['streaming', 'tools', 'vision', 'structured_output'],
            'input_price' => 3.00,      // per million tokens
            'output_price' => 15.00,
            'cached_input_price' => 0.30,
            'pricing_source' => 'https://anthropic.com/pricing',
            'pricing_date' => '2026-08-05',
        ],
    ],
],
```

A priced entry **must** state `pricing_source` and `pricing_date`. Pandora
refuses an unattributed price rather than storing one, because six months later
nobody can tell whether it was ever right. Past
`models.pricing_stale_after_days` the price is still used, and every cost it
produces is flagged as stale — in the UI and on the record itself.

An unpriced model is a perfectly normal thing to have. Its runs record a cost of
`null`, never zero: zero sums into a total that looks like a fact, and you would
never learn your catalog has no prices in it.

## Routing and failover

Which model a run uses, highest priority first:

```
explicit call → run override → conversation override → agent default → configured default
```

Exactly one of those is the primary choice. The *alternatives* come from the
agent's fallback chain:

```php
$agent->update([
    'default_provider' => 'anthropic',
    'default_model' => 'claude-sonnet-4-5',
    'fallback_models' => ['claude-haiku-4-5', 'openai/gpt-4o-mini'],
]);
```

A bare name stays on the agent's own provider; `provider/model` names one
explicitly.

Failover distinguishes failures another model could survive from ones it could
not:

| Failure | What happens |
|---|---|
| Provider unavailable, timeout | Next model in the chain |
| Rate limited | Retries the same model first — the model you asked for is usually still the right one |
| Context overflow | Next model with a **strictly larger** context window; a same-size model would just overflow again |
| Authentication failed, quota exhausted | Next model — a different provider has a different key |
| Rejected request (400) | **No failover.** Malformed is malformed everywhere |

Every hop leaves two steps on the run trace: where it went, and why the
previous one did not work. When the chain is exhausted, the run fails with the
*last provider's* reason — not "no model available", which would send you
hunting through config for a problem that has nothing to do with config.

### Restricting a tenant

```php
'models' => [
    'tenant_restrictions' => [
        'acme' => ['anthropic/claude-haiku-4-5', 'openai/*'],
    ],
],
```

Applied to the candidate set *before* routing, so a fallback chain cannot walk
out of it. A tenant with no entry is unrestricted.

## Health

`ProbeProviderHealth` runs on the maintenance queue and records reachability
and latency. Schedule it:

```php
Schedule::job(new ProbeProviderHealth)->everyFiveMinutes();
```

Degradation takes a run of failures (`providers.health.failure_threshold`, 3 by
default) and recovery takes a single success. A provider that flapped in and
out of the fallback chain on every transient timeout would be worse than no
health tracking at all, because runs would scatter across models for reasons
nobody could explain afterwards.

The router skips degraded providers. A provider nobody has probed yet counts as
usable — refusing to use it would make health tracking an outage of its own on
a fresh installation.

## Usage and budgets

One row is written to `pandora_usage_records` per model **call**, not per run: a
run that failed over spent money at two providers, and a single aggregated row
would hide that in exactly the situation where somebody is asking why the bill
grew.

Budgets are checked **before** each request:

```php
'budgets' => [
    'period' => 'month',
    'agent' => ['cost_minor' => 50_00],     // $50.00
    'tenant' => ['tokens' => 10_000_000],
    'global' => ['cost_minor' => 500_00],
],
```

Run-scope limits live on the agent row (`token_budget`, `cost_budget_minor`).
Scopes are checked narrowest first, so the failure names the limit closest to
somebody who can act on it: "this conversation has spent its budget" is
actionable, "the deployment has" is a support ticket.

A breach terminates the run as `timed_out` with the scope and the figures in
the message, and writes a `budget.exceeded` audit entry.

**An unpriced model contributes nothing to a cost budget**, because its cost is
null rather than zero. Inventing a figure would stop runs on the strength of a
number nobody entered. Where prices are unknown, use a token budget.

## Writing an adapter

Implement `StreamingProvider`, then prove it:

```php
use Pandora\Pandora\Testing\ProviderContractTests;

ProviderContractTests::for(new MyVendorFixtures);
```

`ProviderFixtures` describes your vendor's wire format — request shapes,
response shapes, error bodies, a streamed body. The suite supplies everything
else: normalisation, delta ordering, tool-call assembly, error classification,
secret handling. It runs entirely against recorded fixtures, so writing an
adapter never requires anybody to hold a paid API key, and an adapter is
finished when the shared suite passes rather than when somebody has tried it by
hand against one model.

The suite ships in `src/`, not `tests/`, precisely so an extension package can
use it.

## Who can see what

| Page | Needs | Shows |
|---|---|---|
| Providers | `pandora.access` | connections, endpoints, health, models |
| Providers | `pandora.providers.manage` | additionally: installed credentials and their scopes, and prices |
| Usage | `pandora.usage.view` | calls and tokens |
| Usage | `pandora.costs.view` | additionally: money |

A fresh installation grants `pandora.access`, `pandora.chat` and
`pandora.usage.view` to any authenticated user, and denies everything else.
Knowing how many tokens the application spent cannot cause harm; knowing what
they cost can.

Take control by defining gates of the same name — Pandora never overrides one
your application has already defined:

```php
Gate::define('pandora.providers.manage', fn (User $user) => $user->isAdmin());
Gate::define('pandora.costs.view', fn (User $user) => $user->isAdmin());
```

The sidebar hides links the current user may not open. That is a convenience,
not the boundary: every page authorizes on mount as well.

## Clearing it out

```bash
php artisan pandora:flush              # conversations, runs, traces, usage
php artisan pandora:flush --audit      # ...and the audit log
php artisan pandora:flush --all        # ...and agents, credentials, catalog
php artisan pandora:flush --tenant=acme
```

The default deletes what an agent *did*, not what the deployment *is*: agents,
credentials, the model catalog and settings survive, because losing those turns
"clear the chats" into "set the whole thing up again".

## Related

- `docs/architecture/provider-model.md` — the design, and why
- `docs/adr/0006-deterministic-model-router.md` — why there is no optimiser
- `docs/architecture/security-model.md` — the threat model these rules answer

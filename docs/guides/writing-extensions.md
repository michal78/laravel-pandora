# Writing an extension

An extension is a Composer package that registers providers, tools, skills,
channels, triggers, memory stores or health checks against Pandora's published
contracts.

There is no extension base class, no lifecycle hook, no plugin API and no
`Pandora::extend()`. An extension is an ordinary Laravel package whose service
provider calls the same registration methods this documentation shows a host
application calling. If you need a capability the contracts do not expose, that
is a missing contract — open an issue against core rather than reaching around
it, because a private hook helps exactly one author.

See ADR-0016 for the reasoning. The short version: extensions arrive through
`composer require` and nothing else, and inspecting one never executes it.

## There is no marketplace

Pandora **inspects** what Composer installed and acquires nothing. No remote
install, no update check, no version badge, no "browse available extensions" —
excluded rather than deferred.

A UI that can install code is a UI whose authorization bug is arbitrary
execution, and the entire surface would exist to save you a `composer require`
you already have a lockfile, a review and a deploy for. Installing an extension
requires shell access to the server. The people who can deploy code can install
extensions; the people who can only click cannot. That is the correct partition.

## The manifest

Declare yourself in your own `composer.json`:

```json
{
    "extra": {
        "pandora": {
            "name": "Slack",
            "description": "Slack as a Pandora channel.",
            "provides": {
                "channels": ["slack"],
                "tools": ["slack_post_message"]
            },
            "requires": { "pandora": "^1.0" },
            "documentation": "https://example.com/docs"
        }
    }
}
```

It lives in `composer.json` rather than a `pandora.json` because that file is
already there, already parsed and already in the lockfile. A second file is a
second thing to forget, and a manifest you forgot is an extension that renders
as unknown.

Pandora reads it from `vendor/composer/installed.json` — no autoloading, no
`class_exists`, no service provider. That is why the Extensions page can
describe a package that has never been booted, including one that would fatal if
it were, which is the package an operator most needs to look at.

**A manifest describes; it never grants.** `provides` is what you *say* you
register. The authoritative answer comes from the registries after boot, and the
control center shows both and shows the difference:

- Declared and not registered → the capability does not exist. Usually a typo.
- Registered and not declared → it works, and it is flagged. A package doing
  more than its manifest admits is worth a human looking at.

Neither is enforced. Enforcement would let a manifest disable its own package's
code, which an author can already achieve by not writing the code.

Manifest text is treated as untrusted: bounded, control characters stripped, and
`documentation` restricted to `http` and `https`.

## Registering things

```php
final class MyExtensionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/my-extension.php', 'my-extension');
    }

    public function boot(): void
    {
        app(ChannelRegistry::class)->register(MyChannel::class);
        app(ToolRegistry::class)->register(MyTool::class);
        app(ProviderManager::class)->extend('my-provider', fn () => new MyProvider);
    }
}
```

**Nothing you register is enabled by being installed.** A channel arrives
registered and disabled, needing an operator to create an account, bind an agent
and switch it on. A tool arrives registered and still subject to the registry,
the agent's allowlist, the tenant, the actor's abilities and its declared risk
level. `composer require` grants your package the right to *offer* capabilities
and nothing more — the same rule the MCP server follows, for the same reason:
installation is not consent.

Declare your risk levels honestly. Understating one is the most consequential
mistake a tool author can make, because risk drives approval requirements.

## Contracts you can build against

| Contract | For |
|---|---|
| `Pandora\Contracts\Channel` | A messaging surface. See `docs/guides/channels.md`. |
| `Pandora\Contracts\Provider` / `ChatProvider` / `StreamingProvider` | A model provider. |
| `Pandora\Contracts\EmbeddingProvider` / `VectorStore` | Semantic memory. |
| `Pandora\Contracts\ContextProvider` | A source of context for a model request. |
| `Pandora\Contracts\ToolPolicy` | The decision that gates a tool call. |
| `Pandora\Contracts\CredentialResolver` | Where secrets come from. |
| `Pandora\Contracts\TenantResolver` / `ActorResolver` | Who a run belongs to. |
| `Pandora\Tools\Tool` | A tool. Extend the base class. |

Anything not in `Pandora\Contracts` or documented as public is internal and will
move.

## Credentials

Use Pandora's encrypted store rather than adding config keys of your own:

```php
$credential = app(CredentialResolver::class)->resolve(
    $account->credential_key,
    new ResolutionContext(tenantId: $account->tenant_id),
);

$secret = $credential?->secret();
```

Resolve at call time, never hold the value on an object that outlives the call,
and never put it in a config file, a database row of your own or a log line.

## Testing against core

Depend on `michal78/laravel-pandora`, boot both providers under Testbench, and
load core's migrations:

```php
protected function getPackageProviders($app): array
{
    return [PandoraServiceProvider::class, MyExtensionServiceProvider::class];
}

protected function defineDatabaseMigrations(): void
{
    $this->loadMigrationsFrom(__DIR__.'/../vendor/michal78/laravel-pandora/database/migrations');
}
```

Test through the seams rather than around them. An adapter that called Slack's
API directly instead of going through `ChannelDispatcher` would inherit none of
the delivery recording, the audit entry on failure or the refusal to re-route —
you get those properties by using the contract, not by remembering to
reimplement them.

Core ships fakes for the awkward cases: `Pandora\Testing\FakeChannel`,
`FakeMcpServer`, `ProviderContractTests`.

## A worked example

`michal78/laravel-pandora-slack` is the reference, and it lives in its own
repository on purpose. "No core changes" is a claim about a boundary, and a
boundary you can reach across in one commit is not one: a missing seam gets
filled in `src/` the same afternoon, the extension keeps working, and nobody
finds out until a second author tries the same thing without commit rights to
core.

# Quick start

From nothing to a working, authorized, audited, streamed agent.

## 1. Define an agent

Agents can be database rows edited in the control center, or version-controlled classes. Classes are
authoritative for the fields they set, so a deploy always restores intended behaviour.

```php
<?php

namespace App\Agents;

use Pandora\Pandora\Agents\AgentBlueprint;
use Pandora\Pandora\Contracts\AgentDefinition;

final class SupportAgent implements AgentDefinition
{
    public function define(AgentBlueprint $agent): AgentBlueprint
    {
        return $agent
            ->name('Support')
            ->description('Helps customers resolve order problems.')
            ->instructions('Help customers resolve support issues. Be concise and factual.')
            ->model('openai', 'gpt-4o-mini')
            ->fallback(['openai:gpt-4o', 'ollama:llama3.2'])
            ->maxIterations(8)
            ->timeout(300);
    }
}
```

Register it:

```php
// config/pandora.php
'agents' => [
    'definitions' => [
        App\Agents\SupportAgent::class,
    ],
],
```

```bash
php artisan pandora:agent:list
```

## 2. Run it

From the console:

```bash
php artisan pandora:agent:run support "Where is order 1234?" --trace
```

From application code — **queued**, which is what a web request should always do:

```php
use Pandora\Pandora\Facades\Pandora;

$run = Pandora::agent('support')
    ->forUser($user)
    ->withContext(['order_id' => $order->getKey()])
    ->stream()
    ->dispatch('Help this customer resolve their order problem.');

return redirect()->route('pandora.runs.show', $run);
```

Or inject the runner, which is preferred inside domain services:

```php
final class ResolveSupportRequest
{
    public function __construct(private readonly AgentRunner $agents) {}

    public function handle(User $user, string $message): Run
    {
        return $this->agents->agent('support')->forUser($user)->dispatch($message);
    }
}
```

`run()` executes and waits — correct for console commands, jobs and tests, never for a web request:

```php
$run = Pandora::agent('support')->asSystem('nightly-report')->run('Summarise today.');
echo $run->output;
```

## 3. Watch it

```bash
php artisan queue:work        # performs the run
php artisan reverb:start      # optional: streams it live
```

Open `/pandora/chat`, pick the agent, send a message. You will see queued → running → completed
live, with the answer streaming in. Reload mid-stream: nothing is lost, because the database is
authoritative.

`/pandora/runs/{run}` shows the full trace — context construction, model request, model response,
timings, tokens and errors.

## 4. Inspect what happened

```php
$run->state;          // RunState enum
$run->output;         // final answer
$run->iterations;     // loop passes used
$run->input_tokens;   // normalised across providers
$run->steps;          // the ordered, immutable trace
```

## 5. Cancel

```php
Pandora::cancel($run, 'No longer needed.');
```

A run waiting for a human costs nothing while it waits and survives deploys — there is no job in
flight to lose.

## Testing your integration

Never call a paid API from a test. Script the fake provider instead:

```php
use Pandora\Pandora\Providers\Adapters\FakeProvider;
use Pandora\Pandora\Providers\ProviderManager;

/** @var FakeProvider $provider */
$provider = app(ProviderManager::class)->provider('fake');
$provider->willRespondWith('Order 1234 shipped on Tuesday.');

$run = Pandora::agent('support')->forUser($user)->run('Where is order 1234?');

expect($run->output)->toBe('Order 1234 shipped on Tuesday.');
```

`willThrow()` scripts provider failures so you can test your error handling:

```php
$provider->willThrow(new ProviderRateLimited('Slow down', 'openai'));
```

## What is not here yet

Tools, approvals, memory, automations, skills, MCP and messaging channels are Phases 2–7. See
[`../roadmap.md`](../roadmap.md).

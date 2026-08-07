<?php

declare(strict_types=1);

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Pandora\Agents\AgentRunner;
use Pandora\Audit\AuditLog;
use Pandora\Exceptions\Provider\ProviderException;
use Pandora\Jobs\ContinueAgentRun;
use Pandora\Jobs\RunFailer;
use Pandora\Messages\Message;
use Pandora\Providers\Credentials\CredentialManager;
use Pandora\Providers\Data\ChatMessage;
use Pandora\Providers\Data\ChatRequest;
use Pandora\Providers\ProviderManager;
use Pandora\Realtime\Events\PandoraBroadcastEvent;
use Pandora\Runs\Run;
use Pandora\Runs\RunStep;
use Pandora\Tests\Support\MakesRuns;
use Pandora\Usage\UsageRecord;

uses(MakesRuns::class);

/**
 * Phase 3 acceptance criterion 35 -- the single most important negative
 * assertion in this phase.
 *
 * A credential must not reach a log, a run step, a broadcast, an exception
 * message, an audit entry, an API response or a serialised job payload. Each
 * of those is somewhere a secret outlives the request that used it: a log
 * ships to a third party, a step is rendered in a browser, a job payload is
 * written to the jobs table and from there into every database backup.
 *
 * The test drives a REAL run against a faked HTTP layer, then reads every
 * durable artefact the run produced and looks for the key.
 */
const LEAK_KEY = 'sk-leak-canary-0123456789abcdef';

beforeEach(function (): void {
    config()->set('pandora.providers.connections', [
        'openai' => [
            'adapter' => 'openai-compatible',
            'base_url' => 'https://api.openai.test/v1',
            'api_key' => LEAK_KEY,
        ],
    ]);
    config()->set('pandora.providers.default', 'openai');
    config()->set('pandora.models.default', 'gpt-4o-mini');

    app()->forgetInstance(ProviderManager::class);
});

/**
 * Everything the run wrote down, as one string to hunt through.
 */
function durableArtefacts(): string
{
    return (string) json_encode([
        'runs' => Run::query()->get()->toArray(),
        'steps' => RunStep::query()->get()->toArray(),
        'messages' => Message::query()->get()->toArray(),
        'audit' => AuditLog::query()->get()->toArray(),
        'usage' => UsageRecord::query()->get()->toArray(),
    ]);
}

it('leaves no trace of the credential after a successful run', function (): void {
    Http::fake(['api.openai.test/*' => Http::response([
        'id' => 'chatcmpl-1',
        'choices' => [['message' => ['role' => 'assistant', 'content' => 'Hello.'], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 3],
    ])]);

    app(AgentRunner::class)->agent($this->makeAgent([
        'default_provider' => 'openai',
        'default_model' => 'gpt-4o-mini',
    ]))->inConversation($this->makeConversation())->run('Hello');

    // The key did travel -- in a header, which is the one place it belongs.
    expect(implode(' ', Http::recorded()->last()[0]->header('Authorization')))
        ->toContain(LEAK_KEY);

    expect(durableArtefacts())->not->toContain(LEAK_KEY);
});

it('leaves no trace of the credential after a failed run', function (): void {
    // The failure path is where secrets usually escape: an exception message
    // built from a provider's response, echoed into a step and an audit entry.
    Http::fake(['api.openai.test/*' => Http::response([
        'error' => ['message' => 'Incorrect API key provided: '.LEAK_KEY],
    ], 401)]);

    app(AgentRunner::class)->agent($this->makeAgent([
        'default_provider' => 'openai',
        'default_model' => 'gpt-4o-mini',
    ]))->inConversation($this->makeConversation())->run('Hello');

    expect(durableArtefacts())->not->toContain(LEAK_KEY);
});

it('shows a user a message that names no credential', function (): void {
    Http::fake(['api.openai.test/*' => Http::response([
        'error' => ['message' => 'Incorrect API key provided: '.LEAK_KEY],
    ], 401)]);

    $conversation = $this->makeConversation();

    app(AgentRunner::class)->agent($this->makeAgent([
        'default_provider' => 'openai',
        'default_model' => 'gpt-4o-mini',
    ]))->inConversation($conversation)->run('Hello');

    foreach (Message::query()->get() as $message) {
        expect((string) $message->content)->not->toContain(LEAK_KEY);
    }
});

it('writes nothing to the log that contains a credential', function (): void {
    $logged = [];

    Log::listen(function (MessageLogged $entry) use (&$logged): void {
        $logged[] = $entry->message.' '.json_encode($entry->context);
    });

    Http::fake(['api.openai.test/*' => Http::response([
        'error' => ['message' => 'Incorrect API key provided: '.LEAK_KEY],
    ], 500)]);

    $run = $this->makeRun(['agent_id' => $this->makeAgent([
        'default_provider' => 'openai',
        'default_model' => 'gpt-4o-mini',
    ])->getKey()]);

    // RunFailer is where an UNCLASSIFIED failure is logged in full, which is
    // exactly the path where a provider's prose reaches a log shipper.
    app(RunFailer::class)->fail(
        $run,
        new RuntimeException('Incorrect API key provided: '.LEAK_KEY),
    );

    expect($logged)->not->toBeEmpty()
        ->and(implode("\n", $logged))->not->toContain(LEAK_KEY);
});

it('carries no credential on a serialised job payload', function (): void {
    $run = $this->makeRun();

    $payload = serialize(new ContinueAgentRun($run->getKey(), 'acme', null, null));

    // A job payload is written to the jobs table and from there into every
    // database backup, so this is the one that outlives everything.
    expect($payload)->not->toContain(LEAK_KEY);
});

it('refuses outright to serialise a resolved credential', function (): void {
    app(CredentialManager::class)->issue('openai', LEAK_KEY);

    $credential = app(CredentialManager::class)->resolve('openai');

    expect($credential?->secret())->toBe(LEAK_KEY)
        ->and(fn (): string => serialize($credential))
        ->toThrow(LogicException::class);
});

it('keeps the credential out of an exception a host application might log', function (): void {
    Http::fake(['api.openai.test/*' => Http::response([
        'error' => ['message' => 'Incorrect API key provided: '.LEAK_KEY],
    ], 401)]);

    $provider = app(ProviderManager::class)->chat('openai');

    try {
        $provider->chat(new ChatRequest(
            model: 'gpt-4o-mini',
            messages: [ChatMessage::user('Hi')],
        ));

        $this->fail('The adapter should have thrown.');
    } catch (ProviderException $e) {
        // The full message is retained for an administrator -- that is the
        // documented contract -- but the message a USER sees must not carry
        // it, and neither must the trace, which is what ends up in Sentry.
        expect($e->userMessage())->not->toContain(LEAK_KEY)
            ->and($e->getTraceAsString())->not->toContain(LEAK_KEY);
    }
});

it('keeps the credential out of the broadcast payloads a browser receives', function (): void {
    Http::fake(['api.openai.test/*' => Http::response([
        'error' => ['message' => 'Incorrect API key provided: '.LEAK_KEY],
    ], 401)]);

    $broadcast = [];

    Event::listen(
        PandoraBroadcastEvent::class,
        function (object $event) use (&$broadcast): void {
            $broadcast[] = method_exists($event, 'broadcastWith') ? $event->broadcastWith() : [];
        },
    );

    app(AgentRunner::class)->agent($this->makeAgent([
        'default_provider' => 'openai',
        'default_model' => 'gpt-4o-mini',
    ]))->inConversation($this->makeConversation())->run('Hello');

    expect((string) json_encode($broadcast))->not->toContain(LEAK_KEY);
});

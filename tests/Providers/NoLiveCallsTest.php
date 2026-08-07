<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use Pandora\Providers\Adapters\OpenAiCompatibleProvider;
use Pandora\Providers\Data\ChatMessage;
use Pandora\Providers\Data\ChatRequest;

/**
 * Phase 3 acceptance criterion 13 -- no test performs a real network request.
 *
 * The guarantee is enforced in TestCase::setUp with preventStrayRequests, so
 * it covers every test in the suite rather than the ones whose authors
 * remembered. This file exists to prove the guard is actually armed: without
 * it, a forgotten fake would send a real request to a real provider with a
 * real key, which is the failure mode that makes contributors afraid to run
 * a suite.
 */
it('refuses any HTTP request that has no matching fake', function (): void {
    expect(fn () => Http::get('https://api.openai.com/v1/models'))
        ->toThrow(StrayRequestException::class, 'without a matching fake');
});

it('refuses a stray request made from inside an adapter', function (): void {
    $provider = new OpenAiCompatibleProvider(
        key: 'openai',
        config: ['base_url' => 'https://api.openai.com/v1', 'api_key' => 'sk-would-have-been-real'],
        http: app(HttpFactory::class),
    );

    expect(fn () => $provider->chat(new ChatRequest(
        model: 'gpt-4o-mini',
        messages: [ChatMessage::user('Hello')],
    )))->toThrow(StrayRequestException::class);
});

it('still allows a faked request through', function (): void {
    Http::fake(['*' => Http::response(['ok' => true])]);

    expect(Http::get('https://api.openai.test/v1/models')->json())->toBe(['ok' => true]);
});

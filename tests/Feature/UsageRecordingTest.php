<?php

declare(strict_types=1);

use Pandora\Agents\AgentRunner;
use Pandora\Exceptions\ImmutableRecord;
use Pandora\Exceptions\Provider\ProviderUnavailable;
use Pandora\Providers\Adapters\FakeProvider;
use Pandora\Providers\Catalog\ModelCatalog;
use Pandora\Providers\Data\ToolCall;
use Pandora\Providers\Data\UsageData;
use Pandora\Providers\ProviderManager;
use Pandora\Tests\Support\MakesRuns;
use Pandora\Usage\UsageRecord;

uses(MakesRuns::class);

/**
 * Phase 3 acceptance criteria 26 and 27 -- one record per call, and a cost
 * that says what it was based on.
 */
function pricedCatalog(): void
{
    app(ModelCatalog::class)->seedFromConfig([[
        'provider' => 'fake',
        'key' => 'fake-model',
        'input_price' => 1.00,
        'output_price' => 2.00,
        'currency' => 'USD',
        'pricing_source' => 'https://example.test/pricing',
        'pricing_date' => now()->toDateString(),
    ]]);
}

it('records one row per model call', function (): void {
    $this->fakeProvider()->willRespondWith('Done.', new UsageData(inputTokens: 120, outputTokens: 40));

    $run = app(AgentRunner::class)->agent($this->makeAgent())->run('Hello');

    $record = UsageRecord::query()->firstOrFail();

    expect(UsageRecord::query()->count())->toBe(1)
        ->and($record->run_id)->toBe($run->getKey())
        ->and($record->agent_id)->toBe($run->agent_id)
        ->and($record->reference())->toBe('fake/fake-model')
        ->and($record->input_tokens)->toBe(120)
        ->and($record->output_tokens)->toBe(40)
        ->and($record->total_tokens)->toBe(160)
        ->and($record->occurred_at)->not->toBeNull();
});

it('records a row for each provider a failed-over run touched', function (): void {
    config()->set('pandora.providers.connections', [
        'fake' => ['adapter' => 'fake'],
        'backup' => ['adapter' => 'fake'],
    ]);
    app()->forgetInstance(ProviderManager::class);

    /** @var FakeProvider $primary */
    $primary = app(ProviderManager::class)->provider('fake');
    /** @var FakeProvider $backup */
    $backup = app(ProviderManager::class)->provider('backup');

    $primary->willThrow(new ProviderUnavailable('Down', 'fake', 'fake-model'));
    $backup->willRespondWith('From the backup.');

    app(AgentRunner::class)->agent($this->makeAgent([
        'fallback_models' => ['backup/backup-model'],
    ]))->run('Hello');

    // The failed call spent nothing and left no record; the successful one
    // did. A single aggregated row per run would have hidden which provider
    // the money actually went to.
    expect(UsageRecord::query()->pluck('provider_key')->all())->toBe(['backup']);
});

it('estimates cost and stamps the source and date it used', function (): void {
    pricedCatalog();

    $this->fakeProvider()->willRespondWith('Done.', new UsageData(
        inputTokens: 1_000_000,
        outputTokens: 500_000,
    ));

    app(AgentRunner::class)->agent($this->makeAgent())->run('Hello');

    $record = UsageRecord::query()->firstOrFail();

    // $1.00 + $1.00 = $2.00, in micro units.
    expect($record->cost_micro)->toBe(2_000_000)
        ->and($record->costMinor())->toBe(200)
        ->and($record->currency)->toBe('USD')
        ->and($record->pricing_source)->toBe('https://example.test/pricing')
        ->and($record->pricing_date?->toDateString())->toBe(now()->toDateString())
        ->and($record->pricing_stale)->toBeFalse();
});

it('records a null cost for an unpriced model rather than zero', function (): void {
    $this->fakeProvider()->willRespondWith('Done.', new UsageData(inputTokens: 500, outputTokens: 100));

    app(AgentRunner::class)->agent($this->makeAgent())->run('Hello');

    $record = UsageRecord::query()->firstOrFail();

    // Zero would sum into a total that looks like a fact, and nobody would
    // ever learn the catalog has no prices in it.
    expect($record->cost_micro)->toBeNull()
        ->and($record->costMinor())->toBeNull()
        ->and($record->total_tokens)->toBe(600);
});

it('marks a record priced from a stale catalog entry', function (): void {
    config()->set('pandora.models.pricing_stale_after_days', 30);

    app(ModelCatalog::class)->seedFromConfig([[
        'provider' => 'fake',
        'key' => 'fake-model',
        'input_price' => 1.00,
        'output_price' => 2.00,
        'pricing_source' => 'https://example.test/pricing',
        'pricing_date' => now()->subDays(90)->toDateString(),
    ]]);

    $this->fakeProvider()->willRespondWith('Done.', new UsageData(inputTokens: 1_000));

    app(AgentRunner::class)->agent($this->makeAgent())->run('Hello');

    // The cost is still recorded. It is the confidence that changes, and the
    // record carries it so a report cannot quietly become authoritative.
    expect(UsageRecord::query()->firstOrFail()->pricing_stale)->toBeTrue()
        ->and(UsageRecord::query()->firstOrFail()->cost_micro)->toBe(1_000);
});

it('records usage for each iteration of a multi-turn run', function (): void {
    $this->fakeProvider()
        ->willRequestTools([new ToolCall('call_1', 'inspect_run_status')])
        ->willRespondWith('All done.');

    app(AgentRunner::class)->agent($this->makeAgent([
        'tool_policy' => ['allow' => ['inspect_run_status']],
    ]))->run('Check the run');

    expect(UsageRecord::query()->count())->toBe(2);
});

it('refuses to let a measurement be edited afterwards', function (): void {
    $this->fakeProvider()->willRespondWith('Done.');

    app(AgentRunner::class)->agent($this->makeAgent())->run('Hello');

    $record = UsageRecord::query()->firstOrFail();

    expect(function () use ($record): void {
        $record->forceFill(['input_tokens' => 0])->save();
    })->toThrow(ImmutableRecord::class);
});

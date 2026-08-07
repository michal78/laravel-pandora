<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Pandora\UI\Livewire\UsageIndex;
use Pandora\Usage\UsageRecord;

/**
 * Phase 3 acceptance criterion 37 -- volume and money are two abilities, not
 * one.
 */
function usageRow(array $attributes = []): UsageRecord
{
    /** @var UsageRecord $record */
    $record = UsageRecord::query()->create(array_merge([
        'provider_key' => 'openai',
        'model_key' => 'gpt-4o-mini',
        'input_tokens' => 1_000,
        'output_tokens' => 250,
        'total_tokens' => 1_250,
        'requests' => 1,
        'duration_ms' => 640,
        'cost_micro' => 300_000,
        'currency' => 'USD',
        'occurred_at' => Carbon::now(),
    ], $attributes));

    return $record;
}

beforeEach(function (): void {
    Gate::define('pandora.usage.view', static fn (): bool => true);
    Gate::define('pandora.costs.view', static fn (): bool => true);
});

it('renders volume for a user with pandora.usage.view', function (): void {
    usageRow();
    $this->actingAsUser();

    Livewire::test(UsageIndex::class)
        ->assertOk()
        ->assertSee('openai/gpt-4o-mini')
        ->assertSee('1,250');
});

it('denies a user without pandora.usage.view', function (): void {
    Gate::define('pandora.usage.view', static fn (): bool => false);
    $this->actingAsUser();

    Livewire::test(UsageIndex::class)->assertForbidden();
});

it('hides cost from a user who may see usage but not money', function (): void {
    Gate::define('pandora.costs.view', static fn (): bool => false);

    usageRow();
    $this->actingAsUser();

    $rendered = Livewire::test(UsageIndex::class)->html();

    // The column is omitted from the query, not hidden with CSS.
    expect($rendered)->toContain('1,250')
        ->and($rendered)->not->toContain('0.3000');
});

it('shows cost to a user who may see money', function (): void {
    usageRow();
    $this->actingAsUser();

    Livewire::test(UsageIndex::class)->assertSee('0.3000 USD');
});

it('names unpriced calls instead of folding them into the total', function (): void {
    usageRow(['cost_micro' => null]);
    usageRow();

    $this->actingAsUser();

    // A partial sum presented as a whole one is worse than no sum.
    Livewire::test(UsageIndex::class)->assertSee('1 unpriced');
});

it('flags a total built from stale prices', function (): void {
    usageRow(['pricing_stale' => true]);

    $this->actingAsUser();

    Livewire::test(UsageIndex::class)->assertSee('stale pricing');
});

it('respects the selected period', function (): void {
    usageRow(['occurred_at' => Carbon::now()->subMonths(3), 'model_key' => 'ancient-model']);
    usageRow(['model_key' => 'current-model']);

    $this->actingAsUser();

    Livewire::test(UsageIndex::class)
        ->set('period', 'month')
        ->assertSee('current-model')
        ->assertDontSee('ancient-model')
        ->set('period', 'all')
        ->assertSee('ancient-model');
});

it('says so plainly when there is nothing to report', function (): void {
    $this->actingAsUser();

    Livewire::test(UsageIndex::class)->assertSee('No usage recorded in this period.');
});

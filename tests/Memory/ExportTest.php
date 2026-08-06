<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Pandora\Pandora\Audit\AuditLog;
use Pandora\Pandora\Memory\Enums\MemoryScope;
use Pandora\Pandora\Memory\Enums\MemorySource;
use Pandora\Pandora\Memory\Enums\MemoryStatus;
use Pandora\Pandora\Memory\Enums\MemoryType;
use Pandora\Pandora\Memory\MemoryExporter;
use Pandora\Pandora\Memory\MemoryItem;

/**
 * Phase 5, criterion 15 -- the most dangerous read in the system.
 *
 * One call returns everything an agent believes about a person, in plain text,
 * in a file that leaves the application. It is gated, it is audited at
 * `warning`, and it does one scope at a time -- because every legitimate use
 * is one subject at a time and the illegitimate one is the one that would want
 * a flag to dump everything.
 */
beforeEach(function (): void {
    Gate::define('pandora.memory.manage', static fn (): bool => true);
    $this->actingAsUser();

    $this->exporter = app(MemoryExporter::class);
});

/**
 * @param array<string, mixed> $attributes
 */
function exportableMemory(string $content, array $attributes = []): MemoryItem
{
    /** @var MemoryItem $item */
    $item = MemoryItem::query()->create(array_merge([
        'scope' => MemoryScope::User->value,
        'scope_id' => 'App\\Models\\User#1',
        'type' => MemoryType::UserFact->value,
        'content' => $content,
        'source' => MemorySource::User->value,
    ], $attributes));

    return $item;
}

it('exports one scope, versioned', function (): void {
    exportableMemory('books the aisle seat');
    exportableMemory('prefers morning meetings');

    $export = $this->exporter->export(MemoryScope::User, 'App\\Models\\User#1');

    expect($export['version'])->toBe(MemoryExporter::VERSION)
        ->and($export['scope'])->toBe('user')
        ->and($export['scope_id'])->toBe('App\\Models\\User#1')
        ->and($export['count'])->toBe(2)
        ->and($export['items'])->toHaveCount(2)
        ->and($export['items'][0]['content'])->toBe('books the aisle seat');
});

it('exports nobody else', function (): void {
    exportableMemory('mine');
    exportableMemory('theirs', ['scope_id' => 'App\\Models\\User#2']);

    $export = $this->exporter->export(MemoryScope::User, 'App\\Models\\User#1');

    expect($export['count'])->toBe(1)
        ->and(json_encode($export, JSON_THROW_ON_ERROR))->not->toContain('theirs');
});

it('omits suggested, rejected and expired items unless asked', function (): void {
    exportableMemory('active');
    exportableMemory('suggested', ['status' => MemoryStatus::Suggested->value]);
    exportableMemory('rejected', ['status' => MemoryStatus::Rejected->value]);

    expect($this->exporter->export(MemoryScope::User, 'App\\Models\\User#1')['count'])->toBe(1);

    // A subject access request legitimately wants everything held about them,
    // including what was proposed and refused.
    expect($this->exporter->export(MemoryScope::User, 'App\\Models\\User#1', includeInactive: true)['count'])
        ->toBe(3);
});

it('does not export vectors', function (): void {
    exportableMemory('books the aisle seat');

    $export = $this->exporter->export(MemoryScope::User, 'App\\Models\\User#1');

    // Derived, enormous, and meaningless outside the model that made them.
    expect($export['items'][0])->not->toHaveKey('vector')
        ->and($export['items'][0])->not->toHaveKey('embedding_id');
});

it('carries provenance and sensitivity into the export', function (): void {
    exportableMemory('books the aisle seat', [
        'source' => MemorySource::Agent->value,
        'provenance' => ['stated_in' => 'conversation'],
        'confidence' => 70,
    ]);

    $item = $this->exporter->export(MemoryScope::User, 'App\\Models\\User#1')['items'][0];

    // "The agent inferred this" and "you told us this" are different claims
    // about the same sentence, and the person reading needs to tell which.
    expect($item['source'])->toBe('agent')
        ->and($item['provenance'])->toBe(['stated_in' => 'conversation'])
        ->and($item['confidence'])->toBe(70)
        ->and($item['sensitivity'])->toBe('normal');
});

it('records an export as a warning, not a page view', function (): void {
    exportableMemory('books the aisle seat');

    $this->exporter->export(MemoryScope::User, 'App\\Models\\User#1');

    $audit = AuditLog::query()->where('action', 'memory.exported')->first();

    expect($audit)->not->toBeNull()
        ->and($audit->severity)->toBe('warning')
        ->and($audit->metadata['count'])->toBe(1)
        ->and($audit->metadata['scope_id'])->toBe('App\\Models\\User#1');
});

it('refuses without the manage ability', function (): void {
    Gate::define('pandora.memory.manage', static fn (): bool => false);

    expect(fn () => $this->exporter->export(MemoryScope::User, 'App\\Models\\User#1'))
        ->toThrow(AuthorizationException::class);
});

it('does not cross tenants', function (): void {
    inTenant('acme', fn () => exportableMemory('acme fact'));

    $export = inTenant('globex', fn () => $this->exporter->export(MemoryScope::User, 'App\\Models\\User#1'));

    expect($export['count'])->toBe(0);
});

it('is exposed as a command that insists on a scope id', function (): void {
    exportableMemory('books the aisle seat');

    $this->artisan('pandora:memory:export', ['scope' => 'user'])
        ->expectsOutputToContain('needs --id')
        ->assertFailed();

    $this->artisan('pandora:memory:export', ['scope' => 'user', '--id' => 'App\\Models\\User#1'])
        ->assertSuccessful();
});

it('rejects an unknown scope from the command', function (): void {
    $this->artisan('pandora:memory:export', ['scope' => 'everything'])
        ->expectsOutputToContain('Unknown scope')
        ->assertFailed();
});

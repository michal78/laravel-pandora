<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Pandora\Memory\Enums\MemoryScope;
use Pandora\Memory\Enums\MemorySensitivity;
use Pandora\Memory\Enums\MemorySource;
use Pandora\Memory\Enums\MemoryStatus;
use Pandora\Memory\Enums\MemoryType;
use Pandora\Memory\MemoryItem;
use Pandora\UI\Livewire\MemoryIndex;

/**
 * Phase 5 -- the Memory page.
 *
 * The review queue is the reason this page exists. Everything else is a table.
 */
beforeEach(function (): void {
    Gate::define('pandora.access', static fn (): bool => true);
    Gate::define('pandora.memory.manage', static fn (): bool => true);
    $this->actingAsUser();
});

/**
 * @param array<string, mixed> $attributes
 */
function pageMemory(string $content, array $attributes = []): MemoryItem
{
    /** @var MemoryItem $item */
    $item = MemoryItem::query()->create(array_merge([
        'scope' => MemoryScope::Tenant->value,
        'scope_id' => null,
        'type' => MemoryType::AgentCurated->value,
        'content' => $content,
        'source' => MemorySource::Agent->value,
    ], $attributes));

    return $item;
}

it('shows active memory', function (): void {
    pageMemory('deploys go out on thursday');

    Livewire::test(MemoryIndex::class)
        ->assertOk()
        ->assertSee('deploys go out on thursday');
});

it('puts suggestions in their own queue, above everything else', function (): void {
    pageMemory('a suggestion nobody has read', [
        'status' => MemoryStatus::Suggested->value,
        'sensitivity' => MemorySensitivity::Sensitive->value,
    ]);

    Livewire::test(MemoryIndex::class)
        ->assertSee('Awaiting review')
        ->assertSee('a suggestion nobody has read')
        // Said on the page, because it is the thing an operator most needs to
        // know while deciding.
        ->assertSee('Not retrievable by any agent until approved');
});

it('shows the whole memory rather than a preview', function (): void {
    $long = 'They prefer to be contacted in the afternoon because of a standing commitment '
        .'on Tuesday mornings which they would rather not discuss in detail.';

    pageMemory($long, ['status' => MemoryStatus::Suggested->value]);

    // Truncating the one column that says what an agent will repeat about
    // somebody makes the reviewer approve a preview.
    Livewire::test(MemoryIndex::class)->assertSee($long);
});

it('approves a suggestion', function (): void {
    $item = pageMemory('a suggestion', ['status' => MemoryStatus::Suggested->value]);

    Livewire::test(MemoryIndex::class)
        ->call('approve', $item->getKey())
        ->assertSee('Approved.');

    expect($item->refresh()->status)->toBe(MemoryStatus::Active);
});

it('rejects a suggestion', function (): void {
    $item = pageMemory('a suggestion', ['status' => MemoryStatus::Suggested->value]);

    Livewire::test(MemoryIndex::class)
        ->call('reject', $item->getKey())
        ->assertSee('Rejected.');

    expect($item->refresh()->status)->toBe(MemoryStatus::Rejected);
});

it('forgets a memory', function (): void {
    $item = pageMemory('something to forget');

    Livewire::test(MemoryIndex::class)
        ->call('forget', $item->getKey())
        ->assertSee('Forgotten.');

    expect(MemoryItem::query()->count())->toBe(0);
});

/**
 * Found by the Phase 5 walkthrough: reading the page needed only
 * `pandora.access`, and the listing is filtered by scope and status but never
 * by actor -- so an ordinary chat user could read every user-scoped memory
 * belonging to every person, sensitive ones included. Reading is an operator
 * act here, the same as approving.
 */
it('refuses to open at all without pandora.memory.manage', function (): void {
    Gate::define('pandora.memory.manage', static fn (): bool => false);

    $mine = pageMemory("Michal's home address", [
        'scope' => MemoryScope::User->value,
        'scope_id' => 'App\Models\User#1',
    ]);

    Livewire::test(MemoryIndex::class)->assertForbidden();

    // Not merely hidden behind a filter: the content never reaches the page.
    $this->get(route('pandora.memory'))
        ->assertForbidden()
        ->assertDontSee($mine->content);
});

it('keeps Memory out of the sidebar for a user who cannot open it', function (): void {
    Gate::define('pandora.memory.manage', static fn (): bool => false);

    // Not the boundary -- mount refuses too -- but a sidebar offering a link
    // that answers 403 teaches people to ignore authorization errors.
    $this->get(route('pandora.dashboard'))->assertOk()->assertDontSee('>Memory<', false);
});

it('refuses an approval forged after the page was opened', function (): void {
    $item = pageMemory('a suggestion', ['status' => MemoryStatus::Suggested->value]);

    // Opened while the ability was held, replayed after it was withdrawn --
    // which is the shape a forged request takes when the page is already up.
    $page = Livewire::test(MemoryIndex::class);

    Gate::define('pandora.memory.manage', static fn (): bool => false);

    $page->call('approve', $item->getKey())->assertForbidden();

    expect($item->refresh()->status)->toBe(MemoryStatus::Suggested);
});

it('answers a memory id from another tenant the way it answers one that never existed', function (): void {
    $foreign = inTenant('acme', fn () => pageMemory('acme memory'));

    inTenant('globex', function () use ($foreign): void {
        Livewire::test(MemoryIndex::class)
            ->call('forget', $foreign->getKey())
            ->assertSee('no longer available');
    });

    expect($foreign->refresh()->trashed())->toBeFalse();
});

it('filters by scope and by status', function (): void {
    pageMemory('a tenant memory', ['scope' => MemoryScope::Tenant->value, 'scope_id' => null]);
    pageMemory('an agent memory', ['scope' => MemoryScope::Agent->value, 'scope_id' => 'agent-1']);
    pageMemory('a rejected memory', ['status' => MemoryStatus::Rejected->value]);

    Livewire::test(MemoryIndex::class)
        ->set('scopeFilter', MemoryScope::Agent->value)
        ->assertSee('an agent memory')
        ->assertDontSee('a tenant memory');

    Livewire::test(MemoryIndex::class)
        ->set('statusFilter', MemoryStatus::Rejected->value)
        ->assertSee('a rejected memory')
        ->assertDontSee('a tenant memory');
});

it('searches regardless of case', function (): void {
    pageMemory('DEPLOYS go out on Thursday');

    // The same engine difference the retriever hit: PostgreSQL's LIKE is
    // case-sensitive and the other three are not.
    Livewire::test(MemoryIndex::class)
        ->set('search', 'deploys')
        ->assertSee('DEPLOYS go out on Thursday');
});

it('counts what is active, awaiting review and expired', function (): void {
    pageMemory('active one');
    pageMemory('suggested one', ['status' => MemoryStatus::Suggested->value]);
    pageMemory('expired one', [
        'status' => MemoryStatus::Expired->value,
        'expires_at' => Date::now()->subDay(),
    ]);

    Livewire::test(MemoryIndex::class)
        ->assertSee('Awaiting review')
        ->assertSee('Expired');
});

it('requires pandora.access to open at all', function (): void {
    Gate::define('pandora.access', static fn (): bool => false);

    Livewire::test(MemoryIndex::class)->assertForbidden();
});

it('is reachable over HTTP', function (): void {
    $this->get(route('pandora.memory'))->assertOk()->assertSee('Memory');
});

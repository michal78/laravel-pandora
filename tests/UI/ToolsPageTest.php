<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Pandora\Pandora\Tests\Fixtures\Tools\LegacyLookupTool;
use Pandora\Pandora\Tests\Fixtures\Tools\LookupOrderTool;
use Pandora\Pandora\Tests\Fixtures\Tools\RefundOrderTool;
use Pandora\Pandora\Tests\Support\MakesTools;
use Pandora\Pandora\Tools\ToolRegistry;
use Pandora\Pandora\UI\Livewire\ToolsIndex;

/**
 * Phase 2 acceptance criteria 29 and 31 — the Tools page, and who may read a
 * tool's argument schema.
 */
uses(MakesTools::class);

beforeEach(function (): void {
    app(ToolRegistry::class)->flush()->registerMany([
        LookupOrderTool::class,
        LegacyLookupTool::class,
        RefundOrderTool::class,
    ]);
});

it('renders the catalogue for an authorized user', function (): void {
    $this->actingAsUser();

    Livewire::test(ToolsIndex::class)
        ->assertOk()
        ->assertSee('lookup_order')
        ->assertSee('refund_order')
        ->assertSee('High');
});

it('denies a user without access', function (): void {
    Gate::define('pandora.access', static fn (): bool => false);
    $this->actingAsUser();

    Livewire::test(ToolsIndex::class)->assertForbidden();
});

it('shows every version, and flags a deprecated one', function (): void {
    $this->actingAsUser();

    Livewire::test(ToolsIndex::class)
        ->assertSee('0.9')
        ->assertSee('Deprecated');
});

it('filters by group', function (): void {
    $this->actingAsUser();

    Livewire::test(ToolsIndex::class)
        ->set('groupFilter', 'billing')
        ->assertSee('refund_order')
        ->assertDontSee('lookup_order');
});

it('shows which tools will pause for approval', function (): void {
    $this->actingAsUser();

    Livewire::test(ToolsIndex::class)->assertSee('Required');
});

it('hides argument schemas from a user without tools.io.view', function (): void {
    Gate::define('pandora.tools.io.view', static fn (): bool => false);
    $this->actingAsUser();

    Livewire::test(ToolsIndex::class)
        ->assertDontSee('amount_minor')
        ->assertSee('pandora.tools.io.view');
});

it('shows a schema to a user who may read one', function (): void {
    Gate::define('pandora.tools.io.view', static fn (): bool => true);
    $this->actingAsUser();

    Livewire::test(ToolsIndex::class)
        ->call('toggle', 'refund_order@1.0')
        ->assertSee('amount_minor');
});

it('explains an empty catalogue rather than showing a blank table', function (): void {
    app(ToolRegistry::class)->flush();
    $this->actingAsUser();

    Livewire::test(ToolsIndex::class)
        ->assertSee('No tools are installed')
        ->assertSee('tools.registered');
});

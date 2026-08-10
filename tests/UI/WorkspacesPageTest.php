<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Pandora\Audit\AuditLog;
use Pandora\UI\Livewire\WorkspacesIndex;
use Pandora\Workspaces\Workspace;

/**
 * Phase 5 -- the Workspaces page.
 *
 * Browsing goes through `WorkspaceFiles`, so the control center is subject to
 * exactly the same containment rules as an agent. A page that could show a
 * file an agent cannot read would be a way to confirm what lives outside the
 * root.
 */
beforeEach(function (): void {
    Gate::define('pandora.access', static fn (): bool => true);
    Gate::define('pandora.workspaces.access', static fn (): bool => true);

    // The surface is deferred to Phase 7 and ships disabled. The behaviour
    // below is finished, so it stays covered here rather than being deleted
    // and rewritten a phase later -- which is how tested code becomes
    // untested code. See the tests at the foot of this file for the off state.
    config()->set('pandora.features.workspaces', true);

    $this->actingAsUser();

    $this->root = sys_get_temp_dir().'/pandora-wspage-'.bin2hex(random_bytes(6));
    $this->outside = sys_get_temp_dir().'/pandora-wsout-'.bin2hex(random_bytes(6));

    mkdir($this->root.'/reports', 0777, true);
    mkdir($this->outside, 0777, true);

    file_put_contents($this->root.'/notes.txt', 'workspace notes');
    file_put_contents($this->root.'/reports/q1.txt', 'quarterly');
    file_put_contents($this->outside.'/secret.txt', 'not yours');

    /** @var Workspace $workspace */
    $workspace = Workspace::query()->create([
        'name' => 'Scratch',
        'slug' => 'scratch',
        'disk' => 'local',
        'root_path' => $this->root,
        'quota_bytes' => 1000,
    ]);

    $this->workspace = $workspace;
});

afterEach(function (): void {
    foreach ([$this->root.'/reports', $this->root, $this->outside] as $dir) {
        if (! is_dir($dir)) {
            continue;
        }

        foreach (glob($dir.'/*') ?: [] as $file) {
            is_file($file) || is_link($file) ? unlink($file) : null;
        }

        @rmdir($dir);
    }
});

it('lists workspaces with their usage', function (): void {
    Livewire::test(WorkspacesIndex::class)
        ->assertOk()
        ->assertSee('Scratch')
        ->assertSee($this->root);
});

it('says plainly when there are no workspaces', function (): void {
    $this->workspace->delete();

    Livewire::test(WorkspacesIndex::class)
        ->assertSee('An agent without one can reach no files at all');
});

it('browses inside the workspace', function (): void {
    Livewire::test(WorkspacesIndex::class)
        ->call('select', 'scratch')
        ->assertSee('notes.txt')
        ->assertSee('reports')
        ->call('browse', 'reports')
        ->assertSee('reports/q1.txt');
});

it('offers Open for a directory and Download for a file, never the other way round', function (): void {
    // The Phase 7 walkthrough clicked Open on a 12-byte text file. The page
    // browsed into it as though it were a prefix, listed nothing, and
    // rendered "Empty." -- a file with bytes in it reading as an empty
    // folder, on the page whose own comment says nothing invents an empty
    // folder on the object store.
    $component = Livewire::test(WorkspacesIndex::class)->call('select', 'scratch');

    $component->assertSeeHtml("wire:click=\"browse('reports')\"")
        ->assertDontSeeHtml("wire:click=\"browse('notes.txt')\"")
        ->assertSeeHtml('path=notes.txt');
});

it('does not strand the browser inside a file', function (): void {
    $component = Livewire::test(WorkspacesIndex::class)
        ->call('select', 'scratch')
        ->call('browse', 'notes.txt');

    // Even reached directly, a file is not a place the listing pretends to be.
    expect($component->get('path'))->toBe('notes.txt');

    $component->assertDontSeeHtml("wire:click=\"browse('notes.txt')\"");
});

it('goes back up without ever producing a traversal', function (): void {
    $component = Livewire::test(WorkspacesIndex::class)
        ->call('select', 'scratch')
        ->call('browse', 'reports')
        ->call('up');

    // Trimmed rather than expressed as `..`, so the one place that must never
    // see a traversal is not routinely handed one.
    expect($component->get('path'))->toBe('');
});

it('does not show a symlink that escapes the root', function (): void {
    symlink($this->outside.'/secret.txt', $this->root.'/innocent.txt');

    Livewire::test(WorkspacesIndex::class)
        ->call('select', 'scratch')
        ->assertSee('notes.txt')
        ->assertDontSee('innocent.txt');
});

it('reports an unreachable root instead of failing', function (): void {
    /** @var Workspace $gone */
    $gone = Workspace::query()->create([
        'name' => 'Gone',
        'slug' => 'gone',
        'disk' => 'local',
        'root_path' => $this->root.'/not-here',
    ]);

    // An operator arriving to find out why an agent cannot read its files
    // should see the reason, not a stack trace.
    Livewire::test(WorkspacesIndex::class)
        ->call('select', $gone->slug)
        ->assertOk()
        ->assertSee('root is missing');
});

it('recounts usage from the filesystem', function (): void {
    $this->workspace->update(['used_bytes' => 9999]);

    Livewire::test(WorkspacesIndex::class)
        ->call('select', 'scratch')
        ->call('recount')
        ->assertSee('Recounted');

    // 'workspace notes' (15) + 'quarterly' (9)
    expect($this->workspace->refresh()->used_bytes)->toBe(24);
});

it('records a recount, because it moves the line a write is refused at', function (): void {
    $this->workspace->update(['used_bytes' => 9999]);

    Livewire::test(WorkspacesIndex::class)
        ->call('select', 'scratch')
        ->call('recount');

    $entry = AuditLog::query()->where('action', 'workspace.recounted')->firstOrFail();

    expect($entry->metadata['used_bytes_before'])->toBe(9999)
        ->and($entry->metadata['used_bytes_after'])->toBe(24)
        ->and($this->workspace->refresh()->used_bytes)->toBe(24);
});

it('refuses a forged recount from a user without the ability', function (): void {
    Gate::define('pandora.workspaces.access', static fn (): bool => false);

    Livewire::test(WorkspacesIndex::class)
        ->call('select', 'scratch')
        ->call('recount')
        ->assertForbidden();
});

it('does not show another tenant\'s workspace', function (): void {
    inTenant('acme', function (): void {
        Workspace::query()->create([
            'name' => 'Acme only',
            'slug' => 'acme-only',
            'disk' => 'local',
            'root_path' => $this->root,
        ]);
    });

    inTenant('globex', function (): void {
        Livewire::test(WorkspacesIndex::class)
            ->assertDontSee('Acme only')
            ->call('select', 'acme-only')
            ->assertDontSee('notes.txt');
    });
});

/**
 * Phase 7, criterion 18 — the isolation holds for every verb, not just the
 * listing.
 *
 * A page that hides another tenant's workspace and then acts on it when asked
 * by slug is not isolated; it is politely arranged. Every lookup goes through
 * the model's tenant scope, so the row is not merely filtered out of a view --
 * it is not found.
 */
it('does not act on another tenant\'s workspace when handed its slug', function (): void {
    $acme = inTenant('acme', function (): Workspace {
        /** @var Workspace $workspace */
        $workspace = Workspace::query()->create([
            'name' => 'Acme only',
            'slug' => 'acme-only',
            'disk' => 'local',
            'root_path' => $this->root,
            'quota_bytes' => 4096,
            'used_bytes' => 9999,
        ]);

        return $workspace;
    });

    inTenant('globex', function (): void {
        Livewire::test(WorkspacesIndex::class)
            ->call('startEditing', 'acme-only')
            ->set('formName', 'Stolen')
            ->set('formQuota', '1')
            ->call('save')
            ->call('select', 'acme-only')
            ->call('recount')
            ->call('delete', 'acme-only');
    });

    $acme->refresh();

    expect($acme->name)->toBe('Acme only')
        ->and($acme->quota_bytes)->toBe(4096)
        // Not recounted either: a recount is a write, and it is also a way to
        // learn how many bytes a workspace you cannot see is holding.
        ->and($acme->used_bytes)->toBe(9999)
        ->and($acme->exists)->toBeTrue();
});

it('requires pandora.access to open at all', function (): void {
    Gate::define('pandora.access', static fn (): bool => false);

    Livewire::test(WorkspacesIndex::class)->assertForbidden();
});

it('is reachable over HTTP', function (): void {
    $this->get(route('pandora.workspaces'))->assertOk()->assertSee('Workspaces');
});

/**
 * The off state, which is what actually ships until Phase 7.
 *
 * A feature flag that is never tested in its default position is a feature
 * flag that works in exactly the configuration nobody runs.
 */
it('says the feature is coming rather than listing workspaces', function (): void {
    config()->set('pandora.features.workspaces', false);

    Livewire::test(WorkspacesIndex::class)
        ->assertOk()
        ->assertSee('not here yet')
        // The workspace exists in the database and is still not named here.
        ->assertDontSee('Scratch');
});

it('reaches no file at all while the feature is off', function (): void {
    config()->set('pandora.features.workspaces', false);

    Livewire::test(WorkspacesIndex::class)
        ->set('selected', 'scratch')
        ->set('path', 'reports')
        ->assertOk()
        // Not a listing narrowed by permission -- no listing was taken.
        ->assertDontSee('notes.txt')
        ->assertDontSee('q1.txt');
});

it('withholds the page from an operator holding every ability', function (): void {
    config()->set('pandora.features.workspaces', false);

    Gate::before(static fn (): bool => true);

    Livewire::test(WorkspacesIndex::class)
        ->assertOk()
        ->assertSee('not here yet')
        ->assertDontSee('Scratch');
});

/**
 * Criterion 19, in the form that actually matters. Withholding a page is not
 * withholding a feature: the page is where a flag gets honoured, and a forged
 * Livewire call is exactly the request that never renders one.
 */
it('withholds every action behind the flag, not just the page', function (string $action, array $arguments): void {
    config()->set('pandora.features.workspaces', false);

    Gate::before(static fn (): bool => true);

    Livewire::test(WorkspacesIndex::class)
        ->set('selected', 'scratch')
        ->call($action, ...$arguments)
        ->assertNotFound();
})->with([
    ['recount', []],
    ['startCreating', []],
    ['startEditing', ['scratch']],
    ['save', []],
    ['delete', ['scratch']],
]);

it('leaves the workspace untouched by an action forged while the flag is off', function (): void {
    config()->set('pandora.features.workspaces', false);

    Gate::before(static fn (): bool => true);

    $this->workspace->update(['used_bytes' => 9999]);

    Livewire::test(WorkspacesIndex::class)->set('selected', 'scratch')->call('recount');

    expect($this->workspace->refresh()->used_bytes)->toBe(9999)
        ->and(Workspace::query()->count())->toBe(1);
});

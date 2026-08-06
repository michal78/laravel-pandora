<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Pandora\Pandora\UI\Livewire\WorkspacesIndex;
use Pandora\Pandora\Workspaces\Workspace;

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
        ->assertSee('coming in a later phase')
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
        ->assertSee('coming in a later phase')
        ->assertDontSee('Scratch');
});

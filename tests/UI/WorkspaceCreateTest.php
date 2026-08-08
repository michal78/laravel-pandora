<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Pandora\Agents\Agent;
use Pandora\Audit\AuditLog;
use Pandora\UI\Livewire\WorkspacesIndex;
use Pandora\Workspaces\Workspace;

/**
 * Phase 7, criterion 17 — a root outside the configured set is refused,
 * whatever the UI submits.
 *
 * Phase 5 deferred this surface for one reason: a form with a path field is a
 * form that accepts `/`. So the test worth writing is not "does traversal in
 * the path field get rejected" — it is that no path is transmitted at all. A
 * request carries a root KEY, the key is looked up in operator configuration,
 * and there is no code path from a browser to `disk` or `root_path` that does
 * not go through that lookup.
 */
beforeEach(function (): void {
    Gate::define('pandora.access', static fn (): bool => true);
    Gate::define('pandora.workspaces.access', static fn (): bool => true);

    config()->set('pandora.features.workspaces', true);

    $this->base = sys_get_temp_dir().'/pandora-roots-'.bin2hex(random_bytes(6));

    config()->set('pandora.workspaces.roots', [
        'scratch' => ['label' => 'Scratch', 'disk' => 'local', 'base_prefix' => $this->base],
    ]);

    $this->actingAsUser();
});

afterEach(function (): void {
    // Only ever directories: nothing in this file writes a file.
    foreach (array_reverse(glob($this->base.'/*/*') ?: []) as $dir) {
        @rmdir($dir);
    }

    foreach (glob($this->base.'/*') ?: [] as $dir) {
        @rmdir($dir);
    }

    @rmdir($this->base);
});

it('creates a workspace under a declared root, composing the path itself', function (): void {
    Livewire::test(WorkspacesIndex::class)
        ->call('startCreating')
        ->set('rootKey', 'scratch')
        ->set('formName', 'Quarterly Reports')
        ->set('formQuota', '4096')
        ->set('formMimeTypes', 'text/plain, application/pdf')
        ->call('create')
        ->assertHasNoErrors();

    /** @var Workspace $workspace */
    $workspace = Workspace::query()->where('slug', 'quarterly-reports')->firstOrFail();

    expect($workspace->disk)->toBe('local')
        ->and($workspace->root_path)->toBe($this->base.'/shared/quarterly-reports')
        ->and($workspace->quota_bytes)->toBe(4096)
        ->and($workspace->allowed_mime_types)->toBe(['text/plain', 'application/pdf'])
        // Created on disk, because LocalStorage measures containment with
        // realpath() and realpath() of a directory nobody made is false.
        ->and(is_dir($workspace->root_path))->toBeTrue();
});

it('refuses a root key nobody declared', function (): void {
    Livewire::test(WorkspacesIndex::class)
        ->call('startCreating')
        ->set('rootKey', 'production')
        ->set('formName', 'Sneaky')
        ->call('create')
        ->assertSee('not one an operator has made available');

    expect(Workspace::query()->count())->toBe(0);
});

/**
 * The forged submissions. A Livewire request can set any public property to
 * any string, so these are the real attack surface — and every one of them
 * lands in the same lookup, which either finds a declared root or refuses.
 */
it('refuses a root key that is a path', function (string $forged): void {
    Livewire::test(WorkspacesIndex::class)
        ->call('startCreating')
        ->set('rootKey', $forged)
        ->set('formName', 'Sneaky')
        ->call('create');

    expect(Workspace::query()->count())->toBe(0);
})->with([
    '/',
    '/etc',
    '../../etc',
    'scratch/../..',
    's3://other-bucket',
    'local',
    '',
]);

it('has no property binding a disk or a root path at all', function (): void {
    // The strongest form of criterion 17: not "the path is validated" but
    // "there is nothing for a request to put a path into". A Livewire request
    // can only set public properties, so this is the complete list of what a
    // browser can influence.
    $public = array_map(
        static fn (ReflectionProperty $property): string => $property->getName(),
        (new ReflectionClass(WorkspacesIndex::class))->getProperties(ReflectionProperty::IS_PUBLIC),
    );

    expect($public)->not->toContain('disk')
        ->and($public)->not->toContain('root_path')
        ->and($public)->not->toContain('rootPath');
});

it('cannot create anything when no root is configured', function (): void {
    config()->set('pandora.workspaces.roots', []);

    Livewire::test(WorkspacesIndex::class)
        ->call('startCreating')
        ->set('formName', 'Nowhere')
        // There is nothing to choose between, so the form says so instead of
        // offering a field that would have to be checked.
        ->assertSee('No workspace roots are configured')
        ->call('create');

    expect(Workspace::query()->count())->toBe(0);
});

it('refuses a name that produces no usable slug', function (): void {
    Livewire::test(WorkspacesIndex::class)
        ->call('startCreating')
        ->set('rootKey', 'scratch')
        ->set('formName', '...')
        ->call('create')
        ->assertSee('does not produce a usable slug');

    expect(Workspace::query()->count())->toBe(0);
});

it('refuses a duplicate name rather than sharing a prefix', function (): void {
    $create = function (): void {
        Livewire::test(WorkspacesIndex::class)
            ->call('startCreating')
            ->set('rootKey', 'scratch')
            ->set('formName', 'Reports')
            ->call('create');
    };

    $create();
    $create();

    expect(Workspace::query()->count())->toBe(1);
});

it('gives each tenant its own prefix under the same root', function (): void {
    $path = static function (string $tenant): string {
        return inTenant($tenant, static function (): string {
            Livewire::test(WorkspacesIndex::class)
                ->call('startCreating')
                ->set('rootKey', 'scratch')
                ->set('formName', 'Notes')
                ->call('create');

            /** @var Workspace $workspace */
            $workspace = Workspace::query()->where('slug', 'notes')->firstOrFail();

            return $workspace->root_path;
        });
    };

    expect($path('acme'))->not->toBe($path('globex'));
});

it('records the root a workspace was created under', function (): void {
    Livewire::test(WorkspacesIndex::class)
        ->call('startCreating')
        ->set('rootKey', 'scratch')
        ->set('formName', 'Audited')
        ->call('create');

    /** @var AuditLog $entry */
    $entry = AuditLog::query()->where('action', 'workspace.created')->firstOrFail();

    expect($entry->metadata['root'] ?? null)->toBe('scratch')
        ->and($entry->metadata['disk'] ?? null)->toBe('local');
});

/**
 * Editing, which is deliberately not editing the part that matters.
 */
it('edits the metadata and leaves the storage alone', function (): void {
    Livewire::test(WorkspacesIndex::class)
        ->call('startCreating')
        ->set('rootKey', 'scratch')
        ->set('formName', 'Before')
        ->call('create');

    /** @var Workspace $workspace */
    $workspace = Workspace::query()->where('slug', 'before')->firstOrFail();
    $path = $workspace->root_path;

    Livewire::test(WorkspacesIndex::class)
        ->call('startEditing', 'before')
        ->set('formName', 'After')
        ->set('formQuota', '512')
        ->set('formMimeTypes', 'text/plain')
        ->call('save')
        ->assertHasNoErrors();

    $workspace->refresh();

    expect($workspace->name)->toBe('After')
        ->and($workspace->quota_bytes)->toBe(512)
        ->and($workspace->allowed_mime_types)->toBe(['text/plain'])
        // The slug is half the uniqueness constraint and is what a bookmark
        // names; the root is what every file already written is named by.
        ->and($workspace->slug)->toBe('before')
        ->and($workspace->disk)->toBe('local')
        ->and($workspace->root_path)->toBe($path);
});

it('deletes the row, detaches its agents and leaves the files alone', function (): void {
    Livewire::test(WorkspacesIndex::class)
        ->call('startCreating')
        ->set('rootKey', 'scratch')
        ->set('formName', 'Doomed')
        ->call('create');

    /** @var Workspace $workspace */
    $workspace = Workspace::query()->where('slug', 'doomed')->firstOrFail();

    file_put_contents($workspace->root_path.'/keep.txt', 'still here');

    /** @var Agent $agent */
    $agent = Agent::query()->create([
        'name' => 'Filer',
        'slug' => 'filer',
        'workspace_id' => $workspace->getKey(),
    ]);

    Livewire::test(WorkspacesIndex::class)
        ->call('delete', 'doomed')
        // Said on the page, because bytes that outlive their row are a fact
        // somebody has to be told to act on.
        ->assertSee('files were left at');

    expect(Workspace::query()->count())->toBe(0)
        ->and($agent->refresh()->workspace_id)->toBeNull()
        // N deletes with no transaction around them is how a prefix ends up
        // half emptied under a row claiming it is gone.
        ->and(file_get_contents($workspace->root_path.'/keep.txt'))->toBe('still here');

    unlink($workspace->root_path.'/keep.txt');
});

it('records that a deletion did not remove the files', function (): void {
    Livewire::test(WorkspacesIndex::class)
        ->call('startCreating')
        ->set('rootKey', 'scratch')
        ->set('formName', 'Doomed')
        ->call('create');

    Livewire::test(WorkspacesIndex::class)->call('delete', 'doomed');

    /** @var AuditLog $entry */
    $entry = AuditLog::query()->where('action', 'workspace.deleted')->firstOrFail();

    expect($entry->metadata['files_removed'] ?? null)->toBeFalse();
});

/**
 * Who may, and — separately — whether the surface is there to reach at all.
 */
it('refuses creation from a user without the ability', function (): void {
    Gate::define('pandora.workspaces.access', static fn (): bool => false);

    Livewire::test(WorkspacesIndex::class)
        ->set('rootKey', 'scratch')
        ->set('formName', 'Forged')
        ->call('create')
        ->assertForbidden();

    expect(Workspace::query()->count())->toBe(0);
});

it('refuses a forged creation while the feature is off, ability or not', function (): void {
    config()->set('pandora.features.workspaces', false);

    // Every ability, and it changes nothing: a flag is not a permission, and
    // the page that would have honoured it is exactly what a forged Livewire
    // call skips.
    Gate::before(static fn (): bool => true);

    Livewire::test(WorkspacesIndex::class)
        ->set('rootKey', 'scratch')
        ->set('formName', 'Forged')
        ->call('create')
        ->assertNotFound();

    expect(Workspace::query()->count())->toBe(0);
});

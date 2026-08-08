<?php

declare(strict_types=1);

use Pandora\Exceptions\WorkspaceDenied;
use Pandora\Workspaces\WorkspaceRoots;

/**
 * Phase 7 — where a workspace is allowed to live.
 *
 * The creation surface exists on one property: a request names a KEY, and a
 * key can only be one an operator declared. Everything below is that property
 * from a different angle, because the failure being guarded against is not a
 * clever traversal — it is a `root_path` that came from a browser at all.
 */
beforeEach(function (): void {
    config()->set('pandora.workspaces.roots', [
        'scratch' => [
            'label' => 'Scratch space',
            'disk' => 'local',
            'base_prefix' => 'pandora-workspaces',
        ],
        'bucket' => [
            'label' => 'Shared bucket',
            'disk' => 'spaces',
            'base_prefix' => 'workspaces',
        ],
    ]);

    config()->set('filesystems.disks.spaces', ['driver' => 's3', 'bucket' => 'example']);

    $this->roots = app(WorkspaceRoots::class);
});

it('offers only the roots an operator declared', function (): void {
    expect(array_keys($this->roots->all()))->toBe(['scratch', 'bucket']);
});

it('permits nothing when no root is configured', function (): void {
    // An allowlist that falls open when unconfigured is not an allowlist, and
    // this one decides where the boundary IS rather than narrowing an already
    // bounded thing.
    config()->set('pandora.workspaces.roots', []);

    expect($this->roots->all())->toBe([])
        ->and($this->roots->has('scratch'))->toBeFalse();
});

it('skips a root with no disk rather than defaulting it', function (): void {
    config()->set('pandora.workspaces.roots', ['broken' => ['label' => 'Broken']]);

    // Defaulting the disk would offer a root nobody declared, which is exactly
    // the thing the key is supposed to make impossible.
    expect($this->roots->all())->toBe([]);
});

it('refuses a key nobody declared', function (): void {
    $this->roots->get('etc');
})->throws(WorkspaceDenied::class);

it('composes an object root as base, tenant and slug', function (): void {
    $path = $this->roots->compose($this->roots->get('bucket'), 'quarterly-reports');

    expect($path)->toBe('workspaces/shared/quarterly-reports');
});

it('gives each tenant its own segment', function (): void {
    $acme = inTenant('acme', fn (): string => $this->roots->compose($this->roots->get('bucket'), 'notes'));
    $globex = inTenant('globex', fn (): string => $this->roots->compose($this->roots->get('bucket'), 'notes'));

    expect($acme)->toBe('workspaces/acme/notes')
        ->and($globex)->toBe('workspaces/globex/notes')
        ->and($acme)->not->toBe($globex);
});

it('hashes a tenant id that is not path-safe rather than sanitising it', function (): void {
    $slashed = inTenant('acme/eu', fn (): string => $this->roots->compose($this->roots->get('bucket'), 'notes'));
    $dashed = inTenant('acme-eu', fn (): string => $this->roots->compose($this->roots->get('bucket'), 'notes'));

    // Replacing the awkward character would map these two onto one prefix, and
    // two tenants sharing a workspace is the whole failure this segment exists
    // to prevent.
    expect($slashed)->toStartWith('workspaces/t-')
        ->and($slashed)->not->toBe($dashed);
});

it('resolves a local root against the disk root and creates it', function (): void {
    $root = $this->roots->get('scratch');
    $path = $this->roots->compose($root, 'notes');

    expect($path)->toBe(rtrim((string) config('filesystems.disks.local.root'), '/').'/pandora-workspaces/shared/notes')
        ->and(is_dir($path))->toBeFalse();

    // Created before anything resolves inside it: LocalStorage measures
    // containment with realpath(), and realpath() of a directory nobody made
    // is false.
    $this->roots->prepare($root, $path);

    expect(is_dir($path))->toBeTrue();

    @rmdir($path);
});

it('does nothing to prepare an object root, which has no directories', function (): void {
    $root = $this->roots->get('bucket');

    $this->roots->prepare($root, $this->roots->compose($root, 'notes'));
})->throwsNoExceptions();

it('takes an absolute local base prefix as operator configuration', function (): void {
    config()->set('pandora.workspaces.roots.scratch.base_prefix', '/srv/agent-workspaces');

    expect($this->roots->compose($this->roots->get('scratch'), 'notes'))
        ->toBe('/srv/agent-workspaces/shared/notes');
});

it('refuses a slug that is not a slug', function (string $slug): void {
    $this->roots->compose($this->roots->get('bucket'), $slug);
})->with([
    '../escape',
    '..',
    '/absolute',
    'has/slash',
    'has space',
    'UPPER',
    '-leading-dash',
    "null\0byte",
    '',
])->throws(WorkspaceDenied::class);

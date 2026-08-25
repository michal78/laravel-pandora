<?php

declare(strict_types=1);

use Pandora\Audit\AuditLogger;
use Pandora\Exceptions\WorkspaceDenied;
use Pandora\Workspaces\Denials;
use Pandora\Workspaces\Storage\LocalStorage;
use Pandora\Workspaces\Workspace;
use Pandora\Workspaces\WorkspaceFiles;

/**
 * Phase 9, T5 -- the second containment layer, proved by disabling the first.
 *
 * `ContainmentTest` drives every case through `WorkspaceFiles`, and that is the
 * right altitude for "can an agent escape". It is the wrong altitude for "how
 * many things would have to fail first", which is what this criterion asks.
 *
 * There are two layers on a write, and only one of them was ever asserted:
 *
 *  1. `WorkspaceFiles::write()` calls `storage->size()` for quota accounting
 *     before it writes. `size()` resolves with `mustExist: true`, so a symlink
 *     pointing out of the root throws `outside_root` there -- several lines
 *     before the write path's own check is reached.
 *  2. `LocalStorage::locate(mustExist: false)` re-resolves the target when it
 *     already exists in any form, because a contained parent says nothing
 *     about what the leaf is a link to.
 *
 *  Layer 1 is incidental. It is quota code, it is not there for containment,
 *  and it throws first only because the reservation has to know the old size.
 *  Removing layer 2 leaves the whole of `ContainmentTest` green -- verified by
 *  removing it -- so the tests named for the write-symlink case were passing on
 *  the strength of a byte count.
 *
 *  Every test below therefore drives `LocalStorage` directly, which is the only
 *  way to reach layer 2 with layer 1 out of the way.
 */
beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/pandora-layers-'.bin2hex(random_bytes(6));
    $this->outside = sys_get_temp_dir().'/pandora-layers-out-'.bin2hex(random_bytes(6));

    mkdir($this->root, 0777, true);
    mkdir($this->outside, 0777, true);

    file_put_contents($this->outside.'/secret.txt', 'APP_KEY=hunter2');

    /** @var Workspace $workspace */
    $workspace = Workspace::query()->create([
        'name' => 'Layers',
        'slug' => 'layers',
        'disk' => 'local',
        'root_path' => $this->root,
    ]);

    $this->workspace = $workspace;
    $this->storage = new LocalStorage($workspace, new Denials($workspace, app(AuditLogger::class)));
    $this->files = new WorkspaceFiles($workspace, app(AuditLogger::class));
});

afterEach(function (): void {
    foreach ([$this->root, $this->outside] as $dir) {
        if (! is_dir($dir)) {
            continue;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() && ! $item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
});

it('refuses a write through an escaping symlink at the storage layer itself', function (): void {
    // The leaf is a link out. Its parent is the root, which is contained --
    // so a check that stopped at the parent would let this through.
    symlink($this->outside.'/secret.txt', $this->root.'/innocent.txt');

    expect(fn () => $this->storage->write('innocent.txt', 'overwritten'))
        ->toThrow(WorkspaceDenied::class);

    expect(file_get_contents($this->outside.'/secret.txt'))->toBe('APP_KEY=hunter2');
});

it('refuses a write into a symlinked directory at the storage layer itself', function (): void {
    symlink($this->outside, $this->root.'/elsewhere');

    expect(fn () => $this->storage->write('elsewhere/planted.txt', 'nope'))
        ->toThrow(WorkspaceDenied::class);

    expect(file_exists($this->outside.'/planted.txt'))->toBeFalse();
});

it('refuses a write through a dangling symlink rather than creating it', function (): void {
    // Resolves to nothing, so there is no resolved path to compare -- and
    // creating it would write through the link to wherever it points.
    symlink($this->outside.'/never-existed.txt', $this->root.'/dangling.txt');

    expect(fn () => $this->storage->write('dangling.txt', 'nope'))
        ->toThrow(WorkspaceDenied::class);

    expect(file_exists($this->outside.'/never-existed.txt'))->toBeFalse();
});

it('still creates a genuinely new file, which has nothing to follow', function (): void {
    // The other half of the rule: a contained parent IS the whole answer when
    // the target does not exist. Without this the check above could be a
    // blanket refusal and still pass everything else here.
    $this->storage->write('fresh.txt', 'created');

    expect(file_get_contents($this->root.'/fresh.txt'))->toBe('created');
});

it('locates the escaping leaf as denied even when the parent is contained', function (): void {
    symlink($this->outside.'/secret.txt', $this->root.'/innocent.txt');

    try {
        $this->storage->locate('innocent.txt', mustExist: false);
        $this->fail('the escaping leaf was located rather than denied');
    } catch (WorkspaceDenied $e) {
        // Named, so that a refusal arriving for some unrelated reason -- quota,
        // mime, a missing root -- cannot stand in for containment.
        expect($e->reason)->toBe('outside_root');
    }
});

it('is the quota lookup, not the write path, that refuses through WorkspaceFiles', function (): void {
    // Documents layer 1 for what it is. If this ever stops being true the
    // ordering in WorkspaceFiles::write() changed, and the tests in
    // ContainmentTest quietly changed meaning with it.
    symlink($this->outside.'/secret.txt', $this->root.'/innocent.txt');

    expect(fn () => $this->files->size('innocent.txt'))
        ->toThrow(WorkspaceDenied::class);
});

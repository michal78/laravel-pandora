<?php

declare(strict_types=1);

use Pandora\Workspaces\Storage\LocalStorage;
use Pandora\Workspaces\Storage\ObjectStorage;
use Pandora\Workspaces\Storage\StorageFactory;
use Pandora\Workspaces\Workspace;

/**
 * Phase 7, criterion 7 — the `disk` column decides, and an old row still works.
 *
 * `disk` has been on the workspaces table since Phase 5 with no reader at all.
 * Giving it one is the moment every existing row either keeps working or
 * quietly changes meaning, so what is asserted here is mostly that nothing
 * moved: a row that named `local`, or named nothing, still gets the filesystem
 * adapter and its `realpath` containment.
 *
 * The driver decides, never the name. A host is free to call its bucket
 * `local`, and putting the filesystem adapter in front of an object store
 * would call `realpath()` on a key — which fails in the permissive direction
 * on some paths and the confusing direction on the rest.
 */
function workspaceOn(string $disk): Workspace
{
    /** @var Workspace $workspace */
    $workspace = Workspace::query()->create([
        'name' => 'Scratch',
        'slug' => 'scratch-'.bin2hex(random_bytes(4)),
        'disk' => $disk,
        'root_path' => sys_get_temp_dir(),
    ]);

    return $workspace;
}

it('gives a local disk the filesystem adapter', function (): void {
    expect(app(StorageFactory::class)->for(workspaceOn('local')))
        ->toBeInstanceOf(LocalStorage::class);
});

it('gives a workspace with no disk the filesystem adapter', function (): void {
    expect(app(StorageFactory::class)->for(workspaceOn('')))
        ->toBeInstanceOf(LocalStorage::class);
});

it('gives an s3 disk the object adapter', function (): void {
    config()->set('filesystems.disks.spaces', [
        'driver' => 's3',
        'key' => 'unused-in-this-test',
        'secret' => 'unused-in-this-test',
        'region' => 'ams3',
        'bucket' => 'pandora-test',
        'endpoint' => 'https://ams3.digitaloceanspaces.test',
    ]);

    expect(app(StorageFactory::class)->for(workspaceOn('spaces')))
        ->toBeInstanceOf(ObjectStorage::class);
});

it('decides on the driver, not on what the disk is called', function (): void {
    // A bucket named `local`. The name says filesystem and the driver says
    // otherwise, and the driver is the one that knows.
    config()->set('filesystems.disks.local', [
        'driver' => 's3',
        'key' => 'unused-in-this-test',
        'secret' => 'unused-in-this-test',
        'region' => 'eu-central-1',
        'bucket' => 'pandora-test',
    ]);

    expect(app(StorageFactory::class)->for(workspaceOn('local')))
        ->toBeInstanceOf(ObjectStorage::class);
});

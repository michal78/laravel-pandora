<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Pandora\Audit\AuditLogger;
use Pandora\Workspaces\Denials;
use Pandora\Workspaces\Storage\ObjectStorage;
use Pandora\Workspaces\Workspace;

/**
 * Phase 7, criterion 4 — one prefix must never reach inside a longer one.
 *
 * The bug this exists for has a boring name and a serious outcome. A prefix of
 * `tenant-1` matches the key `tenant-10/secrets.txt` on any `str_starts_with`
 * that forgets the delimiter, so tenant 1 lists and reads tenant 10's
 * workspace through an operation that looks entirely ordinary and logs nothing
 * unusual.
 *
 * It is the same mistake as a root of `/srv/agent` accepting `/srv/agent-
 * secrets`, which the filesystem adapter has guarded against since Phase 5 —
 * and it is worth its own test on this side because object storage has no
 * directory to make the boundary obvious.
 */
function objectStorageFor(string $root): ObjectStorage
{
    /** @var Workspace $workspace */
    $workspace = Workspace::query()->create([
        'name' => 'Workspace '.$root,
        'slug' => str_replace('/', '-', $root),
        'disk' => 'objects',
        'root_path' => $root,
    ]);

    return new ObjectStorage(
        $workspace,
        Storage::disk('objects'),
        new Denials($workspace, app(AuditLogger::class)),
    );
}

beforeEach(function (): void {
    Storage::fake('objects');
});

it('ends the prefix at a delimiter, so a shorter root cannot reach a longer one', function (): void {
    $one = objectStorageFor('tenant-1');
    $ten = objectStorageFor('tenant-10');

    $ten->write('secrets.txt', 'tenant ten only');

    expect($one->list())->toBe([])
        ->and($one->totalBytes())->toBe(0)
        ->and($ten->list())->toBe(['secrets.txt']);
});

it('keeps a neighbouring prefix out of the byte count', function (): void {
    $one = objectStorageFor('tenant-1');
    $ten = objectStorageFor('tenant-10');

    $one->write('mine.txt', 'four');
    $ten->write('theirs.txt', 'a much longer file that would inflate a neighbour');

    expect($one->totalBytes())->toBe(4);
});

it('cannot read a neighbouring prefix by naming it', function (): void {
    $ten = objectStorageFor('tenant-10');
    $ten->write('secrets.txt', 'tenant ten only');

    $one = objectStorageFor('tenant-1');

    // The key `0/secrets.txt` under prefix `tenant-1/` is `tenant-1/0/…`,
    // not `tenant-10/…`. The delimiter is what makes that true.
    expect($one->locate('0/secrets.txt'))->toBe('tenant-1/0/secrets.txt')
        ->and($one->size('0/secrets.txt'))->toBe(0);
});

it('writes under its own prefix and nowhere else', function (): void {
    objectStorageFor('tenant-1')->write('nested/notes.txt', 'hello');

    expect(Storage::disk('objects')->allFiles())->toBe(['tenant-1/nested/notes.txt']);
});

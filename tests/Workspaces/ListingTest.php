<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Pandora\Tests\Support\MakesWorkspaces;

/**
 * Phase 7, criterion 13 — a listing must not stop at the first page.
 *
 * S3 returns at most 1000 keys per request and hands back a continuation
 * token. Anything that reads the first response and stops is correct on every
 * workspace anybody tests by hand and silently wrong on the first real one: a
 * workspace with 1200 files lists 1000 of them, `reconcile()` undercounts, and
 * the missing 200 are invisible rather than reported.
 *
 * This is the test that has to use a real store. A fake returns whatever it
 * holds in one go, so the boundary it exists to prove does not exist there.
 */
uses(MakesWorkspaces::class);

it('lists past the first page of results', function (): void {
    [$workspace, $storage] = $this->objectWorkspace();

    $disk = Storage::disk($workspace->disk);
    $prefix = rtrim($workspace->root_path, '/').'/';

    // One more than the page size, which is the only number that distinguishes
    // a paginating implementation from one that got lucky.
    $count = 1005;

    for ($i = 0; $i < $count; $i++) {
        $disk->put(sprintf('%sfile-%04d.txt', $prefix, $i), 'x');
    }

    expect($storage->list())->toHaveCount($count)
        ->and($storage->totalBytes())->toBe($count);
})->group('slow');

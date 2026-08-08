<?php

declare(strict_types=1);

use Pandora\Audit\AuditLog;
use Pandora\Audit\AuditLogger;
use Pandora\Exceptions\WorkspaceDenied;
use Pandora\Tests\Support\MakesWorkspaces;
use Pandora\Workspaces\WorkspaceFiles;

/**
 * Phase 5 criterion 26 and Phase 7 criteria 9 and 10 -- refused before it
 * lands, accurate under concurrency, and true on both adapters.
 *
 * Checking `used_bytes` and then writing is the same race as Phase 4's
 * `last_run_at` check, and it fails under exactly the load that made you care
 * about the quota. The fix is the same too: one conditional UPDATE, where the
 * database decides and "no rows affected" is the refusal.
 *
 * None of that is storage-specific, which is the point of running it on both
 * legs. What IS storage-specific is where the previous size comes from -- a
 * `filesize()` on a filesystem and a `HEAD` on an object store -- and
 * overwrite accounting is the test that would notice if one of them lied.
 */
uses(MakesWorkspaces::class);

dataset('adapters', ['local', 'object']);

it('tracks usage as files are written', function (string $kind): void {
    [$workspace, $files] = $this->makeFilesOn($kind, ['quota_bytes' => 100]);

    $files->write('a.txt', str_repeat('a', 30));

    expect($workspace->refresh()->used_bytes)->toBe(30);

    $files->write('b.txt', str_repeat('b', 20));

    expect($workspace->refresh()->used_bytes)->toBe(50)
        ->and($workspace->remainingBytes())->toBe(50);
})->with('adapters');

it('refuses a write that would exceed the quota, before it lands', function (string $kind): void {
    [$workspace, $files, $storage] = $this->makeFilesOn($kind, ['quota_bytes' => 100]);

    $files->write('a.txt', str_repeat('a', 80));

    expect(fn () => $files->write('b.txt', str_repeat('b', 30)))
        ->toThrow(WorkspaceDenied::class);

    // Before it lands: the file must not exist and usage must not have moved.
    expect($storage->isFile('b.txt'))->toBeFalse()
        ->and($workspace->refresh()->used_bytes)->toBe(80);
})->with('adapters');

it('allows a write that exactly fills the quota', function (string $kind): void {
    [$workspace, $files] = $this->makeFilesOn($kind, ['quota_bytes' => 100]);

    $files->write('a.txt', str_repeat('a', 100));

    expect($workspace->refresh()->used_bytes)->toBe(100)
        ->and($workspace->remainingBytes())->toBe(0);

    expect(fn () => $files->write('b.txt', 'x'))->toThrow(WorkspaceDenied::class);
})->with('adapters');

it('counts only the difference when overwriting', function (string $kind): void {
    [$workspace, $files] = $this->makeFilesOn($kind, ['quota_bytes' => 100]);

    $files->write('a.txt', str_repeat('a', 90));
    expect($workspace->refresh()->used_bytes)->toBe(90);

    // Shrinking must give the space back, or a file rewritten often would
    // eventually fill a workspace it never grew. On object storage the
    // previous size is a HEAD rather than a stat, and this is where a wrong
    // answer would show up.
    $files->write('a.txt', str_repeat('a', 10));
    expect($workspace->refresh()->used_bytes)->toBe(10);

    $files->write('a.txt', str_repeat('a', 95));
    expect($workspace->refresh()->used_bytes)->toBe(95);
})->with('adapters');

it('gives space back when a file is deleted', function (string $kind): void {
    [$workspace, $files] = $this->makeFilesOn($kind, ['quota_bytes' => 100]);

    $files->write('a.txt', str_repeat('a', 60));
    $files->delete('a.txt');

    expect($workspace->refresh()->used_bytes)->toBe(0);
})->with('adapters');

it('stays accurate when two writers race', function (string $kind): void {
    // Two processes, one quota. The reservation is a conditional UPDATE, so
    // exactly one of these can win the last 40 bytes.
    [$workspace, $files, $storage] = $this->makeFilesOn($kind, ['quota_bytes' => 100]);

    $files->write('a.txt', str_repeat('a', 60));

    $second = new WorkspaceFiles($workspace->fresh(), app(AuditLogger::class), $storage);

    $accepted = 0;

    foreach ([$files, $second] as $index => $writer) {
        try {
            $writer->write("race{$index}.txt", str_repeat('x', 40));
            $accepted++;
        } catch (WorkspaceDenied) {
            // expected for exactly one of them
        }
    }

    expect($accepted)->toBe(1)
        ->and($workspace->refresh()->used_bytes)->toBe(100);
})->with('adapters');

it('never lets usage go negative', function (string $kind): void {
    [$workspace, $files] = $this->makeFilesOn($kind, ['quota_bytes' => 100]);

    $files->write('a.txt', str_repeat('a', 10));

    // `used_bytes` is unsigned: negative is a database error on three engines
    // and silent wraparound on the fourth.
    $workspace->update(['used_bytes' => 0]);

    $files->delete('a.txt');

    expect($workspace->refresh()->used_bytes)->toBe(0);
})->with('adapters');

it('records an exceeded quota as a warning', function (string $kind): void {
    [, $files] = $this->makeFilesOn($kind, ['quota_bytes' => 100]);

    $files->write('a.txt', str_repeat('a', 95));

    try {
        $files->write('b.txt', str_repeat('b', 50));
    } catch (WorkspaceDenied) {
        // expected
    }

    $audit = AuditLog::query()->where('action', 'workspace.quota_exceeded')->first();

    expect($audit)->not->toBeNull()
        ->and($audit->severity)->toBe('warning')
        ->and($audit->metadata['quota_bytes'])->toBe(100);
})->with('adapters');

it('imposes no limit when no quota is set', function (string $kind): void {
    [$workspace, $files] = $this->makeFilesOn($kind, ['quota_bytes' => null]);

    $files->write('big.txt', str_repeat('x', 5000));

    expect($workspace->refresh()->used_bytes)->toBe(5000)
        ->and($workspace->remainingBytes())->toBeNull()
        ->and($workspace->hasQuota())->toBeFalse();
})->with('adapters');

it('repairs a drifted counter by recounting the store', function (string $kind): void {
    [$workspace, $files] = $this->makeFilesOn($kind, ['quota_bytes' => 100]);

    $files->write('a.txt', str_repeat('a', 40));

    // A crash mid-write, or a file removed by hand.
    $workspace->update(['used_bytes' => 9999]);

    expect($files->reconcile())->toBe(40)
        ->and($workspace->refresh()->used_bytes)->toBe(40);
})->with('adapters');

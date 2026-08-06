<?php

declare(strict_types=1);

use Pandora\Pandora\Audit\AuditLog;
use Pandora\Pandora\Audit\AuditLogger;
use Pandora\Pandora\Exceptions\WorkspaceDenied;
use Pandora\Pandora\Workspaces\Workspace;
use Pandora\Pandora\Workspaces\WorkspaceFiles;

/**
 * Phase 5, criterion 26 -- refused before it lands, and accurate under
 * concurrency.
 *
 * Checking `used_bytes` and then writing is the same race as Phase 4's
 * `last_run_at` check, and it fails under exactly the load that made you care
 * about the quota. The fix is the same too: one conditional UPDATE, where the
 * database decides and "no rows affected" is the refusal.
 */
beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/pandora-quota-'.bin2hex(random_bytes(6));
    mkdir($this->root, 0777, true);

    /** @var Workspace $workspace */
    $workspace = Workspace::query()->create([
        'name' => 'Bounded',
        'slug' => 'bounded',
        'disk' => 'local',
        'root_path' => $this->root,
        'quota_bytes' => 100,
    ]);

    $this->workspace = $workspace;
    $this->files = new WorkspaceFiles($workspace, app(AuditLogger::class));
});

afterEach(function (): void {
    if (! is_dir($this->root)) {
        return;
    }

    foreach (glob($this->root.'/*') ?: [] as $file) {
        unlink($file);
    }

    rmdir($this->root);
});

it('tracks usage as files are written', function (): void {
    $this->files->write('a.txt', str_repeat('a', 30));

    expect($this->workspace->refresh()->used_bytes)->toBe(30);

    $this->files->write('b.txt', str_repeat('b', 20));

    expect($this->workspace->refresh()->used_bytes)->toBe(50)
        ->and($this->workspace->remainingBytes())->toBe(50);
});

it('refuses a write that would exceed the quota, before it lands', function (): void {
    $this->files->write('a.txt', str_repeat('a', 80));

    expect(fn () => $this->files->write('b.txt', str_repeat('b', 30)))
        ->toThrow(WorkspaceDenied::class);

    // Before it lands: the file must not exist and usage must not have moved.
    expect(file_exists($this->root.'/b.txt'))->toBeFalse()
        ->and($this->workspace->refresh()->used_bytes)->toBe(80);
});

it('allows a write that exactly fills the quota', function (): void {
    $this->files->write('a.txt', str_repeat('a', 100));

    expect($this->workspace->refresh()->used_bytes)->toBe(100)
        ->and($this->workspace->remainingBytes())->toBe(0);

    expect(fn () => $this->files->write('b.txt', 'x'))->toThrow(WorkspaceDenied::class);
});

it('counts only the difference when overwriting', function (): void {
    $this->files->write('a.txt', str_repeat('a', 90));
    expect($this->workspace->refresh()->used_bytes)->toBe(90);

    // Shrinking must give the space back, or a file rewritten often would
    // eventually fill a workspace it never grew.
    $this->files->write('a.txt', str_repeat('a', 10));
    expect($this->workspace->refresh()->used_bytes)->toBe(10);

    $this->files->write('a.txt', str_repeat('a', 95));
    expect($this->workspace->refresh()->used_bytes)->toBe(95);
});

it('gives space back when a file is deleted', function (): void {
    $this->files->write('a.txt', str_repeat('a', 60));
    $this->files->delete('a.txt');

    expect($this->workspace->refresh()->used_bytes)->toBe(0);
});

it('stays accurate when two writers race', function (): void {
    // Two processes, one quota. The reservation is a conditional UPDATE, so
    // exactly one of these can win the last 40 bytes.
    $this->files->write('a.txt', str_repeat('a', 60));

    $second = new WorkspaceFiles($this->workspace->fresh(), app(AuditLogger::class));

    $accepted = 0;

    foreach ([$this->files, $second] as $index => $writer) {
        try {
            $writer->write("race{$index}.txt", str_repeat('x', 40));
            $accepted++;
        } catch (WorkspaceDenied) {
            // expected for exactly one of them
        }
    }

    expect($accepted)->toBe(1)
        ->and($this->workspace->refresh()->used_bytes)->toBe(100);
});

it('never lets usage go negative', function (): void {
    $this->files->write('a.txt', str_repeat('a', 10));

    // `used_bytes` is unsigned: negative is a database error on three engines
    // and silent wraparound on the fourth.
    $this->workspace->update(['used_bytes' => 0]);

    $this->files->delete('a.txt');

    expect($this->workspace->refresh()->used_bytes)->toBe(0);
});

it('records an exceeded quota as a warning', function (): void {
    $this->files->write('a.txt', str_repeat('a', 95));

    try {
        $this->files->write('b.txt', str_repeat('b', 50));
    } catch (WorkspaceDenied) {
        // expected
    }

    $audit = AuditLog::query()->where('action', 'workspace.quota_exceeded')->first();

    expect($audit)->not->toBeNull()
        ->and($audit->severity)->toBe('warning')
        ->and($audit->metadata['quota_bytes'])->toBe(100);
});

it('imposes no limit when no quota is set', function (): void {
    /** @var Workspace $unlimited */
    $unlimited = Workspace::query()->create([
        'name' => 'Unlimited',
        'slug' => 'unlimited',
        'disk' => 'local',
        'root_path' => $this->root,
        'quota_bytes' => null,
    ]);

    $files = new WorkspaceFiles($unlimited, app(AuditLogger::class));

    $files->write('big.txt', str_repeat('x', 5000));

    expect($unlimited->refresh()->used_bytes)->toBe(5000)
        ->and($unlimited->remainingBytes())->toBeNull()
        ->and($unlimited->hasQuota())->toBeFalse();
});

it('repairs a drifted counter by recounting the tree', function (): void {
    $this->files->write('a.txt', str_repeat('a', 40));

    // A crash mid-write, or a file removed by hand.
    $this->workspace->update(['used_bytes' => 9999]);

    expect($this->files->reconcile())->toBe(40)
        ->and($this->workspace->refresh()->used_bytes)->toBe(40);
});

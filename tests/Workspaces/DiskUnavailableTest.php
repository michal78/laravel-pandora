<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Pandora\Audit\AuditLogger;
use Pandora\Exceptions\WorkspaceDenied;
use Pandora\Workspaces\Denials;
use Pandora\Workspaces\Storage\ObjectStorage;
use Pandora\Workspaces\Workspace;
use Pandora\Workspaces\WorkspaceFiles;

/**
 * Phase 7, criterion 6 — an unreachable disk is a refusal, never a fallback.
 *
 * This is the criterion ADR-0013 exists for. Falling back to local storage
 * when the bucket is unreachable produces a file that lives on exactly one
 * container: every other node reads past it, the next tool call may or may not
 * find it depending on which worker answers, and nothing about any of that
 * looks like an error to an agent or to an operator.
 *
 * So the store being down behaves like an unhealthy provider in Phase 3. The
 * tool fails, the run keeps going, and the agent is told it cannot use files
 * right now — which is true, and is the only thing it needs to know.
 *
 * No endpoint is needed to test this: the point is precisely that there isn't
 * one.
 */
beforeEach(function (): void {
    config()->set('filesystems.disks.dead_bucket', [
        'driver' => 's3',
        'key' => 'unused',
        'secret' => 'unused',
        'region' => 'us-east-1',
        'bucket' => 'pandora-test',
        // Nothing listens here. A port on localhost rather than a hostname
        // that must not resolve, so the test refuses fast and does not depend
        // on what the machine's DNS does with an unknown name.
        'endpoint' => 'http://127.0.0.1:9',
        'use_path_style_endpoint' => true,
        'throw' => true,
    ]);

    /** @var Workspace $workspace */
    $workspace = Workspace::query()->create([
        'name' => 'Offline',
        'slug' => 'offline',
        'disk' => 'dead_bucket',
        'root_path' => 'ws',
        'quota_bytes' => 1_000_000,
    ]);

    $this->workspace = $workspace;
    $this->storage = new ObjectStorage(
        $workspace,
        Storage::disk('dead_bucket'),
        new Denials($workspace, app(AuditLogger::class)),
    );
});

it('refuses a read rather than raising a transport error', function (): void {
    expect(fn (): string => $this->storage->read('notes.txt'))
        ->toThrow(WorkspaceDenied::class);
});

it('refuses a write rather than putting the bytes somewhere else', function (): void {
    expect(fn (): int => $this->storage->write('notes.txt', 'hello'))
        ->toThrow(WorkspaceDenied::class);
});

it('says the disk is unavailable, not that the path is wrong', function (): void {
    // The distinction matters to whoever reads the trace. "That path is not
    // available in this workspace" sends an operator looking for a typo; the
    // bucket being unreachable sends them to the endpoint.
    try {
        $this->storage->read('notes.txt');
    } catch (WorkspaceDenied $e) {
        expect($e->reason)->toBe('disk_unavailable');

        return;
    }

    $this->fail('The read should have been refused.');
});

it('tells the agent it cannot use files, without naming the endpoint', function (): void {
    try {
        $this->storage->write('notes.txt', 'hello');
    } catch (WorkspaceDenied $e) {
        // An endpoint, a bucket name and a signature error are operator facts.
        // They belong in the trace, and not in a sentence a model will read
        // and may repeat to a user.
        expect($e->userMessage())->not->toContain('127.0.0.1')
            ->and($e->userMessage())->not->toContain('pandora-test')
            ->and($e->userMessage())->toContain('cannot be reached');

        return;
    }

    $this->fail('The write should have been refused.');
});

it('fails its availability check up front', function (): void {
    expect(fn () => $this->storage->assertAvailable())->toThrow(WorkspaceDenied::class);
});

it('gives the quota back when the store swallowed the write', function (): void {
    $files = new WorkspaceFiles($this->workspace, app(AuditLogger::class), $this->storage);

    try {
        $files->write('notes.txt', 'five!');
    } catch (WorkspaceDenied) {
        // Expected.
    }

    // A reservation kept by a failed write would shrink the workspace a little
    // on every network blip until it was full of nothing at all — and on
    // object storage the network is the failure that actually happens.
    expect((int) $this->workspace->fresh()?->used_bytes)->toBe(0);
});

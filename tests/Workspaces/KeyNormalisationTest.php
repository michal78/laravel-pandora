<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Pandora\Audit\AuditLog;
use Pandora\Exceptions\WorkspaceDenied;
use Pandora\Tests\Support\MakesWorkspaces;

/**
 * Phase 7, criterion 3 — a key that normalises outside the root is refused.
 *
 * This is the object store's half of containment, and it is deliberately NOT
 * the filesystem's answer. There is no `realpath` to call, no symlink to
 * follow and no second key that reaches the same bytes, so the question is
 * purely "what does this string mean", asked before the prefix goes on.
 *
 * The cases below are the ones a lexical check gets wrong when it is written
 * carelessly: `..` that is resolved rather than refused, a backslash on a
 * client that treats it as a separator, a scheme that some SDKs honour, and a
 * leading slash written by somebody assuming the prefix is advisory.
 *
 * Run against a real endpoint, and skipped without one. `Storage::fake()` is
 * the local driver: it would answer these questions as a filesystem, which is
 * the one thing this file must not be testing.
 */
uses(MakesWorkspaces::class);

beforeEach(function (): void {
    [$workspace, $storage] = $this->objectWorkspace();

    $this->workspace = $workspace;
    $this->storage = $storage;
    $this->prefix = rtrim($workspace->root_path, '/').'/';
});

it('prefixes an ordinary key with the workspace root', function (): void {
    expect($this->storage->locate('notes.txt'))->toBe($this->prefix.'notes.txt')
        ->and($this->storage->locate('nested/deep.txt'))->toBe($this->prefix.'nested/deep.txt');
});

it('collapses redundant segments without accepting an escape', function (): void {
    expect($this->storage->locate('./notes.txt'))->toBe($this->prefix.'notes.txt')
        ->and($this->storage->locate('nested//deep.txt'))->toBe($this->prefix.'nested/deep.txt');
});

it('refuses a parent segment rather than resolving it', function (string $key): void {
    // `a/../b` is refused even though it names something harmless. Resolving
    // it would accept a path whose author was trying to leave, and the one
    // that leaves is the same expression with one more segment.
    expect(fn (): string => $this->storage->locate($key))
        ->toThrow(WorkspaceDenied::class);
})->with([
    '../secrets.txt',
    '../../etc/passwd',
    'nested/../../escape.txt',
    'nested/../notes.txt',
    'a/b/../../../out.txt',
]);

it('refuses a backslash-separated escape', function (): void {
    // A backslash is a separator on some clients and an ordinary key character
    // elsewhere. Normalising it first means a check that only knows about `/`
    // cannot be walked around.
    expect(fn (): string => $this->storage->locate('..\\..\\etc\\passwd'))
        ->toThrow(WorkspaceDenied::class);
});

it('refuses a key wearing a scheme', function (string $key): void {
    expect(fn (): string => $this->storage->locate($key))
        ->toThrow(WorkspaceDenied::class);
})->with([
    's3://other-bucket/secrets.txt',
    'file:///etc/passwd',
    'https://example.test/x',
]);

it('refuses an absolute key', function (): void {
    expect(fn (): string => $this->storage->locate('/etc/passwd'))
        ->toThrow(WorkspaceDenied::class);
});

it('refuses a null byte anywhere in the key', function (): void {
    expect(fn (): string => $this->storage->locate("notes.txt\0.png"))
        ->toThrow(WorkspaceDenied::class);
});

it('refuses a key that normalises to nothing at all', function (string $key): void {
    // An empty key is the prefix itself, and writing "the workspace" as if it
    // were a file is not a request anybody meant to make.
    expect(fn (): string => $this->storage->locate($key))
        ->toThrow(WorkspaceDenied::class);
})->with(['', '.', '/', './']);

it('records a containment violation at critical severity', function (): void {
    try {
        $this->storage->locate('../secrets.txt');
    } catch (WorkspaceDenied) {
        // Expected.
    }

    /** @var AuditLog $entry */
    $entry = AuditLog::query()->where('action', 'workspace.containment_violation')->firstOrFail();

    expect($entry->severity)->toBe('critical')
        ->and($entry->metadata['reason'])->toBe('outside_root');
});

it('never lets a refused key reach the store', function (): void {
    try {
        $this->storage->write('../escape.txt', 'should not land');
    } catch (WorkspaceDenied) {
        // Expected.
    }

    expect($this->storage->list())->toBe([]);
});

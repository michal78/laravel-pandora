<?php

declare(strict_types=1);

use Pandora\Exceptions\WorkspaceDenied;
use Pandora\Tests\Support\MakesWorkspaces;
use Pandora\Workspaces\Storage\WorkspaceStorage;

/**
 * Phase 7, criteria 1, 2 and 5 — one suite, both adapters.
 *
 * What is asserted here is the part that must be true wherever the bytes live:
 * a contained path works, an escaping path is refused, and every operation
 * re-asks. What is deliberately NOT here is anything only one store can do —
 * symlinks belong to `ContainmentTest`, key spellings to
 * `KeyNormalisationTest` — because a contract suite that reached for those
 * would be two suites sharing a filename.
 *
 * The object leg runs against a real endpoint or skips. It is never run
 * against `Storage::fake()`: that is the local driver, and proving the local
 * driver twice is not the same as proving both.
 */
uses(MakesWorkspaces::class);

dataset('adapters', ['local', 'object']);

it('writes and reads back inside the root', function (string $kind): void {
    [, $storage] = $this->makeWorkspaceOn($kind);

    $storage->write('notes.txt', 'inside the workspace');

    expect($storage->read('notes.txt'))->toBe('inside the workspace');
})->with('adapters');

it('writes into a nested path', function (string $kind): void {
    [$workspace, $storage] = $this->makeWorkspaceOn($kind);

    // A filesystem needs the directory to exist first — `locate()` refuses a
    // path whose parent is absent, which is itself correct. An object store
    // has no directories at all. The contract is the same either way: the
    // caller names a nested path and reads the same bytes back.
    if ($kind === 'local') {
        mkdir($workspace->root_path.'/nested', 0777, true);
    }

    $storage->write('nested/deep.txt', 'nested but inside');

    expect($storage->read('nested/deep.txt'))->toBe('nested but inside');
})->with('adapters');

it('reports the size of what it wrote', function (string $kind): void {
    [, $storage] = $this->makeWorkspaceOn($kind);

    $storage->write('notes.txt', 'twelve chars');

    expect($storage->size('notes.txt'))->toBe(12);
})->with('adapters');

it('reports zero for a file that is not there', function (string $kind): void {
    [, $storage] = $this->makeWorkspaceOn($kind);

    expect($storage->size('absent.txt'))->toBe(0);
})->with('adapters');

it('says a missing file is not a file, rather than failing', function (string $kind): void {
    [, $storage] = $this->makeWorkspaceOn($kind);

    expect($storage->isFile('absent.txt'))->toBeFalse();
})->with('adapters');

it('refuses to read something that is not there', function (string $kind): void {
    [, $storage] = $this->makeWorkspaceOn($kind);

    expect(fn (): string => $storage->read('absent.txt'))->toThrow(WorkspaceDenied::class);
})->with('adapters');

/**
 * Streaming, which the download surface needs and which each adapter answers
 * with an entirely different mechanism: an `fopen` on a resolved path, and a
 * ranged GET wearing a stream wrapper. The contract is that neither of those
 * details reaches the caller.
 */
it('streams back exactly what it wrote', function (string $kind): void {
    [, $storage] = $this->makeWorkspaceOn($kind);

    $storage->write('notes.txt', 'streamed byte for byte');

    $handle = $storage->stream('notes.txt');

    expect(is_resource($handle))->toBeTrue()
        ->and(stream_get_contents($handle))->toBe('streamed byte for byte');

    fclose($handle);
})->with('adapters');

it('streams a file larger than one chunk', function (string $kind): void {
    [, $storage] = $this->makeWorkspaceOn($kind);

    // The point of a handle is that the bytes never all land in one process at
    // once, so a payload that fits in a single read proves nothing about the
    // loop that consumes it.
    $payload = str_repeat('0123456789abcdef', 4096); // 64 KiB

    $storage->write('big.bin', $payload);

    $handle = $storage->stream('big.bin');
    $read = '';

    while (! feof($handle)) {
        $chunk = fread($handle, 8192);

        if ($chunk === false) {
            break;
        }

        $read .= $chunk;
    }

    fclose($handle);

    expect($read)->toBe($payload);
})->with('adapters');

it('refuses to stream something that is not there', function (string $kind): void {
    [, $storage] = $this->makeWorkspaceOn($kind);

    expect(fn () => $storage->stream('absent.txt'))->toThrow(WorkspaceDenied::class);
})->with('adapters');

it('refuses to stream a path that leaves the root', function (string $kind): void {
    [, $storage] = $this->makeWorkspaceOn($kind);

    // Re-resolved here as everywhere else: a handle is opened now and read
    // later, and "later" is exactly when a symlink gets planted.
    expect(fn () => $storage->stream('../escape.txt'))->toThrow(WorkspaceDenied::class);
})->with('adapters');

it('deletes what it wrote and stops finding it', function (string $kind): void {
    [, $storage] = $this->makeWorkspaceOn($kind);

    $storage->write('notes.txt', 'here for now');
    $storage->delete('notes.txt');

    expect($storage->isFile('notes.txt'))->toBeFalse()
        ->and($storage->list())->not->toContain('notes.txt');
})->with('adapters');

it('refuses to delete something that is not there', function (string $kind): void {
    [, $storage] = $this->makeWorkspaceOn($kind);

    expect(fn () => $storage->delete('absent.txt'))->toThrow(WorkspaceDenied::class);
})->with('adapters');

it('lists what it holds and nothing else', function (string $kind): void {
    [, $storage] = $this->makeWorkspaceOn($kind);

    $storage->write('a.txt', 'a');
    $storage->write('b.txt', 'bb');

    expect($storage->list())->toBe(['a.txt', 'b.txt']);
})->with('adapters');

it('lists one level, showing a nested path as a single entry', function (string $kind): void {
    [$workspace, $storage] = $this->makeWorkspaceOn($kind);

    if ($kind === 'local') {
        mkdir($workspace->root_path.'/nested', 0777, true);
    }

    $storage->write('top.txt', 'top');
    $storage->write('nested/deep.txt', 'deep');

    // A filesystem has a directory called `nested`; an object store has a
    // common prefix that looks like one. Both must present one level, or a
    // workspace with a deep tree floods a listing meant to be browsed.
    expect($storage->list())->toBe(['nested', 'top.txt']);
})->with('adapters');

it('lists inside a nested path', function (string $kind): void {
    [$workspace, $storage] = $this->makeWorkspaceOn($kind);

    if ($kind === 'local') {
        mkdir($workspace->root_path.'/nested', 0777, true);
    }

    $storage->write('nested/deep.txt', 'deep');

    expect($storage->list('nested'))->toBe(['nested/deep.txt']);
})->with('adapters');

it('counts every byte it holds', function (string $kind): void {
    [, $storage] = $this->makeWorkspaceOn($kind);

    $storage->write('a.txt', 'four');
    $storage->write('b.txt', 'seven!!');

    expect($storage->totalBytes())->toBe(11);
})->with('adapters');

it('refuses a path that climbs out of the root', function (string $kind): void {
    [, $storage] = $this->makeWorkspaceOn($kind);

    expect(fn (): string => $storage->locate('../escape.txt', mustExist: false))
        ->toThrow(WorkspaceDenied::class);
})->with('adapters');

it('refuses a path with a null byte in it', function (string $kind): void {
    [, $storage] = $this->makeWorkspaceOn($kind);

    expect(fn (): string => $storage->locate("notes.txt\0.png", mustExist: false))
        ->toThrow(WorkspaceDenied::class);
})->with('adapters');

it('never writes anything when the path is refused', function (string $kind): void {
    [, $storage] = $this->makeWorkspaceOn($kind);

    try {
        $storage->write('../escape.txt', 'should not land');
    } catch (WorkspaceDenied) {
        // Expected.
    }

    expect($storage->list())->toBe([]);
})->with('adapters');

it('keeps one workspace out of another on the same store', function (string $kind): void {
    [, $mine] = $this->makeWorkspaceOn($kind);
    [, $theirs] = $this->makeWorkspaceOn($kind);

    $theirs->write('secret.txt', 'not yours');

    expect($mine->list())->toBe([])
        ->and($mine->totalBytes())->toBe(0)
        ->and($mine->size('secret.txt'))->toBe(0);
})->with('adapters');

it('overwrites in place rather than appending', function (string $kind): void {
    [, $storage] = $this->makeWorkspaceOn($kind);

    $storage->write('notes.txt', 'first version');
    $storage->write('notes.txt', 'second');

    expect($storage->read('notes.txt'))->toBe('second')
        ->and($storage->size('notes.txt'))->toBe(6);
})->with('adapters');

it('answers that it is available', function (string $kind): void {
    [, $storage] = $this->makeWorkspaceOn($kind);

    expect(fn () => $storage->assertAvailable())->not->toThrow(WorkspaceDenied::class);
})->with('adapters');

it('is the contract, whichever adapter answered', function (string $kind): void {
    [, $storage] = $this->makeWorkspaceOn($kind);

    expect($storage)->toBeInstanceOf(WorkspaceStorage::class);
})->with('adapters');

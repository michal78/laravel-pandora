<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Pandora\Exceptions\WorkspaceDenied;
use Pandora\Tests\Support\MakesWorkspaces;
use Pandora\Workspaces\Workspace;

/**
 * Phase 5 criterion 27 and Phase 7 criteria 11 and 12 -- the detected type,
 * never the claimed one, on both adapters.
 *
 * An extension is an assertion by whoever chose the filename, and in a
 * workspace that whoever is a language model acting on documents it has read.
 * `.png` is not evidence of anything.
 *
 * Object storage adds a second, more convincing lie. Every object carries a
 * `Content-Type`, it is returned by the store as though it were a fact about
 * the bytes, and it is whatever the uploader said. Anything that consulted it
 * would be trusting the same assertion in a smarter hat -- so nothing does,
 * and the last test here proves it against a real bucket.
 */
uses(MakesWorkspaces::class);

dataset('adapters', ['local', 'object']);

/** A real 1x1 PNG, so finfo has actual magic bytes to read. */
function onePixelPng(): string
{
    return base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
        true,
    ) ?: '';
}

it('allows everything when no allowlist is set', function (string $kind): void {
    [, $files] = $this->makeFilesOn($kind, ['allowed_mime_types' => []]);

    $files->write('notes.txt', 'plain text');
    $files->write('image.png', onePixelPng());

    expect($files->list())->toContain('notes.txt', 'image.png');
})->with('adapters');

it('allows a type on the allowlist', function (string $kind): void {
    [, $files] = $this->makeFilesOn($kind, ['allowed_mime_types' => ['text/plain']]);

    $files->write('notes.txt', 'plain text');

    expect($files->read('notes.txt'))->toBe('plain text');
})->with('adapters');

it('refuses a type that is not on the allowlist', function (string $kind): void {
    [, $files, $storage] = $this->makeFilesOn($kind, ['allowed_mime_types' => ['image/png']]);

    expect(fn () => $files->write('notes.txt', 'plain text'))
        ->toThrow(WorkspaceDenied::class);

    expect($storage->isFile('notes.txt'))->toBeFalse();
})->with('adapters');

it('refuses text wearing an image extension', function (string $kind): void {
    // The whole point. The filename claims PNG; the bytes say otherwise.
    [, $files, $storage] = $this->makeFilesOn($kind, ['allowed_mime_types' => ['image/png']]);

    expect(fn () => $files->write('definitely-an-image.png', 'this is plain text'))
        ->toThrow(WorkspaceDenied::class);

    expect($storage->isFile('definitely-an-image.png'))->toBeFalse();
})->with('adapters');

it('accepts a real image whatever it is named', function (string $kind): void {
    [, $files] = $this->makeFilesOn($kind, ['allowed_mime_types' => ['image/png']]);

    // The mirror image of the case above: the bytes are what count, so a
    // genuine PNG named .txt is fine.
    $files->write('mislabelled.txt', onePixelPng());

    expect($files->list())->toContain('mislabelled.txt');
})->with('adapters');

it('honours a wildcard pattern', function (string $kind): void {
    [, $files] = $this->makeFilesOn($kind, ['allowed_mime_types' => ['image/*']]);

    $files->write('image.png', onePixelPng());

    expect(fn () => $files->write('notes.txt', 'plain text'))
        ->toThrow(WorkspaceDenied::class);
})->with('adapters');

it('does not charge the quota for a refused write', function (string $kind): void {
    [$workspace, $files] = $this->makeFilesOn($kind, [
        'allowed_mime_types' => ['image/png'],
        'quota_bytes' => 1000,
    ]);

    try {
        $files->write('notes.txt', str_repeat('a', 100));
    } catch (WorkspaceDenied) {
        // expected
    }

    // The type check runs before the reservation, so a refused write cannot
    // shrink the workspace a little on every attempt.
    expect($workspace->refresh()->used_bytes)->toBe(0);
})->with('adapters');

it('does not let a wildcard match a neighbouring type family', function (): void {
    $workspace = new Workspace(['allowed_mime_types' => ['image/*']]);

    expect($workspace->allowsMimeType('image/png'))->toBeTrue()
        ->and($workspace->allowsMimeType('imagex/png'))->toBeFalse()
        ->and($workspace->allowsMimeType('text/image'))->toBeFalse();
});

/**
 * Phase 7, criterion 12 — the store's own metadata is not evidence.
 *
 * An object uploaded with `Content-Type: image/png` and text inside it is the
 * object-storage version of naming a file `.png`, except that the store hands
 * the claim back through an API that makes it look authoritative. A workspace
 * restricted to images must still refuse it on the bytes.
 */
it('refuses an object whose declared content type disagrees with its bytes', function (): void {
    [$workspace, $files, $storage] = $this->makeFilesOn('object', [
        'allowed_mime_types' => ['image/png'],
    ]);

    $key = $storage->locate('lying.png', mustExist: false);

    // Written past the workspace, so the bad metadata really is on the object
    // rather than something this test asked Pandora to produce.
    Storage::disk($workspace->disk)->put($key, 'this is plain text', [
        'ContentType' => 'image/png',
    ]);

    expect(Storage::disk($workspace->disk)->mimeType($key))->toBe('image/png')
        // And the workspace still refuses to rewrite it, because the only
        // thing consulted is what the bytes are.
        ->and(fn () => $files->write('lying.png', 'still plain text'))
        ->toThrow(WorkspaceDenied::class);
});

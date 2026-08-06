<?php

declare(strict_types=1);

use Pandora\Pandora\Audit\AuditLogger;
use Pandora\Pandora\Exceptions\WorkspaceDenied;
use Pandora\Pandora\Workspaces\Workspace;
use Pandora\Pandora\Workspaces\WorkspaceFiles;

/**
 * Phase 5, criterion 27 -- the detected type, never the claimed extension.
 *
 * An extension is an assertion by whoever chose the filename, and in a
 * workspace that whoever is a language model acting on documents it has read.
 * `.png` is not evidence of anything.
 */
beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/pandora-mime-'.bin2hex(random_bytes(6));
    mkdir($this->root, 0777, true);
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

function mimeWorkspace(array $allowed): WorkspaceFiles
{
    /** @var Workspace $workspace */
    $workspace = Workspace::query()->create([
        'name' => 'Typed',
        'slug' => 'typed-'.bin2hex(random_bytes(3)),
        'disk' => 'local',
        'root_path' => test()->root,
        'allowed_mime_types' => $allowed,
    ]);

    return new WorkspaceFiles($workspace, app(AuditLogger::class));
}

/** A real 1x1 PNG, so finfo has actual magic bytes to read. */
function onePixelPng(): string
{
    return base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
        true,
    ) ?: '';
}

it('allows everything when no allowlist is set', function (): void {
    $files = mimeWorkspace([]);

    $files->write('notes.txt', 'plain text');
    $files->write('image.png', onePixelPng());

    expect($files->list())->toContain('notes.txt', 'image.png');
});

it('allows a type on the allowlist', function (): void {
    $files = mimeWorkspace(['text/plain']);

    $files->write('notes.txt', 'plain text');

    expect($files->read('notes.txt'))->toBe('plain text');
});

it('refuses a type that is not on the allowlist', function (): void {
    $files = mimeWorkspace(['image/png']);

    expect(fn () => $files->write('notes.txt', 'plain text'))
        ->toThrow(WorkspaceDenied::class);

    expect(file_exists(test()->root.'/notes.txt'))->toBeFalse();
});

it('refuses text wearing an image extension', function (): void {
    // The whole point. The filename claims PNG; the bytes say otherwise.
    $files = mimeWorkspace(['image/png']);

    expect(fn () => $files->write('definitely-an-image.png', 'this is plain text'))
        ->toThrow(WorkspaceDenied::class);

    expect(file_exists(test()->root.'/definitely-an-image.png'))->toBeFalse();
});

it('accepts a real image whatever it is named', function (): void {
    $files = mimeWorkspace(['image/png']);

    // The mirror image of the case above: the bytes are what count, so a
    // genuine PNG named .txt is fine.
    $files->write('mislabelled.txt', onePixelPng());

    expect($files->list())->toContain('mislabelled.txt');
});

it('honours a wildcard pattern', function (): void {
    $files = mimeWorkspace(['image/*']);

    $files->write('image.png', onePixelPng());

    expect(fn () => $files->write('notes.txt', 'plain text'))
        ->toThrow(WorkspaceDenied::class);
});

it('does not let a wildcard match a neighbouring type family', function (): void {
    $workspace = new Workspace(['allowed_mime_types' => ['image/*']]);

    expect($workspace->allowsMimeType('image/png'))->toBeTrue()
        ->and($workspace->allowsMimeType('imagex/png'))->toBeFalse()
        ->and($workspace->allowsMimeType('text/image'))->toBeFalse();
});

it('does not charge the quota for a refused write', function (): void {
    /** @var Workspace $workspace */
    $workspace = Workspace::query()->create([
        'name' => 'Typed',
        'slug' => 'typed-quota',
        'disk' => 'local',
        'root_path' => $this->root,
        'allowed_mime_types' => ['image/png'],
        'quota_bytes' => 1000,
    ]);

    $files = new WorkspaceFiles($workspace, app(AuditLogger::class));

    try {
        $files->write('notes.txt', str_repeat('a', 100));
    } catch (WorkspaceDenied) {
        // expected
    }

    // The type check runs before the reservation, so a refused write cannot
    // shrink the workspace a little on every attempt.
    expect($workspace->refresh()->used_bytes)->toBe(0);
});

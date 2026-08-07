<?php

declare(strict_types=1);

use Pandora\Context\ContextFiles;
use Pandora\Exceptions\ContextFileDenied;

/**
 * Phase 5, criterion 23 -- a path is contained after it is resolved.
 *
 * Every case below is the same case wearing a different hat: a string that
 * does not look like it leaves the root, and does. Checking containment
 * against the string a caller passed is checking a spelling, and `../` has a
 * dozen spellings while a symlink has none at all.
 */
beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/pandora-context-'.bin2hex(random_bytes(6));
    $this->outside = sys_get_temp_dir().'/pandora-secret-'.bin2hex(random_bytes(6));

    mkdir($this->root.'/nested', 0777, true);
    mkdir($this->outside, 0777, true);

    file_put_contents($this->root.'/allowed.md', 'the style guide');
    file_put_contents($this->root.'/nested/deep.md', 'nested but allowed');
    file_put_contents($this->outside.'/secret.env', 'APP_KEY=base64:hunter2');

    $this->files = new ContextFiles([$this->root]);
});

afterEach(function (): void {
    foreach ([$this->root, $this->outside] as $dir) {
        if (! is_dir($dir)) {
            continue;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() && ! $item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
});

it('reads a file inside a configured root', function (): void {
    expect($this->files->read($this->root.'/allowed.md'))->toBe('the style guide')
        ->and($this->files->read($this->root.'/nested/deep.md'))->toBe('nested but allowed');
});

it('refuses an absolute path outside every root', function (): void {
    expect(fn () => $this->files->read($this->outside.'/secret.env'))
        ->toThrow(ContextFileDenied::class);
});

it('refuses a traversal that climbs out of the root', function (): void {
    $traversal = $this->root.'/nested/../../'.basename($this->outside).'/secret.env';

    expect(fn () => $this->files->read($traversal))->toThrow(ContextFileDenied::class);
});

it('refuses a symlink inside the root that points outside it', function (): void {
    // The case a pre-resolution check cannot see. The path is unimpeachable
    // -- it is literally inside the root -- and the file is not.
    $link = $this->root.'/innocent.md';
    symlink($this->outside.'/secret.env', $link);

    expect(is_file($link))->toBeTrue()
        ->and(fn () => $this->files->read($link))->toThrow(ContextFileDenied::class);
});

it('refuses a file in a sibling directory sharing the root\'s name prefix', function (): void {
    // A root of /srv/agent must not accept /srv/agent-secrets. The bug is a
    // missing trailing separator in the prefix comparison.
    $sibling = $this->root.'-secrets';
    mkdir($sibling, 0777, true);
    file_put_contents($sibling.'/leak.md', 'adjacent');

    try {
        expect(fn () => $this->files->read($sibling.'/leak.md'))
            ->toThrow(ContextFileDenied::class);
    } finally {
        unlink($sibling.'/leak.md');
        rmdir($sibling);
    }
});

it('refuses everything when no roots are configured', function (): void {
    $none = new ContextFiles([]);

    expect(fn () => $none->read($this->root.'/allowed.md'))
        ->toThrow(ContextFileDenied::class);
});

it('does not reveal whether a refused path exists', function (): void {
    $missing = null;
    $present = null;

    try {
        $this->files->read($this->outside.'/does-not-exist.env');
    } catch (ContextFileDenied $e) {
        $missing = $e->getMessage();
    }

    try {
        $this->files->read($this->outside.'/secret.env');
    } catch (ContextFileDenied $e) {
        $present = $e->getMessage();
    }

    // Identical refusals once the echoed path -- which the caller supplied and
    // already knows -- is removed. A distinct "no such file" would make this
    // an oracle for probing the filesystem outside the allowed roots.
    $reason = static fn (string $message): string => preg_replace('/\[.*?\]/', '[path]', $message) ?? '';

    expect($missing)->not->toBeNull()
        ->and($present)->not->toBeNull()
        ->and($reason($present))->toBe($reason($missing));
});

it('refuses a directory even when it is inside a root', function (): void {
    expect(fn () => $this->files->read($this->root.'/nested'))
        ->toThrow(ContextFileDenied::class);
});

it('bounds how much of a file it will read', function (): void {
    file_put_contents($this->root.'/big.md', str_repeat('x', 10000));

    $bounded = new ContextFiles([$this->root], maxBytes: 100);

    expect(strlen($bounded->read($this->root.'/big.md')))->toBe(100);
});

it('skips refused paths instead of failing the run', function (): void {
    $loaded = $this->files->readAll([
        $this->root.'/allowed.md',
        $this->outside.'/secret.env',
        $this->root.'/nested/deep.md',
    ]);

    expect($loaded)->toHaveCount(2)
        ->and(implode('', array_values($loaded)))->not->toContain('hunter2');
});

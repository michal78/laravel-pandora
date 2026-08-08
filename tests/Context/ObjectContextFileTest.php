<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Storage;
use Pandora\Context\ContextFiles;
use Pandora\Context\ObjectContextPath;
use Pandora\Context\ObjectContextReader;
use Pandora\Exceptions\ContextFileDenied;
use Pandora\Tests\Support\MakesWorkspaces;

/**
 * Phase 7, criteria 14, 15 and 16 — context files in an object store.
 *
 * Context files are read on EVERY iteration of EVERY run, which makes them the
 * one place where the naive object-storage implementation is not merely slower
 * but expensive: a full GET per file per iteration, billed, and slow enough to
 * look like the model hanging.
 *
 * So the body is fetched once and revalidated by ETag, and the byte budget is
 * enforced by asking for a range rather than by trimming what came back. The
 * allowlist is unchanged in spirit and now has two kinds of root — a
 * filesystem root never authorises a key, and a bucket prefix never authorises
 * a file.
 */
uses(MakesWorkspaces::class);

beforeEach(function (): void {
    $this->disk = $this->objectDisk();
    $this->prefix = 'ctx-'.bin2hex(random_bytes(6));
    $this->root = 'disk:'.$this->disk.'/'.$this->prefix;

    Storage::disk($this->disk)->put($this->prefix.'/handbook.md', 'The escalation codeword is saltmarsh.');
});

function contextFiles(array $roots, int $maxBytes = 65536): ContextFiles
{
    return new ContextFiles($roots, $maxBytes, new ObjectContextReader(app(Cache::class)));
}

it('reads a context file from a configured bucket prefix', function (): void {
    $files = contextFiles([$this->root]);

    expect($files->read($this->root.'/handbook.md'))
        ->toBe('The escalation codeword is saltmarsh.');
});

it('refuses a key outside the configured prefix', function (): void {
    Storage::disk($this->disk)->put('elsewhere/secrets.md', 'not for agents');

    $files = contextFiles([$this->root]);

    expect(fn (): string => $files->read('disk:'.$this->disk.'/elsewhere/secrets.md'))
        ->toThrow(ContextFileDenied::class);
});

it('refuses a key that climbs out of the prefix', function (): void {
    $files = contextFiles([$this->root]);

    expect(fn (): string => $files->read($this->root.'/../elsewhere/secrets.md'))
        ->toThrow(ContextFileDenied::class);
});

it('does not let a filesystem root authorise a bucket key', function (): void {
    // The roots are non-empty, so this is not the "feature off" refusal — it
    // is a root of the wrong kind failing to vouch for a path.
    $files = contextFiles([sys_get_temp_dir()]);

    expect(fn (): string => $files->read($this->root.'/handbook.md'))
        ->toThrow(ContextFileDenied::class);
});

it('does not let a bucket prefix authorise a file on disk', function (): void {
    $local = sys_get_temp_dir().'/pandora-ctx-'.bin2hex(random_bytes(6)).'.md';
    file_put_contents($local, 'local secrets');

    $files = contextFiles([$this->root]);

    expect(fn (): string => $files->read($local))->toThrow(ContextFileDenied::class);

    unlink($local);
});

it('does not let a neighbouring prefix in', function (): void {
    // `ctx-abc` must not vouch for `ctx-abc-internal`, the same delimiter bug
    // the workspace prefix guards against.
    Storage::disk($this->disk)->put($this->prefix.'-internal/salaries.md', 'confidential');

    $files = contextFiles([$this->root]);

    expect(fn (): string => $files->read('disk:'.$this->disk.'/'.$this->prefix.'-internal/salaries.md'))
        ->toThrow(ContextFileDenied::class);
});

it('reads no more than the byte budget, whatever the object holds', function (): void {
    Storage::disk($this->disk)->put($this->prefix.'/huge.log', str_repeat('x', 200_000));

    $files = contextFiles([$this->root], maxBytes: 1024);

    // Ranged: the store is asked for 1024 bytes rather than handing over 200KB
    // that are then thrown away. On a real log this is the difference between
    // one small request per iteration and a bill.
    expect(strlen($files->read($this->root.'/huge.log')))->toBe(1024);
});

it('serves an unchanged object from cache without fetching the body again', function (): void {
    $files = contextFiles([$this->root]);
    $path = $this->root.'/handbook.md';

    // Prime the cache, then poison it with a body the store does not hold. A
    // second read returning the poison proves the bytes came from cache and no
    // GET was made; returning the real text would prove the cache is
    // decorative.
    $files->read($path);

    $parsed = ObjectContextPath::parse($path);
    $key = 'pandora:context-file:'.sha1($parsed->disk.'/'.$parsed->key.'/65536');

    /** @var array{etag: string, contents: string} $cached */
    $cached = app(Cache::class)->get($key);

    app(Cache::class)->put($key, ['etag' => $cached['etag'], 'contents' => 'FROM THE CACHE'], 600);

    expect($files->read($path))->toBe('FROM THE CACHE');
});

it('re-reads when the object changes, rather than serving a stale copy', function (): void {
    $files = contextFiles([$this->root]);
    $path = $this->root.'/handbook.md';

    $files->read($path);

    // An operator edits the handbook. The next run must see it — a TTL would
    // mean "in a few minutes", which is the wrong answer to "I fixed the
    // instructions".
    Storage::disk($this->disk)->put($this->prefix.'/handbook.md', 'The codeword is now tideline.');

    expect($files->read($path))->toBe('The codeword is now tideline.');
});

it('omits an unreachable context file rather than failing the run', function (): void {
    $files = contextFiles([$this->root]);

    $loaded = $files->readAll([
        $this->root.'/handbook.md',
        $this->root.'/not-there.md',
    ]);

    expect($loaded)->toHaveCount(1)
        ->and(array_values($loaded)[0])->toBe('The escalation codeword is saltmarsh.');
});

it('keys the loaded map by the canonical path', function (): void {
    $files = contextFiles([$this->root]);

    // Written with redundant segments; recorded in the form that was read.
    $loaded = $files->readAll(['disk:'.$this->disk.'/'.$this->prefix.'/./handbook.md']);

    expect(array_keys($loaded))->toBe([$this->root.'/handbook.md']);
});

<?php

declare(strict_types=1);

namespace Pandora\Context;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use Pandora\Exceptions\ContextFileDenied;

/**
 * Context files that live in an object store.
 *
 * Two things make this different from reading a file off a disk, and both are
 * about the fact that every read is a network call made on EVERY iteration of
 * EVERY run:
 *
 * *The cache is validated, not timed.* A `HEAD` returns the ETag, which changes
 * exactly when the object does. So the body is fetched once and re-fetched when
 * the operator edits it -- not when a TTL happens to expire, which would mean
 * either serving a stale handbook for minutes or paying for the whole file
 * every few seconds. An operator who updates a context file wants the next run
 * to see it, and that is what this gives them for the price of one small
 * request.
 *
 * *The read is ranged.* The byte budget is applied by asking the store for the
 * first N bytes rather than by downloading an object and then trimming it. A
 * 2GB log accidentally named in configuration must cost one truncated read, and
 * on object storage the naive version costs 2GB of transfer per iteration --
 * billed, and slow enough to look like the model hanging.
 */
final readonly class ObjectContextReader
{
    public function __construct(
        private Cache $cache,
        private int $cacheTtlSeconds = 86400,
    ) {}

    /**
     * Read at most `$maxBytes`, from cache when the object has not changed.
     *
     * @throws ContextFileDenied
     */
    public function read(ObjectContextPath $path, int $maxBytes): string
    {
        $etag = $this->etag($path);

        if ($etag === null) {
            // Same refusal as an out-of-root path. A distinct "no such object"
            // answer turns this into an oracle for what exists in the bucket.
            throw ContextFileDenied::outsideRoots($path->original);
        }

        $key = $this->cacheKey($path, $maxBytes);

        /** @var array{etag: string, contents: string}|null $cached */
        $cached = $this->cache->get($key);

        if ($cached !== null && $cached['etag'] === $etag) {
            return $cached['contents'];
        }

        $contents = $this->fetch($path, $maxBytes);

        $this->cache->put($key, ['etag' => $etag, 'contents' => $contents], $this->cacheTtlSeconds);

        return $contents;
    }

    /**
     * The object's ETag, or null when it is not there.
     *
     * Deliberately tolerant of a store that is down: a context file that cannot
     * be reached is an omission on the trace, exactly like a local one that was
     * deleted. A run does not fail because a handbook was briefly unreachable.
     */
    private function etag(ObjectContextPath $path): ?string
    {
        try {
            $disk = Storage::disk($path->disk);

            if (! $disk->exists($path->key)) {
                return null;
            }

            // Falls back to size and modified time where a store offers no
            // checksum. Weaker than an ETag and still changes when the object
            // does, for every edit that is not a byte-identical rewrite.
            $checksum = $disk->checksum($path->key);

            if (is_string($checksum) && $checksum !== '') {
                return $checksum;
            }

            return $disk->size($path->key).':'.$disk->lastModified($path->key);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @throws ContextFileDenied
     */
    private function fetch(ObjectContextPath $path, int $maxBytes): string
    {
        $disk = Storage::disk($path->disk);

        try {
            // A ranged GET where the store speaks S3, so the budget is enforced
            // by the request rather than after it. `readStream` is the fallback
            // and is only bounded once the bytes are already coming down.
            if ($disk instanceof AwsS3V3Adapter) {
                $result = $disk->getClient()->getObject([
                    'Bucket' => config("filesystems.disks.{$path->disk}.bucket"),
                    'Key' => $path->key,
                    'Range' => 'bytes=0-'.($maxBytes - 1),
                ]);

                return (string) $result['Body'];
            }

            $stream = $disk->readStream($path->key);

            if ($stream === null) {
                throw ContextFileDenied::unreadable($path->original);
            }

            try {
                $contents = fread($stream, max(1, $maxBytes));
            } finally {
                fclose($stream);
            }

            return $contents === false ? '' : $contents;
        } catch (ContextFileDenied $e) {
            throw $e;
        } catch (\Throwable) {
            throw ContextFileDenied::unreadable($path->original);
        }
    }

    private function cacheKey(ObjectContextPath $path, int $maxBytes): string
    {
        // The budget is part of the key: the same object read under a smaller
        // budget is a different string, and serving the longer cached copy
        // would quietly exceed a limit somebody lowered on purpose.
        return 'pandora:context-file:'.sha1($path->disk.'/'.$path->key.'/'.$maxBytes);
    }
}

<?php

declare(strict_types=1);

namespace Pandora\Tests\Support;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Pandora\Audit\AuditLogger;
use Pandora\Workspaces\Denials;
use Pandora\Workspaces\Storage\LocalStorage;
use Pandora\Workspaces\Storage\ObjectStorage;
use Pandora\Workspaces\Storage\WorkspaceStorage;
use Pandora\Workspaces\Workspace;
use Pandora\Workspaces\WorkspaceFiles;

/**
 * Builds a workspace on either kind of storage, for the suites that must pass
 * on both.
 *
 * The object leg runs against a REAL S3-compatible endpoint — MinIO locally
 * and in CI — and **skips** when none is configured. It is deliberately not
 * run against `Storage::fake()`, which is the local driver wearing an object
 * store's name: it has directories, it has symlinks, and `..` behaves like a
 * filesystem. A contract suite passing against that would be asserting the
 * fake's behaviour and calling it S3.
 *
 * This is the pgvector rule applied again. A skipped test is honest about not
 * having run; a test that passes because it substituted a convenient fake for
 * the thing under test is the failure mode the whole phase exists to avoid.
 */
trait MakesWorkspaces
{
    /**
     * @param array<string, mixed> $attributes
     * @return array{0: Workspace, 1: WorkspaceStorage}
     */
    public function makeWorkspaceOn(string $kind, ?string $slug = null, array $attributes = []): array
    {
        return $kind === 'object'
            ? $this->objectWorkspace($slug, $attributes)
            : $this->localWorkspace($slug, $attributes);
    }

    /**
     * The same, wrapped in the quota-and-MIME layer that sits above the seam.
     *
     * @param array<string, mixed> $attributes
     * @return array{0: Workspace, 1: WorkspaceFiles, 2: WorkspaceStorage}
     */
    public function makeFilesOn(string $kind, array $attributes = []): array
    {
        [$workspace, $storage] = $this->makeWorkspaceOn($kind, null, $attributes);

        return [$workspace, new WorkspaceFiles($workspace, app(AuditLogger::class), $storage), $storage];
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array{0: Workspace, 1: WorkspaceStorage}
     */
    public function localWorkspace(?string $slug = null, array $attributes = []): array
    {
        $root = sys_get_temp_dir().'/pandora-ws-'.bin2hex(random_bytes(6));

        mkdir($root, 0777, true);

        $workspace = $this->workspaceRow('local', $root, $slug, $attributes);

        return [$workspace, new LocalStorage($workspace, $this->denials($workspace))];
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array{0: Workspace, 1: WorkspaceStorage}
     */
    public function objectWorkspace(?string $slug = null, array $attributes = []): array
    {
        $disk = $this->objectDisk();

        // A prefix per test. Object storage has no cheap "empty this
        // directory", and a leaked object from an earlier test would show up
        // as a listing that is right in a way nobody arranged.
        $workspace = $this->workspaceRow($disk, 'ws-'.bin2hex(random_bytes(6)), $slug, $attributes);

        return [$workspace, new ObjectStorage($workspace, Storage::disk($disk), $this->denials($workspace))];
    }

    /**
     * Configure and return the name of a disk pointing at a real S3-compatible
     * endpoint, or skip the test.
     */
    public function objectDisk(): string
    {
        $endpoint = env('PANDORA_TEST_S3_ENDPOINT');

        if (! is_string($endpoint) || $endpoint === '') {
            test()->markTestSkipped(
                'No S3-compatible endpoint configured. Set PANDORA_TEST_S3_ENDPOINT to run the '
                .'object-storage leg; CI runs it against MinIO.',
            );
        }

        $bucket = (string) (env('PANDORA_TEST_S3_BUCKET') ?: 'pandora-test');

        config()->set('filesystems.disks.pandora_objects', [
            'driver' => 's3',
            'key' => (string) (env('PANDORA_TEST_S3_KEY') ?: 'sail'),
            'secret' => (string) (env('PANDORA_TEST_S3_SECRET') ?: 'password'),
            'region' => (string) (env('PANDORA_TEST_S3_REGION') ?: 'us-east-1'),
            'bucket' => $bucket,
            'endpoint' => $endpoint,
            // MinIO serves buckets as a path, not a subdomain, and so does
            // every other endpoint worth testing against locally.
            'use_path_style_endpoint' => true,
            'throw' => true,
        ]);

        $this->ensureBucket($bucket);

        return 'pandora_objects';
    }

    private function ensureBucket(string $bucket): void
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('pandora_objects');

        try {
            $client = $disk->getClient();

            if (! $client->doesBucketExist($bucket)) {
                $client->createBucket(['Bucket' => $bucket]);
            }
        } catch (\Throwable $e) {
            test()->markTestSkipped(
                'The configured S3 endpoint did not answer: '.$e->getMessage(),
            );
        }
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function workspaceRow(string $disk, string $root, ?string $slug, array $attributes = []): Workspace
    {
        /** @var Workspace $workspace */
        $workspace = Workspace::query()->create($attributes + [
            'name' => 'Scratch',
            'slug' => $slug ?? 'scratch-'.bin2hex(random_bytes(4)),
            'disk' => $disk,
            'root_path' => $root,
        ]);

        return $workspace;
    }

    private function denials(Workspace $workspace): Denials
    {
        return new Denials($workspace, app(AuditLogger::class));
    }
}

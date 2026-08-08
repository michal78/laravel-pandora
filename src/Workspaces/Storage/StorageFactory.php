<?php

declare(strict_types=1);

namespace Pandora\Workspaces\Storage;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Pandora\Audit\AuditLogger;
use Pandora\Workspaces\Denials;
use Pandora\Workspaces\Workspace;

/**
 * Which storage a workspace uses, decided by its `disk` column.
 *
 * The column has existed since Phase 5 and nothing read it; this is the reader.
 * A row with no disk, or one naming a local driver, gets the filesystem
 * adapter, so every workspace created before object storage existed keeps
 * working with nothing changed.
 *
 * The choice is made from CONFIGURATION -- the host's `filesystems.php`, which
 * is where the credentials live too (ADR-0013). Nothing an agent says reaches
 * this decision, and nothing here can name a bucket an operator did not.
 */
final readonly class StorageFactory
{
    public function __construct(
        private FilesystemFactory $filesystems,
        private AuditLogger $audit,
    ) {}

    public function for(Workspace $workspace): WorkspaceStorage
    {
        $denials = new Denials($workspace, $this->audit);
        $disk = trim($workspace->disk);

        if ($disk === '' || $this->isLocal($disk)) {
            return new LocalStorage($workspace, $denials);
        }

        return new ObjectStorage($workspace, $this->filesystems->disk($disk), $denials);
    }

    /**
     * A disk is local when its configured driver says so.
     *
     * Decided by the driver rather than by the disk's NAME, because a host is
     * free to call its S3 bucket "local" and Laravel's default `local` disk is
     * only conventionally named. Guessing from the name would put the
     * filesystem adapter -- with its symlink handling and its `realpath` --
     * in front of an object store, where every one of those calls is wrong.
     */
    private function isLocal(string $disk): bool
    {
        /** @var string|null $driver */
        $driver = config("filesystems.disks.{$disk}.driver");

        return $driver === null || $driver === 'local';
    }
}

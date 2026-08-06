<?php

declare(strict_types=1);

use Pandora\Pandora\Audit\AuditLog;
use Pandora\Pandora\Audit\AuditLogger;
use Pandora\Pandora\Exceptions\WorkspaceDenied;
use Pandora\Pandora\Workspaces\Workspace;
use Pandora\Pandora\Workspaces\WorkspaceFiles;

/**
 * Phase 5, criterion 25 -- traversal and symlink escape both fail.
 *
 * Every case here is the same case wearing a different hat: a path that does
 * not look like it leaves the root, and does. The symlink case is the one that
 * separates a real check from a plausible one, because the path is genuinely
 * inside the root -- it is the *file* that is not.
 */
beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/pandora-ws-'.bin2hex(random_bytes(6));
    $this->outside = sys_get_temp_dir().'/pandora-out-'.bin2hex(random_bytes(6));

    mkdir($this->root.'/nested', 0777, true);
    mkdir($this->outside, 0777, true);

    file_put_contents($this->root.'/notes.txt', 'inside the workspace');
    file_put_contents($this->root.'/nested/deep.txt', 'nested but inside');
    file_put_contents($this->outside.'/secret.txt', 'APP_KEY=hunter2');

    /** @var Workspace $workspace */
    $workspace = Workspace::query()->create([
        'name' => 'Scratch',
        'slug' => 'scratch',
        'disk' => 'local',
        'root_path' => $this->root,
    ]);

    $this->workspace = $workspace;
    $this->files = new WorkspaceFiles($workspace, app(AuditLogger::class));
});

afterEach(function (): void {
    foreach ([$this->root, $this->outside, $this->root.'-secrets'] as $dir) {
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

it('reads a file inside the workspace', function (): void {
    expect($this->files->read('notes.txt'))->toBe('inside the workspace')
        ->and($this->files->read('nested/deep.txt'))->toBe('nested but inside');
});

it('refuses a traversal that climbs out', function (): void {
    foreach ([
        '../'.basename($this->outside).'/secret.txt',
        'nested/../../'.basename($this->outside).'/secret.txt',
        './nested/./../../'.basename($this->outside).'/secret.txt',
    ] as $traversal) {
        expect(fn () => $this->files->read($traversal))
            ->toThrow(WorkspaceDenied::class, null, "traversal [{$traversal}] was not refused");
    }
});

it('refuses an absolute path outside the root', function (): void {
    // Leading separators are stripped and the path is joined to the root, so
    // this lands inside -- and then fails to exist. Either refusal is correct;
    // what must never happen is reading /etc/passwd.
    expect(fn () => $this->files->read($this->outside.'/secret.txt'))
        ->toThrow(WorkspaceDenied::class);
});

it('refuses a symlink inside the root that points outside it', function (): void {
    // The case a pre-resolution check cannot see. The path is unimpeachable.
    symlink($this->outside.'/secret.txt', $this->root.'/innocent.txt');

    expect(is_file($this->root.'/innocent.txt'))->toBeTrue()
        ->and(fn () => $this->files->read('innocent.txt'))->toThrow(WorkspaceDenied::class);
});

it('refuses a file reached through a symlinked directory', function (): void {
    symlink($this->outside, $this->root.'/elsewhere');

    expect(fn () => $this->files->read('elsewhere/secret.txt'))
        ->toThrow(WorkspaceDenied::class);
});

it('refuses a write through a symlink pointing outside', function (): void {
    symlink($this->outside.'/secret.txt', $this->root.'/innocent.txt');

    expect(fn () => $this->files->write('innocent.txt', 'overwritten'))
        ->toThrow(WorkspaceDenied::class);

    // The file outside is untouched.
    expect(file_get_contents($this->outside.'/secret.txt'))->toBe('APP_KEY=hunter2');
});

it('refuses a write into a symlinked directory', function (): void {
    symlink($this->outside, $this->root.'/elsewhere');

    expect(fn () => $this->files->write('elsewhere/planted.txt', 'nope'))
        ->toThrow(WorkspaceDenied::class);

    expect(file_exists($this->outside.'/planted.txt'))->toBeFalse();
});

it('refuses a sibling directory sharing the root\'s name prefix', function (): void {
    // A root of /srv/agent must not accept /srv/agent-secrets. The bug is a
    // missing trailing separator in the prefix comparison.
    mkdir($this->root.'-secrets', 0777, true);
    file_put_contents($this->root.'-secrets/leak.txt', 'adjacent');

    expect(fn () => $this->files->read('../'.basename($this->root).'-secrets/leak.txt'))
        ->toThrow(WorkspaceDenied::class);
});

it('refuses a path containing a null byte', function (): void {
    // The null byte truncates the path at the C level, so everything after it
    // is invisible to the checks and visible to the filesystem.
    expect(fn () => $this->files->read("notes.txt\0.png"))
        ->toThrow(WorkspaceDenied::class);
});

it('records a containment violation as critical', function (): void {
    symlink($this->outside.'/secret.txt', $this->root.'/innocent.txt');

    try {
        $this->files->read('innocent.txt');
    } catch (WorkspaceDenied) {
        // expected
    }

    $audit = AuditLog::query()->where('action', 'workspace.containment_violation')->first();

    // Either a bug in the containment check or somebody probing. Both deserve
    // to wake somebody up.
    expect($audit)->not->toBeNull()
        ->and($audit->severity)->toBe('critical');
});

it('does not reveal the resolved absolute path in the refusal', function (): void {
    symlink($this->outside.'/secret.txt', $this->root.'/innocent.txt');

    try {
        $this->files->read('innocent.txt');
        $message = null;
    } catch (WorkspaceDenied $e) {
        $message = $e->getMessage();
    }

    // Telling an agent where the symlink pointed confirms the file exists and
    // confirms where the root is.
    expect($message)->not->toBeNull()
        ->and($message)->not->toContain($this->outside)
        ->and($message)->not->toContain('secret.txt');
});

it('omits an escaping symlink from a listing', function (): void {
    symlink($this->outside.'/secret.txt', $this->root.'/innocent.txt');

    // Listing it tells an agent a file it may not read exists, which is the
    // same information leak in a smaller package.
    expect($this->files->list())->toBe(['nested', 'notes.txt']);
});

it('lists only what is inside', function (): void {
    expect($this->files->list())->toBe(['nested', 'notes.txt'])
        ->and($this->files->list('nested'))->toBe(['nested/deep.txt']);
});

it('writes, reads back and deletes inside the root', function (): void {
    $this->files->write('new.txt', 'hello');

    expect($this->files->read('new.txt'))->toBe('hello');

    $this->files->delete('new.txt');

    expect(fn () => $this->files->read('new.txt'))->toThrow(WorkspaceDenied::class);
});

it('creates a file in an existing subdirectory but not a missing one', function (): void {
    $this->files->write('nested/created.txt', 'ok');

    expect($this->files->read('nested/created.txt'))->toBe('ok');

    expect(fn () => $this->files->write('no/such/dir/file.txt', 'nope'))
        ->toThrow(WorkspaceDenied::class);
});

it('refuses everything when the root does not exist', function (): void {
    /** @var Workspace $broken */
    $broken = Workspace::query()->create([
        'name' => 'Gone',
        'slug' => 'gone',
        'disk' => 'local',
        'root_path' => $this->root.'/does-not-exist',
    ]);

    $files = new WorkspaceFiles($broken, app(AuditLogger::class));

    expect(fn () => $files->read('anything.txt'))->toThrow(WorkspaceDenied::class);
});

it('audits a write and a delete', function (): void {
    $this->files->write('audited.txt', 'hello');
    $this->files->delete('audited.txt');

    expect(AuditLog::query()->where('action', 'workspace.file_written')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'workspace.file_deleted')->count())->toBe(1);
});

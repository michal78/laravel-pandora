<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Pandora\Audit\AuditLog;
use Pandora\Workspaces\Workspace;

/**
 * Phase 7, criterion 20 — a download streams through the app, is authorized
 * and is audited, and no presigned URL is issued for any workspace.
 *
 * The easy version of this feature is a signed object URL: no bandwidth, no
 * worker, three lines. It is refused because of what it does to the other two
 * halves of the criterion. A presigned URL is a bearer token for one object
 * until it expires — forwardable, logged by every proxy it crosses, and
 * usable by whoever ends up holding it. What the audit trail can record is
 * that somebody asked for a link. What it cannot record is that the file left,
 * who took it, or how many times.
 */
beforeEach(function (): void {
    Gate::define('pandora.access', static fn (): bool => true);
    Gate::define('pandora.workspaces.access', static fn (): bool => true);

    config()->set('pandora.features.workspaces', true);

    $this->actingAsUser();

    $this->root = sys_get_temp_dir().'/pandora-dl-'.bin2hex(random_bytes(6));
    $this->outside = sys_get_temp_dir().'/pandora-dlout-'.bin2hex(random_bytes(6));

    mkdir($this->root.'/reports', 0777, true);
    mkdir($this->outside, 0777, true);

    file_put_contents($this->root.'/notes.txt', 'workspace notes');
    file_put_contents($this->root.'/reports/q1.txt', 'quarterly');
    file_put_contents($this->outside.'/secret.txt', 'not yours');

    /** @var Workspace $workspace */
    $workspace = Workspace::query()->create([
        'name' => 'Scratch',
        'slug' => 'scratch',
        'disk' => 'local',
        'root_path' => $this->root,
    ]);

    $this->workspace = $workspace;

    $this->download = fn (string $path, string $slug = 'scratch') => $this->get(
        route('pandora.workspaces.download', ['workspace' => $slug, 'path' => $path]),
    );
});

afterEach(function (): void {
    foreach ([$this->root.'/reports', $this->root, $this->outside] as $dir) {
        if (! is_dir($dir)) {
            continue;
        }

        foreach (glob($dir.'/*') ?: [] as $file) {
            is_file($file) || is_link($file) ? unlink($file) : null;
        }

        @rmdir($dir);
    }
});

it('streams a file through the application', function (): void {
    $response = ($this->download)('notes.txt');

    $response->assertOk()
        ->assertStreamed()
        ->assertStreamedContent('workspace notes')
        // Never the store's Content-Type and never one guessed from the
        // extension: both are chosen by whoever wrote the file, and in a
        // workspace that whoever is a model.
        ->assertHeader('Content-Type', 'application/octet-stream')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Length', '15')
        ->assertHeader('Content-Disposition', 'attachment; filename="notes.txt"');
});

it('streams a file from a subdirectory', function (): void {
    ($this->download)('reports/q1.txt')->assertOk()->assertStreamedContent('quarterly');
});

it('audits the download with the path and the byte count', function (): void {
    ($this->download)('notes.txt')->assertOk();

    /** @var AuditLog $entry */
    $entry = AuditLog::query()->where('action', 'workspace.file_downloaded')->firstOrFail();

    expect($entry->target_id)->toBe($this->workspace->getKey())
        ->and($entry->metadata['path'] ?? null)->toBe('notes.txt')
        ->and($entry->metadata['bytes'] ?? null)->toBe(15);
});

/**
 * Containment, through the door a browser uses. The page reads through the
 * same `WorkspaceFiles` an agent does, and so does this.
 */
it('refuses a path that leaves the root', function (string $path): void {
    ($this->download)($path)->assertNotFound();

    expect(AuditLog::query()->where('action', 'workspace.file_downloaded')->count())->toBe(0);
})->with([
    '../secret.txt',
    '../../etc/passwd',
    '/etc/passwd',
    'reports/../../secret.txt',
    'missing.txt',
    'reports',
    '',
]);

it('refuses a symlink pointing out of the root', function (): void {
    symlink($this->outside.'/secret.txt', $this->root.'/innocent.txt');

    // Listed nowhere and readable through nothing, including here. A download
    // that followed the link would be the same information leak as showing it.
    ($this->download)('innocent.txt')->assertNotFound();
});

it('refuses a download of another tenant\'s workspace', function (): void {
    inTenant('acme', function (): void {
        Workspace::query()->create([
            'name' => 'Acme only',
            'slug' => 'acme-only',
            'disk' => 'local',
            'root_path' => $this->root,
        ]);
    });

    inTenant('globex', function (): void {
        // 404 rather than 403: a refusal that distinguishes "not yours" from
        // "not there" confirms the slug exists.
        ($this->download)('notes.txt', 'acme-only')->assertNotFound();
    });
});

it('requires the workspaces ability', function (): void {
    Gate::define('pandora.workspaces.access', static fn (): bool => false);

    ($this->download)('notes.txt')->assertForbidden();

    expect(AuditLog::query()->where('action', 'workspace.file_downloaded')->count())->toBe(0);
});

it('requires pandora.access as well', function (): void {
    Gate::define('pandora.access', static fn (): bool => false);

    ($this->download)('notes.txt')->assertForbidden();
});

it('withholds the download from an operator holding every ability while the flag is off', function (): void {
    config()->set('pandora.features.workspaces', false);

    Gate::before(static fn (): bool => true);

    ($this->download)('notes.txt')->assertNotFound();

    expect(AuditLog::query()->where('action', 'workspace.file_downloaded')->count())->toBe(0);
});

it('sanitises the filename it puts in the header', function (): void {
    file_put_contents($this->root.'/od"d;name.txt', 'odd');

    // A `Content-Disposition` that echoed the name back would be header
    // injection with a friendly face, and the name was chosen by an agent.
    ($this->download)('od"d;name.txt')
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="od_d_name.txt"');
});

/**
 * The architectural half of the criterion. The behaviour above proves the
 * download that exists streams; this proves the other one does not exist.
 */
it('issues no presigned URL anywhere in the package', function (): void {
    $offenders = [];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../src')) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $contents = (string) file_get_contents($file->getPathname());

        // Call shapes rather than the word, so that writing down WHY there is
        // no presigned URL does not trip the test that says there is none.
        if (preg_match('/->\s*(temporaryUrl|temporaryUploadUrl|createPresigned\w*|getPresigned\w*)\s*\(/i', $contents) === 1) {
            $offenders[] = $file->getPathname();
        }
    }

    // A grep is a blunt instrument and that is the point: this fails the first
    // time somebody adds the convenient version, which is what makes replacing
    // a streamed download with a bearer token a decision instead of a commit.
    expect($offenders)->toBe([]);
});

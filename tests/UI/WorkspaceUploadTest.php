<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Pandora\Audit\AuditLog;
use Pandora\UI\Livewire\WorkspacesIndex;
use Pandora\Workspaces\Workspace;

/**
 * Phase 7, criterion 22 — an operator puts a file into a workspace, through
 * the same door an agent writes through.
 *
 * Before this existed the only writers were agents and whatever put bytes in
 * the bucket out of band, which is why the walkthrough told you to `mc cp` a
 * file in and press Recount. That is a workaround standing in for a feature.
 *
 * The upload is deliberately not its own write path. Every guarantee about a
 * workspace — the quota reserved before the bytes land, the MIME allowlist
 * matched on the detected type, containment proven by the adapter — belongs to
 * `WorkspaceFiles`, and a second way in would arrive with its own slightly
 * different version of each.
 */
beforeEach(function (): void {
    Gate::define('pandora.access', static fn (): bool => true);
    Gate::define('pandora.workspaces.access', static fn (): bool => true);

    config()->set('pandora.features.workspaces', true);

    $this->actingAsUser();

    $this->root = sys_get_temp_dir().'/pandora-up-'.bin2hex(random_bytes(6));

    mkdir($this->root.'/reports', 0777, true);

    /** @var Workspace $workspace */
    $workspace = Workspace::query()->create([
        'name' => 'Scratch',
        'slug' => 'scratch',
        'disk' => 'local',
        'root_path' => $this->root,
        'quota_bytes' => 4096,
    ]);

    $this->workspace = $workspace;

    $this->upload = fn (UploadedFile $file, string $path = '') => Livewire::test(WorkspacesIndex::class)
        ->call('select', 'scratch')
        ->set('path', $path)
        ->set('file', $file)
        ->call('uploadFile');
});

afterEach(function (): void {
    foreach ([$this->root.'/reports', $this->root] as $dir) {
        foreach (glob($dir.'/*') ?: [] as $file) {
            is_file($file) ? unlink($file) : null;
        }

        @rmdir($dir);
    }
});

it('writes an uploaded file into the workspace', function (): void {
    ($this->upload)(UploadedFile::fake()->createWithContent('notes.txt', 'from an operator'))
        ->assertHasNoErrors()
        ->assertSee('Uploaded notes.txt');

    expect(file_get_contents($this->root.'/notes.txt'))->toBe('from an operator')
        // Accounted exactly as an agent's write is, because it IS an agent's
        // write path.
        ->and($this->workspace->refresh()->used_bytes)->toBe(16);
});

it('writes into the directory being browsed', function (): void {
    ($this->upload)(UploadedFile::fake()->createWithContent('q1.txt', 'quarterly'), 'reports')
        ->assertHasNoErrors();

    expect(file_get_contents($this->root.'/reports/q1.txt'))->toBe('quarterly');
});

it('records that a person put the file there', function (): void {
    ($this->upload)(UploadedFile::fake()->createWithContent('notes.txt', 'from an operator'));

    // On top of the `workspace.file_written` the write itself logs. "An agent
    // wrote this" and "a person put this here" are different facts, and only
    // one of them is somebody's decision.
    /** @var AuditLog $entry */
    $entry = AuditLog::query()->where('action', 'workspace.file_uploaded')->firstOrFail();

    expect($entry->metadata['path'] ?? null)->toBe('notes.txt')
        ->and(AuditLog::query()->where('action', 'workspace.file_written')->count())->toBe(1);
});

/**
 * The filename, which the browser sends exactly as it was given and which is
 * therefore chosen by whoever made the file.
 */
it('reduces a filename that is trying to be a path', function (string $sent, string $expected): void {
    ($this->upload)(UploadedFile::fake()->createWithContent($sent, 'contained'));

    expect(file_get_contents($this->root.'/'.$expected))->toBe('contained')
        // The thing that must not have happened, stated directly.
        ->and(file_exists(dirname($this->root).'/escaped.txt'))->toBeFalse();
})->with([
    ['../escaped.txt', 'escaped.txt'],
    ['../../escaped.txt', 'escaped.txt'],
    ['/etc/passwd', 'passwd'],
    ['reports/../../escaped.txt', 'escaped.txt'],
    ['..', 'upload'],
    ['.hidden', 'hidden'],
    ['odd name (1).txt', 'odd_name__1_.txt'],
]);

it('refuses a file bigger than the quota, before it lands', function (): void {
    ($this->upload)(UploadedFile::fake()->createWithContent('big.txt', str_repeat('x', 5000)))
        ->assertSee('The workspace is full');

    expect(file_exists($this->root.'/big.txt'))->toBeFalse()
        ->and($this->workspace->refresh()->used_bytes)->toBe(0);
});

it('refuses a file bigger than the declared upload bound', function (): void {
    config()->set('pandora.workspaces.max_upload_bytes', 1024);

    // Bounded by policy rather than by `upload_max_filesize`, which is a
    // deployment accident.
    ($this->upload)(UploadedFile::fake()->create('big.bin', 4))
        ->assertHasErrors('file');

    expect(file_exists($this->root.'/big.bin'))->toBeFalse();
});

it('refuses a type the workspace does not allow, on the detected type', function (): void {
    $this->workspace->update(['allowed_mime_types' => ['text/plain']]);

    // Named `.txt`, and a PNG inside. The extension is an assertion by
    // whoever chose the filename; the bytes are not.
    ($this->upload)(UploadedFile::fake()->createWithContent('sneaky.txt', base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
    )))->assertSee('That kind of file is not allowed');

    expect(file_exists($this->root.'/sneaky.txt'))->toBeFalse();
});

it('refuses an upload with no file at all', function (): void {
    Livewire::test(WorkspacesIndex::class)
        ->call('select', 'scratch')
        ->call('uploadFile')
        ->assertHasErrors('file');
});

it('uploads nothing when no workspace is selected', function (): void {
    Livewire::test(WorkspacesIndex::class)
        ->set('file', UploadedFile::fake()->createWithContent('notes.txt', 'nowhere'))
        ->call('uploadFile');

    expect(glob($this->root.'/*.txt'))->toBe([]);
});

/**
 * Who may, and whether the surface is there to reach at all.
 */
it('refuses an upload from a user without the ability', function (): void {
    Gate::define('pandora.workspaces.access', static fn (): bool => false);

    ($this->upload)(UploadedFile::fake()->createWithContent('notes.txt', 'forged'))
        ->assertForbidden();

    expect(file_exists($this->root.'/notes.txt'))->toBeFalse();
});

it('refuses a forged upload while the feature is off, ability or not', function (): void {
    config()->set('pandora.features.workspaces', false);

    Gate::before(static fn (): bool => true);

    ($this->upload)(UploadedFile::fake()->createWithContent('notes.txt', 'forged'))
        ->assertNotFound();

    expect(file_exists($this->root.'/notes.txt'))->toBeFalse();
});

it('does not upload into another tenant\'s workspace', function (): void {
    $acme = inTenant('acme', function (): Workspace {
        /** @var Workspace $workspace */
        $workspace = Workspace::query()->create([
            'name' => 'Acme only',
            'slug' => 'acme-only',
            'disk' => 'local',
            'root_path' => $this->root,
        ]);

        return $workspace;
    });

    inTenant('globex', function (): void {
        Livewire::test(WorkspacesIndex::class)
            ->set('selected', 'acme-only')
            ->set('file', UploadedFile::fake()->createWithContent('notes.txt', 'not yours'))
            ->call('uploadFile');
    });

    expect(file_exists($this->root.'/notes.txt'))->toBeFalse()
        ->and($acme->refresh()->used_bytes)->toBe(0);
});

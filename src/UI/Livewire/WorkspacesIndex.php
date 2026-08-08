<?php

declare(strict_types=1);

namespace Pandora\UI\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Pandora\Agents\Agent;
use Pandora\Audit\AuditLogger;
use Pandora\Exceptions\WorkspaceDenied;
use Pandora\UI\Feature;
use Pandora\UI\PandoraGate;
use Pandora\Workspaces\Workspace;
use Pandora\Workspaces\WorkspaceFiles;
use Pandora\Workspaces\WorkspaceRoots;

/**
 * The files agents can reach, and how full each workspace is.
 *
 * Browsing goes through `WorkspaceFiles` rather than reading the disk
 * directly, which means the control center is subject to exactly the same
 * containment rules as an agent. That is not caution for its own sake: a page
 * that could show a file an agent cannot read would be a way to confirm what
 * lives outside the root, and the whole point of the root is that nobody finds
 * out.
 *
 * Usage is shown from the counter and can be recounted on demand, because the
 * counter is authoritative for enforcement but the filesystem is authoritative
 * for truth, and they can drift after a crash.
 *
 * Creation is the part Phase 5 held back, and the form here has no path field.
 * It offers the roots an operator declared, by key, and `WorkspaceRoots`
 * composes the rest; `disk` and `root_path` are never bound from a request and
 * never editable afterwards, because re-pointing a root orphans every path
 * already written and, on object storage, moves not one byte.
 */
final class WorkspacesIndex extends Component
{
    use WithFileUploads;

    #[Url(as: 'workspace', except: '')]
    public string $selected = '';

    #[Url(as: 'path', except: '')]
    public string $path = '';

    public ?string $notice = null;

    public ?string $error = null;

    /** Empty unless a form is open: 'create', or the slug being edited. */
    public string $form = '';

    public string $rootKey = '';

    public string $formName = '';

    public string $formDescription = '';

    /** Bytes, as typed. Empty means unlimited, which stays a deliberate choice. */
    public string $formQuota = '';

    /** Comma-separated. Empty permits every type, and narrows an already-bounded workspace when it is not. */
    public string $formMimeTypes = '';

    /** A file an operator is putting into the workspace being browsed. */
    public ?TemporaryUploadedFile $file = null;

    public function mount(): void
    {
        PandoraGate::authorize('access');
    }

    public function startCreating(): void
    {
        $this->guard();

        $this->form = 'create';
        $this->rootKey = (string) array_key_first($this->roots()->all());
        $this->formName = '';
        $this->formDescription = '';
        $this->formQuota = (string) (config('pandora.workspaces.default_quota_bytes') ?? '');
        $this->formMimeTypes = '';
        $this->error = null;
        $this->resetValidation();
    }

    /**
     * Open the edit form, which offers everything except where the bytes are.
     *
     * `disk` and `root_path` are shown and are not fields. A workspace that
     * could be re-pointed would leave every file already written somewhere
     * nothing reads, and on object storage the move that would fix that does
     * not exist: there is no rename, only a copy of every object and a delete
     * of every original, with no transaction around the pair.
     */
    public function startEditing(string $slug): void
    {
        $this->guard();

        $workspace = $this->find($slug);

        if ($workspace === null) {
            return;
        }

        $this->form = $workspace->slug;
        $this->formName = $workspace->name;
        $this->formDescription = (string) $workspace->description;
        $this->formQuota = $workspace->quota_bytes === null ? '' : (string) $workspace->quota_bytes;
        $this->formMimeTypes = implode(', ', $workspace->allowed_mime_types ?? []);
        $this->error = null;
        $this->resetValidation();
    }

    public function cancelForm(): void
    {
        $this->form = '';
        $this->error = null;
        $this->resetValidation();
    }

    /**
     * Create a workspace under one of the declared roots.
     *
     * The request carries a root KEY and a name. It does not carry a path, it
     * cannot carry a path, and `WorkspaceRoots` refuses a key nobody declared
     * -- so whatever a forged submission puts in `rootKey`, the only reachable
     * outcomes are a declared root or a refusal.
     */
    public function create(AuditLogger $audit): void
    {
        $this->guard();

        $this->validate([
            'rootKey' => ['required', 'string'],
            'formName' => ['required', 'string', 'min:2', 'max:120'],
            'formDescription' => ['nullable', 'string', 'max:500'],
            'formQuota' => ['nullable', 'numeric', 'min:0'],
            'formMimeTypes' => ['nullable', 'string', 'max:500'],
        ], attributes: $this->formAttributes());

        $slug = Str::slug($this->formName);

        if ($slug === '') {
            $this->error = 'That name does not produce a usable slug. Try one with letters or digits in it.';

            return;
        }

        if ($this->find($slug) !== null) {
            $this->error = 'A workspace with that name already exists.';

            return;
        }

        try {
            $root = $this->roots()->get($this->rootKey);
            $path = $this->roots()->compose($root, $slug);
            $this->roots()->prepare($root, $path);
        } catch (WorkspaceDenied $e) {
            $this->error = $e->userMessage();

            return;
        }

        /** @var Workspace $workspace */
        $workspace = Workspace::query()->create([
            'name' => trim($this->formName),
            'slug' => $slug,
            'description' => $this->formDescription === '' ? null : trim($this->formDescription),
            'disk' => $root->disk,
            'root_path' => $path,
            'quota_bytes' => $this->quota(),
            'allowed_mime_types' => $this->mimeTypes(),
        ]);

        $audit->record(
            action: 'workspace.created',
            targetType: 'workspace',
            targetId: (string) $workspace->getKey(),
            metadata: ['slug' => $slug, 'root' => $root->key, 'disk' => $root->disk, 'root_path' => $path],
        );

        $this->form = '';
        $this->notice = 'Workspace created at '.$root->disk.':'.$path;
        $this->selected = $slug;
        $this->path = '';
    }

    public function save(AuditLogger $audit): void
    {
        $this->guard();

        $workspace = $this->find($this->form);

        if ($workspace === null) {
            return;
        }

        $this->validate([
            'formName' => ['required', 'string', 'min:2', 'max:120'],
            'formDescription' => ['nullable', 'string', 'max:500'],
            'formQuota' => ['nullable', 'numeric', 'min:0'],
            'formMimeTypes' => ['nullable', 'string', 'max:500'],
        ], attributes: $this->formAttributes());

        // The slug is left alone along with the disk and the root. It is half
        // of the uniqueness constraint and it is what a bookmark names; a
        // rename that silently re-slugged would break both to save a click.
        $workspace->update([
            'name' => trim($this->formName),
            'description' => $this->formDescription === '' ? null : trim($this->formDescription),
            'quota_bytes' => $this->quota(),
            'allowed_mime_types' => $this->mimeTypes(),
        ]);

        $audit->record(
            action: 'workspace.updated',
            targetType: 'workspace',
            targetId: (string) $workspace->getKey(),
            metadata: [
                'slug' => $workspace->slug,
                'quota_bytes' => $workspace->quota_bytes,
                'allowed_mime_types' => $workspace->allowed_mime_types ?? [],
            ],
        );

        $this->form = '';
        $this->notice = 'Workspace updated.';
    }

    /**
     * Remove the workspace row. The files stay exactly where they are.
     *
     * Deleting them is N delete calls with no transaction around them, and a
     * partial failure leaves a half-emptied prefix under a row that says it is
     * gone -- worse than bytes nobody deleted, and unrecoverable in the
     * direction that matters. So the row goes, every agent pointing at it is
     * detached, and the page says which disk and prefix still hold the files
     * so that whoever wants them gone can go and say so.
     */
    public function delete(string $slug, AuditLogger $audit): void
    {
        $this->guard();

        $workspace = $this->find($slug);

        if ($workspace === null) {
            return;
        }

        $detached = Agent::query()
            ->where('workspace_id', $workspace->getKey())
            ->update(['workspace_id' => null]);

        $audit->record(
            action: 'workspace.deleted',
            targetType: 'workspace',
            targetId: (string) $workspace->getKey(),
            severity: 'warning',
            metadata: [
                'slug' => $workspace->slug,
                'disk' => $workspace->disk,
                'root_path' => $workspace->root_path,
                'agents_detached' => $detached,
                // Said in the record as well as on the page, because an
                // auditor reading this a year later needs to know the bytes
                // outlived the row.
                'files_removed' => false,
            ],
        );

        $disk = $workspace->disk;
        $path = $workspace->root_path;

        $workspace->delete();

        if ($this->selected === $slug) {
            $this->selected = '';
            $this->path = '';
        }

        $this->form = '';
        $this->notice = 'Workspace removed. Its files were left at '.$disk.':'.$path.'.';
    }

    public function select(string $slug): void
    {
        $this->selected = $slug;
        $this->path = '';
        $this->notice = null;
        $this->error = null;
    }

    public function browse(string $path): void
    {
        $this->path = $path;
        $this->error = null;
    }

    public function up(): void
    {
        // Trimmed here rather than by passing `..` into the resolver. The
        // resolver would refuse it correctly at the root, but producing a
        // traversal in the UI to express "go up" means the one place that
        // must never see a traversal is routinely handed one.
        $this->path = str_contains($this->path, '/')
            ? substr($this->path, 0, (int) strrpos($this->path, '/'))
            : '';
    }

    public function recount(): void
    {
        $this->guard();

        $workspace = $this->workspace();

        if ($workspace === null) {
            return;
        }

        try {
            $bytes = $this->files($workspace)->reconcile();
            $this->notice = 'Recounted: '.number_format($bytes).' bytes.';
        } catch (WorkspaceDenied $e) {
            $this->error = $e->userMessage();
        }
    }

    /**
     * Put a file into the workspace currently being browsed.
     *
     * Written through `WorkspaceFiles` like everything else, which is the
     * whole design: the quota is reserved before the bytes land, the MIME
     * allowlist is matched on the detected type, and containment is proven by
     * the adapter. An upload that wrote to the disk directly would be a second
     * way in with its own idea of when a workspace is full.
     *
     * The client filename is hostile in exactly the way an agent's path is --
     * it is chosen by whoever made the file, and the browser sends it
     * unchanged. It is reduced to a bare name here, and then the adapter
     * refuses anything that still escapes; neither check is trusted to be the
     * only one.
     */
    public function uploadFile(AuditLogger $audit): void
    {
        $this->guard();

        $workspace = $this->workspace();

        if ($workspace === null) {
            return;
        }

        $this->validate([
            'file' => ['required', 'file', 'max:'.(int) (self::maxUploadBytes() / 1024)],
        ], attributes: ['file' => 'file']);

        /** @var TemporaryUploadedFile $file */
        $file = $this->file;
        $name = $this->safeFilename((string) $file->getClientOriginalName());
        $relative = $this->path === '' ? $name : $this->path.'/'.$name;

        try {
            // Read whole, because that is what the write path takes and what
            // the quota accounting is expressed in. Bounded by the validation
            // above rather than by hope: this is the one place a workspace
            // reads a file into the worker instead of streaming it.
            $this->files($workspace)->write($relative, (string) $file->get());
        } catch (WorkspaceDenied $e) {
            $this->error = $e->userMessage();
            $this->file = null;

            return;
        }

        // Recorded on top of the `workspace.file_written` the write itself
        // logs. "An agent wrote this" and "a person put this here" are
        // different facts, and only one of them is somebody's decision.
        $audit->record(
            action: 'workspace.file_uploaded',
            targetType: 'workspace',
            targetId: (string) $workspace->getKey(),
            metadata: ['path' => $relative, 'bytes' => $file->getSize(), 'disk' => $workspace->disk],
        );

        $this->file = null;
        $this->error = null;
        $this->notice = 'Uploaded '.$relative.'.';
    }

    public function render(): View
    {
        // Held back rather than removed. Nothing below runs while the feature
        // is off, so no workspace is listed, read or counted -- including for
        // an operator holding every ability, because this is not a question
        // about who is asking.
        if (Feature::disabled('workspaces')) {
            return view('pandora::livewire.workspaces-soon')
                ->layout('pandora::layouts.app', ['title' => 'Workspaces']);
        }

        $workspace = $this->workspace();
        $entries = [];
        $unreachable = false;

        if ($workspace !== null) {
            try {
                $entries = $this->files($workspace)->list($this->path);
            } catch (WorkspaceDenied) {
                // A root that has moved or been unmounted. Reported on the
                // page rather than thrown, because an operator arriving to
                // find out why an agent cannot read its files should see the
                // reason, not a stack trace.
                $unreachable = true;
            }
        }

        return view('pandora::livewire.workspaces-index', [
            'workspaces' => $this->workspaces(),
            'workspace' => $workspace,
            'entries' => $entries,
            'unreachable' => $unreachable,
            'canManage' => PandoraGate::allows('workspaces.access'),
            'roots' => $this->roots()->all(),
            'editing' => $this->form === '' || $this->form === 'create' ? null : $this->find($this->form),
            'maxUploadBytes' => self::maxUploadBytes(),
        ])->layout('pandora::layouts.app', ['title' => 'Workspaces']);
    }

    /**
     * Everything a mutation has to be true before it runs.
     *
     * The flag is checked first and separately, because it is not an ability.
     * A withheld surface is withheld from an operator holding every ability
     * too, and a forged Livewire call is exactly the request that skips the
     * page where the flag was honoured.
     */
    private function guard(): void
    {
        if (Feature::disabled('workspaces')) {
            abort(404);
        }

        PandoraGate::authorize('workspaces.access');
    }

    /**
     * The largest file the page will accept.
     *
     * A bound is declared rather than left to `upload_max_filesize`, because
     * the PHP limit is a deployment accident and this is a policy. It is not
     * the quota: the quota is what the workspace may hold in total, and this
     * is what one request may carry into the worker's memory at once.
     */
    public static function maxUploadBytes(): int
    {
        $configured = config('pandora.workspaces.max_upload_bytes');

        return is_numeric($configured) ? (int) $configured : 26214400;
    }

    /**
     * A bare filename, from a string chosen by whoever made the file.
     *
     * Reduced rather than rejected, because an operator uploading `Q1 (final).pdf`
     * has done nothing wrong. What cannot survive is anything that makes it a
     * path: the adapter would refuse that too, and this is the first of the
     * two checks rather than the only one.
     */
    private function safeFilename(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
        $name = ltrim($name, '.');

        return $name === '' ? 'upload' : Str::limit($name, 120, '');
    }

    private function roots(): WorkspaceRoots
    {
        return app(WorkspaceRoots::class);
    }

    /**
     * Tenant-scoped by the model's global scope, which is what makes a slug
     * from a request safe to look up directly.
     */
    private function find(string $slug): ?Workspace
    {
        if ($slug === '') {
            return null;
        }

        /** @var Workspace|null $workspace */
        $workspace = Workspace::query()->where('slug', $slug)->first();

        return $workspace;
    }

    private function quota(): ?int
    {
        return trim($this->formQuota) === '' ? null : (int) $this->formQuota;
    }

    /**
     * @return list<string>
     */
    private function mimeTypes(): array
    {
        $types = array_filter(array_map(
            static fn (string $type): string => strtolower(trim($type)),
            explode(',', $this->formMimeTypes),
        ), static fn (string $type): bool => $type !== '');

        return array_values(array_unique($types));
    }

    /**
     * @return array<string, string>
     */
    private function formAttributes(): array
    {
        return [
            'rootKey' => 'root',
            'formName' => 'name',
            'formDescription' => 'description',
            'formQuota' => 'quota',
            'formMimeTypes' => 'allowed types',
        ];
    }

    /** @return Collection<int, Workspace> */
    private function workspaces(): Collection
    {
        /** @var Collection<int, Workspace> $workspaces */
        $workspaces = Workspace::query()->orderBy('name')->get();

        return $workspaces;
    }

    private function workspace(): ?Workspace
    {
        if ($this->selected === '') {
            return null;
        }

        /** @var Workspace|null $workspace */
        $workspace = Workspace::query()->where('slug', $this->selected)->first();

        return $workspace;
    }

    private function files(Workspace $workspace): WorkspaceFiles
    {
        return new WorkspaceFiles($workspace, app(AuditLogger::class));
    }
}

<?php

declare(strict_types=1);

namespace Pandora\UI\Http;

use Illuminate\Http\Request;
use Pandora\Audit\AuditLogger;
use Pandora\Exceptions\WorkspaceDenied;
use Pandora\UI\Feature;
use Pandora\UI\PandoraGate;
use Pandora\Workspaces\Workspace;
use Pandora\Workspaces\WorkspaceFiles;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Download one file out of a workspace, through the application.
 *
 * A presigned URL would be easier and is refused on purpose (ADR-0013). It is
 * a bearer token for one object until it expires: forwardable, logged by every
 * proxy it passes, and -- the part that matters here -- invisible to the audit
 * trail the moment it is issued. What gets recorded is that somebody asked for
 * a link, not that the file left. Streaming through the app costs bandwidth
 * and buys the only version of "who exported this" that stays true.
 *
 * Every guard an agent meets applies, plus the two an agent does not have: the
 * feature flag, and a person's own ability.
 */
final class WorkspaceDownloadController
{
    public function __invoke(Request $request, string $workspace, AuditLogger $audit): StreamedResponse
    {
        // The flag first and by itself. It is not an ability, and no operator
        // holds their way past it.
        if (Feature::disabled('workspaces')) {
            abort(404);
        }

        PandoraGate::authorize('access');
        PandoraGate::authorize('workspaces.access');

        /** @var Workspace|null $model */
        $model = Workspace::query()->where('slug', $workspace)->first();

        // Tenant-scoped by the model's global scope, so another tenant's
        // workspace is not found rather than forbidden. A 403 here would
        // confirm the slug exists, which is the fact worth withholding.
        if ($model === null) {
            abort(404);
        }

        $path = (string) $request->query('path', '');
        $files = new WorkspaceFiles($model, $audit);

        try {
            if (! $files->isFile($path)) {
                abort(404);
            }

            $size = $files->size($path);
            $handle = $files->stream($path);
        } catch (WorkspaceDenied) {
            // Traversal, a symlink out of the root, a key that normalises
            // outside the prefix, an unreachable disk. All 404: an operator
            // browsing the page never produces one of these, and the shapes
            // that do are asking a question about what exists.
            abort(404);
        }

        $audit->record(
            action: 'workspace.file_downloaded',
            targetType: 'workspace',
            targetId: (string) $model->getKey(),
            metadata: ['path' => $path, 'bytes' => $size, 'disk' => $model->disk],
        );

        return response()->stream(
            function () use ($handle): void {
                // Chunked rather than fpassthru: a workspace is allowed to
                // hold a file bigger than the worker's memory limit, and the
                // whole reason for a handle is that it never all lands here.
                while (! feof($handle)) {
                    $chunk = fread($handle, 8192);

                    if ($chunk === false) {
                        break;
                    }

                    echo $chunk;
                    flush();
                }

                fclose($handle);
            },
            200,
            [
                // Never the store's `Content-Type`, and never one guessed from
                // the extension. Both are chosen by whoever uploaded the file,
                // and in a workspace that whoever is a model. An octet-stream
                // attachment is the one answer no filename can turn into
                // something the browser executes.
                'Content-Type' => 'application/octet-stream',
                'Content-Length' => (string) $size,
                'Content-Disposition' => 'attachment; filename="'.$this->safeFilename($path).'"',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    /**
     * A filename the header can carry without being rewritten by it.
     *
     * Quotes, newlines and semicolons in a `Content-Disposition` are header
     * injection with a friendly name, and the path this comes from was chosen
     * by an agent.
     */
    private function safeFilename(string $path): string
    {
        $name = basename(str_replace('\\', '/', $path));
        $name = (string) preg_replace('/[^A-Za-z0-9._-]/', '_', $name);

        return $name === '' ? 'download' : $name;
    }
}

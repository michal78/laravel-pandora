# Workspaces

> **Released in Phase 7.** `pandora.features.workspaces` now defaults to `true`.
> Set it `false` to withdraw the surface again — from everybody, including an
> operator holding every ability, because a flag is not a permission.

A workspace is a bounded piece of filesystem an agent may use. An agent with no
workspace can reach no files at all, and that is the default — it is the right
one for an agent nobody has thought about yet.

## Containment

**A path is contained after it is resolved, not before, and on every
operation.**

Both halves matter, for different reasons.

*After resolution*, because a check against the string a caller passed is a
check against a spelling. `../` has a dozen spellings and a symlink has none at
all: it is simply a path inside the root that is not a *file* inside the root.
Only `realpath()` answers the question worth asking — which file is this,
actually.

*Every operation*, because resolving once and using twice is a TOCTOU window
that a symlink planted between the two fits through exactly. There is no
"already validated" fast path, and there should never be one.

Paths from an agent are treated as hostile throughout. That is not paranoia
about the model: a tool argument is downstream of every document the agent has
read this run, and one of those documents is allowed to be a web page.

Refused, in all cases:

- `../` traversal, in any spelling
- an absolute path outside the root
- a symlink inside the root pointing outside it — on read **and on write**
- a file reached through a symlinked directory
- a dangling symlink, refused rather than created
- a sibling directory sharing the root's name prefix (`/srv/agent-secrets` for
  a root of `/srv/agent`)
- a path containing a null byte

An escaping symlink is also **omitted from listings**. Showing it tells an agent
that a file it may not read exists, which is the same information leak in a
smaller package.

Refusals name the relative path the caller supplied and never the resolved
one. Saying where a symlink pointed confirms both that the file exists and
where the root is.

## Quotas

Claimed before the bytes land, by a conditional increment rather than a
read-then-write. Checking `used_bytes` and then writing is a race whose window
is a database round trip, and it fails under exactly the load that made you set
a quota. Two writers racing for the last bytes cannot both see room.

A refused or short write gives the reservation back, so failures do not shrink
a workspace a little at a time until it is full of nothing.

`used_bytes` is authoritative for enforcement because reading it is one query
while walking a tree is thousands of syscalls. The filesystem is authoritative
for truth. When they drift — a crash mid-write, a file removed by hand — use
**Recount** on the Workspaces page, or `WorkspaceFiles::reconcile()`.

A null quota means unlimited, which an operator has to choose explicitly rather
than have the default fall open into.

## MIME restrictions

Matched on the **detected** type, read from magic bytes with `finfo`. The
filename is never consulted: an extension is an assertion by whoever chose it,
and in a workspace that whoever is a model acting on documents it has read.

An empty allowlist permits everything. That is the opposite of the rule context
files use for roots, and deliberately so — a root list describes where files may
come *from* and must fail closed, whereas a MIME list narrows what may go into a
workspace that is already bounded. An operator who set none has not implicitly
banned everything.

Wildcards are supported: `image/*` matches `image/png` but not `imagex/png`.

## Setup

### Declaring where workspaces may live

Before anything can be created in the control center, an operator says where.
`pandora.workspaces.roots` is that declaration: a disk from your
`filesystems.php` and a base prefix inside it, named by a key.

```php
'workspaces' => [
    'roots' => [
        'local'  => ['label' => 'Local storage',  'disk' => 'local',
                     'base_prefix' => 'pandora-workspaces'],
        'bucket' => ['label' => 'Object storage', 'disk' => 's3',
                     'base_prefix' => 'workspaces'],
    ],

    'default_quota_bytes' => 104857600,
],
```

The **New workspace** form offers these by key and asks for a name. It has no
path field, and that is the whole design: the path is composed as
`<base>/<tenant>/<slug>`, so a request's entire vocabulary for saying where a
workspace lives is a key an operator wrote down. A form with a path field is a
form that accepts `/`.

An empty root list permits nothing rather than everything. That is the opposite
direction from the MIME allowlist below — a MIME list narrows what may enter an
already-bounded workspace, while a root list decides where the boundary *is*.

For a local root the directory is created; `realpath()` of a directory nobody
made is `false`, and every containment check starts there. For an object root
there is nothing to create, because a prefix with no objects under it is already
as real as a prefix gets.

**A workspace's `disk` and `root_path` cannot be changed afterwards.** Name,
description, quota and MIME list can. Re-pointing a root leaves every file
already written somewhere nothing reads, and on object storage the move that
would fix it does not exist: no rename, only a copy of every object and a delete
of every original with no transaction around the pair. Somewhere else is a new
workspace.

**Removing a workspace does not remove its files.** The row goes, every agent
pointing at it is detached, and the bytes stay where they are — the page names
the disk and prefix that still hold them, and the audit entry records
`files_removed: false`. Deleting them would be N calls with no transaction, and
a partial failure leaves a half-emptied prefix under a row claiming it is gone.

### In code

Creation in code still works and is unchanged:

```php
use Pandora\Workspaces\Workspace;

$workspace = Workspace::query()->create([
    'name' => 'Reports',
    'slug' => 'reports',
    'disk' => 'local',
    'root_path' => storage_path('app/agent-reports'),
    'quota_bytes' => 50 * 1024 * 1024,
    'allowed_mime_types' => ['text/plain', 'text/csv', 'application/pdf'],
]);

$agent->update(['workspace_id' => $workspace->getKey()]);
```

`disk` and `root_path` are operator configuration and nothing an agent says
reaches them. The root is what every containment check is measured against; a
root an agent could influence is not a boundary, it is a suggestion.

## Object storage

A workspace may live in any S3-compatible bucket — AWS, DigitalOcean Spaces,
Hetzner, MinIO, Cloudflare R2 — by naming a disk the application has already
configured in `config/filesystems.php`. `root_path` becomes a key prefix:

```php
$workspace = Workspace::query()->create([
    'name' => 'Reports',
    'slug' => 'reports',
    'disk' => 's3',                      // a disk in YOUR filesystems.php
    'root_path' => 'workspaces/reports', // a key prefix, not a path
    'quota_bytes' => 50 * 1024 * 1024,
]);
```

Install `league/flysystem-aws-s3-v3` for the `s3` driver. Pandora stores no
endpoint, key or secret of its own: a second secret store is a second thing to
leak, and rotation belongs where you already rotate.

Everything above this section is unchanged on either kind of disk. What differs
is beneath it (ADR-0013):

- **Containment is re-derived, not ported.** A filesystem resolves a path with
  `realpath()` and checks what it resolved, because `../` has many spellings and
  a symlink has none. An object store has no links, no directories and no second
  key for the same bytes, so keys are normalised lexically instead — `..` is
  refused rather than resolved, along with absolute, scheme-shaped and
  backslash-separated keys.
- **An unreachable disk is a tool error, never a fallback.** The run continues
  and the agent is told it cannot use files. Nothing is written locally instead:
  that file would exist on one container while every other node read past it.
- **MIME still comes from the bytes.** An object's `Content-Type` is chosen by
  whoever uploaded it, exactly like a file extension, and nothing consults it.
- **Listing paginates.** A prefix holding more than a thousand objects lists all
  of them.

## Context files on object storage

`pandora.context.files.roots` accepts the same idea, written
`disk:<name>/<prefix>`:

```php
'roots' => [
    storage_path('app/pandora-context'),
    'disk:s3/context',
],
```

An agent then names a file as `disk:s3/context/handbook.md`. Roots vouch only
for their own kind — a filesystem root never authorises a bucket key, and a
bucket prefix never authorises a file on disk.

Context files are read on every iteration of every run, so bodies are cached
and revalidated by ETag rather than by a TTL: edit the object and the next run
sees it, without a full download each time. Reads are ranged to
`pandora.context.files.max_bytes`, so an oversized object costs one truncated
read rather than a transfer bill.

## Reading and writing

```php
use Pandora\Workspaces\WorkspaceFiles;

$files = new WorkspaceFiles($workspace, app(AuditLogger::class));

$files->write('reports/q1.csv', $csv);
$files->read('reports/q1.csv');
$files->list('reports');
$files->delete('reports/q1.csv');
```

Every method throws `WorkspaceDenied` rather than returning false, so a refused
path cannot be mistaken for an empty file.

The control center browses through this same class, which means it is subject
to exactly the same containment rules as an agent. A page that could show a file
an agent cannot read would be a way to confirm what lives outside the root, and
the whole point of the root is that nobody finds out.

## Uploads

An operator with `pandora.workspaces.access` can put a file into a workspace
from the control center, into whichever directory the page is browsing.

It goes through `WorkspaceFiles::write` — the same call an agent's write tool
makes — so it is not a second way in. The quota is reserved before the bytes
land, the MIME allowlist is matched on the **detected** type, and containment is
proven by the adapter, none of which the upload restates. `max_upload_bytes`
bounds one request; the quota bounds the workspace.

The filename the browser sends is chosen by whoever made the file, so it is
reduced to a bare name — `../../etc/passwd` becomes `passwd` — and then handed
to the adapter, which checks it again. Neither check is trusted to be the only
one.

An upload records `workspace.file_uploaded` in addition to the
`workspace.file_written` the write itself logs. "An agent wrote this" and "a
person put this here" are different facts.

## Downloads

A file is downloaded through the application, at
`/pandora/workspaces/{workspace}/download?path=…`, and it is streamed rather
than read into memory — a workspace is allowed to hold a file larger than the
worker's memory limit.

**No presigned URL is ever issued.** A signed object URL is a bearer token for
one object until it expires: forwardable, logged by every proxy it crosses, and
invisible to the audit trail the moment it is issued. What such a trail can
record is that somebody asked for a link, not that the file left, who took it,
or how many times. Streaming costs bandwidth and buys the only version of "who
exported this" that stays true.

The response is always `application/octet-stream` with `nosniff` and a sanitised
filename. The store's `Content-Type` and the file's extension are both chosen by
whoever wrote the file, and in a workspace that whoever is a model.

## Audit actions

`workspace.created` · `workspace.updated` · `workspace.deleted` (`warning`) ·
`workspace.file_downloaded` ·
`workspace.file_written` · `workspace.file_deleted` ·
`workspace.access_denied` · `workspace.quota_exceeded` (`warning`) ·
`workspace.containment_violation` (**`critical`**)

A containment violation is critical rather than warning because it is either a
bug in the containment check or somebody probing, and both deserve to wake
somebody up.

## Abilities

`pandora.access` to view; `pandora.workspaces.access` to recount or manage.

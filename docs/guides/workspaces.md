# Workspaces

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

```php
use Pandora\Pandora\Workspaces\Workspace;

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

## Reading and writing

```php
use Pandora\Pandora\Workspaces\WorkspaceFiles;

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

## Audit actions

`workspace.file_written` · `workspace.file_deleted` ·
`workspace.access_denied` · `workspace.quota_exceeded` (`warning`) ·
`workspace.containment_violation` (**`critical`**)

A containment violation is critical rather than warning because it is either a
bug in the containment check or somebody probing, and both deserve to wake
somebody up.

## Abilities

`pandora.access` to view; `pandora.workspaces.access` to recount or manage.

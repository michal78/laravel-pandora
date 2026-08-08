# ADR-0013: Object storage for workspaces and context files

- **Status:** accepted
- **Date:** 2026-08-08

## Context

Workspaces (Phase 5, deferred to Phase 7) and context files (Phase 5) are both implemented against
a POSIX filesystem and nothing else. `WorkspaceFiles` and `ContextFiles` call `realpath()`,
`fopen()`, `filesize()`, `is_link()` and `finfo` directly. The `pandora_workspaces` table has
carried a `disk` column since it was created and **no code has ever read it**: the schema
anticipated Flysystem and the implementation never arrived.

That is not sustainable for the deployments this package targets. A containerised host has no
durable local disk worth writing to, and a horizontally scaled one has a *different* local disk per
container — so a file written by the worker that handled one tool call is invisible to the worker
that handles the next. The intended target is S3-compatible object storage: AWS S3, DigitalOcean
Spaces, Hetzner Object Storage, MinIO, Cloudflare R2.

The obvious move — "swap the calls for `Storage::disk()` and we are done" — is wrong, and the reason
is the entire security model of both classes.

Containment today is one sentence: **resolve the path with `realpath()`, then check the resolved
path is under the resolved root, on every operation.** Every property depends on resolution
happening first. `../` has a dozen spellings and a symlink has none at all — it is simply a path
inside the root that is not a *file* inside the root. Re-checking on every call is what closes the
TOCTOU window a symlink planted between two operations fits through.

Object storage has no `realpath`, no symlinks, no hardlinks and no directories. A key is an opaque
string. Every concept the containment check is built on is absent, so the property cannot be ported
— it has to be re-derived, and it is a genuinely different (and simpler) problem.

## Decision

**1. A storage contract with containment implemented per adapter, never shared.**

One interface, two implementations, and the containment logic lives in each rather than in a common
base class that would have to be correct for both:

- **Local** keeps the current `realpath()` implementation unchanged. Flysystem's local adapter has
  its own path prefixer and link handling, and it is *not* equivalent to what is there now.
  Replacing a resolve-then-check model with a prefix-then-hope model is a quiet downgrade, and the
  fact that the tests would still pass is exactly why it would go unnoticed.
- **Object storage** normalises the key lexically — reject null bytes, reject any `..` segment
  after normalisation, reject absolute paths and anything scheme-shaped — and then prefixes it with
  the root. There is no link to follow and no race to lose, so lexical normalisation is the whole
  answer rather than a first pass. Tenancy prefixes end in the delimiter, so `tenant-1/` cannot
  match `tenant-10/`.

**2. The disk is configuration. It is never a runtime failover.**

A workspace names its disk. If that disk is unreachable, the tool fails as an ordinary tool error
and the run continues — the same answer Phase 3 gives for an unhealthy provider. Local is the
default when no disk is named, so a host with no object storage works with nothing configured.

Failing over from object storage to local on write was considered and rejected outright. It splits
the source of truth silently: the file exists on exactly one container, every other node reads
past it, and whether a subsequent read finds it depends on which worker answers. An agent cannot
be told that its file half-exists, and an operator cannot be told either, because nothing about it
looks like an error.

**3. Credentials come from the host's `filesystems.php`, and Pandora stores none.**

A workspace names a disk the host has already configured. There is no endpoint field, no key field
and no secret field anywhere in Pandora's schema or UI. A second secret store is a second thing to
leak, and rotation belongs where the host already rotates.

The cost is real and accepted: a tenant cannot bring its own bucket at runtime, only a bucket an
operator declared at deploy time. That is the same trade the workspace root itself makes, and for
the same reason — a form that accepts a bucket and a key pair is a form that accepts someone
else's bucket and someone else's key pair.

**4. MIME comes from the bytes, on every adapter.**

Unchanged in intent, and worth stating because object storage makes it easy to get wrong:
`Content-Type` on an S3 object is metadata *the uploader chose*. It is attacker-controlled in
exactly the way a filename extension is, and it looks more authoritative. Detection reads the magic
bytes, as it does today.

**5. Context files are read through an ETag cache, with bounded reads.**

Context files are read on every iteration of every run, so on object storage a naive implementation
is one full network read per file per iteration. A `HEAD` for the ETag, cached bytes when unchanged,
and a range read bounded by the same byte budget that exists today — so a 2GB object named in
configuration still costs one truncated read rather than the worker's memory limit.

**6. The control center streams file contents; it does not issue presigned URLs.**

Every download passes the ability check and lands in the audit log. A presigned URL is a bearer
token for one object until it expires: forwardable, loggable by any proxy it passes through, and
invisible to the audit trail the moment it is issued. "Who read this file" is a question a
workspace has to be able to answer.

## Alternatives considered

- **One generic Flysystem path for every adapter.** Rejected. It is the option that looks cleanest
  and quietly deletes the containment model: the local case loses resolve-then-check and nothing
  fails to make that visible.
- **Runtime failover to local storage.** Rejected — see decision 2. The failure mode is silent
  divergence, which is worse than an error an agent can report.
- **Per-workspace encrypted S3 credentials via the Phase 3 resolver.** Rejected for now, not
  forever. It is the flexible answer and it adds a secret store, an endpoint field and a UI form
  that accepts both. If per-tenant buckets become a requirement, this ADR is the thing to revisit,
  and the credential resolver already exists to hold them.
- **Presigned URLs for downloads.** Rejected — see decision 6. Reconsider only with a threat model
  that says the audit gap is acceptable, e.g. a workspace explicitly marked public.
- **Keeping context files local-only and moving workspaces alone.** Rejected: a containerised host
  would have to bake context files into its image, which makes updating a handbook a deploy.

## Consequences

- `Workspace::disk` becomes load-bearing after existing unused since Phase 5. Existing rows default
  to the local disk, so nothing that works today stops working.
- `WorkspaceFiles` gains an adapter seam. Its Phase 5 tests — `ContainmentTest`, `QuotaTest`,
  `MimeTest` — must run against **both** adapters, or the local ones will be the only ones that
  ever run and the S3 path will be the untested half.
- The quota model is unaffected: it is a conditional increment against a counter, which was already
  storage-agnostic. Overwrite accounting needs the previous object's size, which becomes a `HEAD`
  rather than a `filesize()`.
- Listing a workspace becomes a prefix listing with a delimiter, and it must paginate. A workspace
  with 100,000 objects is not an error condition.
- Object storage has no atomic rename and no directories. Writes are last-write-wins, and an empty
  directory does not exist — the UI must not imply otherwise.
- A test suite for the object path needs a real S3-compatible endpoint. MinIO in CI, the same way
  the pgvector leg runs against `pgvector/pg17`, since a mocked S3 proves only that the mock agrees
  with itself.

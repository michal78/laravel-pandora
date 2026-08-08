# Phase 7 — Acceptance Test Plan

> **Status: 21 of 22 criteria accepted. What remains is a human driving it.**
>
> This phase was originally six criteria: turn on a feature that was already built. ADR-0013 moved
> workspaces and context files onto S3-compatible object storage, which reopens the one thing the
> earlier plan put out of scope — `WorkspaceFiles` itself — and adds a second adapter that has to
> earn the same guarantees by different means.
>
> Criteria are ticked when the named test asserts them and passes. The three carried from Phase 5
> were **not** pre-ticked: they passed against a local filesystem, and this phase required them to
> pass against both adapters, which is a different claim. They now do.
>
> Criteria 1–16 are verified, and the object leg runs against a real S3-compatible endpoint — MinIO
> in CI and locally — or **skips**. It is never run against `Storage::fake()`, which is the local
> driver wearing an object store's name; a suite green against that would be proving the local
> adapter twice. Without an endpoint the suite reports those 72 tests as skipped rather than green;
> with one it is 1,440 passed and 8 skipped, the 8 being the pgvector leg that needs PostgreSQL.
>
> Criterion 22 was added during the phase. The control center could browse and download and never
> write, so the only ways into a workspace were an agent and `mc cp` — which is a workaround
> standing in for a feature, and the walkthrough was carrying it as an instruction. The upload goes
> through `WorkspaceFiles` rather than beside it, so it inherits every guarantee instead of
> restating any of them.
>
> Criteria 17–20 are the surface, and they are built: roots are declared in
> `pandora.workspaces.roots` and chosen by key, isolation is asserted for every verb rather than
> only the listing, and a download streams through the app with the audit entry a presigned URL
> could not have produced. Criterion 21 is a human driving `phase-7-walkthrough.md`, which has not
> happened — every box in that document is unticked, and it stays that way until it has.
>
> **Two things the real store settled that reasoning had not.** `Content-Type` on an object is
> whatever the uploader wrote — MinIO reports `image/png` for a key holding text, and the workspace
> refuses it on the magic bytes anyway (criterion 12). And listing genuinely pages at 1000, so
> criterion 13 needed 1005 objects to tell a paginating implementation from a lucky one. Neither
> could have been asked of a fake.

Phase 5 built agent file workspaces and then declined to release them. The engine was finished —
containment, quotas and MIME detection implemented and covered — and what was missing was the part
hardest to take back: a way in.

That deferral was about one question. A workspace is somewhere an agent may read and write, and
every guarantee reduces to *who chose the root?* Phase 5 answered "an operator, in code", which is
correct and is also why the walkthrough stalled: there is no way to create a workspace from the
control center, and the obvious fix is a form with a path field. A form with a path field is a form
that accepts `/`.

ADR-0013 adds a second question of the same shape: *what does containment even mean when there is
no filesystem?* Object storage has no `realpath`, no symlinks and no directories, so the property
that makes a workspace safe cannot be ported to it — it has to be re-derived. The danger is that
this looks like a driver swap, and a driver swap would delete the containment model while leaving
every existing test green.

Four properties dominate the acceptance bar:

**Containment is per adapter, and both halves are load-bearing.** Local keeps resolve-with-realpath
then check, on every operation. Object storage normalises the key lexically and prefixes it. Neither
is a weaker version of the other; they are answers to different problems, and a shared
implementation that satisfied both would be satisfying neither carefully.

**A root is chosen by configuration, never by a request.** Unchanged, and now it means a disk *and*
a prefix. The set of permissible roots is declared where an operator declares things, and the UI
selects from it. A field accepting an arbitrary path — or an arbitrary bucket — is the thing this
property exists to forbid.

**A configured disk is never quietly substituted.** An unreachable disk fails as a tool error and
the run continues. It does not fall back to local storage, because a file written to a fallback
exists on exactly one container, every other node reads past it, and nothing about that looks like
an error to anyone.

**A feature held back is held back for everybody.** `pandora.features.workspaces` is not an ability.
No gate, no operator flag and no tenant configuration reaches past it while it is false.

## Scope

A storage contract with **two adapters** (local, object) · `Workspace::disk` becoming load-bearing ·
lexical key normalisation for object keys · tenant prefixes that cannot collide · paginated listing ·
MIME from bytes on both adapters · quota accounting through `HEAD` rather than `filesize()` ·
context files on object storage with an ETag cache and bounded range reads · streamed, audited
downloads · operator upload through the agent write path · `pandora.features.workspaces` enabled by
default · a root-selection mechanism that does not accept free text · workspace creation in the
control center · the Workspaces page and the
agent's **Workspace** tab un-deferred · a **MinIO leg in CI** · the Phase 5 walkthrough's workspace
section driven by a human.

**No longer out of scope.** The earlier plan excluded changes to `WorkspaceFiles` containment,
quota and MIME behaviour on the grounds that the code was finished. ADR-0013 reopens it
deliberately: it is finished for one adapter, and this phase adds a second.

**Not in this phase, and not scheduled anywhere else — recorded here so it stops being invisible.**
An agent still cannot reach a workspace file. `read_file` and `write_file` were named "Phase 7
workspace tools" by the Phase 5 walkthrough and were never carried into these criteria when
ADR-0013 rewrote the phase around storage; `WorkspaceFiles` has two callers and both are the
control center. Attaching a workspace to an agent is likewise code-only — `agents.workspace_id`
exists and no UI writes it — and an agent may hold exactly one, that being a single nullable column
rather than a decision anybody took. So this phase delivers a workspace an operator can fill and
empty, and nothing an agent can use.

Out of scope: per-workspace S3 credentials (ADR-0013 keeps credentials in the host's
`filesystems.php`), presigned URLs, and any sync or replication between disks.

## Design decisions carried in from Phase 5

| Decision | Choice | Rationale |
|---|---|---|
| Local containment | `realpath()` then prefix check, on **every** operation | A check at open time and a use at write time is a TOCTOU window a symlink fits through. |
| Quota | Reserved before the write, reconciled after | Checking `used_bytes` then writing is the same race as Phase 4's `last_run_at` check, with the same fix. |
| MIME | Matched on the **detected** type, never the extension | An extension is an assertion by whoever named the file, and in a workspace that whoever is a model. |
| Empty MIME allowlist | Permits everything | A MIME list narrows what may enter an already-bounded workspace. Unlike a root list, which fails closed. |
| Browsing | The control center reads through the same containment as an agent | A page that could show a file an agent cannot read is a way to confirm what lives outside the root. |

## Design decisions taken in ADR-0013

| Decision | Choice | Rationale |
|---|---|---|
| Adapter seam | One contract, containment written **per adapter** | A shared base class would have to be correct for a filesystem and for a key-value store at once. The local case would lose resolve-then-check and no test would notice. |
| Object containment | Lexical normalisation — reject null bytes, `..` after normalisation, absolute and scheme-shaped keys — then prefix | There is no link to follow and no race to lose. This is the whole answer, not a first pass. |
| Unreachable disk | An ordinary tool error; the run continues | Same as an unhealthy provider in Phase 3. Failover would split the source of truth silently. |
| Credentials | The host's `filesystems.php` disks only | A second secret store is a second thing to leak. Costs runtime per-tenant buckets, knowingly. |
| MIME on object storage | Still the magic bytes | `Content-Type` is metadata the *uploader* chose. It is attacker-controlled exactly like an extension, and it looks more authoritative. |
| Context files | `HEAD` for the ETag, cached bytes when unchanged, bounded range read | They are read on every iteration of every run; the naive version is a full network read per file per iteration. |
| Downloads | Streamed through the app | A presigned URL is a bearer token for one object until it expires — forwardable, proxy-loggable, and invisible to the audit trail once issued. |

## Design decisions taken in this phase

| Decision | Choice | Rationale |
|---|---|---|
| Root selection | Named roots in `pandora.workspaces.roots`; the UI selects a **key** and the path is composed as `<base>/<tenant>/<slug>` | The deferral existed for this. A request's entire vocabulary for saying where a workspace lives is a key an operator declared, so there is no spelling of a root that is not one of them. An empty root list permits nothing — the opposite direction from the MIME allowlist, because this one decides where the boundary *is*. |
| Editing | Name, description, quota, MIME list and enabled are editable; **`disk` and `root_path` never are** | Re-pointing a root orphans every path already written and, on object storage, moves not one byte. A new workspace is the honest expression of that change. |
| Deletion | Removes the row and detaches agents; **files are left where they are**, and the page says which disk and prefix still hold them | On object storage a bulk delete is N calls with no transaction. A partial failure leaves a half-emptied prefix under a row claiming it is gone, which is worse than bytes nobody deleted. |
| Operator upload | Through `WorkspaceFiles::write`, never beside it; bounded by a declared `max_upload_bytes` | Quota reservation, detected-MIME matching and containment are properties of the write path. A second way in would arrive with its own slightly different version of each, and the one that gets written by accident skips the quota. The size bound is policy rather than `upload_max_filesize`, which is a deployment accident. |
| Uploaded filename | Reduced to a bare name, then handed to the adapter, which checks it again | The browser sends the name it was given, so it is chosen by whoever made the file. Reduced rather than rejected because `Q1 (final).pdf` is not an attack; checked twice because neither check is trusted to be the only one. |
| Directories | Derived from what the store reports; the UI **invents none** and offers no create-folder | On object storage a prefix exists exactly because objects do, so nothing can vanish when its last object is deleted. On a filesystem an empty directory is a real thing the store reports and an agent can also see, so it is shown — the rule is *never synthesised*, not *never empty*. |

## Acceptance criteria

### Containment — the property, on both adapters

| # | Criterion | Verified by |
|---|---|---|
| 1 ✅ | A local workspace confines reads and writes to its root — **traversal and symlink escape both fail** | `Workspaces/ContainmentTest` (local leg) |
| 2 ✅ | **The same containment suite passes against the object adapter**, and a failure on either adapter fails the build | `Workspaces/StorageContractTest` (both legs) |
| 3 ✅ | **An object key normalising to anything outside the root is refused** — `..` in every spelling, absolute paths, scheme-shaped keys, and a null byte anywhere | `Workspaces/KeyNormalisationTest` |
| 4 ✅ | **A tenant prefix cannot match a longer one** — `tenant-1/` never reaches `tenant-10/` | `Workspaces/TenantPrefixTest` |
| 5 ✅ | A path is re-resolved on **every** operation; there is no validated fast path on either adapter | `Workspaces/ContainmentTest` · `StorageContractTest` |

### The disk itself

| # | Criterion | Verified by |
|---|---|---|
| 6 ✅ | **An unreachable disk produces a tool error and the run continues** — nothing is written locally, and no read is served from anywhere else | `Workspaces/DiskUnavailableTest` |
| 7 ✅ | A workspace with no disk configured uses the local disk, and a host that configured no object storage works untouched | `Workspaces/DiskRoutingTest` |
| 8 ✅ | **Pandora stores no object-storage credential** — no endpoint, key or secret exists in the schema, the UI or any API response | `Workspaces/NoCredentialTest` |

### Quota, MIME and listing

| # | Criterion | Verified by |
|---|---|---|
| 9 ✅ | A write exceeding the quota is refused **before it lands**, and `used_bytes` stays accurate under concurrent writes, on both adapters | `Workspaces/QuotaTest` |
| 10 ✅ | Overwrite accounting is correct on object storage, where the previous size comes from a `HEAD` rather than a stat | `Workspaces/QuotaTest` |
| 11 ✅ | A disallowed MIME type is refused on the **detected** type on both adapters | `Workspaces/MimeTest` |
| 12 ✅ | **An object whose `Content-Type` says `image/png` while its bytes say otherwise is refused** — the metadata is never consulted | `Workspaces/MimeTest` |
| 13 ✅ | Listing a workspace paginates, and a workspace holding more objects than one page returns all of them | `Workspaces/ListingTest` |

### Context files

| # | Criterion | Verified by |
|---|---|---|
| 14 ✅ | A context file on object storage is read within the byte budget — an oversized object costs one truncated read, not the worker's memory | `Context/ObjectContextFileTest` |
| 15 ✅ | **An unchanged object is served from cache without re-reading its body**, and a changed ETag invalidates it | `Context/ObjectContextFileTest` |
| 16 ✅ | Context file roots on object storage remain an **allowlist**, and a path outside it is refused exactly as a local one is | `Context/ObjectContextFileTest` |

### The surface

| # | Criterion | Verified by |
|---|---|---|
| 17 ✅ | **A root outside the configured permissible set is refused, whatever the UI submits** | `UI/WorkspaceCreateTest` |
| 18 ✅ | **A tenant cannot see, read, write or export another tenant's workspace through the UI** | `UI/WorkspacesPageTest` · `UI/WorkspaceDownloadTest` |
| 19 ✅ | The feature flag withholds the surface from an operator holding every ability | `UI/WorkspacesPageTest` · `UI/WorkspaceDownloadTest` |
| 20 ✅ | **A download streams through the app, is authorized and is audited; no presigned URL is issued for any workspace** | `UI/WorkspaceDownloadTest` |
| 21 | **A human drives the workspace section of the walkthrough**, against a real object-storage bucket | `phase-7-walkthrough.md` |
| 22 ✅ | **An operator uploads a file through the same write path an agent uses** — quota, detected MIME and containment apply unchanged, and a filename trying to be a path cannot escape | `UI/WorkspaceUploadTest` |

## What the tests must run against

The object leg runs against **MinIO in CI**, the way the pgvector leg runs against `pgvector/pg17`.
This is not a preference. A mocked S3 client proves that the mock agrees with the code that drives
it, and every interesting behaviour here — key semantics, `HEAD` on a missing object, listing
pagination, what happens when the endpoint is unreachable — is behaviour of the *service*.

The local leg keeps running everywhere with no service at all, so a contributor without Docker can
still run the suite and get an honest partial answer.

## Notes

The walkthrough section for workspaces stays in `phase-5-walkthrough.md` until this phase runs. The
host application created a local workspace in code during the Phase 5 walkthrough
(`storage/app/pandora-workspace`); it is harmless with the flag off, and it becomes the local leg's
fixture when the flag turns on.

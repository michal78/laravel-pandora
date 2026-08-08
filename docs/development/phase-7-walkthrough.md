# Phase 7 — Host Walkthrough

> Status: **not driven.** Every box below is unticked, and criterion 21 in
> `phase-7-acceptance.md` stays open until a human has driven the workspace
> section against a real object-storage bucket.
>
> The storage half of the phase (criteria 1–16) is verified against MinIO by
> the suite, and the surface half (17–20) is covered by `UI/WorkspaceCreateTest`,
> `UI/WorkspacesPageTest` and `UI/WorkspaceDownloadTest`. What none of that can
> tell you is whether a person can create a workspace, attach it to an agent and
> get a file out of it without reading the source first.

Every walkthrough so far has found something the suite could not. Phase 5's
found a Memory page reading everyone's mail; Phase 6's found a delegated child
that could not see its own tool loop while nothing threw and nothing logged.
Expect this one to find something too, and write down what it was.

Run against `laravel-test`, or any host application, with a real object store.

## Before you start

The first three cost an hour each on the first run, and two of them are the same
landmines as Phase 6:

- [ ] **Restart the queue worker after every change to package source.**
      `queue:work` loaded its classes at boot. A symlinked path repository
      updates the files instantly and the worker keeps serving the old code, so
      a correct fix looks like it did nothing. `pkill -f queue:work`, start it
      again.
- [ ] **`vendor:publish --tag=pandora-config --force`, or add the new keys by
      hand.** A published `config/pandora.php` is a snapshot. One published
      before this phase has no `workspaces` block at all, which means no roots —
      and no roots means the create form correctly tells you nothing can be
      created here, which reads exactly like a bug.
      *Check `vendor/orchestra/testbench-core/laravel/config/` too if you run
      the package suite: a stale published config there shadows the package's
      own and the suite quietly stops exercising what you ship. It had
      reappeared once already.*
- [ ] **A real bucket, or a real MinIO.** `Storage::fake()` is the local driver
      wearing an object store's name — it has directories, it has symlinks, and
      `..` behaves like a filesystem. Driving this walkthrough against a fake
      would prove the local adapter twice.
- [ ] **A disk in the host's `filesystems.php`**, with its credentials there and
      nowhere else. Pandora stores no endpoint, key or secret; a workspace names
      a disk and that is all it knows.
- [ ] **At least one root in `pandora.workspaces.roots`.** This is the thing the
      phase exists for: an operator declares where workspaces may live, and the
      UI offers those and nothing else.

      ```php
      'workspaces' => [
          'roots' => [
              'local'  => ['label' => 'Local storage', 'disk' => 'local',
                           'base_prefix' => 'pandora-workspaces'],
              'bucket' => ['label' => 'Object storage', 'disk' => 'minio',
                           'base_prefix' => 'workspaces'],
          ],
          'default_quota_bytes' => 104857600,
      ],
      ```
- [ ] `PANDORA_FEATURE_WORKSPACES` is now **on by default**. Set it false to
      check the off state at the end.

## Creating a workspace, which is the part Phase 5 would not ship

- [ ] `/pandora/workspaces` lists workspaces and offers **New workspace**.
- [ ] The form has a **Root** select, a name, a description, a quota and a MIME
      list. **There is no path field.** Confirm this by looking, and then
      confirm it the other way: there is no public property on
      `WorkspacesIndex` that a forged Livewire request could put a path into.
- [ ] Create one on the **object storage** root. The page reports where it
      landed: `<disk>:<base>/<tenant>/<slug>`.
- [ ] Nothing was created in the bucket, and that is correct — object storage
      has no directories, so a prefix with no objects under it is already as
      real as it gets.
- [ ] Create one on the **local** root. This time the directory *is* created,
      because `realpath()` of a directory nobody made is `false` and every
      containment check starts there.
- [ ] Create a second workspace with the same name. Refused, rather than two
      workspaces sharing a prefix.
- [ ] Attach a workspace to an agent on the agent's page, and the agent's
      **Workspace** tab shows it rather than saying the feature is coming.

## Driving it with an agent

- [ ] Ask the agent to write a file. It appears in the bucket under the
      workspace's prefix and nowhere else, and the page lists it.
- [ ] **Upload a file yourself**, from the page, into the workspace you are
      browsing. It lands where the breadcrumb says, and the agent can read it
      back on its next run — this is how a source document gets to an agent
      without anybody touching the bucket.
- [ ] The upload obeys the same rules an agent's write does, because it is the
      same write: with a MIME allowlist set, a file whose bytes disagree with
      its extension is refused; over quota is refused before it lands.
- [ ] **Usage.** `used_bytes` moves with the write, including with an upload.
      Put an object in the prefix out of band (`mc cp`, or the MinIO console)
      and it does *not* — press **Recount** and it does. The counter is
      authoritative for enforcement and the store is authoritative for truth;
      this is the button that reconciles them.
- [ ] Set a small quota and have the agent write past it. Refused **before the
      bytes land**, and nothing appears in the bucket.
- [ ] Set an allowed MIME list of `text/plain`, then have the agent write a PNG.
      Refused on the detected type. Now upload an object by hand whose
      `Content-Type` says `text/plain` and whose bytes are a PNG: still refused,
      because the metadata is never consulted.
- [ ] Ask the agent for `../../etc/passwd`, and for `s3://another-bucket/key`.
      Both refused, and the run continues rather than dying.
- [ ] **Break the disk.** Stop MinIO, or point the disk at a wrong endpoint, and
      have the agent read a file. It is an ordinary tool error, the run
      continues, and nothing was written to local storage instead. Start it
      again.

## Browsing and downloading

- [ ] Descending into a folder works, and **Up** goes back without ever putting
      `..` into a path.
- [ ] The directories shown are ones the store reported. Nothing invents an
      empty folder on the object store, and there is no create-folder button.
- [ ] **Download** a file. It streams through the application: the URL is
      `/pandora/workspaces/<slug>/download?path=…` on your own host, and there
      is no signed bucket URL anywhere in the page source or the redirect chain.
- [ ] The audit log has a `workspace.file_downloaded` entry naming the path and
      the byte count. This is the entire reason a presigned URL was refused.
- [ ] Download a file of a few hundred megabytes. It arrives, and the worker's
      memory does not go with it.
- [ ] On a local workspace, `ln -s /etc/passwd <root>/innocent.txt`. It does not
      appear in the listing, and downloading it by name 404s.

## Editing, and removing

- [ ] **Edit** a workspace. Name, description, quota and MIME list are fields.
      The disk and root are shown and are not editable — files already written
      are named by that path, and on object storage moving it is a copy of every
      object and a delete of every original with no transaction around the pair.
- [ ] **Remove** a workspace that an agent is attached to. The agent is
      detached, and its Workspace tab says plainly that an agent without one can
      reach no files at all.
- [ ] **The files are still in the bucket.** The page said so when it removed
      the row, and the audit entry records `files_removed: false`. Deleting them
      is N calls with no transaction; a partial failure leaves a half-emptied
      prefix under a row claiming it is gone.

## Tenancy, if the host has it

- [ ] Two tenants create a workspace with the same name under the same root.
      They get different prefixes and neither can list the other's.
- [ ] As tenant B, hit tenant A's workspace by slug: the download URL, and a
      forged Livewire `delete`/`recount`/`save`. All 404, and nothing changes.
      A 403 would confirm the slug exists.

## The off state

- [ ] Set `PANDORA_FEATURE_WORKSPACES=false`. `/pandora/workspaces` says the
      feature is not here yet and names no workspace, **for an operator holding
      every ability** — a flag is not a permission.
- [ ] With the flag off, the download URL 404s, and so does every forged Livewire
      action. The page is where a flag gets honoured, and a forged call is
      exactly the request that never renders one.
- [ ] Turn it back on.

## What this found

*Fill this in. If it found nothing, say that — a walkthrough that found nothing
is worth recording, and a walkthrough with an empty findings section is
indistinguishable from one nobody ran.*

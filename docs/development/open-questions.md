# Open Questions

Questions requiring a decision from the repository owner, or deferred until evidence exists.
Resolved items move to the bottom with their resolution.

---

## Q1 — Package location inside `vendor/` ✅ **resolved**

**Raised:** 2026-08-05 · **Resolved:** 2026-08-05

The package was developed at `/home/michal/development/laravel-test/vendor/michal78/laravel-pandora`,
where `composer install` could delete it without warning.

**Resolution:** moved to `/home/michal/development/laravel-pandora` and consumed through a `path`
repository with `"options": {"symlink": true}`, the pattern already used for `michal78/wisp`. The
host's `compose.yaml` mounts the same absolute path into the Sail container so Composer can resolve
it from inside. The temporary PSR-4 shim in the host's `autoload` block is gone: Pandora is now an
ordinary Composer dependency, installed and auto-discovered like any other.

---

## Q2 — Open-source license ⚠ **owner decision required**

**Raised:** 2026-08-05 · **ADR:** 0012

`LICENSE.md` carries MIT marked explicitly as a placeholder pending confirmation.

**Recommendation: MIT.** It matches Laravel, Livewire, Reverb, Hermes Agent and Hermes Studio. For a
framework intended for broad adoption, matching the ecosystem default removes a procurement question
that would otherwise deter commercial adopters.

**Alternative: Apache-2.0.** Adds an explicit patent grant, which some enterprises prefer. Unusual in
the Laravel ecosystem.

Must be settled before any public release.

---

## Q3 — Final Composer package name and namespace

**Raised:** 2026-08-05 · **ADR:** 0012

Currently `michal78/laravel-pandora` with namespace `Pandora\Pandora\`. Changing both is mechanical
and documented. Needed before publishing to Packagist.

---

## Q4 — Should the framework system prompt be host-overridable?

**Raised:** 2026-08-05 · **Deferred to:** Phase 2

Pandora injects a framework-owned system instruction carrying safety boundaries (untrusted-content
delimiting, tool-call discipline). Making it overridable lets hosts break their own safety properties;
making it fixed will frustrate advanced users.

**Leaning:** fixed by default, with an explicit, audited, config-only override that logs a warning at
boot. Decide when the tool loop exists and the actual prompt content is known.

---

## Q5 — Skill format compatibility with the Agent Skills convention

**Raised:** 2026-08-05 · **Deferred to:** Phase 5

Hermes Agent uses the `agentskills.io` standard. Compatibility would let hosts reuse an existing
corpus. The convention may evolve, and adopting it wholesale creates a dependency on a third party's
decisions — which ADR-0008 explicitly avoids.

**Leaning:** compatible-by-convention. Read the layout where it is unambiguous, keep our own manifest
authoritative, never depend on the registry.

---

## Q6 — Reverb as a hard requirement for the UI?

**Raised:** 2026-08-05 · **Resolved:** 2026-08-05 → **No.**

Resolved during architecture: because the database is authoritative (ADR-0003), a polling fallback is
correct rather than merely degraded. `realtime.enabled = false` is a supported configuration and is
covered by acceptance criterion 22. This materially lowers the barrier to trying the package.

---

## Q7 — Vector database in the default install?

**Raised:** 2026-08-05 · **Resolved:** 2026-08-05 → **No.**

Default memory retrieval is database-backed and lexical. `EmbeddingProvider` and `VectorStore` are
contracts; pgvector, Qdrant and others are extensions. Recorded in the parity matrix.

---

## Q8 — Which messaging channel ships as the reference extension?

**Raised:** 2026-08-05 · **Deferred to:** Phase 7

**Leaning: Slack.** Best-documented API, strongest fit with business applications (Pandora's target),
native interactive components that map cleanly onto approval cards, and an existing Laravel
notification channel to build on.

---

## Q9 — Host-application verification ✅ **resolved, and it found three defects**

**Raised:** 2026-08-05 · **Resolved:** 2026-08-05

Docker Desktop was running after all. Pandora was installed into `laravel-test` — Laravel 13.19,
PHP 8.5.8, MySQL 8.4, Redis queue and cache, `BROADCAST_CONNECTION=log` (no Reverb) — and the
walkthrough was performed against it.

Verified live: `pandora:install`, all nine migrations on MySQL 8.4, `pandora:status`,
`pandora:agent:list`, a synchronous console run, a queued run drained by a real
`php artisan queue:work` worker, and every control center page rendering for an authenticated user
while redirecting a guest.

The exercise justified itself immediately. Three defects that the package suite structurally could
not see were found and fixed (commit `09d96ac`):

1. `run()` did not wait, because only the first job was dispatched synchronously and the suite's
   `sync` queue connection hid the leak.
2. The control center layout read its stylesheet with `__DIR__` from a compiled Blade view — a 500
   on every page of a real installation, invisible to `Livewire::test()`, which renders no layout.
3. `symfony/uid` was pinned to `^7.0`, so the package would not install alongside Laravel 13.

**Still not verified:** a live Reverb server (the host broadcasts to `log`, so the UI is running on
its polling fallback — which is precisely the degraded mode acceptance criterion 22 requires to
remain correct), and a real paid provider endpoint. The DB matrix now covers SQLite and MySQL 8.4;
PostgreSQL and MariaDB remain CI-only.

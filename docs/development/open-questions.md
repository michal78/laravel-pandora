# Open Questions

Questions requiring a decision from the repository owner, or deferred until evidence exists.
Resolved items move to the bottom with their resolution.

---

## Q1 — Package location inside `vendor/` ⚠ **needs a decision**

**Raised:** 2026-08-05 · **Blocks:** nothing yet; risks data loss at any time

The package is being developed at `/home/michal/development/laravel-test/vendor/michal78/laravel-pandora`
as instructed. `composer install`, `composer update` or `composer dump-autoload --no-dev` in the host
application can delete the entire directory without warning.

Mitigation in place: git is initialised on `master`, so work is recoverable from `.git` — unless the
whole directory is removed, which composer will do.

**Recommendation:** move the source to `/home/michal/development/laravel-pandora` and consume it via a
`path` repository with `"options": {"symlink": true}` — exactly the pattern already used for
`michal78/wisp`. This is a two-minute change, does not alter any code, and removes the risk entirely.

**Status:** proceeding at the specified location. Awaiting owner preference.

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

## Q9 — Host-application verification cannot run in this environment ⚠

**Raised:** 2026-08-05 · **Blocks:** Phase 1 acceptance criterion 14 only

`laravel-test` requires PHP ^8.4 and runs via Laravel Sail. This WSL distro has no Docker
integration (`docker` is not on PATH) and local PHP is 8.3.6, so the host application cannot boot
here at all — with or without Pandora.

What this does **not** affect: the package suite runs a real Laravel 13 application under Orchestra
Testbench on PHP 8.3 and covers all 22 acceptance criteria in automated form, including the
installer, the Livewire chat page, streaming, mid-stream reload reconstruction and cancellation.

What remains genuinely unverified: a live `php artisan queue:work` worker, a live Reverb server,
and a real provider endpoint. These are exactly the parts an integration environment exists to
prove, and they should not be assumed working.

**To close:** enable Docker Desktop WSL integration, then `./vendor/bin/sail up -d`,
`sail artisan migrate`, `sail artisan queue:work`, `sail artisan reverb:start`, and walk the
11-step manual checklist in `phase-1-acceptance.md`.

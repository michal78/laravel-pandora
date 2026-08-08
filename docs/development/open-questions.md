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

## Q2 — Open-source license ✅ **settled**

**Raised:** 2026-08-05 · **Settled:** 2026-08-07 · **ADR:** 0012

**MIT**, confirmed by the owner. `LICENSE.md` now carries the full text with the copyright holder
named and no placeholder notice above it. Apache-2.0 was the alternative — an explicit patent grant,
at the cost of being unusual in this ecosystem — and was not chosen.

---

## Q3 — Final Composer package name and namespace ✅ **settled**

**Raised:** 2026-08-05 · **Settled:** 2026-08-07 · **ADR:** 0012

**`michal78/laravel-pandora`**, namespace **`Pandora\`**, PSR-4 rooted at `src/`.

The namespace was `Pandora\Pandora\` while the owner was unknown. The doubled segment bought nothing
and read badly at every import, so it was dropped before the first release — the same shape Livewire
uses, where `Livewire\Livewire` is the class and `Livewire\` is everything else. Done now because
after a Packagist release it is a breaking change for every host application.

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

## Q8 — Which messaging channel ships as the reference extension? ✅ **settled**

**Raised:** 2026-08-05 · **Settled:** 2026-08-08, at the start of Phase 8

**Slack**, for the reasons the leaning already gave: best-documented API, strongest fit with business
applications, interactive components that map cleanly onto approval cards, and an existing Laravel
notification channel to build on.

The part that was not decided until now is *where it lives*: its own Composer package in its own
repository (`/home/michal/development/laravel-pandora-slack`), consumed by `laravel-test` through a
path repository, the way `wisp` already is. This is the only layout under which Phase 8's second
acceptance criterion can actually be proved. "An extension registers providers, tools and channels
through the documented contracts alone, with no core changes" is a claim about a boundary, and a
boundary you can reach across in the same commit is not one — a missing seam gets filled in `src/`
in the same afternoon it is discovered, the extension keeps working, and nobody finds out until a
second author tries the same thing without commit rights to core.

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

### Phase 4 walkthrough — 2026-08-06

Repeated against the same host for the automation surfaces, because Phase 4 is the first phase whose
correctness depends on things no test can reach: a real cron, a route outside the control center's
middleware, and a real `APP_KEY` encrypting a secret.

Verified live in `laravel-test`:

- `pandora:install` published the five new migrations into an installation that already had fifteen —
  the Phase 3 installer fix working on a real upgrade rather than in a test.
- All five ran on MySQL 8.4, including the `ALTER TABLE` adding `autonomy_level` and `automation_id`
  to a populated `pandora_runs`.
- **The scheduler entry registered itself** — `schedule:list` shows `* * * * * pandora:automation:tick`
  with no edit to the host's own console routes. This is the claim the design rests on and it had
  never been observed outside a unit test.
- `pandora:status` reports the Automation section; `pandora:automation:list` renders next-run times in
  each automation's own timezone (a UTC `01:17` showing as `03:17` Europe/Copenhagen — the offset
  correct against a real clock, not a frozen one).
- `pandora:automation:run --sync` produced a genuine run against a real OpenAI model, stamped
  `trigger=schedule`, `autonomy=observe_only`, with the occurrence recorded `dispatched`.
- The webhook endpoint over real HTTP: **202** with a run id, **409** on a byte-identical replay,
  **401** on a wrong secret — criteria 21, 22 and 23 outside the test harness.

No defects in that half, which looked like a weaker result than Phase 1's — until the browser half
ran and found three.

### Phase 4 browser walkthrough — 2026-08-06 ✅ **complete, and it found three defects**

All twenty checks in `docs/development/phase-4-walkthrough.md` pass. A real cron fired a real
automation; an unparseable cron expression was refused rather than stored; an autonomy level that
would be clamped was not offered; a rotated webhook secret was genuinely unreadable after the
response that minted it; the autonomy budget disabled an automation by itself; and removing the
ability made every control disappear.

Three defects, none of them reachable from the package suite:

1. **A fatal `TypeError` on the Automations page** of any host using
   `Date::use(CarbonImmutable::class)` — a suggestion in Laravel's own default `AppServiceProvider`.
   Phase 4 typed its dates `Illuminate\Support\Carbon`, which a model cast no longer returns once a
   host opts in. Everything Pandora constructed itself satisfied the hint; everything it read back
   from a model did not.
2. **A date reported as changed on every save**, because two `Carbon` objects were compared with
   `!==` — identity, not value. Every schedule edit wrote a spurious audit entry, defeating the one
   question the per-tab diff exists to answer.
3. **A replayed webhook left no evidence anywhere.** Replay protection is a unique insert, so the
   duplicate could not record itself, and it threw before reaching the audit path every other
   rejection uses. The only rejection in the system with nothing to show for it.

The first two were found while preparing the walkthrough; the third by following it. `TypeError`
aside, the pattern is the same one the database matrix showed: the suite is good at what it was told
to check and blind to configuration it was never run under.

**Still not verified:** a live Reverb server (the host broadcasts to `log`, so the UI is running on
its polling fallback — which is precisely the degraded mode acceptance criterion 22 requires to
remain correct), and an automation left running long enough to exercise the misfire policy against a
genuine worker outage. Both are Phase 8 hardening items.

**The DB matrix is now real** (2026-08-06). It previously ran SQLite in all three "engine" jobs
because `TestCase` hardcoded the connection; it now honours `DB_CONNECTION` and the full suite passes
on MySQL 8.4, MariaDB 11 and PostgreSQL 17. Making it real immediately found three defects — see the
Phase 4 entry in `progress.md`.

## `pandora.agents.discovery` is configuration wired to nothing (2026-08-08)

Found while driving the Phase 7 workspace tools in `laravel-test`. The published config carries

```php
'agents' => [
    'discovery' => [
        'enabled' => env('PANDORA_AGENT_DISCOVERY', false),
        'path' => app_path('Agents'),
    ],
],
```

and nothing reads it. `PandoraServiceProvider::registerConfiguredAgents()` reads
`pandora.agents.definitions` and returns early when that list is empty; there is no equivalent of
`ToolDiscovery::in($path)` for agents. Tools have discovery, agents have the config block for it.

The failure is quiet in the way that costs an afternoon. An operator drops a class in `app/Agents`,
sets `PANDORA_AGENT_DISCOVERY=true`, and gets nothing — no agent, no error, no log line. If a row
for that slug already exists from an earlier registration, it is worse: the agent is there, edits to
the definition class simply never take effect, and `syncAll(true)` reports success having iterated
an empty list. That is exactly what happened here, and it read as "my tool grant did not save".

Two honest fixes, and this is a decision rather than an obvious bug fix:

- **Implement it**, mirroring `ToolDiscovery`. Discovering agents is a bigger act than discovering
  tools, though: a tool still has to be granted before anything can call it, while a discovered
  agent is a thing that can be run.
- **Delete the config block.** An explicit `definitions` list is arguably the right and only way in,
  in which case the block is a promise the code never made.

Not Phase 7's, and not scheduled. Recorded here rather than fixed, because either answer changes
what an operator's `app/Agents` directory means.

---

## Q10 — Two undriven walkthroughs carried into Phase 8 ⏸ **deferred, deliberately**

**Raised:** 2026-08-08 · **Deferred to:** after Phase 8, and before Phase 9

Phase 6's MCP walkthrough and Phase 7's workspace walkthrough are both unticked, and Phase 8 starts
anyway. This is a decision, so it is written down as one.

What is being given up is not small, and the record says so plainly. Every walkthrough driven so far
has found something the suite could not: Phase 1 found three, Phase 4 found three, Phase 5 found four
— including a Memory page that served every user's user-scoped memories to any authenticated
account, on an installation whose *retrieval* scoping was proven by twenty-eight passing criteria.
The consistent shape is that the guard is correct and the surface around it is not, which is exactly
the class of defect a test suite is worst at seeing and a person clicking is best at.

Two specific things stay unproved until it happens. For Phase 6, a real MCP server whose tool
description changes after approval — `FakeMcpServer` can produce the changed hash, but the thing
being judged is whether the resulting refusal tells an operator what to do, and no assertion answers
that. For Phase 7, a real bucket driven by hand rather than by MinIO in CI.

The cost accepted in exchange: with three phases open at once, a defect found in September is harder
to attribute to the change that caused it. Mitigation is only that both are paid before Phase 9,
which is the phase that claims T1–T15 are covered — and that claim cannot be made over an undriven
surface.

# Contributing

Thanks for considering a contribution.

## Before you start

Read `docs/architecture/overview.md` and the relevant ADRs under `docs/adr/`. Most design questions
are already answered there, including the ones where we chose the harder option on purpose.

If you want to change something an ADR decided, that is welcome — open an issue proposing a
superseding ADR rather than a PR that quietly diverges.

## Setup

```bash
composer install
vendor/bin/pest
vendor/bin/phpstan analyse
vendor/bin/pint --test
```

## Standards

- PHP 8.3+, `declare(strict_types=1)` in every file.
- Immutable `readonly` DTOs; backed enums for bounded states.
- Interfaces at external boundaries. Dependency injection in domain services; the facade is an
  ergonomic entry point only and contains no logic.
- No `AgentService`-style god objects. Respect module boundaries — an architecture test enforces them.
- No placeholder methods that silently succeed.
- No broad `catch (Throwable)` without classification, logging and explicit state handling.
- No vendor SDK type outside its own adapter directory.

## Tests

Every change needs a test. Security-relevant changes need a test in `tests/Security/`.

Tests must never call a paid external API. Use `FakeProvider` / `FakeStreamingProvider`, or recorded
fixtures.

## Branches

**`development` is the default branch and where every pull request goes.** It is published to
Packagist as `dev-development`, so anything merged there is immediately installable by anyone who
asks for it — which is the point, and the reason it still has to be green.

**`master` is stable and releases are cut from it.** Merging to `master` publishes: the release
workflow reads the first `## vX.Y.Z` heading in `CHANGELOG.md`, and if no tag of that name exists, it
tags the commit and publishes a GitHub Release with that section as the body. It no-ops when the
heading has not moved, so a documentation fix on `master` releases nothing.

Cutting a release is therefore one edit — the CHANGELOG heading — and a merge. There is no other
button, and nothing else may create a tag.

## Commits and PRs

Focused commits with clear messages, written as prose. **Conventional Commits are deliberately not
used**: the release version comes from the CHANGELOG rather than from commit subjects, so nothing
here needs to parse them, and a changelog written for a reader beats one assembled from prefixes.

PRs should state which phase and which acceptance criteria they affect, and must not mark a phase
complete while any test, analysis check or documented acceptance criterion fails.

A new test that cannot fail proves nothing. Where a test asserts a mitigation, say in the PR how you
verified it fails with that mitigation removed — that is the standard Phase 9 sets and it applies to
new work now.

## Security

Do not open a public issue for a vulnerability. See `SECURITY.md`.

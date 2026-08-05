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

## Commits and PRs

Focused commits with clear messages. PRs should state which phase and which acceptance criteria they
affect, and must not mark a phase complete while any test, analysis check or documented acceptance
criterion fails.

## Security

Do not open a public issue for a vulnerability. See `SECURITY.md`.

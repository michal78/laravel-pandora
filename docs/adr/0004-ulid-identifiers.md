# ADR-0004: ULID primary keys stored as char(26)

- **Status:** accepted
- **Date:** 2026-08-05

## Context
Keys must be portable across PostgreSQL, MySQL, MariaDB and SQLite; safe to expose in URLs and
broadcast channel names; and index-friendly for time-ordered, high-insert tables like `run_steps`.

## Decision
ULID, stored as `char(26)`, string primary keys, via Laravel's `HasUlids`.

## Alternatives considered
- **Auto-increment integers.** Best index locality, but enumerable in URLs and channel names, and
  they leak volume. Rejected.
- **UUIDv4.** Random, so poor index locality on write-heavy tables. Rejected.
- **UUIDv7.** Equally time-sortable and a fine choice, but no single native column type across all
  four engines; storing it portably means `char(36)` — more bytes for the same property. Rejected on
  storage/portability grounds only.

## Consequences
- 26 bytes per key; time-sortable; case-insensitive; URL-safe.
- Host tables are referenced by `string` + morph, never assuming the host's key type.
- Changing this later is a breaking migration, so it is settled now.

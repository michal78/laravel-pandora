# ADR-0005: Tenancy is an abstraction, not a bundled package

- **Status:** accepted
- **Date:** 2026-08-05

## Context
Laravel applications use several mutually incompatible tenancy approaches (single DB with a column,
multi-database, multi-schema, or none). The reference agent products avoid the problem entirely by
declaring multi-tenancy out of scope — which is exactly the constraint Pandora exists to remove.

## Decision
Define `TenantResolver` and `ActorResolver` contracts. Every data-bearing table carries a nullable
`tenant_id` string with a global scope. Bundle no tenancy package. Default to a null tenant so
single-tenant applications need zero configuration and every scope is a no-op.

## Alternatives considered
- Depend on a specific tenancy package. Rejected: forces a large dependency and excludes everyone
  using a different one.
- No tenancy at all. Rejected: it is the core differentiator.
- Multi-database tenancy only. Rejected: excludes the most common single-database pattern.

## Consequences
- Works in single-tenant apps with no setup, and in any tenancy scheme with two small bindings.
- Queued jobs must carry tenant context explicitly — the request it was resolved from is long gone.
- Isolation must be proven by tests, not assumed. `tests/Security/` is not optional.

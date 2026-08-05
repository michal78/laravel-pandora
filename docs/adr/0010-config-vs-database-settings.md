# ADR-0010: Deployment configuration stays in config; only runtime settings go in the database

- **Status:** accepted
- **Date:** 2026-08-05

## Context
It is tempting to move every option into the database so it is editable in the control center. That
trades away version control, code review, per-environment values and deploy-time validation.

## Decision
Draw the line explicitly.

**`config/pandora.php` (version-controlled):** routes, middleware, guards, tenancy bindings, queue
names, table prefix, provider adapters and base URLs, enabled tools, workspace roots and disks,
security policy, feature toggles, retention defaults.

**Database `pandora_settings` (runtime):** agent enable/disable, default agent, approval expiry
windows, UI preferences, notification preferences, budget values, per-tenant overrides.

Rule of thumb: if changing it should require code review, it is config. If an operator should change
it at 2am without a deploy, it is a setting.

## Alternatives considered
- Everything in the database. Rejected: unreviewable, undiffable, no per-environment values.
- Everything in config. Rejected: an operator cannot disable a misbehaving agent without a deploy.

## Consequences
- The Settings UI is smaller than users may initially expect, and the docs explain why.
- `pandora:doctor` validates config and surfaces diagnostics at deploy time rather than at 2am.
- Config changes to security-relevant values are visible in version control, where they belong.

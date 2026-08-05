# ADR-0007: Tools are PHP classes authorized against the actor, not the agent

- **Status:** accepted
- **Date:** 2026-08-05

## Context
A tool is where an agent touches the application, and therefore where every safety property is won or
lost. The framework must make the safe thing the easy thing for a Laravel developer.

## Decision
A tool is a class extending `Tool` with a typed input DTO, Laravel validation rules (from which the
JSON schema is generated), a declared risk level, and an `authorize()` method checked against the
**acting user's** Laravel gates and policies — not the agent's. A tool call must clear five layers:
registry → agent allow/deny → tenant restriction → `ToolPolicy` → `Tool::authorize()`.

## Alternatives considered
- **Closure-based tools.** Fast to write, but not discoverable, not testable in isolation, and give
  nowhere natural to declare risk or authorization. Rejected.
- **Attribute-only definition.** Elegant for metadata but cannot express real authorization logic.
  Adopted *additionally* for metadata, not as the whole mechanism.
- **Schema hand-written as an array.** Rejected as the primary path: it duplicates validation rules
  and the two drift. Generation from rules keeps one source of truth. A raw schema override remains
  available for exotic cases.

## Consequences
- An agent can never do something the acting user could not do themselves. This is the single most
  important safety property in the system.
- Host developers write ordinary Laravel authorization and get agent safety as a side effect.
- Schema generation must cover the rule set it claims to; unsupported rules fail loudly at
  registration rather than producing a wrong schema.

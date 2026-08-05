# ADR-0012: Placeholder namespace; license decision deferred to the repository owner

- **Status:** accepted (namespace) / **open** (license)
- **Date:** 2026-08-05

## Context
The repository owner and final package identity are not yet known. The project brief instructs that a
license must not be chosen silently.

## Decision
**Namespace.** Use `Pandora\Pandora\` (PSR-4 root `src/`) and the Composer name
`michal78/laravel-pandora`. Both are changed in exactly two places — the `autoload.psr-4` key and the
`extra.laravel` entries in `composer.json` — plus a project-wide namespace replace. `docs/guides/`
documents the procedure, and no namespace string is hard-coded anywhere outside `composer.json` and
PHP namespace declarations (no `Pandora\\Pandora` inside config, views, or migrations).

**License.** `LICENSE.md` currently carries MIT as a *placeholder*, clearly marked as pending owner
confirmation, and the decision is recorded as open in `docs/development/open-questions.md`.

**Recommendation: MIT.** It is what Laravel itself, Livewire, Reverb, and both reference products
(Hermes Studio, Hermes Agent) use. For a framework package intended for broad adoption, matching the
ecosystem's default removes a question that would otherwise deter commercial adopters. Apache-2.0
would add an explicit patent grant — the main reason to prefer it — at the cost of being unusual in
this ecosystem.

## Alternatives considered
- A vendor-specific placeholder namespace. Rejected: churn once the owner is known, for no benefit.
- Picking a license silently. Rejected by the brief, and it is genuinely the owner's decision.

## Consequences
- Renaming is a mechanical, documented, single-commit operation.
- Until the owner confirms, the license header states that it is provisional so no one relies on it.

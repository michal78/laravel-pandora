# ADR-0012: Namespace `Pandora\`, and MIT

- **Status:** accepted
- **Date:** 2026-08-05 · **Settled:** 2026-08-07

## Context
The repository owner and final package identity were not known when the package was skeletoned, and
the brief was explicit that a license must not be chosen silently. Both were carried as placeholders
until the owner decided.

## Decision
**Namespace.** `Pandora\`, PSR-4 rooted at `src/`, published as `michal78/laravel-pandora`.

It was `Pandora\Pandora\` for as long as the vendor was unknown, on the theory that the first
segment would become the owner's. It did not, and the doubled segment bought nothing while reading
badly at every import. Dropping it leaves the shape Livewire uses: `Pandora\Pandora` is the class
the facade points at, `Pandora\` is everything else.

The rename happened before the first Packagist release on purpose. Afterwards it is a breaking
change for every host application, for no gain that could not have been had for free today.

**License.** **MIT**, confirmed by the owner. It is what Laravel, Livewire, Reverb and both
reference products use. For a framework package intended for broad adoption, matching the
ecosystem's default removes a procurement question that would otherwise deter commercial adopters.
Apache-2.0 would have added an explicit patent grant — the main reason to prefer it — at the cost of
being unusual in this ecosystem.

## Alternatives considered
- **Keeping `Pandora\Pandora\`.** Rejected: it only ever existed to reserve a vendor segment that
  was never claimed, and the cost of removing it rises to "breaking change" the day we publish.
- **A single-segment root shared with the Composer vendor (`Michal\Pandora\`).** Rejected: the
  brand is the package, not the author, and the facade, the config key, the route prefix and the
  CSS custom properties are all already `pandora`.
- **Picking a license silently.** Rejected by the brief, and it was genuinely the owner's decision.

## Consequences
- Host applications written against the old namespace must replace `Pandora\Pandora\` with
  `Pandora\` and run `composer dump-autoload`. Nothing else changes: no namespace string is
  hard-coded in config, views or migrations.
- A *published* `config/pandora.php` in a host application still names the old namespace in
  `tenancy.resolver` and will fail to resolve until it is republished or edited. This bit us in the
  package's own test run, where a stale published copy under `vendor/orchestra/testbench-core`
  failed 803 tests that the source had already fixed.
- `LICENSE.md` is now a plain MIT license and can be relied on.

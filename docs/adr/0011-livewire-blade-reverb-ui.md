# ADR-0011: Blade + Livewire + Reverb; no SPA

- **Status:** accepted
- **Date:** 2026-08-05

## Context
The control center is a genuinely realtime, stateful interface — streaming text, live tool cards,
approval prompts. Comparable products ship React SPAs. The project brief requires no SPA.

## Decision
Blade for layout and views, Livewire for interactive components, Reverb for realtime, Alpine sparingly
for local non-authoritative concerns (scroll pinning, composer state, collapse toggles). Server-side
Markdown rendering with sanitisation. No React, Vue, Inertia or separate JS build for package UI.

## Alternatives considered
- React/Inertia SPA. Rejected by the brief, and it would force a JS toolchain on every host app.
- Blade + htmx. Rejected: Livewire is the idiomatic Laravel answer and integrates with Echo directly.
- Server-rendered with polling only. Rejected as the *default*, but retained as the fallback when
  Reverb is disabled — which makes the package installable without broadcasting infrastructure.

## Consequences
- No JS build step for host applications; assets publish as compiled CSS.
- Streaming components must be carefully scoped so a delta re-renders one small subtree.
- Alpine state is never a source of truth, which is stated as a rule so it stays true.

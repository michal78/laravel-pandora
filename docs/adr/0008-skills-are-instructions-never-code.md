# ADR-0008: Skills are instructions and are never executed

- **Status:** accepted
- **Date:** 2026-08-05

## Context
Skill ecosystems in comparable products distribute thousands of community skills, some of which
contain setup or installation instructions. An imported skill is untrusted content authored by a
stranger.

## Decision
A skill is Markdown instructions plus a YAML manifest. Pandora never executes anything from a skill,
never runs embedded installation instructions, and never grants a skill capabilities beyond the tools
its assigned agent already has. Import validates the manifest and surfaces warnings. No remote
marketplace installation in v1.

## Alternatives considered
- Executable skills (scripts/hooks). Rejected: that is remote code execution driven by a web UI.
- Auto-granting the tools a skill declares it requires. Rejected: privilege escalation by document.
- Remote registry install in v1. Rejected for v1; inspection-only extension discovery ships instead.

## Consequences
- Skills are safe to import from anywhere. That is the whole point.
- Some capability that competitors deliver via executable skills must be delivered as a real tool
  instead — which is the correct place for it anyway.
- ~~Skill instructions still reach the prompt, so they remain a prompt-injection vector and are
  treated as untrusted content.~~

  **Amended 2026-08-17, Phase 9 / T9.** This was written as a description of what would ship and was
  never true of what did. Nothing in `src/` reads `Skill::$instructions`: a skill can be imported,
  attached to an agent and listed on its detail page, and its text reaches no prompt because nothing
  composes it into one. The decision above holds and is in fact enforced more strongly than it
  claims — but the *feature* is inert, not merely unprivileged, and that is a gap rather than a
  design. `Skills/UntrustedSkillTest` asserts the current state, so wiring skills into the context
  pipeline turns it red and the untrusted-content handling this consequence describes has to be
  built at the same time rather than assumed to already exist.

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
- Skill instructions still reach the prompt, so they remain a prompt-injection vector and are treated
  as untrusted content.

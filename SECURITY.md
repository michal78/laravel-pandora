# Security Policy

## Reporting a vulnerability

Please do **not** open a public issue.

Report privately via GitHub's private vulnerability reporting on this repository, or to the
maintainer contact listed in `composer.json`. Include affected version, reproduction steps, impact,
and any suggested mitigation. We aim to acknowledge within 72 hours.

## Scope

Pandora executes model-directed actions inside a host application, so we treat the following as
security issues:

- Cross-tenant or cross-session data leakage
- Authorization bypass on any page, action, tool, run or broadcast channel
- Approval bypass, or a run resuming without a valid decision
- Secret exposure in logs, run traces, broadcasts, prompts or API responses
- Workspace path traversal or symlink escape
- SSRF via HTTP tools, webhooks or fetched content
- Webhook signature bypass or replay
- Privilege escalation via delegation, skills, or MCP tools
- Budget or loop-limit bypass leading to unbounded cost

## Known limitations — stated deliberately

**Prompt injection is not solved, and we do not claim it is.** Pandora applies layered controls (least
authority, approval gates, tool allowlists, argument validation, egress control, budgets, audit) that
bound what an injected instruction can reach. A report demonstrating that a model *can be persuaded*
is expected behaviour. A report demonstrating that a persuaded model **bypasses a control listed
above** is a vulnerability, and we want to hear about it.

**Policy restriction is not sandboxing.** Without a container or OS boundary, a process-execution tool
is only as contained as the PHP process. This is documented wherever sandbox adapters are discussed.

**Estimated cost is not billed cost.**

**Audit logs are append-only at the application layer.** They are not tamper-proof against an actor
with direct database write access.

## Supported versions

Pre-1.0. Only the latest release receives security fixes. This will be revised at 1.0.

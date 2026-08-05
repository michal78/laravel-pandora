# Pandora — Product Vision

> Status: Phase 0 (discovery). Nothing in this document describes shipped functionality.

## One sentence

**Pandora is an agentic framework for Laravel applications: it lets an application expose its own
actions to LLM-driven agents under explicit, auditable policy, and ships a control center for
operating them.**

## The problem

Two distinct kinds of product exist today and neither fits a Laravel application.

**1. Personal agent daemons** (OpenClaw, Hermes Agent and similar self-hosted assistants). These are
excellent at what they do: one operator, one machine, a workspace of Markdown files, a gateway that
bridges messaging platforms, a heartbeat that lets the agent act proactively. Their trust model is
explicitly *one user per gateway* — OpenClaw's own security documentation states it "is not a hostile
multi-tenant security boundary". They are personal infrastructure. You cannot put one in front of
your customers.

**2. LLM SDKs.** Thin wrappers around chat completions. They give you a `->chat()` call and leave
every hard problem — durability, approvals, tenancy, auditing, cost control, resumability, the entire
operational surface — as an exercise for the reader.

A Laravel application that wants agents needs the *capabilities* of category 1 with the *trust,
tenancy and authorization model* of the framework it already runs on.

## What Pandora is

Pandora treats an agent run as **a first-class, durable, queued, auditable unit of work in a Laravel
application** — closer to a job with a state machine than to a chat request.

Concretely, a Laravel developer should be able to:

```php
// Expose an application action to agents. It is an ordinary PHP class with a policy.
final class IssueRefund extends Tool { /* ... */ }

// Run an agent against it, in a queue, streamed to the browser, under the current user's abilities.
$run = Pandora::agent('support')
    ->forUser($user)
    ->withContext(['order_id' => $order->getKey()])
    ->stream()
    ->dispatch('Help this customer resolve their order problem.');
```

…and have the framework guarantee that the refund tool call is authorized against the *application's*
policies, paused for a human approval, recorded in an audit trail, charged against a budget, and
resumable if the worker is restarted mid-run.

## Principles

| Principle | What it means concretely |
|---|---|
| **Laravel-native** | Tools are classes with `authorize()`. Policies are Laravel policies. Triggers are Laravel events. Async is Laravel queues. Nothing invents a parallel framework. |
| **Safe by default** | Risk is a first-class field on every tool. `read_only` tools are trivially enabled; `high`/`critical` tools require an explicit policy decision and may require human approval. The installer creates **no** default agent. |
| **Observable** | Every run persists a structured, ordered, redacted trace. If it is not in the trace, it did not happen. We never depend on the model exposing hidden reasoning. |
| **Queue-first** | No web request is ever held open for an agent run. A request may *start* a run; a queue worker performs it. |
| **Durable over in-memory** | Run state lives in the database. Reverb broadcasts are notifications, not the state store. A client that missed every broadcast must be able to reconstruct correct state from a page reload. |
| **Extensible at the seams** | Providers, tools, skills, channels, memory stores, triggers, approval strategies, context providers, model routers and sandbox adapters are all contracts a Composer package can implement. |
| **A framework, not an assistant** | Pandora ships no personality, no opinions about what your agent is for, and no hard-coded agent. |

## Deliberate departures from the reference products

These are choices, not omissions. Each is recorded as an ADR.

| Reference behaviour | Pandora's position |
|---|---|
| Single-operator trust boundary | **Rejected.** Pandora is multi-user and multi-tenant from the first migration. Tenant and session isolation are tested, not assumed. |
| Filesystem (`~/.agent/`, Markdown + YAML) as the system of record | **Rejected as the source of truth.** The database is authoritative. Markdown context files are read from *explicitly configured* workspace roots as *input*, never as config. |
| Agent has shell access to the host by default | **Rejected as a default.** Process execution is an opt-in, separately-enabled, sandbox-adapter-backed capability. Never on in a default install. |
| Skills as installable executable units from a public registry | **Adapted.** Skills are *instructions*, never trusted executable code. Instructions embedded in an imported skill are never auto-executed. No remote marketplace install in v1. |
| Heartbeat: daemon wakes and acts on its own | **Adapted into "intuition" with a hard leash.** Every autonomous action requires a recorded trigger, an autonomy level, a policy decision, and a bounded budget. An agent can never wake itself indefinitely. |
| Provider credentials in a home-directory file | **Adapted.** Encrypted at rest, resolvable per-tenant and per-agent, never broadcast, never logged, never placed in a prompt. |

## Non-goals

- Pandora is not an authentication framework. It integrates with the host's guards and user model.
- Pandora is not a JavaScript SPA. Blade + Livewire + Reverb, with Alpine used sparingly.
- Pandora does not require Redis, Horizon, or a vector database for its core runtime.
- Pandora does not claim to solve prompt injection. It builds layered controls that limit what
  injected instructions can reach. See `docs/architecture/security-model.md`.

## Success criteria for v1.0

1. A Laravel developer installs the package, registers one tool and one agent, and has a working,
   authorized, audited, streamed agent in under thirty minutes.
2. Every capability marked **Core** in `feature-parity.md` is implemented, documented and tested.
3. The security test suite proves tenant isolation, session isolation, workspace path containment,
   SSRF containment, secret redaction, broadcast authorization and approval enforcement.
4. The test suite runs green on SQLite, MySQL/MariaDB and PostgreSQL.

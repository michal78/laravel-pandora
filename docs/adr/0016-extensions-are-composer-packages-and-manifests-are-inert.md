# ADR-0016: Extensions are Composer packages, and a manifest is inert data

- **Status:** accepted
- **Date:** 2026-08-08

## Context

Pandora has thirteen contracts and a registry for most of them. Providers, tools, skills, context
providers, memory stores, vector stores, MCP integrations and now channels are all things a third
party is supposed to be able to add. "Supposed to be able to" has never been tested by anyone outside
this repository, which means the contracts are a claim rather than a seam.

Two questions are open, and they are usually answered together and wrongly.

**How does an extension arrive?** Both reference products answer this with a marketplace: browse,
click install, the capability appears. That is a very good product experience and, in a system where
an extension registers tools an autonomous agent may call, it is remote code execution driven from a
web form. The person clicking is an administrator; the code runs as the application; the tools it
registers are then available to a model that reads untrusted documents.

**How does the control center describe an extension it has not booted?** The obvious answer is to
load it and ask. That inverts the trust relationship in a way that is easy to miss: inspecting a
thing should not require running it, and an "installed extensions" page that boots every candidate is
a page that executes unreviewed code in order to render a table — including for the extension an
operator is currently deciding whether to trust.

## Decision

**1. An extension is a Composer package. There is no other installation mechanism.**

It arrives through `composer require`, which means it arrives through a lockfile, a code review, a
deploy and whatever supply-chain policy the host already has. Pandora contributes nothing to
installation and has no opinion about it, because everything it could contribute would be a way to
get code onto a server without those four things.

**2. There is no marketplace, no remote install, and no update mechanism.**

Not deferred — excluded. The control center *inspects* what is installed and never acquires
anything. There is no endpoint that fetches a package, no allowlist of registries that would make one
acceptable, and no "developer mode" that enables one. A UI that can install code is a UI whose
authorization bug is arbitrary execution.

**3. The manifest lives in `composer.json` under `extra.pandora`, and it is data.**

```json
{
  "extra": {
    "pandora": {
      "name": "Slack",
      "description": "Slack as a Pandora channel.",
      "provides": { "channels": ["slack"], "tools": ["slack.post_message"] },
      "requires": { "pandora": "^1.0" },
      "documentation": "https://…"
    }
  }
}
```

`composer.json` rather than a dedicated `pandora.json` because the file is already there, already
parsed, already in the lockfile, and already the thing a package author edits. A second file is a
second thing to forget, and a manifest an author forgot is an extension that renders as unknown.

**4. Discovery reads `vendor/composer/installed.json` and boots nothing.**

The list of installed packages, with their `extra` blocks, is on disk in a JSON file Composer
maintains. Reading it requires no autoloading, no class existence checks, no reflection and no
service provider. The Extensions page can therefore describe a package that has never been loaded,
including one that would fatal if it were — which is the case that matters, because a broken
extension is exactly when an operator most needs the page to render.

Class-based manifests were the alternative and they are strictly worse here for one reason: they make
inspection require execution. Everything they buy — type safety, IDE support, refactorability — is
bought at the price of the property this decision exists to preserve.

**5. A manifest is a description, not a grant.**

`provides` is what the package *says* it registers. It is displayed, and it is never trusted: the
authoritative answer to "what channels exist" is the channel registry after boot, and to "what tools
exist" the tool registry. The page shows both and shows the difference, because a package claiming a
tool it does not register is worth seeing, and a package registering one it did not declare is worth
seeing more. Nothing is enabled, permitted or exposed because a manifest said so — a manifest is
written by the same person as the code and authorizes nothing about it.

**6. Manifest content is untrusted text.**

Names, descriptions and URLs come from a third-party package and are rendered in an operator's
browser. They are length-bounded, escaped, and URLs are scheme-restricted. A manifest is markup
somebody else wrote, arriving on an authenticated admin page.

**7. The reference extension lives outside this repository.**

`laravel-pandora-slack` is its own package in its own repository, depending on `laravel-pandora`
through Composer like anybody else's would. This is the only arrangement in which "no core changes"
is a fact rather than a habit: a missing seam discovered while writing the extension has to be added
to core, released, and depended upon — the same loop an external author is in. In the same repository
the gap would be closed in the same commit and nobody would ever learn the seam was missing.

**8. Extensions register through the documented contracts, in an ordinary service provider.**

There is no extension base class, no lifecycle hooks, no plugin API beside the contracts, and no
`Pandora::extend()`. An extension is a Laravel package whose service provider calls the same
registration methods the documentation shows a host application calling. If an extension needs a
capability the contracts do not expose, that is a missing contract and it is fixed in core, in the
open, for everyone — not with a private hook.

**9. Nothing an extension registers is enabled by installing it.**

A channel is registered and disabled; an account for it must be created by an operator. A tool is
registered and still subject to the registry, the agent allowlist, the tenant, the actor's abilities
and its risk level (ADR-0007). `composer require` grants an extension the right to *offer*
capabilities, and nothing more. This is the same rule the MCP server follows (ADR-0014, decision 9)
and for the same reason: installation is not consent.

## Consequences

**Installing an extension requires shell access to the server.** Deliberate. The people who can
deploy code can install extensions; the people who can only click cannot. That is the correct
partition and it is the one every alternative erodes.

**The Extensions page can only ever show what is on disk.** No "browse available extensions", no
version-update badge, no one-click anything. It is an inventory, and inventories are useful.

**A manifest can lie, and the page is built expecting it to.** Declared-versus-registered is a
diff shown to an operator, not an error, because the innocent cause (a typo) and the interesting
cause (a package registering more than it admits) look identical from here and only a person can tell
them apart.

**Writing the Slack package will find gaps in the contracts.** That is the point of building it
outside. Each gap is a core change with a test, made because an external author would have needed it.

## Alternatives considered

**A curated marketplace with signed packages.** Rejected. Signing answers "who wrote this" and not
"should this run here", and the entire remote-install surface would exist to save a `composer
require`. Packagist already distributes signed-in-practice code through a reviewed lockfile.

**`pandora.json` at the package root.** Rejected — see decision 3. A second discovery path and a
second file to forget, buying separation nobody asked for.

**A registered `ExtensionManifest` class.** Rejected — see decision 4. Inspection would require
execution, and the extension most in need of inspection is the one it is least safe to boot.

**Manifest fields that grant capability** — `"auto_enable": true`, a declared risk level accepted at
face value, or a tenant list. Rejected: every one of them lets the package being inspected decide the
terms of its own inspection.

**Slack inside this repository under `extensions/`.** Rejected — see decision 7. It proves the
extension works; it does not prove the seam exists, and the seam is what Phase 8 is for.

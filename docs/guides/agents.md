# Agents

An agent is a configured identity — instructions, a model preference, limits,
budgets, an autonomy level and a tool policy. It is a definition, never a
running process. Starting one produces a `Run`.

## Two ways to define one, on purpose

|  | Class-defined | Database-defined |
|---|---|---|
| Lives in | your application's version control | the `pandora_agents` table |
| Created by | a class implementing `AgentDefinition` | the control center, or a seeder |
| Reviewed by | code review | whoever holds `pandora.agents.manage` |
| Changed by | editing the class and deploying | editing it in the control center |
| Best for | agents your application depends on | agents your operators tune |

Both are the same row in the same table. The difference is who is allowed to
decide what, and that difference is enforced rather than documented.

## Class definitions win, field by field

```php
final class SupportAgent implements AgentDefinition
{
    public function define(AgentBlueprint $agent): AgentBlueprint
    {
        return $agent
            ->name('Support')
            ->instructions('Help customers resolve support issues.')
            ->model('anthropic', 'claude-sonnet-4-5')
            ->tools(['group:read-only']);
    }
}
```

Register it under `agents.definitions` in `config/pandora.php`. On first use it
is synced into the database, and from then on:

- **The fields it sets are authoritative.** Name, instructions, model and tool
  policy above are owned by the class. The control center shows them and
  states which class decides them. Attempting to change one there is refused,
  with nothing saved — including the rest of that submission.
- **The fields it leaves unset stay yours.** This definition sets no autonomy
  level, no budgets and no limits, so an operator can raise the token budget or
  drop autonomy to `observe_only` without touching the class, and the next
  deploy will not revert it.

That split is the whole design. A control-center edit that the next deploy
would silently undo is worse than one that is refused, because nothing in the
logs explains it six months later.

To take a field back from operators, set it in the class. To hand one over,
stop setting it.

### Renaming and removing

The slug identifies a definition. It comes from `slug()` if the class defines
one, otherwise from the class name (`SupportAgent` → `support`). Changing it
creates a second agent rather than renaming the first.

Deleting the class does not delete the row — history, runs and conversations
are preserved. The agent becomes fully editable in the control center, and the
page says so. Reinstalling the class takes its fields back.

## The control center

`/pandora/agents` lists every agent with its source, model, autonomy level,
status and run count. Opening one gives six tabs:

| Tab | What it holds |
|---|---|
| Overview | name, description, enabled, identifier |
| Instructions | system instructions (the framework boundary) and role instructions (the persona) |
| Models | provider, model, and the ordered fallback chain |
| Limits & Autonomy | iterations, tool calls, timeout, context budget, token and cost budgets, autonomy level |
| Runs | this agent's recent runs, linked to their traces |
| Usage | calls, tokens and cost for this agent |

Tools, Skills, Memory, Channels, Automations, Workspace and Permissions appear
as tabs naming the phase that builds them.

Creating an agent here produces a database-defined one. It starts **disabled**,
at **`observe_only`**, with **no tools** — you enable it once you have told it
what to do, rather than the other way round.

## Abilities

| Ability | Grants | Default |
|---|---|---|
| `pandora.access` | seeing the roster and every tab except Instructions | authenticated users |
| `pandora.agents.manage` | creating, editing and deleting | **denied** |
| `pandora.prompts.view` | reading *and* writing instructions | **denied** |
| `pandora.costs.view` | the cost figure on the Usage tab | **denied** |

`agents.manage` is administrative because an agent row decides which tools a
language model can reach. `prompts.view` gates instructions in both directions:
you cannot safely edit what you are not allowed to read.

Define any of these as an ordinary Laravel gate and Pandora's default steps
aside — the package never overrides an application's authorization decision.

## Audit

Every change is recorded. `agent.updated` carries the tab, the attributes that
changed, and both before and after values, so "who raised autonomy, and from
what" has an answer. `agent.deleted` is recorded at `warning` severity, and the
delete is soft — runs, conversations and audit history are untouched.

## From the console

```bash
php artisan pandora:agent:list          # every agent, with its source
php artisan pandora:agent:run support "Summarise ticket 4181"
```

## Related

- [Tools](tools.md) — what an agent may do, and the five authorization layers
- [Providers](providers.md) — models, routing, fallback and cost
- ADR-0009 — bounded autonomy, and what each level permits

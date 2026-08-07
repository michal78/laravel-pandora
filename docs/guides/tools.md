# Tools

A tool is where an agent touches your application. It is also where every
safety property of the system is won or lost, so this guide is as much about
what a tool *cannot* do as about how to write one.

## The one rule

> **A tool call is a request from an untrusted party, never an instruction.**

Model output is untrusted input. A tool call is validated, policy-checked and
authorized exactly as if it had arrived as an unauthenticated HTTP request
from the internet — because in effect it did.

## Writing one

```php
use Pandora\Tools\{Tool, ToolContext, ToolInput, ToolResult};
use Pandora\Tools\Enums\RiskLevel;
use Illuminate\Support\Facades\Gate;

final class LookupOrder extends Tool
{
    public function name(): string
    {
        return 'lookup_order';
    }

    public function description(): string
    {
        return 'Look up an order by its customer-facing reference.';
    }

    public function rules(): array
    {
        return [
            'reference' => 'required|string|min:3|max:32',
            'include_lines' => 'boolean',
        ];
    }

    public function descriptions(): array
    {
        return ['reference' => 'The customer-facing order reference.'];
    }

    public function risk(): RiskLevel
    {
        return RiskLevel::Low;
    }

    public function authorize(ToolInput $input, ToolContext $context): bool
    {
        $user = $context->user();

        return $user !== null && Gate::forUser($user)->allows('viewAny', Order::class);
    }

    public function handle(ToolInput $input, ToolContext $context): ToolResult
    {
        $order = Order::query()
            ->where('reference', $input->string('reference'))
            ->first();

        if ($order === null) {
            return ToolResult::failure('No order has that reference.');
        }

        return ToolResult::success(
            "Order {$order->reference} is {$order->status}.",
            ['reference' => $order->reference, 'status' => $order->status],
        );
    }
}
```

Register it, and grant it:

```php
// config/pandora.php
'tools' => ['registered' => [App\Tools\LookupOrder::class]],
```

```php
// The agent still has to be given it. Registering installs; it does not grant.
AgentBlueprint::for('support')->tools(['lookup_order']);
```

## The five layers

Every call clears all five, in order, and no layer can widen what an earlier
one narrowed:

| # | Layer | Refuses when |
|---|---|---|
| 1 | Registry | The tool is not installed |
| 2 | Agent | It is not in this agent's allowlist, or is in its denylist |
| 3 | Tenant | This tenant may not use it |
| — | Validation | The model's arguments fail `rules()` |
| 4 | `ToolPolicy` | Your deployment's own rules say no, or say "ask a human" |
| 5 | `Tool::authorize()` | The **acting user** may not do this |

Layer 5 is the one you write, and it is the one that matters: **an agent
cannot do something the person it acts for could not do themselves.** Write
ordinary Laravel authorization against `$context->user()` and you get that
property for free.

Two ordering details are deliberate. Validation runs before the policy, so a
policy reasons about clean values, and runs *again* after argument
modification, so a policy can narrow a call but never smuggle a value past
your rules. The tenant check runs before validation, so a refused tenant
learns nothing about a tool's interface — not even through an error message.

### The default `authorize()` denies

If you do not write one, the base implementation permits only a low-risk tool
acting for a real user. Forgetting the method gives you a visibly broken tool
rather than a quiet hole.

## Schemas are generated, not written

The JSON schema shown to the model comes from `rules()`. One source of truth
means the interface the model is told about and the interface enforced at call
time cannot drift.

Rules fall into three categories:

- **Mapped** — become schema constraints (`string`, `min`, `in`, `email`, …).
- **Runtime-only** — enforced but not expressible (`exists`, `required_if`, a
  `Rule` object, a closure). These only ever narrow what is accepted, so the
  schema is less specific rather than wrong.
- **Unsupported** — anything else throws `UnsupportedValidationRule` at
  registration, so the application fails to boot rather than failing in the
  middle of somebody's conversation.

A bound whose meaning depends on a type you did not declare also throws:
`min:3` means three different things, and Pandora refuses to guess. Declare
`string|min:3` or `integer|min:3`.

Upload rules (`file`, `image`, `mimes`) are rejected outright. A tool receives
JSON from a model, never a file.

## Risk levels

| Level | Means | Default |
|---|---|---|
| `low` | Reads non-sensitive data, or affects only the run | Runs |
| `medium` | Writes data the actor owns, or has a bounded external effect | Runs |
| `high` | Destructive, financial, or visible outside the application | **Pauses for approval** |
| `critical` | Irreversible, or affecting people other than the actor | **Pauses for approval** |

Understating this is the most consequential mistake a tool author can make.

## Policies

Bind your own `ToolPolicy` to express decisions that belong to your
application:

```php
final class DeskLimitPolicy implements ToolPolicy
{
    public function evaluate(Tool $tool, ToolInput $input, ToolContext $context): PolicyDecision
    {
        if ($tool->name() !== 'refund_order') {
            return PolicyDecision::allow();
        }

        if ($input->integer('amount_minor', 0) > 10_000) {
            return PolicyDecision::modifyArguments(
                [...$input->toArray(), 'amount_minor' => 10_000],
                'Clamped to the £100 desk limit.',
            );
        }

        return PolicyDecision::allow();
    }
}
```

`PolicyDecision::allow()` means "this layer raises no objection". It does
**not** waive the approval a tool's risk level demands — a policy with nothing
to say about a critical tool must not thereby wave it through. Lowering that
floor takes `allowWithoutApproval()`, written out on purpose.

Argument modification is never silent. The change is recorded as a diff on the
run trace, in the audit log, and on the approval card, and the reason travels
with it.

## Approvals

A high-risk call pauses the run at `waiting_for_approval` holding **no job**.
The run costs nothing while it waits, survives deploys, and can wait days.

Decisions have three scopes:

- `once` — this call only. The default, and the safe one.
- `run` — every further call to this tool in this run.
- `remembered` — every further call to this tool by this actor, until revoked.
  Can be disabled entirely with `approvals.allow_remembered`.

Resolution is transactional: two approvers pressing the button at the same
moment produce exactly one decision and exactly one execution. And an approved
call is re-validated and re-authorized when it actually runs — an approval says
a human is willing, not that the gates still agree.

An approval nobody answers expires, and the run fails with a reason that says
exactly that. Schedule the sweep:

```php
// routes/console.php
Schedule::call(fn () => app(ApprovalManager::class)->expireOverdue())->everyFifteenMinutes();
```

## Built-in tools

Eight ship with the package, and registering them **installs** them — each
agent still has to be granted each one.

| Tool | Risk | What it is |
|---|---|---|
| `ask_user` | low | Pause and ask a clarifying question |
| `request_approval` | critical | Pause and ask a human to sign off |
| `inspect_run_status` | low | Read this run's remaining budget |
| `query_records` | low | Read a configured, allowlisted resource |
| `read_config` | low | Read an exactly-allowlisted config key |
| `dispatch_job` | medium | Queue a configured job |
| `emit_event` | medium | Fire a configured event |
| `send_notification` | high | Notify **the actor**, and nobody else |

Each is an allowlist over something you configured. There is deliberately no
shell, no HTTP client, no "run this query" and no file access: those are not
tools a framework can make safe on your behalf, and shipping them
disabled-by-default would still be shipping them.

`query_records` is worth reading closely:

```php
'resources' => [
    'orders' => [
        'model' => App\Models\Order::class,
        'fields' => ['id', 'reference', 'status', 'total'],   // readable columns
        'filterable' => ['reference', 'status'],               // filterable columns
        'max_results' => 25,
        'authorize' => fn ($user) => $user->can('viewAny', Order::class),
        'scope' => fn ($query, $user) => $query->where('user_id', $user->id),
    ],
],
```

A resource with no `authorize` callback is denied: silence is not permission.
`scope` applies whatever the model asked for, which is where an ownership or
tenant constraint belongs.

## Limits

Every run carries `max_iterations`, `max_tool_calls`, `max_duration_seconds`
and a token budget. A duplicate guard also refuses an identical repeat call
within one run — arguments are canonicalised, so reordering them does not read
as a different call — and tells the model to use the answer it already has.

## What Pandora does not claim

Prompt injection is not solved. A model will at some point be persuaded to try
something it should not. What these layers do is bound the blast radius: least
authority, approval gates on real arguments, per-agent allowlists, validated
input, egress control, budgets, and an audit record of everything attempted
whether or not it succeeded.

Policy restriction is also not sandboxing. Without a container or OS boundary,
a tool is only as contained as the PHP process it runs in.

See `docs/architecture/security-model.md` for the full threat model.

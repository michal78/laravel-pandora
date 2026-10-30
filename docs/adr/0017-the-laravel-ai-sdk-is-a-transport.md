# ADR-0017: The Laravel AI SDK is a transport, never a loop

- **Status:** accepted
- **Date:** 2026-10-28

## Context

`laravel/ai` is Laravel's own AI SDK. It has drivers for more text providers than Pandora will ever
ship native adapters for — Bedrock, Azure, Mistral, Groq, xAI, DeepSeek, Cohere, OpenRouter and the
rest — and it will keep up with those vendors on Laravel's schedule rather than ours. Reaching them
through it is clearly worth having.

The SDK is also an agent framework in its own right, and that part overlaps Pandora almost exactly:
agent classes, a tool loop that calls `Tool::handle()` itself, approvals, conversation tables,
streaming protocols. Adopted at that level, it would run tools in a loop Pandora does not own. The
run would stop being a durable state machine with append-only steps (ADR-0001, ADR-0002), a tool
call would execute without passing through `ToolPolicy` and the approval gate (ADR-0007, ADR-0009),
and the T1–T15 controls Phase 9 verified by ablation would sit beside the execution path instead of
on it.

**The question is not whether to use the SDK. It is which layer of it.**

## Decision

**1. Pandora uses the SDK's single-step gateway and nothing above it.**

`Laravel\Ai\Contracts\Gateway\StepTextGateway::generateTextStep()` and `generateStreamStep()` make
exactly one model call and return the tool calls the model asked for without running them. Tool
execution in the SDK lives only in `TextGenerationLoop`, which Pandora never constructs. The adapter
is `Pandora\Providers\Adapters\LaravelAi\LaravelAiProvider`, behind the same `StreamingProvider`
contract as every other adapter.

**2. Tools handed to the SDK cannot be executed.**

Each `ToolDefinition` becomes an SDK `Tool` carrying a name, a description and a schema. Its
`handle()` throws. If anything ever calls it, Pandora's authorisation layer has been bypassed, and a
loud failure is the only acceptable outcome.

**3. The credential stays Pandora's.**

It is resolved through `CredentialManager` at call time and given to the SDK as an on-demand
provider (`AiManager::build()`), so per-agent and per-tenant credentials and rotation behave exactly
as they do for the native adapters. `config/ai.php` is optional, and a connection may name an entry
in it instead of repeating it — but a Pandora credential always wins.

**4. The SDK is suggested, and confined.**

`laravel/ai` is in `suggest`, never `require`. An architecture test refuses any `Laravel\Ai` symbol
outside `src/Providers/Adapters/LaravelAi/` — stricter than the general vendor rule, because a
reference elsewhere is a class-not-found error for every host that did not install it.

**5. It is held to the same contract, on the same fixtures.**

The shared `ProviderContractTests` suite runs against the adapter using the native Anthropic and
OpenAI-compatible adapters' own recorded fixtures, so the SDK path is measured against wire formats
nobody wrote to suit it. Where the SDK speaks a different API from the native adapter — its Gemini
driver uses the Interactions API — there is no leg, and the native adapter remains the supported
path, rather than a second set of fixtures that agrees with the code it fakes for.

## Alternatives considered

**Build on SDK agents.** Pandora's agents would wrap SDK agents and Pandora would observe the loop
through middleware. Rejected: observation is not control. A tool the SDK runs before a middleware
can refuse it has already run, and the audit has nothing to assert against.

**Replace the native adapters with the SDK.** Fewer adapters to keep. Rejected for now: the native
adapters carry the classification and normalisation Phase 3 and Phase 9 tested hard, depend on
nothing but Laravel's HTTP client, and are what the threat tests ran against. The SDK adapter has
to earn that position, not inherit it.

**Write native adapters per vendor.** Most predictable. Rejected as the default: it is the slowest
way to reach the long tail, and it duplicates work Laravel maintains. A native adapter remains the
right answer where a vendor needs something the SDK does not expose.

## Consequences

- Every SDK text driver is reachable with a config entry. The extension list in
  `provider-model.md` section 6 is largely covered without a package per vendor.
- **Health is unknown for these connections.** The SDK has no reachability probe and a paid
  completion is not an acceptable one.
- **Capabilities are claimed conservatively** — streaming and tools. Vision and structured output
  vary by driver, and a capability the router trusts but the provider lacks fails at the worst
  moment.
- **The SDK retries a short `Retry-After` itself**, before Pandora's retry policy sees the failure.
  Harmless, but it means a rate-limit hop can take longer than the configured delay suggests.
- **Mid-stream failures arrive as events, not exceptions**, and the SDK's `recoverable` flag is not
  usable (Anthropic's marks an overload unrecoverable). The adapter classifies the vendor's error
  type instead, and treats anything unrecognised as transient.
- An SDK minor release can change a driver's wire behaviour. The contract legs are what will notice,
  which is the reason they run on the native fixtures.

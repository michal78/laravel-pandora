# Provider Model

> Status: implemented in Phase 3. Sections 1–10 describe shipped behaviour;
> the extension adapters listed in section 6 remain future work.

## 1. Rule

> **No vendor SDK type ever crosses a Pandora boundary.** Adapters translate, in both directions.
> Everything outside `src/Providers/Adapters/` speaks only Pandora DTOs.

This is enforced by an architecture test asserting no `Anthropic\`, `OpenAI\`, `Google\` … symbol is
referenced outside its own adapter directory.

The reason is not purity. It is that provider APIs change on their own schedule, and a leaked vendor
type turns a vendor's minor release into a breaking change for every host application.

## 2. Contracts

```php
interface Provider {
    public function key(): string;                 // 'anthropic'
    public function capabilities(): ProviderCapabilities;
    public function health(): ProviderHealth;
}

interface ChatProvider extends Provider {
    public function chat(ChatRequest $request): ChatResponse;
}

interface StreamingProvider extends ChatProvider {
    /** @param callable(StreamDelta): void $onDelta */
    public function stream(ChatRequest $request, callable $onDelta): ChatResponse;
}

interface EmbeddingProvider   extends Provider { public function embed(EmbeddingRequest $r): EmbeddingResponse; }
interface ImageProvider       extends Provider { /* … */ }
interface AudioProvider       extends Provider { /* … */ }
interface TranscriptionProvider extends Provider { /* … */ }
interface RerankingProvider   extends Provider { /* … */ }

interface ModelCatalogProvider { public function models(): ModelDescriptorCollection; }
interface UsageNormalizer      { public function normalize(mixed $raw): UsageRecordData; }
interface CredentialResolver   { public function resolve(string $provider, ResolutionContext $c): Credential; }
```

A provider implements only what it supports. `ProviderCapabilities` is queried before use, so the
router never routes a vision request to a text-only model.

## 3. DTOs

All immutable `readonly` classes in `src/Providers/Data/`:

`ChatRequest` (messages, tools, model, options, response format, budget hints) ·
`ChatMessage` · `ContentPart` (text | image | audio | document | tool_call | tool_result) ·
`ToolDefinition` · `ToolCall` · `ChatResponse` (content, tool calls, finish reason, usage, reasoning
summary, raw id) · `StreamDelta` (text | tool_call_partial | reasoning | usage | done) ·
`UsageRecordData` · `ProviderCapabilities` · `ModelDescriptor` · `ProviderHealth` · `Credential`.

`ChatResponse::reasoning()` returns a **summary if and only if the provider exposes one**. Pandora
never fabricates one and never depends on its presence.

## 4. Normalisation

Providers disagree about almost everything. The adapter absorbs the disagreement:

| Concern | Normalised to |
|---|---|
| Tool-call format (native vs. JSON-in-text) | `ToolCall[]` on the response |
| Streaming event shape (SSE deltas, partial JSON) | `StreamDelta` union |
| Usage field names | `UsageRecordData` (input, output, cached_input, cached_output, reasoning, audio units, image units, requests) |
| Finish reasons | `FinishReason` enum (`stop`, `tool_calls`, `length`, `content_filter`, `error`) |
| Errors | Pandora exception hierarchy with a `retryable` classification |
| Multimodal content | `ContentPart` |
| System prompt placement (top-level vs. first message) | adapter's problem |

Unmapped vendor fields are preserved in a `raw_meta` bag on the run step for debugging — redacted,
and never fed back into a prompt.

## 5. Errors

| Exception | Retryable | Failover |
|---|---|---|
| `ProviderUnavailable` | yes | yes |
| `ProviderRateLimited` (carries `retryAfter`) | yes | after N attempts |
| `ProviderRejectedRequest` (400-class) | no | no |
| `ProviderAuthenticationFailed` | no | yes (different credential) |
| `ContextOverflow` | no | yes (larger-context model) |
| `InvalidStructuredResponse` | once, with a repair prompt | no |
| `ProviderTimeout` | yes | yes |

Classification is the adapter's responsibility and is covered by the shared contract test suite.

## 6. Adapters

**Core (shipped in Phase 3):** `OpenAiCompatible` (the workhorse — OpenAI, Ollama, OpenRouter, vLLM,
llama.cpp, LM Studio, anything speaking the shape), `Anthropic` and `Gemini`.

Gemini moved from extension to core during Phase 3: it is the third genuinely
distinct dialect, and building it was what forced the contract suite to stop
assuming every vendor issues tool-call ids. That assumption would otherwise
have been baked in and inherited by every adapter written afterwards.

**Official extensions:** Azure OpenAI, Bedrock, Mistral, Groq, xAI, Together, DeepSeek.

**Testing:** `FakeProvider`, `FakeStreamingProvider`, `FakeEmbeddingProvider`.

Vendor SDKs are `suggest`ed, never `require`d. Where an adapter can be written against Laravel's HTTP
client without an SDK, it is — that keeps the dependency footprint near zero.

### Contract test suite

`src/Testing/ProviderContractTests.php` is a Pest test set every adapter must pass, against recorded
fixtures: basic completion, streaming deltas in order, tool-call round trip, multi-tool round trip,
usage normalisation, each error classification, context overflow, malformed response, cancellation
mid-stream. A new adapter is "done" when the shared suite passes.

## 7. Credentials

Resolution order — first match wins:

```
per-agent credential → per-tenant credential → deployment credential → config/pandora.php
```

There is no separate environment step. `config/pandora.php` is where the
environment is read, and it is the only place it *can* be read: `env()` returns
null once a deployment caches its config, so a resolver that consulted the
environment directly would work in every test and fail in production.

Resolved lazily inside the adapter at HTTP-call time. Never on a job payload, never in context, never
in a step, never broadcast, never in an API resource. Rotation is supported by versioned credential
rows; the previous version stays valid for a grace window.

## 8. Model catalog

`Model` rows carry: provider, key, display name, context limit, max output, modality support, tool
support, structured-output support, streaming support, input/output/cached pricing, currency, pricing
source, pricing date, deprecation date, enabled flag.

Synced from the provider where an API exists (`ModelCatalogProvider`); seeded from config otherwise.
**Pricing is always stamped with a source and a date**, because pricing goes stale and a silently
stale cost estimate is worse than no estimate.

## 9. Model router

```php
interface ModelRouter {
    public function resolve(RoutingRequest $request): RoutingDecision;
}
```

v1 ships `DeterministicModelRouter`, in this precedence order:

```
1. explicit per-call model
2. run override
3. conversation override
4. agent default
5. provider default from config
```

then, on failure, walk the agent's `fallback_models` chain, skipping models whose provider health is
degraded and models lacking a required capability. Each hop is recorded as a run step so a failover is
visible in the trace rather than mysterious.

Capability-, cost- and latency-aware routing are deliberately **not** in v1 — see ADR-0006. Building
an optimiser before there is production data to optimise against produces an unpredictable system and
an untestable one. The interface is the extension point; a host that wants one can bind it today.

Tenant restrictions (an allowlist of permitted models per tenant) are applied *before* routing, so a
fallback chain can never escape them.

## 10. Health

`ProbeProviderHealth` runs on `pandora-maintenance`, recording reachability, latency percentiles,
error rate and rate-limit headroom. Consumed by the router (skip degraded providers), the Health page
and `pandora:doctor`. A provider is marked degraded on consecutive failures and recovers on a
successful probe.

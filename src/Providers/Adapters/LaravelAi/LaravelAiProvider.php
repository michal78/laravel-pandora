<?php

declare(strict_types=1);

namespace Pandora\Providers\Adapters\LaravelAi;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\JsonSchema\Deserializer;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\Data\FinishReason as SdkFinishReason;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall as SdkToolCall;
use Laravel\Ai\Responses\Data\ToolResult as SdkToolResult;
use Laravel\Ai\Streaming\Events\Error as SdkStreamError;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Tools\Request as SdkToolRequest;
use Pandora\Contracts\StreamingProvider;
use Pandora\Exceptions\InvalidConfiguration;
use Pandora\Exceptions\Provider\ContextOverflow;
use Pandora\Exceptions\Provider\ProviderAuthenticationFailed;
use Pandora\Exceptions\Provider\ProviderException;
use Pandora\Exceptions\Provider\ProviderRateLimited;
use Pandora\Exceptions\Provider\ProviderRejectedRequest;
use Pandora\Exceptions\Provider\ProviderTimeout;
use Pandora\Exceptions\Provider\ProviderUnavailable;
use Pandora\Messages\Enums\MessageRole;
use Pandora\Providers\Adapters\Concerns\ClassifiesProviderFailures;
use Pandora\Providers\Credentials\CredentialManager;
use Pandora\Providers\Data\ChatMessage;
use Pandora\Providers\Data\ChatRequest;
use Pandora\Providers\Data\ChatResponse;
use Pandora\Providers\Data\FinishReason;
use Pandora\Providers\Data\ProviderCapabilities;
use Pandora\Providers\Data\ProviderHealth;
use Pandora\Providers\Data\StreamDelta;
use Pandora\Providers\Data\ToolCall;
use Pandora\Providers\Data\ToolDefinition;
use Pandora\Providers\Data\UsageData;

/**
 * Any text provider the Laravel AI SDK (`laravel/ai`) supports, behind
 * Pandora's own contract.
 *
 * The SDK is used for exactly one thing: its gateways, one model call at a
 * time, through `StepTextGateway`. Its agents, its tool loop, its approvals
 * and its conversation tables are deliberately not used. Pandora's run is a
 * durable state machine that records every step and authorises every tool
 * call (ADR-0001, ADR-0007); if the SDK ran the loop, a tool would execute
 * with neither. So the tools handed to the gateway are descriptions only, and
 * calling one is a bug -- see `describe()`.
 *
 * The credential is still Pandora's. It is resolved at call time through the
 * CredentialManager, exactly as the native adapters do, and handed to the SDK
 * as an on-demand provider, so per-agent and per-tenant keys keep working and
 * `config/ai.php` is optional.
 *
 * `laravel/ai` is suggested, not required. Nothing outside this directory may
 * reference a `Laravel\Ai` symbol -- the architecture suite enforces it.
 */
final class LaravelAiProvider implements StreamingProvider
{
    use ClassifiesProviderFailures;

    private const DEFAULT_TIMEOUT = 120;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly string $key,
        private readonly array $config,
        private readonly AiManager $ai,
        private readonly Config $appConfig,
        private readonly ?CredentialManager $credentials = null,
    ) {}

    /**
     * Build the adapter, or explain what is missing.
     *
     * `laravel/ai` is suggested rather than required, so its absence is a
     * configuration error naming the fix, not a class-not-found error naming
     * a class the host never typed. Safe to call without the SDK installed:
     * nothing here touches an SDK class until the check has passed.
     *
     * @param array<string, mixed> $config
     */
    public static function make(
        string $key,
        array $config,
        Container $container,
        ?CredentialManager $credentials = null,
    ): self {
        if (! class_exists(AiManager::class)) {
            throw new InvalidConfiguration(
                "Provider [{$key}] uses the laravel-ai adapter, which needs the Laravel AI SDK: composer require laravel/ai",
            );
        }

        return new self(
            key: $key,
            config: $config,
            ai: $container->make(AiManager::class),
            appConfig: $container->make('config'),
            credentials: $credentials,
        );
    }

    public function key(): string
    {
        return $this->key;
    }

    public function capabilities(): ProviderCapabilities
    {
        // What every SDK text driver does. Vision and structured output vary
        // by driver and are not claimed, because a capability the router
        // trusts and the provider lacks fails at the worst possible moment.
        return new ProviderCapabilities(streaming: true, tools: true);
    }

    public function health(): ProviderHealth
    {
        // The SDK exposes no reachability probe, and a paid completion is not
        // an acceptable one. Unknown is honest; the router still uses the
        // provider, and failures during real calls are recorded as usual.
        return ProviderHealth::unknown();
    }

    public function chat(ChatRequest $request): ChatResponse
    {
        $startedAt = hrtime(true);

        [$provider, $instructions, $messages, $tools, $options] = $this->prepare($request);

        $response = $this->guard($request, fn (): StepResponse => $this->gateway($provider)->generateTextStep(
            $provider,
            $request->model,
            $instructions,
            $messages,
            $tools,
            null,
            $options,
            $this->timeout(),
            new StepContext,
        ));

        return $this->translate($response, $this->elapsedMs($startedAt));
    }

    public function stream(ChatRequest $request, callable $onDelta): ChatResponse
    {
        $startedAt = hrtime(true);

        [$provider, $instructions, $messages, $tools, $options] = $this->prepare($request);

        try {
            $events = $this->gateway($provider)->generateStreamStep(
                strtolower((string) Str::uuid7()),
                $provider,
                $request->model,
                $instructions,
                $messages,
                $tools,
                null,
                $options,
                $this->timeout(),
                new StepContext,
            );
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw $this->classify($e, $request);
        }

        $error = null;

        // Only advancing the generator is guarded. `$onDelta` runs outside it,
        // so a caller that stops the stream gets its own exception back rather
        // than a provider failure -- "the user pressed stop" is not "the model
        // failed".
        while ($this->guard($request, static fn (): bool => $events->valid())) {
            $event = $events->current();

            match (true) {
                $event instanceof TextDelta => $onDelta(StreamDelta::text($event->delta)),
                $event instanceof ReasoningDelta => $onDelta(StreamDelta::reasoning($event->delta)),
                $event instanceof SdkStreamError => $error = $event,
                default => null,
            };

            $this->guard($request, static fn () => $events->next());
        }

        $response = $events->getReturn();

        if (! $response instanceof StepResponse) {
            throw $this->streamFailure($error, $request);
        }

        $translated = $this->translate($response, $this->elapsedMs($startedAt));

        // Tool calls are only complete once the stream has closed, which is
        // also when the native adapters emit them.
        foreach ($translated->toolCalls as $call) {
            $onDelta(StreamDelta::toolCall($call));
        }

        $onDelta(StreamDelta::usage($translated->usage));
        $onDelta(StreamDelta::done());

        return $translated;
    }

    // ----------------------------------------------------------------- request

    /**
     * @return array{0: TextProvider, 1: string|null, 2: list<Message>, 3: list<Tool>, 4: TextGenerationOptions}
     */
    private function prepare(ChatRequest $request): array
    {
        [$instructions, $messages] = $this->conversation($request->messages);

        $maxTokens = $request->maxTokens
            ?? (isset($this->config['max_tokens']) ? (int) $this->config['max_tokens'] : null);

        /** @var array<string, mixed> $providerOptions */
        $providerOptions = $request->options;

        $options = new TextGenerationOptions(
            maxSteps: 1,
            maxTokens: $maxTokens,
            temperature: $request->temperature,
            providerOptions: $providerOptions === [] ? null : $providerOptions,
        );

        return [
            $this->provider(),
            $instructions,
            $messages,
            array_map(fn (ToolDefinition $tool): Tool => $this->describe($tool, $request), $request->tools),
            $options,
        ];
    }

    /**
     * Lift system messages out into the SDK's instructions, which is where
     * every one of its gateways expects them, and translate the rest.
     *
     * Consecutive tool results are folded into ONE result message: several
     * results answering one assistant turn are a single turn on the wire for
     * the vendors that care, and the SDK models them that way.
     *
     * @param list<ChatMessage> $messages
     * @return array{0: string|null, 1: list<Message>}
     */
    private function conversation(array $messages): array
    {
        $system = [];
        $out = [];

        /** @var array<string, ToolCall> $calls */
        $calls = [];

        /** @var list<SdkToolResult> $results */
        $results = [];

        $flush = static function () use (&$results, &$out): void {
            if ($results !== []) {
                $out[] = new ToolResultMessage(new Collection($results));
                $results = [];
            }
        };

        foreach ($messages as $message) {
            if ($message->role === MessageRole::Tool) {
                $id = (string) $message->toolCallId;
                $call = $calls[$id] ?? null;

                $results[] = new SdkToolResult(
                    id: $id,
                    name: $message->name ?? $call->name ?? '',
                    arguments: $call->arguments ?? [],
                    result: $message->content,
                );

                continue;
            }

            $flush();

            match ($message->role) {
                MessageRole::System => $system[] = $message->content,
                MessageRole::Assistant => $out[] = $this->assistant($message, $calls),
                default => $out[] = new UserMessage($message->content),
            };
        }

        $flush();

        return [$system === [] ? null : implode("\n\n", $system), $out];
    }

    /**
     * @param array<string, ToolCall> $calls the calls seen so far, by id, so a later result can name its arguments
     */
    private function assistant(ChatMessage $message, array &$calls): AssistantMessage
    {
        $toolCalls = [];

        foreach ($message->toolCalls as $call) {
            $calls[$call->id] = $call;
            $toolCalls[] = new SdkToolCall($call->id, $call->name, $call->arguments);
        }

        return new AssistantMessage($message->content, new Collection($toolCalls));
    }

    /**
     * A tool as the SDK needs to see it: a name, a description and a schema.
     *
     * It cannot be executed. The gateway only maps it to the vendor's tool
     * format; execution belongs to Pandora's own loop, behind its approval and
     * authorisation layer. If anything ever calls `handle()`, that layer has
     * been bypassed, and failing loudly is the only acceptable outcome.
     */
    private function describe(ToolDefinition $tool, ChatRequest $request): Tool
    {
        $properties = $this->properties($tool, $request);

        return new class($tool->name, $tool->description, $properties) implements Tool
        {
            /**
             * @param array<string, Type> $properties
             */
            public function __construct(
                private readonly string $name,
                private readonly string $description,
                private readonly array $properties,
            ) {}

            public function name(): string
            {
                return $this->name;
            }

            public function description(): string
            {
                return $this->description;
            }

            public function schema(JsonSchema $schema): array
            {
                return $this->properties;
            }

            public function handle(SdkToolRequest $request): never
            {
                throw new \LogicException(
                    "Tool [{$this->name}] was invoked by the Laravel AI SDK. Pandora executes tools itself; "
                    .'an SDK-side call would bypass approval and authorisation.',
                );
            }
        };
    }

    /**
     * Rebuild a tool's top-level properties as the SDK's schema types.
     *
     * The SDK takes the properties of an object rather than a whole schema,
     * so each one is deserialised on its own and marked required if the
     * schema says so.
     *
     * @return array<string, Type>
     */
    private function properties(ToolDefinition $tool, ChatRequest $request): array
    {
        /** @var array<string, mixed> $declared */
        $declared = is_array($tool->schema['properties'] ?? null) ? $tool->schema['properties'] : [];

        /** @var list<string> $required */
        $required = is_array($tool->schema['required'] ?? null) ? $tool->schema['required'] : [];

        $properties = [];

        foreach ($declared as $name => $definition) {
            try {
                $type = Deserializer::deserialize(is_array($definition) ? $definition : []);
            } catch (\InvalidArgumentException $e) {
                // A schema the SDK cannot represent is a definition problem,
                // not a transient failure -- retrying it would only repeat it.
                throw new ProviderRejectedRequest(
                    "Tool [{$tool->name}] has a schema the Laravel AI SDK cannot represent: {$e->getMessage()}",
                    $this->key,
                    $request->model,
                    $e,
                );
            }

            if (in_array($name, $required, true)) {
                $type->required();
            }

            $properties[(string) $name] = $type;
        }

        return $properties;
    }

    // ---------------------------------------------------------------- response

    private function translate(StepResponse $response, int $durationMs): ChatResponse
    {
        $toolCalls = array_map(
            static fn (SdkToolCall $call): ToolCall => new ToolCall(
                id: $call->id !== '' ? $call->id : uniqid('call_'),
                name: $call->name,
                // Malformed arguments from a model are normal; the tool's own
                // validation rejects the call.
                arguments: $call->arguments,
            ),
            array_values($response->toolCalls),
        );

        $finishReason = $toolCalls === [] ? $this->finishReason($response->finishReason) : FinishReason::ToolCalls;

        return new ChatResponse(
            content: $response->text,
            finishReason: $finishReason,
            usage: $this->usage($response->usage, $durationMs),
            toolCalls: $toolCalls,
            reasoningSummary: $response->reasoning === '' ? null : $response->reasoning,
            // The SDK's response meta carries no vendor response id.
            providerResponseId: null,
        );
    }

    private function finishReason(SdkFinishReason $reason): FinishReason
    {
        return match ($reason) {
            SdkFinishReason::ToolCalls => FinishReason::ToolCalls,
            SdkFinishReason::Length => FinishReason::Length,
            SdkFinishReason::ContentFilter => FinishReason::ContentFilter,
            SdkFinishReason::Error => FinishReason::Error,
            default => FinishReason::Stop,
        };
    }

    /**
     * Pandora's three input figures are disjoint -- fresh input, cache reads,
     * cache writes -- because `CatalogModel::estimate()` prices each one
     * separately. The SDK's `inputTokens` is the TOTAL including both cache
     * classes, on every driver, so the fresh share is what remains once they
     * are taken out. Passing the total through would bill every cached token
     * twice: once at the input rate and again at the cached rate.
     */
    private function usage(TextUsage $usage, int $durationMs): UsageData
    {
        return new UsageData(
            inputTokens: max(0, $usage->uncachedInputTokens()),
            outputTokens: $usage->outputTokens,
            cachedInputTokens: $usage->cacheReadInputTokens ?? 0,
            cachedOutputTokens: $usage->cacheWriteInputTokens ?? 0,
            reasoningTokens: $usage->reasoningTokens ?? 0,
            durationMs: $durationMs,
        );
    }

    // ----------------------------------------------------------------- failure

    /**
     * Run a gateway call, turning anything it throws into a Pandora exception.
     *
     * The SDK already turns some failures into its own exceptions; each keeps
     * the HTTP failure it came from, and THAT is what is classified, so this
     * adapter makes the same retry-or-failover decision every native adapter
     * makes for the same response.
     *
     * @template T
     *
     * @param \Closure(): T $call
     * @return T
     */
    private function guard(ChatRequest $request, \Closure $call): mixed
    {
        try {
            return $call();
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw $this->classify($e, $request);
        }
    }

    private function classify(\Throwable $e, ChatRequest $request): ProviderException
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof RequestException && $cause->response !== null) {
                $classified = $this->classifyFailure($cause->response, $request->model);

                return $classified instanceof ProviderException
                    ? $classified
                    : new ProviderUnavailable($classified->getMessage(), $this->key, $request->model, $e);
            }

            if ($cause instanceof ConnectionException) {
                return new ProviderTimeout(
                    "Could not reach provider [{$this->key}]: {$cause->getMessage()}",
                    $this->key,
                    $request->model,
                    $e,
                );
            }
        }

        // Anything else -- a body the SDK could not parse, a driver that is not
        // a text provider -- is a broken response rather than a PHP error.
        return new ProviderUnavailable(
            "Provider [{$this->key}] failed inside the Laravel AI SDK: {$e->getMessage()}",
            $this->key,
            $request->model,
            $e,
        );
    }

    /**
     * A stream that ended without a response.
     *
     * Gateways report a mid-stream failure as an event rather than an
     * exception, and their `recoverable` flag is not usable -- Anthropic's
     * marks an `overloaded_error` unrecoverable, which would turn a transient
     * overload into a request nobody may retry. So the vendor's error TYPE is
     * classified instead, and anything unrecognised is treated as transient:
     * a wrongly retried request costs one call, a wrongly abandoned one costs
     * the run.
     */
    private function streamFailure(?SdkStreamError $error, ChatRequest $request): ProviderException
    {
        $message = $error?->message ?? 'The stream ended without a response.';
        $type = mb_strtolower($error?->type ?? '');

        return match (true) {
            str_contains($type, 'rate_limit') => new ProviderRateLimited($message, $this->key, $request->model),
            str_contains($type, 'authentication'), str_contains($type, 'permission') => new ProviderAuthenticationFailed($message, $this->key, $request->model),
            $this->looksLikeContextOverflow($message) => new ContextOverflow($message, $this->key, $request->model),
            str_contains($type, 'invalid_request') => new ProviderRejectedRequest($message, $this->key, $request->model),
            default => new ProviderUnavailable($message, $this->key, $request->model),
        };
    }

    protected function providerKey(): string
    {
        return $this->key;
    }

    protected function extractErrorMessage(string $body): ?string
    {
        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            return null;
        }

        $error = $decoded['error'] ?? null;

        if (is_array($error) && is_string($error['message'] ?? null)) {
            return $error['message'];
        }

        if (is_string($error)) {
            return $error;
        }

        return is_string($decoded['message'] ?? null) ? $decoded['message'] : null;
    }

    // --------------------------------------------------------------- transport

    /**
     * The SDK provider for this connection, built on demand with the
     * credential resolved now -- never on a job payload, never in a step.
     */
    private function provider(): TextProvider
    {
        $sdkConfig = $this->sdkConfig();

        try {
            $provider = $this->ai->build($sdkConfig);
        } catch (\InvalidArgumentException $e) {
            throw new InvalidConfiguration(
                "Provider [{$this->key}] could not be built by the Laravel AI SDK: {$e->getMessage()}",
                previous: $e,
            );
        }

        if (! $provider instanceof TextProvider) {
            throw new InvalidConfiguration(
                "The Laravel AI SDK driver [{$sdkConfig['driver']}] used by provider [{$this->key}] does not generate text.",
            );
        }

        return $provider;
    }

    /**
     * Build the SDK's provider configuration.
     *
     * Either `driver` names an SDK driver directly, or `ai_provider` names an
     * entry in the host's `config/ai.php`. `sdk` passes anything else straight
     * through (a Bedrock region, an Azure deployment). A Pandora credential,
     * where one resolves, always wins over the key in either place.
     *
     * @return array<string, mixed>&array{driver: string}
     */
    private function sdkConfig(): array
    {
        $base = [];

        if (is_string($this->config['ai_provider'] ?? null)) {
            $named = $this->appConfig->get('ai.providers.'.$this->config['ai_provider']);

            if (! is_array($named)) {
                throw new InvalidConfiguration(
                    "Provider [{$this->key}] names Laravel AI provider [{$this->config['ai_provider']}], which config/ai.php does not define.",
                );
            }

            $base = $named;
        }

        /** @var array<string, mixed> $passthrough */
        $passthrough = is_array($this->config['sdk'] ?? null) ? $this->config['sdk'] : [];

        $config = [...$base, ...$passthrough];

        if (is_string($this->config['driver'] ?? null)) {
            $config['driver'] = $this->config['driver'];
        }

        if (! is_string($config['driver'] ?? null) || $config['driver'] === '') {
            throw new InvalidConfiguration(
                "Provider [{$this->key}] uses the laravel-ai adapter but names no SDK driver. Set `driver` or `ai_provider`.",
            );
        }

        if (is_string($this->config['base_url'] ?? null) && $this->config['base_url'] !== '') {
            $config['url'] = $this->config['base_url'];
        }

        $apiKey = $this->credentials?->resolve($this->key)?->secret()
            ?? $this->config['api_key']
            ?? null;

        if (is_string($apiKey) && $apiKey !== '') {
            $config['key'] = $apiKey;
        }

        // A stable name, so rebuilding on every call replaces the previous
        // on-demand provider instead of accumulating one per credential.
        $config['name'] = 'pandora_'.$this->key;

        /** @var array<string, mixed>&array{driver: string} $config */
        return $config;
    }

    private function gateway(TextProvider $provider): StepTextGateway
    {
        // Every SDK text provider has one; the interface simply does not say so.
        if (! method_exists($provider, 'textGateway')) {
            throw new InvalidConfiguration(
                "The Laravel AI SDK provider behind [{$this->key}] exposes no text gateway.",
            );
        }

        $gateway = $provider->textGateway();

        if (! $gateway instanceof StepTextGateway) {
            throw new InvalidConfiguration(
                "The Laravel AI SDK provider behind [{$this->key}] exposes no text gateway.",
            );
        }

        return $gateway;
    }

    private function timeout(): int
    {
        return (int) ($this->config['timeout'] ?? self::DEFAULT_TIMEOUT);
    }

    private function elapsedMs(int $startedAt): int
    {
        return (int) ((hrtime(true) - $startedAt) / 1_000_000);
    }
}

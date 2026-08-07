<?php

declare(strict_types=1);

namespace Pandora\Console\Commands;

use Illuminate\Console\Command;
use Pandora\Contracts\ChatProvider;
use Pandora\Contracts\StreamingProvider;
use Pandora\Exceptions\PandoraException;
use Pandora\Providers\Credentials\CredentialManager;
use Pandora\Providers\Data\ChatMessage;
use Pandora\Providers\Data\ChatRequest;
use Pandora\Providers\Data\StreamDelta;
use Pandora\Providers\Data\StreamDeltaType;
use Pandora\Providers\Health\ProviderHealthMonitor;
use Pandora\Providers\ProviderManager;
use Pandora\Support\Redactor;

/**
 * A real round trip against a real provider.
 *
 * Everything else in Phase 3 is proven against fixtures, which is what makes
 * the suite runnable by anybody. This is the one place a human deliberately
 * spends a fraction of a cent to find out whether the key in this
 * environment's `.env` actually works -- the question fixtures cannot answer.
 *
 * It prints what came back, what it cost and how long it took. It never
 * prints the credential, not even a prefix of it.
 */
final class ProviderTestCommand extends Command
{
    protected $signature = 'pandora:provider:test
                            {connection? : The connection key; the default provider when omitted}
                            {--model= : Override the model}
                            {--prompt=Reply with the single word: ready. : What to send}
                            {--stream : Exercise the streaming path instead}
                            {--health : Probe health only, sending no prompt}';

    protected $description = 'Send one real request to a configured provider';

    private Redactor $redactor;

    public function handle(
        ProviderManager $providers,
        ProviderHealthMonitor $health,
        CredentialManager $credentials,
        Redactor $redactor,
    ): int {
        $this->redactor = $redactor;

        /** @var string|null $requested */
        $requested = $this->argument('connection');
        $key = $requested ?? $providers->default();

        if (! $providers->has($key)) {
            $this->components->error("No provider connection named [{$key}] is configured.");

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Connection', $key);
        $this->components->twoColumnDetail(
            'Credential',
            // Presence and fingerprint. Never the value, and never a prefix:
            // a prefix is enough to identify an account in a leaked log.
            $credentials->resolve($key) === null
                ? '<fg=yellow>none resolved</>'
                : '<fg=green>resolved</> ('.$credentials->resolve($key)?->fingerprint().')',
        );

        try {
            $provider = $providers->provider($key);
        } catch (PandoraException $e) {
            // Providers echo the key back in their own error text -- OpenAI
            // does exactly that on a 401 -- and a terminal ends up in a CI log
            // or a pasted bug report.
            $this->components->error($this->redactor->redactText($e->getMessage()));

            return self::FAILURE;
        }

        $probe = $provider->health();
        $health->record($key, $probe);

        $this->components->twoColumnDetail(
            'Health',
            $probe->status.($probe->latencyMs === null ? '' : " ({$probe->latencyMs} ms)"),
        );

        if ($probe->message !== null) {
            $this->components->twoColumnDetail('Detail', $this->redactor->redactText($probe->message));
        }

        if ($this->option('health') === true) {
            return $probe->isUsable() ? self::SUCCESS : self::FAILURE;
        }

        if (! $provider instanceof ChatProvider) {
            $this->components->warn("Provider [{$key}] does not support chat completions.");

            return self::SUCCESS;
        }

        return $this->roundTrip($provider, $key);
    }

    private function roundTrip(ChatProvider $provider, string $key): int
    {
        /** @var string $model */
        $model = $this->option('model') ?? config('pandora.models.default', 'fake-model');
        /** @var string $prompt */
        $prompt = $this->option('prompt');

        $streamer = $provider instanceof StreamingProvider ? $provider : null;
        $wantsStream = $this->option('stream') === true && $streamer !== null;

        $request = new ChatRequest(
            model: $model,
            messages: [ChatMessage::user($prompt)],
            maxTokens: 64,
            stream: $wantsStream,
        );

        $this->components->twoColumnDetail('Model', $model);
        $this->newLine();

        $startedAt = hrtime(true);

        try {
            $response = $wantsStream
                ? $streamer->stream($request, function (StreamDelta $delta): void {
                    if ($delta->type === StreamDeltaType::Text) {
                        $this->output->write($delta->text);
                    }
                })
                : $provider->chat($request);
        } catch (PandoraException $e) {
            $this->newLine();

            // Redacted, because providers echo the key back in their own error
            // text and a terminal ends up in a CI log or a pasted bug report.
            $this->components->error($this->redactor->redactText($e->getMessage()));

            // The classification is the useful part: "rate limited" and "no
            // credit" look identical from the outside and need completely
            // different responses from a human.
            $this->components->twoColumnDetail('Classified as', $e->errorCode());
            $this->components->twoColumnDetail('Retryable', $e->isRetryable() ? 'yes' : 'no');

            return self::FAILURE;
        }

        $elapsedMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);

        if (! $wantsStream) {
            $this->line($response->content);
        }

        $this->newLine();
        $this->components->twoColumnDetail('Finish reason', $response->finishReason->value);
        $this->components->twoColumnDetail('Tokens', sprintf(
            '%d in / %d out',
            $response->usage->inputTokens,
            $response->usage->outputTokens,
        ));
        $this->components->twoColumnDetail('Round trip', "{$elapsedMs} ms");
        $this->components->info("Provider [{$key}] answered.");

        return self::SUCCESS;
    }
}

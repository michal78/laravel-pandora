<?php

declare(strict_types=1);

namespace Pandora\Pandora\Usage;

use Illuminate\Support\Carbon;
use Pandora\Pandora\Core\Actor\ActorManager;
use Pandora\Pandora\Providers\Catalog\ModelCatalog;
use Pandora\Pandora\Providers\Data\UsageData;
use Pandora\Pandora\Runs\Run;

/**
 * Writes one row per model call.
 *
 * Per CALL, not per run: a run that failed over spent money at two providers,
 * and a single aggregated row would make that invisible in exactly the
 * situation where somebody is asking why the bill went up.
 */
final class UsageRecorder
{
    public function __construct(
        private readonly ModelCatalog $catalog,
        private readonly ActorManager $actors,
    ) {}

    public function record(
        Run $run,
        string $providerKey,
        string $modelKey,
        UsageData $usage,
    ): UsageRecord {
        $estimate = $this->catalog->estimate($providerKey, $modelKey, $usage);
        $actor = $this->actors->current();

        /** @var UsageRecord $record */
        $record = UsageRecord::query()->create([
            'tenant_id' => $run->tenant_id,
            'run_id' => $run->getKey(),
            'agent_id' => $run->agent_id,
            'conversation_id' => $run->conversation_id,
            'actor_type' => $actor?->type,
            'actor_id' => $actor?->id,
            'provider_key' => $providerKey,
            'model_key' => $modelKey,
            'input_tokens' => $usage->inputTokens,
            'output_tokens' => $usage->outputTokens,
            'cached_input_tokens' => $usage->cachedInputTokens,
            'cached_output_tokens' => $usage->cachedOutputTokens,
            'reasoning_tokens' => $usage->reasoningTokens,
            'total_tokens' => $usage->totalTokens(),
            'requests' => $usage->requests,
            'duration_ms' => $usage->durationMs,
            // Null, not zero, for an unpriced model. The two must never be
            // summed together into a total that looks authoritative.
            'cost_micro' => $estimate?->amountMicro,
            'currency' => $estimate?->currency ?? 'USD',
            'pricing_source' => $estimate?->source,
            'pricing_date' => $estimate?->pricedAt,
            'pricing_stale' => $estimate?->stale ?? false,
            'occurred_at' => Carbon::now(),
        ]);

        return $record;
    }
}

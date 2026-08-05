<?php

declare(strict_types=1);

namespace Pandora\Pandora\Automation\Webhooks;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Pandora\Pandora\Audit\AuditLogger;
use Pandora\Pandora\Automation\Automation;
use Pandora\Pandora\Automation\AutomationDispatcher;
use Pandora\Pandora\Automation\Enums\AutomationTrigger;
use Pandora\Pandora\Automation\WebhookDelivery;
use Pandora\Pandora\Exceptions\WebhookRejected;
use Pandora\Pandora\Support\Redactor;

/**
 * One inbound webhook delivery, from raw body to run.
 *
 * The order of checks is the order of cost, cheapest first:
 *
 *   size → automation exists → secret configured → signature → freshness
 *        → nonce → occurrence
 *
 * The nonce check comes LAST among the rejections and is a database insert
 * rather than a lookup, for the same reason the scheduler's claim is: a
 * check-then-act pair has a window, and behind a load balancer two processes
 * can be inside it simultaneously with neither seeing the other.
 *
 * Rejections are recorded. A stream of them is the earliest sign that a secret
 * was rotated on one side and not the other -- which otherwise presents, days
 * later, as "the integration stopped working" with nothing to look at.
 */
final class WebhookReceiver
{
    public function __construct(
        private readonly AutomationDispatcher $dispatcher,
        private readonly AuditLogger $audit,
        private readonly Redactor $redactor,
        private readonly Config $config,
    ) {}

    /**
     * @return array{run_id: string|null, occurrence_id: string, duplicate: bool}
     *
     * @throws WebhookRejected
     */
    public function receive(string $slug, string $body, ?string $signatureHeader, ?string $sourceIp = null): array
    {
        /** @var int $maxBytes */
        $maxBytes = $this->config->get('pandora.automation.webhooks.max_payload_bytes', 65536);

        if (strlen($body) > $maxBytes) {
            throw WebhookRejected::payloadTooLarge($maxBytes);
        }

        $automation = $this->automation($slug);
        $secret = $automation->webhook_secret;

        // An automation with no secret is not an endpoint. Answering 404
        // rather than "configure a secret" keeps a prober from learning which
        // slugs exist.
        if ($secret === null || $secret === '') {
            throw $this->reject($automation, null, WebhookRejected::notConfigured($slug), $sourceIp, $body);
        }

        $signature = WebhookSignature::parse($signatureHeader);

        /** @var int $tolerance */
        $tolerance = $this->config->get('pandora.automation.webhooks.tolerance_seconds', 300);

        try {
            $signature->verify($secret, $body, $tolerance);
        } catch (WebhookRejected $e) {
            throw $this->reject($automation, $signature->hash, $e, $sourceIp, $body);
        }

        $delivery = $this->recordDelivery($automation, $signature->hash, $sourceIp, $body);

        if ($delivery === null) {
            // The nonce was already there. Nothing further happens, and the
            // status code says so -- a legitimate integrator retrying after a
            // timeout should learn its first attempt landed.
            throw WebhookRejected::replay();
        }

        $occurrence = $this->dispatcher->dispatch(
            automation: $automation,
            occurrence: Carbon::now(),
            payload: $this->decode($body),
            // Bound to the delivery, not to the clock: two deliveries in the
            // same second are two different events, and an occurrence key
            // derived from time alone would silently merge them.
            idempotencyKey: 'webhook:'.$signature->hash,
        );

        $runId = $occurrence?->run_id;

        $delivery->forceFill(['run_id' => $runId])->save();

        return [
            'run_id' => $runId,
            'occurrence_id' => (string) ($occurrence?->getKey() ?? $delivery->getKey()),
            'duplicate' => $occurrence === null,
        ];
    }

    private function automation(string $slug): Automation
    {
        /** @var Automation|null $automation */
        $automation = Automation::query()
            ->where('slug', $slug)
            ->where('trigger_type', AutomationTrigger::Webhook->value)
            ->first();

        // 404 for missing, disabled and wrong-tenant alike. A caller learning
        // which of those applied is being handed an oracle, and none of the
        // three is actionable by anybody who is not already an operator.
        if ($automation === null || ! $automation->enabled) {
            throw WebhookRejected::notConfigured($slug);
        }

        return $automation;
    }

    /**
     * Insert the nonce, or discover it is already there.
     *
     * Null means replay. The unique index does the deciding, so two processes
     * behind a load balancer cannot both conclude they are the first.
     */
    private function recordDelivery(
        Automation $automation,
        string $signature,
        ?string $sourceIp,
        string $body,
    ): ?WebhookDelivery {
        try {
            /** @var WebhookDelivery $delivery */
            $delivery = WebhookDelivery::query()->create([
                'tenant_id' => $automation->tenant_id,
                'automation_id' => $automation->getKey(),
                'signature' => $signature,
                'status' => WebhookDelivery::ACCEPTED,
                'source_ip' => $sourceIp,
                'payload_bytes' => strlen($body),
                'payload' => $this->redactor->redact($this->decode($body)),
            ]);

            return $delivery;
        } catch (QueryException) {
            return null;
        }
    }

    /**
     * Record a rejection and hand the exception back to be thrown.
     *
     * Returning rather than throwing keeps the caller's `throw` visible at the
     * call site, so nothing reads as though a rejection might continue.
     */
    private function reject(
        Automation $automation,
        ?string $signature,
        WebhookRejected $e,
        ?string $sourceIp,
        string $body,
    ): WebhookRejected {
        // Best effort: a rejected delivery whose signature has been seen
        // before cannot be inserted twice, and losing the second record of an
        // attack is preferable to a 500 that tells the attacker they found an
        // edge.
        try {
            WebhookDelivery::query()->create([
                'tenant_id' => $automation->tenant_id,
                'automation_id' => $automation->getKey(),
                'signature' => $signature ?? 'unsigned:'.bin2hex(random_bytes(16)),
                'status' => WebhookDelivery::REJECTED,
                'reason' => $e->reason,
                'source_ip' => $sourceIp,
                'payload_bytes' => strlen($body),
                // No payload on a rejection. It failed authentication, so
                // nothing in it is trustworthy enough to store.
                'payload' => null,
            ]);
        } catch (QueryException) {
            // Already recorded.
        }

        $this->audit->record(
            action: 'webhook.rejected',
            targetType: 'automation',
            targetId: $automation->id,
            severity: 'warning',
            metadata: ['slug' => $automation->slug, 'reason' => $e->reason, 'ip' => $sourceIp],
        );

        return $e;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $body): array
    {
        $decoded = json_decode($body, true);

        // A body that is not a JSON object is kept as a single value rather
        // than discarded: plenty of senders post form data or plain text, and
        // the agent may still have something to say about it.
        return is_array($decoded) ? $decoded : ['body' => $body];
    }
}

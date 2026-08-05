<?php

declare(strict_types=1);

namespace Pandora\Pandora\Automation\Http;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pandora\Pandora\Automation\Webhooks\WebhookReceiver;
use Pandora\Pandora\Exceptions\WebhookRejected;

/**
 * The public edge of an automation.
 *
 * Registered outside the control center's middleware stack on purpose. An
 * inbound webhook has no session, no CSRF token and no authenticated user, and
 * asking it for any of those would only mean an integrator disabling
 * middleware until it worked. The signature IS the authentication.
 *
 * Responses are deliberately uninformative. A caller learning which of "wrong
 * secret", "stale timestamp" and "no such automation" applied is being handed
 * an oracle; the status code carries everything a legitimate integrator needs,
 * and the delivery history carries the rest for whoever owns the automation.
 */
final class WebhookController
{
    public function __invoke(
        Request $request,
        string $automation,
        WebhookReceiver $receiver,
        Config $config,
    ): JsonResponse {
        if ($config->get('pandora.automation.enabled', true) !== true
            || $config->get('pandora.automation.webhooks.enabled', true) !== true) {
            return new JsonResponse(['message' => 'Not found.'], 404);
        }

        /** @var string $header */
        $header = $config->get('pandora.automation.webhooks.signature_header', 'X-Pandora-Signature');

        try {
            $result = $receiver->receive(
                slug: $automation,
                // The RAW body. Re-encoding Laravel's parsed input would
                // change key order and whitespace, and the signature would
                // never match anything.
                body: $request->getContent(),
                signatureHeader: $request->header($header),
                sourceIp: $request->ip(),
            );
        } catch (WebhookRejected $e) {
            return new JsonResponse(['message' => $e->userMessage()], $e->status);
        }

        // 202, not 200: the run is queued, not finished. A sender that treats
        // 200 as "the work is done" would be wrong, and this is the status
        // code that says so.
        return new JsonResponse([
            'accepted' => true,
            'run_id' => $result['run_id'],
        ], 202);
    }
}

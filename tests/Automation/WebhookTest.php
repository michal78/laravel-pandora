<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Pandora\Pandora\Audit\AuditLog;
use Pandora\Pandora\Automation\Enums\AutomationTrigger;
use Pandora\Pandora\Automation\WebhookDelivery;
use Pandora\Pandora\Automation\Webhooks\WebhookSignature;
use Pandora\Pandora\Runs\Enums\TriggerType;
use Pandora\Pandora\Runs\Run;
use Pandora\Pandora\Tests\Fixtures\AutomationFactory;

/**
 * Phase 4, criteria 21, 22 and 23 -- the public edge.
 *
 * This is the only route in Pandora an unauthenticated stranger can reach, so
 * it is the only one where every rejection is worth a test. Criterion 22 is
 * the one that matters: timestamp tolerance is a narrowing, not a defence, and
 * inside the window a captured request can be sent as often as anybody likes.
 */
const WEBHOOK_SECRET = 'shhh-this-is-the-secret';

beforeEach(function (): void {
    $this->automation = AutomationFactory::make([
        'slug' => 'inbound',
        'trigger_type' => AutomationTrigger::Webhook->value,
        'cron_expression' => null,
        'webhook_secret' => WEBHOOK_SECRET,
    ]);
});

function postWebhook(string $body, ?string $signature, string $slug = 'inbound'): TestResponse
{
    return test()->call(
        'POST',
        "/pandora/webhooks/{$slug}",
        server: $signature === null ? [] : ['HTTP_X_PANDORA_SIGNATURE' => $signature],
        content: $body,
    );
}

// ---------------------------------------------------------------- criterion 21

it('creates a run and answers 202 for a correctly signed delivery', function (): void {
    $body = json_encode(['order' => 'ORD-9']);

    $response = postWebhook($body, WebhookSignature::sign(WEBHOOK_SECRET, $body));

    // 202, not 200: the run is queued, not finished. A sender treating 200 as
    // "the work is done" would be wrong.
    $response->assertStatus(202)->assertJson(['accepted' => true]);

    /** @var Run $run */
    $run = Run::query()->firstOrFail();

    expect($run->trigger_type)->toBe(TriggerType::Webhook)
        ->and($run->automation_id)->toBe($this->automation->getKey())
        ->and($response->json('run_id'))->toBe((string) $run->getKey());
});

it('passes the body to the agent as context', function (): void {
    $body = json_encode(['order' => 'ORD-9']);

    postWebhook($body, WebhookSignature::sign(WEBHOOK_SECRET, $body));

    expect(Run::query()->firstOrFail()->metadata['context']['payload'])
        ->toBe(['order' => 'ORD-9']);
});

it('records the accepted delivery against the automation', function (): void {
    $body = json_encode(['order' => 'ORD-9']);

    postWebhook($body, WebhookSignature::sign(WEBHOOK_SECRET, $body));

    /** @var WebhookDelivery $delivery */
    $delivery = WebhookDelivery::query()->firstOrFail();

    expect($delivery->status)->toBe(WebhookDelivery::ACCEPTED)
        ->and($delivery->run_id)->not->toBeNull()
        ->and($delivery->payload_bytes)->toBe(strlen($body));
});

it('redacts a secret-shaped value out of the stored payload', function (): void {
    // A sender that puts a token in its payload should not have it sitting in
    // Pandora's delivery history forever.
    $body = json_encode(['api_key' => 'sk-live-1234567890', 'order' => 'ORD-9']);

    postWebhook($body, WebhookSignature::sign(WEBHOOK_SECRET, $body));

    /** @var WebhookDelivery $delivery */
    $delivery = WebhookDelivery::query()->firstOrFail();

    expect($delivery->payload['api_key'])->not->toBe('sk-live-1234567890')
        ->and($delivery->payload['order'])->toBe('ORD-9');
});

// ---------------------------------------------------------------- criterion 22

it('rejects a replayed delivery and creates no second run', function (): void {
    $body = json_encode(['order' => 'ORD-9']);
    $signature = WebhookSignature::sign(WEBHOOK_SECRET, $body);

    postWebhook($body, $signature)->assertStatus(202);

    // Byte-identical, well inside the timestamp tolerance -- exactly what an
    // attacker with a captured request has, and exactly what a sender with an
    // over-eager retry sends.
    postWebhook($body, $signature)->assertStatus(409);

    expect(Run::query()->count())->toBe(1)
        ->and(WebhookDelivery::query()->count())->toBe(1);
});

it('counts a replay on the delivery it duplicates, and audits it', function (): void {
    // Replay protection is a unique insert, so the duplicate cannot be its own
    // row. Without counting it here a 409 leaves no evidence anywhere -- the
    // only rejection with none -- and a sender with broken retry logic stays
    // invisible until somebody wonders about the bill.
    $body = json_encode(['order' => 'ORD-9']);
    $signature = WebhookSignature::sign(WEBHOOK_SECRET, $body);

    postWebhook($body, $signature)->assertStatus(202);
    postWebhook($body, $signature)->assertStatus(409);
    postWebhook($body, $signature)->assertStatus(409);

    /** @var WebhookDelivery $delivery */
    $delivery = WebhookDelivery::query()->firstOrFail();

    expect(WebhookDelivery::query()->count())->toBe(1)
        ->and($delivery->status)->toBe(WebhookDelivery::ACCEPTED)
        ->and($delivery->replay_count)->toBe(2)
        ->and($delivery->last_replayed_at)->not->toBeNull();

    expect(AuditLog::query()->where('action', 'webhook.rejected')->count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'webhook.rejected')->value('metadata'))
        ->not->toBeNull();
});

it('leaves replay_count alone on a delivery nobody repeated', function (): void {
    $body = json_encode(['order' => 'ORD-9']);

    postWebhook($body, WebhookSignature::sign(WEBHOOK_SECRET, $body))->assertStatus(202);

    expect(WebhookDelivery::query()->firstOrFail()->replay_count)->toBe(0);
});

it('accepts a second delivery with its own signature', function (): void {
    // The guard has to be tight enough to stop a replay and loose enough that
    // two genuine deliveries close together both land.
    //
    // The clock is read ONCE. Reading it per request looks equivalent and is
    // not: `now - 1` evaluated a second later is the same instant as the first
    // request's `now`, which makes the same signature, which is a real replay
    // and a correct 409. That version passed locally for as long as both calls
    // fell inside one second and failed on a slow runner -- the exact shape of
    // a test that flakes forever and gets re-run rather than read.
    $body = json_encode(['order' => 'ORD-9']);
    $at = Carbon::now()->getTimestamp();

    postWebhook($body, WebhookSignature::sign(WEBHOOK_SECRET, $body, $at))->assertStatus(202);
    postWebhook($body, WebhookSignature::sign(WEBHOOK_SECRET, $body, $at - 5))->assertStatus(202);

    expect(Run::query()->count())->toBe(2);
});

// ---------------------------------------------------------------- criterion 23

it('rejects a wrong signature', function (): void {
    $body = json_encode(['order' => 'ORD-9']);

    postWebhook($body, WebhookSignature::sign('the-wrong-secret', $body))->assertStatus(401);

    expect(Run::query()->count())->toBe(0)
        ->and(WebhookDelivery::query()->firstOrFail()->status)->toBe(WebhookDelivery::REJECTED);
});

it('rejects a body that was changed after signing', function (): void {
    $signature = WebhookSignature::sign(WEBHOOK_SECRET, json_encode(['amount' => 1]));

    postWebhook(json_encode(['amount' => 1000000]), $signature)->assertStatus(401);

    expect(Run::query()->count())->toBe(0);
});

it('rejects an absent signature', function (): void {
    postWebhook(json_encode(['order' => 'ORD-9']), null)->assertStatus(401);

    expect(Run::query()->count())->toBe(0);
});

it('rejects a malformed signature header', function (): void {
    postWebhook(json_encode([]), 'not-a-signature')->assertStatus(401);

    expect(Run::query()->count())->toBe(0);
});

it('rejects a stale timestamp', function (): void {
    config()->set('pandora.automation.webhooks.tolerance_seconds', 60);

    $body = json_encode(['order' => 'ORD-9']);
    $stale = WebhookSignature::sign(WEBHOOK_SECRET, $body, Carbon::now()->getTimestamp() - 600);

    postWebhook($body, $stale)->assertStatus(401);

    expect(Run::query()->count())->toBe(0);
});

it('rejects a timestamp from the future beyond tolerance', function (): void {
    // Signing with tomorrow's clock is how somebody keeps a captured request
    // valid indefinitely.
    config()->set('pandora.automation.webhooks.tolerance_seconds', 60);

    $body = json_encode([]);
    $future = WebhookSignature::sign(WEBHOOK_SECRET, $body, Carbon::now()->getTimestamp() + 86400);

    postWebhook($body, $future)->assertStatus(401);
});

it('rejects a delivery to a disabled automation', function (): void {
    $this->automation->forceFill(['enabled' => false])->save();

    $body = json_encode([]);

    // 404, the same answer a slug that never existed gets. A prober must not
    // learn which slugs are real.
    postWebhook($body, WebhookSignature::sign(WEBHOOK_SECRET, $body))->assertStatus(404);

    expect(Run::query()->count())->toBe(0);
});

it('rejects a delivery to an automation with no secret', function (): void {
    $bare = AutomationFactory::make([
        'slug' => 'no-secret',
        'trigger_type' => AutomationTrigger::Webhook->value,
        'cron_expression' => null,
    ]);

    $body = json_encode([]);

    postWebhook($body, WebhookSignature::sign(WEBHOOK_SECRET, $body), 'no-secret')->assertStatus(404);

    expect($bare->refresh()->enabled)->toBeTrue()
        ->and(Run::query()->count())->toBe(0);
});

it('rejects a delivery to a slug that is not a webhook automation', function (): void {
    AutomationFactory::make(['slug' => 'scheduled-one']);

    $body = json_encode([]);

    postWebhook($body, WebhookSignature::sign(WEBHOOK_SECRET, $body), 'scheduled-one')->assertStatus(404);
});

it('rejects a payload over the configured size limit before parsing it', function (): void {
    config()->set('pandora.automation.webhooks.max_payload_bytes', 64);

    $body = json_encode(['pad' => str_repeat('x', 500)]);

    postWebhook($body, WebhookSignature::sign(WEBHOOK_SECRET, $body))->assertStatus(413);

    expect(Run::query()->count())->toBe(0);
});

it('answers 404 when webhooks are disabled for the deployment', function (): void {
    config()->set('pandora.automation.webhooks.enabled', false);

    $body = json_encode([]);

    postWebhook($body, WebhookSignature::sign(WEBHOOK_SECRET, $body))->assertStatus(404);
});

it('audits every rejection at warning severity', function (): void {
    postWebhook(json_encode([]), WebhookSignature::sign('wrong', json_encode([])));

    /** @var AuditLog $entry */
    $entry = AuditLog::query()->where('action', 'webhook.rejected')->firstOrFail();

    expect($entry->severity)->toBe('warning')
        ->and($entry->metadata['reason'])->toBe('bad_signature');
});

it('stores no payload on a rejected delivery', function (): void {
    // It failed authentication, so nothing in it is trustworthy enough to keep.
    $body = json_encode(['order' => 'ORD-9']);

    postWebhook($body, WebhookSignature::sign('wrong', $body));

    expect(WebhookDelivery::query()->firstOrFail()->payload)->toBeNull();
});

it('never puts the webhook secret in an array or JSON copy of the row', function (): void {
    // A Livewire property, a broadcast payload and an audit metadata blob are
    // all built from toArray(). One of them leaking the secret would make
    // every signature on the endpoint forgeable.
    expect($this->automation->toArray())->not->toHaveKey('webhook_secret')
        ->and(json_encode($this->automation))->not->toContain(WEBHOOK_SECRET);
});

it('signs and verifies a round trip', function (): void {
    $signature = WebhookSignature::parse(WebhookSignature::sign(WEBHOOK_SECRET, 'body'));

    $signature->verify(WEBHOOK_SECRET, 'body', 300);

    expect($signature->hash)->toHaveLength(64);
});

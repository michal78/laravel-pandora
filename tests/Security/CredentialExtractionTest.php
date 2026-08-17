<?php

declare(strict_types=1);

use Pandora\Tests\Support\MakesTools;
use Pandora\Tools\BuiltIn\ReadConfigTool;
use Pandora\Tools\ToolContext;
use Pandora\Tools\ToolInput;

/**
 * Phase 9, criterion 4 -- T4's last clause: a credential **cannot be extracted
 * by a prompt that asks for one.**
 *
 * The other clauses are covered thoroughly. `CredentialIsolationTest`,
 * `SecretLeakTest` and `SecretRedactionTest` between them prove a credential is
 * absent from the context, the step payloads, the queue, the broadcasts, the
 * logs, the API resources and an exception a host might log -- fourteen tests,
 * and the audit found nothing wrong with any of them.
 *
 * What none of them asks is whether a *tool* will simply hand one over when the
 * model asks nicely. That is the only path a prompt has, and it turns on
 * `read_config`, whose whole job is publishing configuration to a model.
 *
 * Its design was already careful: an exact-match allowlist, never a prefix,
 * because `services.*` would publish every third-party credential in the
 * application. But an exact allowlist is still a person's judgement, and
 * `services.stripe.secret` is an exact key somebody could reasonably add while
 * wiring up a tool. One line in a config file, and a live credential is a tool
 * result -- read by a model that may be relaying an attacker's instructions,
 * and stored in an execution row whose redactor will not catch it, because the
 * key it is filed under is `value`.
 *
 * So the tool now refuses credential-shaped keys **even when allowlisted**. T4
 * says a credential is never in context; an allowlist entry must not be able to
 * make that false.
 */
uses(MakesTools::class);

function readConfig(string $key): string
{
    $tool = new ReadConfigTool;

    /** @var ToolContext $context */
    $context = test()->toolContext();

    return $tool->handle(new ToolInput(['key' => $key]), $context)->content;
}

beforeEach(function (): void {
    config()->set('pandora.tools.readable_config', [
        'app.name',
        // The mistake this guards against, made on purpose.
        'services.stripe.secret',
        'pandora.providers.openai.api_key',
        'app.cipher_password',
    ]);

    config()->set('services.stripe.secret', 'sk_live_51H8xExtractMePlease');
    config()->set('pandora.providers.openai.api_key', 'sk-proj-abcdef1234567890');
    config()->set('app.cipher_password', 'hunter2-the-real-one');
});

it('still reads an ordinary published key', function (): void {
    // The tool has to keep working, or the fix is just a broken feature.
    expect(readConfig('app.name'))->toContain('app.name = ');
});

it('refuses an allowlisted key that names a secret', function (): void {
    $result = readConfig('services.stripe.secret');

    expect($result)->toContain('looks like a credential')
        ->and($result)->not->toContain('sk_live_51H8xExtractMePlease');
});

it('refuses an allowlisted provider api key', function (): void {
    // The exact shape T4 names: the provider credential this package holds.
    $result = readConfig('pandora.providers.openai.api_key');

    expect($result)->not->toContain('sk-proj-abcdef1234567890')
        ->and($result)->toContain('never readable');
});

it('refuses a key whose secret-ness is only in the middle of a segment', function (): void {
    // `cipher_password` is not `password`, and substring matching is why it is
    // still refused. Exact-segment matching would have published this one.
    expect(readConfig('app.cipher_password'))->not->toContain('hunter2-the-real-one');
});

it('names the config line an operator has to remove', function (): void {
    // A refusal that does not say how to fix the misconfiguration leaves the
    // misconfiguration in place. The operator gets the key to delete.
    expect(readConfig('services.stripe.secret'))
        ->toContain('pandora.tools.readable_config');
});

it('does not tell the model the value exists at all', function (): void {
    // The refusal goes to the model, so it must not become an oracle: "that
    // key is a credential" confirms the deployment has one and names where it
    // lives. It says the key is unreadable and nothing about what is behind it.
    $result = readConfig('services.stripe.secret');

    expect($result)->not->toContain('sk_live')
        ->and(strlen($result))->toBeLessThan(300);
});

it('still refuses when the redaction list has been narrowed to nothing', function (): void {
    // This assertion used to say the opposite, and the opposite was wrong.
    //
    // The first version derived the refusal entirely from
    // `pandora.security.redact_keys`, reasoning that one list cannot drift
    // from another. But that list is tuned for OUTPUT NOISE: an operator
    // dropping `session` because it clutters every trace is making a
    // reasonable call about logs, and they would have been silently re-opening
    // credential reads in a tool. A change made for one purpose weakened
    // another, at a distance, invisibly -- and the test baked that in as
    // intended behaviour, which is how an insecure fallback becomes a
    // requirement nobody meant to write. Raised in review on PR #3.
    config()->set('pandora.security.redact_keys', []);

    expect(readConfig('services.stripe.secret'))
        ->not->toContain('sk_live_51H8xExtractMePlease')
        ->and(readConfig('pandora.providers.openai.api_key'))
        ->not->toContain('sk-proj-abcdef1234567890');
});

it('lets a deployment add to the refusal but never subtract from it', function (): void {
    // Additive, so a host with its own naming convention is covered.
    config()->set('pandora.tools.readable_config', ['app.house_style_handle']);
    config()->set('pandora.security.redact_keys', ['house_style']);
    config()->set('app.house_style_handle', 'not-really-a-secret-but-treated-as-one');

    expect(readConfig('app.house_style_handle'))->toContain('looks like a credential');
});

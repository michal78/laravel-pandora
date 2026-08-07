<?php

declare(strict_types=1);

use Pandora\Audit\AuditLog;
use Pandora\Providers\Credentials\CredentialManager;
use Pandora\Providers\Credentials\ProviderCredential;

/**
 * Phase 3 acceptance criterion 33 -- rotation with a grace window.
 */
function rotator(): CredentialManager
{
    return app(CredentialManager::class);
}

it('makes the new credential live immediately', function (): void {
    rotator()->issue('openai', 'sk-old');
    rotator()->rotate('openai', 'sk-new');

    expect(rotator()->resolve('openai')?->secret())->toBe('sk-new');
});

it('keeps the superseded credential usable for its grace window', function (): void {
    config()->set('pandora.providers.credentials.rotation_grace_minutes', 30);

    $old = rotator()->issue('openai', 'sk-old');
    rotator()->rotate('openai', 'sk-new');

    $old->refresh();

    expect($old->expires_at)->not->toBeNull()
        ->and($old->isUsable())->toBeTrue()
        ->and($old->isUsable(now()->addMinutes(31)))->toBeFalse();
});

it('stops honouring the superseded credential once the window passes', function (): void {
    config()->set('pandora.providers.credentials.rotation_grace_minutes', 30);

    rotator()->issue('openai', 'sk-old');
    rotator()->rotate('openai', 'sk-new');

    // Wind time forward past the window and revoke the new one; the old key
    // must NOT come back as the answer.
    $this->travel(31)->minutes();

    rotator()->revoke(ProviderCredential::query()->where('version', 2)->firstOrFail());

    expect(rotator()->resolve('openai'))->toBeNull();
});

it('does not extend the first key\'s life when a second rotation follows', function (): void {
    config()->set('pandora.providers.credentials.rotation_grace_minutes', 60);

    $first = rotator()->issue('openai', 'sk-1');
    rotator()->rotate('openai', 'sk-2');

    $firstExpiry = $first->refresh()->expires_at;

    $this->travel(10)->minutes();
    rotator()->rotate('openai', 'sk-3');

    expect($first->refresh()->expires_at?->timestamp)->toBe($firstExpiry?->timestamp);
});

it('increments the version on each rotation', function (): void {
    rotator()->issue('openai', 'sk-1');
    rotator()->rotate('openai', 'sk-2');
    rotator()->rotate('openai', 'sk-3');

    expect(rotator()->resolve('openai')?->version)->toBe(3);
});

it('revokes immediately, with no grace window', function (): void {
    $credential = rotator()->issue('openai', 'sk-leaked');

    rotator()->revoke($credential);

    expect(rotator()->resolve('openai'))->toBeNull();
});

it('audits creation, rotation and revocation without recording the secret', function (): void {
    $credential = rotator()->issue('openai', 'sk-audited-value');
    rotator()->rotate('openai', 'sk-audited-replacement');
    rotator()->revoke($credential->refresh());

    $logs = AuditLog::query()->pluck('action')->all();

    expect($logs)->toContain('credential.created')
        ->and($logs)->toContain('credential.rotated')
        ->and($logs)->toContain('credential.revoked');

    $serialized = (string) json_encode(AuditLog::query()->get()->toArray());

    expect($serialized)->not->toContain('sk-audited-value')
        ->and($serialized)->not->toContain('sk-audited-replacement');
});

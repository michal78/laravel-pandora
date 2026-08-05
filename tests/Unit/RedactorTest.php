<?php

declare(strict_types=1);

use Pandora\Pandora\Support\Redactor;

/** Acceptance guarantee 18 -- secret redaction. */
beforeEach(function (): void {
    $this->redactor = new Redactor(
        ['password', 'secret', 'token', 'api_key', 'authorization'],
        '[redacted]',
    );
});

it('redacts sensitive keys at any depth', function (): void {
    $result = $this->redactor->redact([
        'model' => 'gpt-4o',
        'api_key' => 'sk-live-abcdef1234567890',
        'nested' => [
            'authorization' => 'Bearer abc123',
            'safe' => 'keep me',
            'deeper' => ['password' => 'hunter2'],
        ],
    ]);

    expect($result['model'])->toBe('gpt-4o')
        ->and($result['api_key'])->toBe('[redacted]')
        ->and($result['nested']['authorization'])->toBe('[redacted]')
        ->and($result['nested']['safe'])->toBe('keep me')
        ->and($result['nested']['deeper']['password'])->toBe('[redacted]');
});

it('matches sensitive keys case-insensitively and as substrings', function (): void {
    $result = $this->redactor->redact([
        'API_KEY' => 'x', 'openai_api_key' => 'y', 'userToken' => 'z', 'Secret-Value' => 'w',
    ]);

    expect(array_values($result))->each->toBe('[redacted]');
});

it('catches credential-shaped values in free text', function (): void {
    $text = 'Request failed with key sk-proj-abcdefghijklmnop1234 and header Bearer eyJhbGciOiJIUzI1NiJ9';

    $result = $this->redactor->redactText($text);

    expect($result)->not->toContain('sk-proj-abcdefghijklmnop1234')
        ->and($result)->toContain('[redacted]');
});

it('leaves ordinary values untouched', function (): void {
    $payload = ['count' => 42, 'enabled' => true, 'name' => 'Support', 'items' => ['a', 'b']];

    expect($this->redactor->redact($payload))->toBe($payload);
});

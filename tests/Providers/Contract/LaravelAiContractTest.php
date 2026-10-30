<?php

declare(strict_types=1);

use Pandora\Testing\ProviderContractTests;
use Pandora\Tests\Providers\Contract\AnthropicFixtures;
use Pandora\Tests\Providers\Contract\LaravelAiFixtures;
use Pandora\Tests\Providers\Contract\OpenAiCompatibleFixtures;

/**
 * The `laravel-ai` adapter against the shared contract, once for each dialect
 * Pandora already speaks natively whose wire format the SDK also speaks, and
 * against the native adapters' own recorded fixtures. If the SDK translates a
 * vendor's wire format differently from the native adapter, it shows up here
 * as a failure against fixtures nobody wrote to suit the SDK.
 */
describe('through the anthropic driver', function (): void {
    ProviderContractTests::for(new LaravelAiFixtures(
        new AnthropicFixtures,
        driver: 'anthropic',
        baseUrl: 'https://api.anthropic.test/v1',
    ));
});

describe('through the openai-compatible driver', function (): void {
    ProviderContractTests::for(new LaravelAiFixtures(
        new OpenAiCompatibleFixtures,
        driver: 'openai-compatible',
        baseUrl: 'https://api.openai.test/v1',
    ));
});

// No gemini leg, deliberately. The SDK's gemini driver speaks Google's
// Interactions API (`/interactions`), not the `generateContent` dialect the
// native adapter and its fixtures describe, so those fixtures cannot hold it
// to anything. Writing a second set to suit the SDK would be the fake that
// agrees with the code it fakes for. The native `gemini` adapter is the
// supported path to Gemini; see docs/guides/providers.md.

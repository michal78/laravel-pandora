<?php

declare(strict_types=1);

use Pandora\Testing\ProviderContractTests;
use Pandora\Tests\Providers\Contract\OpenRouterFixtures;

/**
 * Phase 3 acceptance criterion 1 -- OpenRouter, through the OpenAI-compatible
 * adapter, with its own error body.
 */
ProviderContractTests::for(new OpenRouterFixtures);

<?php

declare(strict_types=1);

use Pandora\Pandora\Testing\ProviderContractTests;
use Pandora\Pandora\Tests\Providers\Contract\OllamaFixtures;

/**
 * Phase 3 acceptance criterion 1 -- Ollama, through the OpenAI-compatible
 * adapter. Running the whole suite against it is what makes "compatible" a
 * claim rather than a hope.
 */
ProviderContractTests::for(new OllamaFixtures);

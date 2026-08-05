<?php

declare(strict_types=1);

use Pandora\Pandora\Testing\ProviderContractTests;
use Pandora\Pandora\Tests\Providers\Contract\OpenAiCompatibleFixtures;

/**
 * Phase 3 acceptance criterion 1, for the workhorse adapter.
 *
 * Everything asserted here is asserted identically for every other adapter.
 * That is the point: an adapter is done when this passes, not when somebody
 * has tried it by hand against one model.
 */
ProviderContractTests::for(new OpenAiCompatibleFixtures);

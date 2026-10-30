<?php

declare(strict_types=1);

namespace Pandora\Tests\Fixtures\Discovery\Agents\Nested;

use Pandora\Agents\AgentBlueprint;
use Pandora\Contracts\AgentDefinition;

final class BillingAgent implements AgentDefinition
{
    public function define(AgentBlueprint $agent): AgentBlueprint
    {
        return $agent->name('Billing')->instructions('Answer billing questions.')->model('fake', 'fake-model');
    }
}

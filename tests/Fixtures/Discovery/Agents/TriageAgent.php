<?php

declare(strict_types=1);

namespace Pandora\Tests\Fixtures\Discovery\Agents;

use Pandora\Agents\AgentBlueprint;
use Pandora\Contracts\AgentDefinition;

final class TriageAgent implements AgentDefinition
{
    public function define(AgentBlueprint $agent): AgentBlueprint
    {
        return $agent->name('Triage')->instructions('Sort incoming requests.')->model('fake', 'fake-model');
    }
}

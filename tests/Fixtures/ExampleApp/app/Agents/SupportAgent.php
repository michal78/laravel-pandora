<?php // app/Agents/SupportAgent.php

namespace App\Agents;

use Pandora\Agents\AgentBlueprint;
use Pandora\Contracts\AgentDefinition;

final class SupportAgent implements AgentDefinition
{
    public function define(AgentBlueprint $agent): AgentBlueprint
    {
        return $agent
            ->name('Support')
            ->instructions('Help customers resolve support issues.')
            ->tools(['lookup_order']);
    }
}

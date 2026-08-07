<?php

declare(strict_types=1);

namespace Pandora\Tests\Fixtures;

use Pandora\Agents\AgentBlueprint;
use Pandora\Contracts\AgentDefinition;

/**
 * The example class-based agent, also used as the quick-start example in the
 * README.
 */
final class EchoAgent implements AgentDefinition
{
    public function define(AgentBlueprint $agent): AgentBlueprint
    {
        return $agent
            ->name('Echo')
            ->description('A minimal agent used to verify a Pandora installation.')
            ->instructions('You are a helpful assistant. Answer concisely.')
            ->model('fake', 'fake-model')
            ->maxIterations(3);
    }
}

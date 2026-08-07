<?php

declare(strict_types=1);

namespace Pandora\Tests\Fixtures;

use Pandora\Agents\Agent;
use Pandora\Agents\AgentRegistry;
use Pandora\Runs\Enums\AutonomyLevel;

/**
 * The two kinds of agent, built the way production builds them.
 *
 * Shared by the index and detail test files, which both need one of each: the
 * whole point of the Agents page is that it serves two kinds of row with
 * different rules, so no test of it can use only one.
 */
final class AgentFactory
{
    /**
     * @param array<string, mixed> $attributes
     */
    public static function database(array $attributes = []): Agent
    {
        /** @var Agent $agent */
        $agent = Agent::query()->create(array_merge([
            'name' => 'Support',
            'slug' => 'support',
            'description' => 'Answers customer questions.',
            'enabled' => true,
            'default_provider' => 'fake',
            'default_model' => 'fake-model',
            'autonomy_level' => AutonomyLevel::Suggest->value,
        ], $attributes));

        return $agent;
    }

    /**
     * The Echo fixture, synced through the registry exactly as a deploy would.
     */
    public static function classDefined(): Agent
    {
        $registry = app(AgentRegistry::class);
        $registry->define(EchoAgent::class);

        return $registry->get('echo');
    }
}

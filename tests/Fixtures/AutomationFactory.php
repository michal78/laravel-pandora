<?php

declare(strict_types=1);

namespace Pandora\Pandora\Tests\Fixtures;

use Pandora\Pandora\Agents\Agent;
use Pandora\Pandora\Automation\Automation;
use Pandora\Pandora\Automation\Enums\AutomationTrigger;
use Pandora\Pandora\Runs\Enums\AutonomyLevel;

/**
 * Automations, built the way the editor builds them.
 *
 * Every automation needs an agent, and most tests do not care which -- so this
 * makes one unless handed one. The agent defaults to `act_within_policy`
 * because the interesting clamp tests are the ones where the AUTOMATION is the
 * narrower of the two, and an agent that was already restrictive would hide a
 * missing clamp by accident.
 */
final class AutomationFactory
{
    /**
     * @param array<string, mixed> $attributes
     */
    public static function make(array $attributes = [], ?Agent $agent = null): Automation
    {
        $agent ??= AgentFactory::database([
            'slug' => 'automation-agent-'.substr(uniqid(), -6),
            'autonomy_level' => AutonomyLevel::ActWithinPolicy->value,
        ]);

        /** @var Automation $automation */
        $automation = Automation::query()->create(array_merge([
            'agent_id' => $agent->getKey(),
            'name' => 'Nightly report',
            'slug' => 'nightly-report',
            'trigger_type' => AutomationTrigger::Cron->value,
            'cron_expression' => '0 9 * * *',
            'timezone' => 'UTC',
            'prompt' => 'Summarise yesterday.',
            'autonomy_level' => AutonomyLevel::ObserveOnly->value,
            'enabled' => true,
        ], $attributes));

        return $automation;
    }

    /**
     * Due right now, which is what most scheduler tests want to arrange
     * without also arranging a plausible cron expression.
     *
     * @param array<string, mixed> $attributes
     */
    public static function due(array $attributes = [], ?Agent $agent = null): Automation
    {
        $automation = self::make($attributes, $agent);

        $automation->forceFill(['next_run_at' => now()->subSeconds(5)])->save();

        return $automation;
    }
}

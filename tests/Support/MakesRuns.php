<?php

declare(strict_types=1);

namespace Pandora\Tests\Support;

use Pandora\Agents\Agent;
use Pandora\Conversations\Conversation;
use Pandora\Conversations\Session;
use Pandora\Runs\Enums\RunState;
use Pandora\Runs\Enums\TriggerType;
use Pandora\Runs\Run;
use Symfony\Component\Uid\Ulid;

/**
 * Builders for the entities most tests need.
 *
 * Deliberately explicit rather than factory-driven: these tests assert on
 * exact tenant and session values, and a factory that invented them would hide
 * the very thing under test.
 */
trait MakesRuns
{
    public function makeAgent(array $attributes = []): Agent
    {
        /** @var Agent $agent */
        $agent = Agent::query()->create(array_merge([
            'name' => 'Test Agent',
            'slug' => 'test-agent-'.strtolower((string) new Ulid),
            'enabled' => true,
            'role_instructions' => 'You are a test agent.',
            'default_provider' => 'fake',
            'default_model' => 'fake-model',
            'max_iterations' => 5,
            'max_tool_calls' => 10,
            'max_duration_seconds' => 600,
            'context_budget_tokens' => 8000,
        ], $attributes));

        return $agent;
    }

    public function makeConversation(?Agent $agent = null, array $attributes = []): Conversation
    {
        $agent ??= $this->makeAgent();

        /** @var Conversation $conversation */
        $conversation = Conversation::query()->create(array_merge([
            'agent_id' => $agent->getKey(),
            'channel' => 'web',
            'status' => 'active',
            'last_activity_at' => now(),
        ], $attributes));

        return $conversation;
    }

    public function makeSession(?Agent $agent = null, array $attributes = []): Session
    {
        $agent ??= $this->makeAgent();

        /** @var Session $session */
        $session = Session::query()->create(array_merge([
            'agent_id' => $agent->getKey(),
            'channel' => 'web',
            'origin' => 'web',
            'isolation_key' => hash('sha256', (string) new Ulid),
        ], $attributes));

        return $session;
    }

    public function makeRun(array $attributes = []): Run
    {
        $agent = isset($attributes['agent_id'])
            ? Agent::query()->findOrFail($attributes['agent_id'])
            : $this->makeAgent();

        $session = isset($attributes['session_id'])
            ? Session::query()->findOrFail($attributes['session_id'])
            : $this->makeSession($agent);

        /** @var Run $run */
        $run = Run::query()->create(array_merge([
            'agent_id' => $agent->getKey(),
            'session_id' => $session->getKey(),
            'state' => RunState::Pending->value,
            'trigger_type' => TriggerType::Application->value,
            'correlation_id' => (string) new Ulid,
            'deadline_at' => now()->addMinutes(10),
        ], $attributes));

        return $run;
    }
}

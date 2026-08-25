<?php

declare(strict_types=1);

namespace Pandora\Tests\Support\Concurrency\Scenarios;

use Pandora\Agents\Agent;
use Pandora\Agents\AgentRunner;
use Pandora\Conversations\Conversation;
use Pandora\Runs\Enums\RunState;
use Pandora\Runs\Run;
use Pandora\Tests\Support\Concurrency\Scenario;

/**
 * One worker executes one run, against an agent every other worker is also
 * using at that moment.
 *
 * Each worker owns its own conversation, because criterion 27 is about N runs
 * contending for one AGENT -- its lock discipline, its budgets, its step
 * writes -- rather than N writers to one conversation, which is a different
 * question with a different answer.
 */
final readonly class ExecuteRunForAgent implements Scenario
{
    public function __construct(private AgentRunner $agents) {}

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function run(array $payload): array
    {
        $agentId = (string) $payload['agent_id'];

        // Resolved to a model rather than passed as a string: `agent(string)`
        // means SLUG, and handing it a primary key fails with "no agent
        // registered" -- which reads like a registry problem rather than the
        // argument-type mistake it is.
        /** @var Agent $agent */
        $agent = Agent::query()->findOrFail($agentId);

        /** @var Conversation $conversation */
        $conversation = Conversation::query()->create([
            'agent_id' => $agentId,
            'channel' => 'web',
            'status' => 'active',
            'last_activity_at' => now(),
        ]);

        $startedAt = microtime(true);

        try {
            $run = $this->agents
                ->agent($agent)
                ->inConversation($conversation)
                ->run('Say something.');
        } catch (\Throwable $e) {
            // Returned rather than thrown: one worker failing is a RESULT that
            // the test should see and report precisely, not a harness error
            // that hides the other forty-nine.
            return [
                'completed' => false,
                'error' => $e->getMessage(),
                'class' => $e::class,
                'started_at' => $startedAt,
                'finished_at' => microtime(true),
            ];
        }

        $run->refresh();

        return [
            'completed' => $run->state === RunState::Completed,
            'state' => $run->state->value,
            'run_id' => (string) $run->getKey(),
            'output' => $run->output,
            // Counted here, in the worker, because the parent counting them
            // afterwards would not know which run each step belonged to
            // without trusting the very foreign keys under test.
            'step_count' => $run->steps()->count(),
            'started_at' => $startedAt,
            'finished_at' => microtime(true),
        ];
    }
}

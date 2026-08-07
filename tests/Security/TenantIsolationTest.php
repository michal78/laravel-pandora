<?php

declare(strict_types=1);

use Pandora\Agents\Agent;
use Pandora\Agents\AgentRunner;
use Pandora\Conversations\Conversation;
use Pandora\Messages\Message;
use Pandora\Runs\Run;
use Pandora\Runs\RunStep;
use Pandora\Tests\Support\MakesRuns;

uses(MakesRuns::class);

/**
 * Acceptance guarantee 15 -- tenant isolation.
 *
 * This is the guarantee that separates Pandora from the single-operator agent
 * daemons it takes its capability list from. If any of these fail, the whole
 * premise fails.
 */
it('stamps the current tenant on every record it creates', function (): void {
    $agent = inTenant('acme', fn () => $this->makeAgent());

    expect($agent->tenant_id)->toBe('acme');
});

it('hides another tenant\'s agents, conversations, runs, messages and steps', function (): void {
    $acme = inTenant('acme', function (): array {
        $agent = $this->makeAgent();
        $conversation = $this->makeConversation($agent);
        $run = $this->makeRun(['agent_id' => $agent->getKey(), 'conversation_id' => $conversation->getKey()]);

        Message::query()->create([
            'conversation_id' => $conversation->getKey(),
            'role' => 'user', 'type' => 'text', 'sequence' => 1, 'content' => 'acme secret',
        ]);

        RunStep::query()->create([
            'run_id' => $run->getKey(), 'sequence' => 1,
            'type' => 'model_request', 'status' => 'succeeded', 'started_at' => now(),
        ]);

        return compact('agent', 'conversation', 'run');
    });

    inTenant('globex', function () use ($acme): void {
        expect(Agent::query()->count())->toBe(0)
            ->and(Conversation::query()->count())->toBe(0)
            ->and(Run::query()->count())->toBe(0)
            ->and(Message::query()->count())->toBe(0)
            ->and(RunStep::query()->count())->toBe(0);

        // The leak that matters most: a direct lookup by a known id.
        expect(Agent::query()->find($acme['agent']->getKey()))->toBeNull()
            ->and(Conversation::query()->find($acme['conversation']->getKey()))->toBeNull()
            ->and(Run::query()->find($acme['run']->getKey()))->toBeNull();
    });
});

it('still sees its own records', function (): void {
    $agent = inTenant('acme', fn () => $this->makeAgent());

    inTenant('acme', function () use ($agent): void {
        expect(Agent::query()->find($agent->getKey()))->not->toBeNull();
    });
});

it('applies no scope for a single-tenant application', function (): void {
    $agent = $this->makeAgent();

    expect($agent->tenant_id)->toBeNull()
        ->and(Agent::query()->find($agent->getKey()))->not->toBeNull();
});

it('requires an explicit, greppable opt-out to cross tenants', function (): void {
    $agent = inTenant('acme', fn () => $this->makeAgent());

    inTenant('globex', function () use ($agent): void {
        expect(Agent::query()->find($agent->getKey()))->toBeNull()
            ->and(Agent::acrossAllTenants()->find($agent->getKey()))->not->toBeNull();
    });
});

it('keeps runs isolated across tenants when executed', function (): void {
    $this->fakeProvider()->willRespondWith('ok');

    inTenant('acme', function (): void {
        $conversation = $this->makeConversation();

        app(AgentRunner::class)
            ->agent($conversation->agent)
            ->inConversation($conversation)
            ->run('Hello');
    });

    inTenant('globex', function (): void {
        expect(Run::query()->count())->toBe(0)
            ->and(Message::query()->count())->toBe(0);
    });

    inTenant('acme', function (): void {
        expect(Run::query()->count())->toBe(1);
    });
});

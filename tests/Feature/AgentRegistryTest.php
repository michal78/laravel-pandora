<?php

declare(strict_types=1);

use Pandora\Agents\Agent;
use Pandora\Agents\AgentRegistry;
use Pandora\Exceptions\AgentNotFound;
use Pandora\Tests\Fixtures\EchoAgent;

/** Acceptance criterion 4 -- class and database agent definitions. */
it('synchronises a class definition into the database', function (): void {
    $registry = app(AgentRegistry::class)->define(EchoAgent::class);

    $agent = $registry->get('echo');

    expect($agent->slug)->toBe('echo')
        ->and($agent->name)->toBe('Echo')
        ->and($agent->role_instructions)->toBe('You are a helpful assistant. Answer concisely.')
        ->and($agent->default_provider)->toBe('fake')
        ->and($agent->max_iterations)->toBe(3)
        ->and($agent->isClassDefined())->toBeTrue()
        ->and(Agent::query()->where('slug', 'echo')->exists())->toBeTrue();
});

it('is idempotent -- syncing twice does not duplicate the agent', function (): void {
    $registry = app(AgentRegistry::class)->define(EchoAgent::class);

    $registry->get('echo');
    $registry->flush();
    $registry->get('echo');

    expect(Agent::query()->where('slug', 'echo')->count())->toBe(1);
});

it('lets a class definition win over a database edit to a managed field', function (): void {
    $registry = app(AgentRegistry::class)->define(EchoAgent::class);

    $agent = $registry->get('echo');
    $agent->forceFill(['role_instructions' => 'Hijacked instructions.'])->save();

    $registry->flush();

    // Version-controlled definitions are authoritative for the fields they set.
    expect($registry->get('echo')->role_instructions)
        ->toBe('You are a helpful assistant. Answer concisely.');
});

it('preserves operator edits to fields the class does not express', function (): void {
    $registry = app(AgentRegistry::class)->define(EchoAgent::class);

    $agent = $registry->get('echo');
    // EchoAgent sets no autonomy level, so an operator's choice must survive.
    $agent->forceFill(['autonomy_level' => 'observe_only'])->save();

    $registry->flush();

    expect($registry->get('echo')->autonomy_level->value)->toBe('observe_only');
});

it('resolves a database-only agent that has no class', function (): void {
    Agent::query()->create([
        'name' => 'Database Agent',
        'slug' => 'db-agent',
        'enabled' => true,
    ]);

    $agent = app(AgentRegistry::class)->get('db-agent');

    expect($agent->name)->toBe('Database Agent')
        ->and($agent->isClassDefined())->toBeFalse();
});

it('throws a clear exception for an unknown agent', function (): void {
    app(AgentRegistry::class)->get('does-not-exist');
})->throws(AgentNotFound::class, 'No agent registered with slug [does-not-exist].');

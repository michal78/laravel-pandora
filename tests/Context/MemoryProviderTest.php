<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Pandora\Context\ContextBuilder;
use Pandora\Context\ContextRequest;
use Pandora\Context\Providers\MemoryContextProvider;
use Pandora\Conversations\Session;
use Pandora\Core\Actor\ActorContext;
use Pandora\Memory\Enums\MemoryScope;
use Pandora\Memory\Enums\MemorySource;
use Pandora\Memory\Enums\MemoryStatus;
use Pandora\Memory\Enums\MemoryType;
use Pandora\Memory\MemoryItem;
use Pandora\Runs\Enums\RunState;
use Pandora\Runs\Run;
use Pandora\Tests\Fixtures\AgentFactory;

/**
 * Phase 5, criterion 20 -- memory reaches the prompt, and only the right
 * memory does.
 *
 * The provider takes no scope argument, which is the point: it runs on every
 * request precisely because there is no parameter on it that could be made to
 * widen what it sees.
 */
beforeEach(function (): void {
    $this->agent = AgentFactory::database(['slug' => 'rememberer']);
});

function memoryRequest(string $input, ?ActorContext $actor = null, int $budget = 8000): ContextRequest
{
    /** @var Session $session */
    $session = Session::query()->create([
        'agent_id' => test()->agent->getKey(),
        'actor_type' => $actor?->type,
        'actor_id' => $actor?->id,
        'channel' => 'web',
        'origin' => 'test',
        'isolation_key' => (string) Str::ulid(),
    ]);

    /** @var Run $run */
    $run = Run::query()->create([
        'agent_id' => test()->agent->getKey(),
        'session_id' => $session->getKey(),
        'state' => RunState::Running->value,
        'trigger_type' => 'user_message',
        'correlation_id' => (string) Str::ulid(),
        'input' => $input,
    ]);

    return new ContextRequest($run, test()->agent, $session, $budget);
}

/**
 * @param array<string, mixed> $attributes
 */
function tenantMemory(string $content, array $attributes = []): MemoryItem
{
    /** @var MemoryItem $item */
    $item = MemoryItem::query()->create(array_merge([
        'scope' => MemoryScope::Tenant->value,
        'scope_id' => null,
        'type' => MemoryType::UserFact->value,
        'content' => $content,
        'source' => MemorySource::User->value,
    ], $attributes));

    return $item;
}

it('contributes what it knows that bears on the question', function (): void {
    tenantMemory('Deploys go out on Thursday afternoons.');
    tenantMemory('The office cat is called Marmalade.');

    $section = app(MemoryContextProvider::class)->provide(
        memoryRequest('when do deploys happen?'),
    );

    expect($section)->not->toBeNull()
        ->and($section->key)->toBe('memory')
        ->and($section->messages[0]->content)->toContain('Thursday')
        ->and($section->messages[0]->content)->not->toContain('Marmalade');
});

it('says nothing when it knows nothing relevant', function (): void {
    tenantMemory('The office cat is called Marmalade.');

    expect(app(MemoryContextProvider::class)->provide(memoryRequest('deploy schedule')))
        ->toBeNull();
});

it('says nothing when the run carries no input to retrieve against', function (): void {
    tenantMemory('Deploys go out on Thursday afternoons.');

    // A retrieval built from an empty question matches by word frequency
    // rather than by relevance, and the agent starts volunteering unrelated
    // facts.
    expect(app(MemoryContextProvider::class)->provide(memoryRequest('')))->toBeNull();
});

it('attributes each memory to where it came from', function (): void {
    tenantMemory('Deploys go out on Thursday.', ['source' => MemorySource::Agent->value]);

    $section = app(MemoryContextProvider::class)->provide(memoryRequest('deploys'));

    // "you told me" and "I worked this out" are different claims and the model
    // should be able to hedge accordingly.
    expect($section->messages[0]->content)->toContain('Inferred by the agent');
});

it('never surfaces a memory awaiting review', function (): void {
    tenantMemory('Deploys go out on Thursday.', ['status' => MemoryStatus::Suggested->value]);

    expect(app(MemoryContextProvider::class)->provide(memoryRequest('deploys')))->toBeNull();
});

it('does not reach another user\'s memory from this session', function (): void {
    $user = $this->actingAsUser();

    MemoryItem::query()->create([
        'scope' => MemoryScope::User->value,
        'scope_id' => 'App\\Models\\Other#999',
        'type' => MemoryType::UserFact->value,
        'content' => 'The recovery phrase is orchid.',
        'source' => MemorySource::User->value,
    ]);

    $section = app(MemoryContextProvider::class)->provide(
        memoryRequest('what is the recovery phrase?', ActorContext::forUser($user)),
    );

    expect($section)->toBeNull();
});

it('appears on the trace as an included section', function (): void {
    tenantMemory('Deploys go out on Thursday afternoons.');

    $builder = new ContextBuilder(app(), [MemoryContextProvider::class]);
    $trace = $builder->build(memoryRequest('when do deploys happen?'))->toTrace();

    expect(array_column($trace['included'], 'key'))->toBe(['memory'])
        ->and($trace['estimated_tokens'])->toBeGreaterThan(0);
});

it('is dropped rather than truncated when it does not fit the budget', function (): void {
    tenantMemory(str_repeat('deploy thursday ', 200));

    $builder = new ContextBuilder(app(), [MemoryContextProvider::class]);
    $context = $builder->build(memoryRequest('deploy', budget: 20));

    expect($context->omitted)->toBe([['key' => 'memory', 'reason' => 'budget_exhausted']])
        ->and($context->messages)->toBe([]);
});

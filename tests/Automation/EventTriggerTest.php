<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Bus;
use Pandora\Pandora\Automation\Enums\AutomationTrigger;
use Pandora\Pandora\Automation\EventTriggerRegistry;
use Pandora\Pandora\Jobs\RunAutomation;
use Pandora\Pandora\Runs\Enums\AutonomyLevel;
use Pandora\Pandora\Runs\Enums\TriggerType;
use Pandora\Pandora\Runs\Run;
use Pandora\Pandora\Tests\Fixtures\AgentFactory;
use Pandora\Pandora\Tests\Fixtures\AutomationFactory;
use Pandora\Pandora\Tests\Fixtures\OrderShipped;

/**
 * Phase 4, criteria 18, 19 and 20 -- events that start runs.
 *
 * Two sources on purpose: code for what belongs in version control and gets
 * reviewed, database rows for what an operator adds at 3am. They behave
 * differently in one respect worth knowing -- a database automation gets the
 * whole occurrence machinery (condition, concurrency, budget, history), and a
 * code binding does not, because it has no row to record against.
 */
beforeEach(function (): void {
    $this->registry = app(EventTriggerRegistry::class);
    $this->events = app(Dispatcher::class);
});

// ---------------------------------------------------------------- criterion 18

it('creates a run when an event a code binding names fires', function (): void {
    $agent = AgentFactory::database(['slug' => 'logistics']);

    $this->registry->on(OrderShipped::class)->run('logistics');
    $this->registry->listen();

    $this->events->dispatch(new OrderShipped('ORD-9'));

    $run = Run::query()->where('agent_id', $agent->getKey())->first();

    expect($run)->not->toBeNull()
        ->and($run->trigger_type)->toBe(TriggerType::Event)
        ->and($run->actor_type)->toBe('system');
});

it('sends the agent only what the binding mapped, never the event object', function (): void {
    // An event object is application internals and frequently carries a whole
    // Eloquent model. Serialising one into a prompt is how a customer's
    // address reaches a model request nobody meant to send.
    AgentFactory::database(['slug' => 'logistics']);

    $this->registry->on(OrderShipped::class)
        ->map(static fn (OrderShipped $e): array => ['reference' => $e->reference])
        ->run('logistics');
    $this->registry->listen();

    $this->events->dispatch(new OrderShipped('ORD-9', international: true));

    /** @var Run $run */
    $run = Run::query()->firstOrFail();

    expect($run->metadata['context']['payload'])->toBe(['reference' => 'ORD-9'])
        // The `international` flag was not mapped, so the agent never sees it.
        ->and(json_encode($run->metadata))->not->toContain('international');
});

it('honours the binding\'s condition', function (): void {
    AgentFactory::database(['slug' => 'logistics']);

    $this->registry->on(OrderShipped::class)
        ->when(static fn (OrderShipped $e): bool => $e->international)
        ->run('logistics');
    $this->registry->listen();

    $this->events->dispatch(new OrderShipped('ORD-1', international: false));

    expect(Run::query()->count())->toBe(0);

    $this->events->dispatch(new OrderShipped('ORD-2', international: true));

    expect(Run::query()->count())->toBe(1);
});

it('clamps a code binding to the agent\'s autonomy', function (): void {
    // `Pandora::on()` must not be a way around an agent's own configuration.
    AgentFactory::database([
        'slug' => 'logistics',
        'autonomy_level' => AutonomyLevel::Suggest->value,
    ]);

    $this->registry->on(OrderShipped::class)
        ->autonomy(AutonomyLevel::ActWithinPolicy)
        ->run('logistics');
    $this->registry->listen();

    $this->events->dispatch(new OrderShipped);

    expect(Run::query()->firstOrFail()->autonomy_level)->toBe(AutonomyLevel::Suggest);
});

it('lets a binding ask for less than the agent has', function (): void {
    AgentFactory::database([
        'slug' => 'logistics',
        'autonomy_level' => AutonomyLevel::ActWithinPolicy->value,
    ]);

    $this->registry->on(OrderShipped::class)
        ->autonomy(AutonomyLevel::ObserveOnly)
        ->run('logistics');
    $this->registry->listen();

    $this->events->dispatch(new OrderShipped);

    expect(Run::query()->firstOrFail()->autonomy_level)->toBe(AutonomyLevel::ObserveOnly);
});

it('does not take down the transaction that dispatched the event', function (): void {
    // An order does not fail to ship because a reporting agent was renamed.
    $this->registry->on(OrderShipped::class)->run('agent-that-does-not-exist');
    $this->registry->listen();

    $this->events->dispatch(new OrderShipped);

    expect(Run::query()->count())->toBe(0);
});

it('skips a binding whose agent is disabled', function (): void {
    AgentFactory::database(['slug' => 'logistics', 'enabled' => false]);

    $this->registry->on(OrderShipped::class)->run('logistics');
    $this->registry->listen();

    $this->events->dispatch(new OrderShipped);

    expect(Run::query()->count())->toBe(0);
});

it('registers a binding declared after listeners were already attached', function (): void {
    // Hosts declare these in boot(), and boot order is not something a package
    // gets to insist on.
    AgentFactory::database(['slug' => 'logistics']);

    $this->registry->listen();
    $this->registry->on(OrderShipped::class)->run('logistics');

    $this->events->dispatch(new OrderShipped);

    expect(Run::query()->count())->toBe(1);
});

// ---------------------------------------------------------------- criterion 19

it('fires a database automation bound to an event class', function (): void {
    Bus::fake();

    $automation = AutomationFactory::make([
        'trigger_type' => AutomationTrigger::Event->value,
        'cron_expression' => null,
        'event_class' => OrderShipped::class,
    ]);

    $this->registry->listen();
    $this->events->dispatch(new OrderShipped);

    Bus::assertDispatched(
        RunAutomation::class,
        static fn (RunAutomation $job): bool => $job->automationId === $automation->getKey(),
    );
});

it('fires a database automation on that event and no other', function (): void {
    Bus::fake();

    AutomationFactory::make([
        'trigger_type' => AutomationTrigger::Event->value,
        'cron_expression' => null,
        'event_class' => OrderShipped::class,
    ]);

    $this->registry->listen();
    $this->events->dispatch(new stdClass);

    Bus::assertNotDispatched(RunAutomation::class);
});

it('ignores a disabled event automation', function (): void {
    Bus::fake();

    AutomationFactory::make([
        'trigger_type' => AutomationTrigger::Event->value,
        'cron_expression' => null,
        'event_class' => OrderShipped::class,
        'enabled' => false,
    ]);

    $this->registry->listen();
    $this->events->dispatch(new OrderShipped);

    Bus::assertNotDispatched(RunAutomation::class);
});

// ---------------------------------------------------------------- criterion 20

it('listens for nothing when nothing is bound', function (): void {
    // The alternative -- a wildcard listener on `*` -- would make Pandora a
    // tax on every event the host application dispatches, forever, including
    // all the ones it dispatches in a loop.
    expect($this->registry->eventClasses())->toBe([]);

    $this->registry->listen();

    expect($this->events->hasListeners(OrderShipped::class))->toBeFalse();
});

it('listens only for classes something actually names', function (): void {
    AgentFactory::database(['slug' => 'logistics']);

    $this->registry->on(OrderShipped::class)->run('logistics');
    $this->registry->listen();

    expect($this->events->hasListeners(OrderShipped::class))->toBeTrue()
        ->and($this->events->hasListeners('App\\Events\\SomethingElse'))->toBeFalse();
});

it('re-reads the event class list when an automation is saved', function (): void {
    // An operator who adds an event automation and finds it never fires would
    // reasonably conclude the feature is broken.
    expect($this->registry->eventClasses())->toBe([]);

    AutomationFactory::make([
        'trigger_type' => AutomationTrigger::Event->value,
        'cron_expression' => null,
        'event_class' => OrderShipped::class,
    ]);

    expect($this->registry->eventClasses())->toContain(OrderShipped::class);
});

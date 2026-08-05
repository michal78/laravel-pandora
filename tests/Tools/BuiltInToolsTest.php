<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Pandora\Pandora\Core\Actor\ActorContext;
use Pandora\Pandora\PandoraServiceProvider;
use Pandora\Pandora\Providers\Data\ToolCall;
use Pandora\Pandora\Tests\Fixtures\TestUser;
use Pandora\Pandora\Tests\Support\MakesTools;
use Pandora\Pandora\Tools\BuiltIn\BuiltInTools;
use Pandora\Pandora\Tools\BuiltIn\DispatchJobTool;
use Pandora\Pandora\Tools\BuiltIn\EmitEventTool;
use Pandora\Pandora\Tools\BuiltIn\InspectRunStatusTool;
use Pandora\Pandora\Tools\BuiltIn\QueryRecordsTool;
use Pandora\Pandora\Tools\BuiltIn\ReadConfigTool;
use Pandora\Pandora\Tools\BuiltIn\RequestApprovalTool;
use Pandora\Pandora\Tools\BuiltIn\SendNotificationTool;
use Pandora\Pandora\Tools\Enums\RiskLevel;
use Pandora\Pandora\Tools\Tool;
use Pandora\Pandora\Tools\ToolInput;
use Pandora\Pandora\Tools\ToolRegistry;
use Pandora\Pandora\Tools\ToolResult;

/**
 * Phase 2 acceptance criterion 28 — every built-in runs, and each refuses
 * what it is documented to refuse.
 *
 * The refusals are the interesting half. Each of these tools is an allowlist
 * over something the deployment configured, and an allowlist that fails open
 * is not one.
 */
uses(MakesTools::class);

function runTool(Tool $tool, array $arguments): ToolResult
{
    return $tool->handle(new ToolInput($arguments), test()->toolContext());
}

it('registers every built-in when the package boots', function (): void {
    app(ToolRegistry::class)->flush()->registerMany(BuiltInTools::all());

    expect(app(ToolRegistry::class)->names())->toContain(
        'ask_user',
        'request_approval',
        'inspect_run_status',
        'query_records',
        'read_config',
        'dispatch_job',
        'emit_event',
        'send_notification',
    );
});

it('installs the built-ins without granting them to any agent', function (): void {
    // The distinction that matters on a fresh installation: a catalogue
    // exists, and no agent can reach any of it.
    app(ToolRegistry::class)->flush()->registerMany(BuiltInTools::all());
    $this->agentAllows([]);

    expect($this->decide(new ToolCall(
        'call_1',
        'inspect_run_status',
        [],
    ))->isDenied())->toBeTrue();
});

it('can be switched off entirely', function (): void {
    config()->set('pandora.tools.builtin.enabled', false);
    app(ToolRegistry::class)->flush();

    (new PandoraServiceProvider(app()))->boot();

    expect(app(ToolRegistry::class)->names())->toBe([]);
});

// ---------------------------------------------------------------- inspect

it('reports the run budget without touching another run', function (): void {
    $result = runTool(new InspectRunStatusTool, []);

    expect($result->ok)->toBeTrue()
        ->and($result->data['iterations_remaining'])->toBeGreaterThan(0)
        ->and($result->data)->toHaveKey('tool_calls_remaining')
        ->and($result->content)->toContain('iterations');
});

// ----------------------------------------------------------- request approval

it('makes request_approval critical, so it always pauses', function (): void {
    expect((new RequestApprovalTool)->risk())->toBe(RiskLevel::Critical);
});

// ------------------------------------------------------------- query records

it('reads only the fields a resource declares', function (): void {
    config()->set('pandora.tools.resources', [
        'users' => [
            'model' => TestUser::class,
            'fields' => ['id', 'name'],
            'filterable' => ['name'],
            'authorize' => static fn (): bool => true,
        ],
    ]);

    TestUser::create(['name' => 'Ada', 'email' => 'ada@example.test', 'password' => 'secret']);

    $result = runTool(new QueryRecordsTool, ['resource' => 'users', 'filters' => ['name' => 'Ada']]);

    expect($result->ok)->toBeTrue()
        ->and($result->data['records'][0])->toHaveKeys(['id', 'name'])
        // The password hash and the email were never in `fields`, so they are
        // not in the answer, whatever the model asked for.
        ->and($result->data['records'][0])->not->toHaveKey('email')
        ->and($result->data['records'][0])->not->toHaveKey('password');
});

it('refuses a filter on a field that is not filterable', function (): void {
    config()->set('pandora.tools.resources', [
        'users' => [
            'model' => TestUser::class,
            'fields' => ['id'],
            'filterable' => ['name'],
            'authorize' => static fn (): bool => true,
        ],
    ]);

    $result = runTool(new QueryRecordsTool, ['resource' => 'users', 'filters' => ['password' => 'x']]);

    expect($result->ok)->toBeFalse()
        ->and($result->content)->toContain('cannot be filtered');
});

it('refuses a resource nobody configured', function (): void {
    $result = runTool(new QueryRecordsTool, ['resource' => 'secrets']);

    expect($result->ok)->toBeFalse()
        ->and($result->content)->toContain('no resource named');
});

it('denies a resource whose author wrote no authorize callback', function (): void {
    // Silence is not permission.
    config()->set('pandora.tools.resources', [
        'users' => ['model' => TestUser::class, 'fields' => ['id'], 'filterable' => []],
    ]);

    expect((new QueryRecordsTool)->authorize(
        new ToolInput(['resource' => 'users']),
        $this->toolContext(),
    ))->toBeFalse();
});

it('denies a resource to a system actor with no user', function (): void {
    config()->set('pandora.tools.resources', [
        'users' => [
            'model' => TestUser::class,
            'fields' => ['id'],
            'filterable' => [],
            'authorize' => static fn (): bool => true,
        ],
    ]);

    expect((new QueryRecordsTool)->authorize(
        new ToolInput(['resource' => 'users']),
        $this->toolContext(ActorContext::system('automation')),
    ))->toBeFalse();
});

it('applies the configured scope whatever the model asked for', function (): void {
    TestUser::create(['name' => 'Mine', 'email' => 'mine@example.test', 'password' => 'secret']);
    TestUser::create(['name' => 'Theirs', 'email' => 'theirs@example.test', 'password' => 'secret']);

    config()->set('pandora.tools.resources', [
        'users' => [
            'model' => TestUser::class,
            'fields' => ['name'],
            'filterable' => ['name'],
            'authorize' => static fn (): bool => true,
            'scope' => static fn ($query): mixed => $query->where('name', 'Mine'),
        ],
    ]);

    $result = runTool(new QueryRecordsTool, ['resource' => 'users', 'filters' => ['name' => 'Theirs']]);

    expect($result->data['records'])->toBe([]);
});

// --------------------------------------------------------------- read config

it('reads only an exactly allowlisted configuration key', function (): void {
    config()->set('pandora.tools.readable_config', ['app.name']);

    expect(runTool(new ReadConfigTool, ['key' => 'app.name'])->ok)->toBeTrue()
        ->and(runTool(new ReadConfigTool, ['key' => 'app.key'])->ok)->toBeFalse();
});

it('refuses a key by prefix, not only by exact miss', function (): void {
    // `app.name` being readable must not make `app.key` readable.
    config()->set('pandora.tools.readable_config', ['app.name']);

    expect((new ReadConfigTool)->authorize(
        new ToolInput(['key' => 'app.key']),
        $this->toolContext(),
    ))->toBeFalse();
});

// -------------------------------------------------------------- dispatch job

it('queues an allowlisted job with only the arguments it declares', function (): void {
    Queue::fake();

    config()->set('pandora.tools.jobs', [
        'noop' => [
            'class' => BuiltInNoopJob::class,
            'arguments' => ['label'],
            'authorize' => static fn (): bool => true,
        ],
    ]);

    $result = runTool(app(DispatchJobTool::class), ['job' => 'noop', 'arguments' => ['label' => 'x']]);

    expect($result->ok)->toBeTrue();
    Queue::assertPushed(BuiltInNoopJob::class);
});

it('refuses an argument a job never declared', function (): void {
    config()->set('pandora.tools.jobs', [
        'noop' => [
            'class' => BuiltInNoopJob::class,
            'arguments' => ['label'],
            'authorize' => static fn (): bool => true,
        ],
    ]);

    $result = runTool(app(DispatchJobTool::class), ['job' => 'noop', 'arguments' => ['sudo' => true]]);

    expect($result->ok)->toBeFalse()
        ->and($result->content)->toContain('does not accept');
});

it('refuses a job nobody configured, whatever class the model names', function (): void {
    $result = runTool(app(DispatchJobTool::class), ['job' => 'App\\Jobs\\DeleteEverything']);

    expect($result->ok)->toBeFalse()
        ->and($result->content)->toContain('no job named');
});

// ---------------------------------------------------------------- emit event

it('emits an allowlisted event', function (): void {
    Event::fake([BuiltInNoopEvent::class]);

    config()->set('pandora.tools.events', [
        'noop' => [
            'class' => BuiltInNoopEvent::class,
            'payload' => ['label'],
            'authorize' => static fn (): bool => true,
        ],
    ]);

    expect(runTool(app(EmitEventTool::class), ['event' => 'noop', 'payload' => ['label' => 'x']])->ok)
        ->toBeTrue();

    Event::assertDispatched(BuiltInNoopEvent::class);
});

it('refuses an event nobody configured', function (): void {
    expect(runTool(app(EmitEventTool::class), ['event' => 'anything'])->ok)->toBeFalse();
});

// ---------------------------------------------------------- send notification

it('notifies the actor and nobody else', function (): void {
    Notification::fake();

    config()->set('pandora.tools.notifications', [
        'noop' => [
            'class' => BuiltInNoopNotification::class,
            'payload' => ['label'],
            'authorize' => static fn (): bool => true,
        ],
    ]);

    $context = $this->toolContext();

    app(SendNotificationTool::class)
        ->handle(new ToolInput(['notification' => 'noop', 'payload' => ['label' => 'x']]), $context);

    // There is no recipient argument, so there is no way for a model to reach
    // anybody but the person it is acting for.
    Notification::assertSentTo($this->toolUser(), BuiltInNoopNotification::class);
});

it('makes send_notification high risk, so it pauses by default', function (): void {
    expect(app(SendNotificationTool::class)->risk())->toBe(RiskLevel::High);
});

it('declares no recipient argument at all', function (): void {
    $rules = app(SendNotificationTool::class)->rules();

    expect(array_keys($rules))->toBe(['notification', 'payload']);
});

final class BuiltInNoopJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;

    public function __construct(public string $label = '') {}

    public function handle(): void {}
}

final class BuiltInNoopEvent
{
    public function __construct(public string $label = '') {}
}

final class BuiltInNoopNotification extends Illuminate\Notifications\Notification
{
    public function __construct(public string $label = '') {}

    /**
     * @return list<string>
     */
    public function via(mixed $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(mixed $notifiable): array
    {
        return ['label' => $this->label];
    }
}

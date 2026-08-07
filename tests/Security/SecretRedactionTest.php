<?php

declare(strict_types=1);

use Pandora\Agents\AgentRunner;
use Pandora\Runs\Enums\RunStepStatus;
use Pandora\Runs\Enums\RunStepType;
use Pandora\Runs\RunStep;
use Pandora\Runs\RunStepRecorder;
use Pandora\Tests\Fixtures\LeakyBroadcastEvent;
use Pandora\Tests\Support\MakesRuns;

uses(MakesRuns::class);

/** Acceptance guarantee 18 -- no secret reaches a trace, broadcast or response. */
it('never writes provider credentials into the run trace', function (): void {
    config()->set('pandora.providers.connections.fake.api_key', 'sk-live-SUPERSECRET123456');

    $this->fakeProvider()->willRespondWith('Fine.');

    $conversation = $this->makeConversation();

    $run = app(AgentRunner::class)
        ->agent($conversation->agent)
        ->inConversation($conversation)
        ->run('Hello');

    $trace = RunStep::query()->where('run_id', $run->getKey())->get()
        ->map(fn (RunStep $s): string => json_encode([$s->payload, $s->raw_meta, $s->error_message]))
        ->implode("\n");

    expect($trace)->not->toContain('sk-live-SUPERSECRET123456');
});

it('redacts sensitive keys inside a recorded step payload', function (): void {
    $run = $this->makeRun();

    app(RunStepRecorder::class)->record(
        $run,
        RunStepType::ToolRequest,
        payload: ['tool' => 'CallApi', 'arguments' => ['api_key' => 'sk-abc123', 'host' => 'example.test']],
    );

    /** @var RunStep $step */
    $step = RunStep::query()->where('run_id', $run->getKey())->first();

    expect($step->payload['arguments']['api_key'])->toBe('[redacted]')
        ->and($step->payload['arguments']['host'])->toBe('example.test');
});

it('redacts credential-shaped values inside a recorded error message', function (): void {
    $run = $this->makeRun();

    app(RunStepRecorder::class)->record(
        $run,
        RunStepType::Error,
        status: RunStepStatus::Failed,
        errorMessage: 'Rejected request with Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.payload.sig',
    );

    /** @var RunStep $step */
    $step = RunStep::query()->where('run_id', $run->getKey())->first();

    expect($step->error_message)->not->toContain('eyJhbGciOiJIUzI1NiJ9')
        ->and($step->error_message)->toContain('[redacted]');
});

it('redacts every broadcast payload without the caller having to ask', function (): void {
    $payload = (new LeakyBroadcastEvent)->broadcastWith();

    expect($payload['data']['api_key'])->toBe('[redacted]')
        ->and($payload['data']['nested']['password'])->toBe('[redacted]')
        ->and($payload['data']['safe'])->toBe('ok');
});

<?php

declare(strict_types=1);

use Pandora\Pandora\Audit\AuditLog;
use Pandora\Pandora\Providers\Data\ToolCall;
use Pandora\Pandora\Runs\Enums\RunStepType;
use Pandora\Pandora\Tests\Support\MakesTools;
use Pandora\Pandora\Tools\Tool;
use Pandora\Pandora\Tools\ToolContext;
use Pandora\Pandora\Tools\ToolExecution;
use Pandora\Pandora\Tools\ToolInput;
use Pandora\Pandora\Tools\ToolResult;

/**
 * Phase 2 acceptance criterion 30 — tool arguments and results are redacted
 * everywhere they are shown.
 *
 * A tool's arguments come from a model and its results come from the
 * application, and either can carry a credential. The stored copy that will
 * be EXECUTED keeps its real values, because it has to; every copy anybody
 * ever reads is sanitized.
 */
uses(MakesTools::class);

final class SecretHandlingTool extends Tool
{
    public function name(): string
    {
        return 'secret_handling';
    }

    public function description(): string
    {
        return 'A tool whose arguments and results carry credential-shaped values.';
    }

    public function rules(): array
    {
        return [
            'api_key' => 'required|string|max:128',
            'reference' => 'required|string|max:32',
        ];
    }

    public function handle(ToolInput $input, ToolContext $context): ToolResult
    {
        return ToolResult::success('Done.', [
            'password' => 'hunter2',
            'reference' => $input->string('reference'),
        ]);
    }
}

beforeEach(function (): void {
    $this->registerTools([SecretHandlingTool::class]);
    $this->agentAllows(['secret_handling']);

    $this->fakeProvider()
        ->willRequestTools([new ToolCall('call_1', 'secret_handling', [
            'api_key' => 'sk-live-abcdefghijklmnopqrstuvwxyz',
            'reference' => 'ORD-1234',
        ])])
        ->willRespondWith('Done.');

    $this->secretRun = $this->runToolAgent('Do the thing.');
});

it('keeps the real arguments only where they must be executed from', function (): void {
    /** @var ToolExecution $execution */
    $execution = ToolExecution::query()->where('run_id', $this->secretRun->getKey())->firstOrFail();

    expect($execution->arguments['api_key'])->toBe('sk-live-abcdefghijklmnopqrstuvwxyz')
        ->and($execution->sanitized_arguments['api_key'])->toBe('[redacted]')
        // A non-sensitive key alongside a sensitive one is not collateral.
        ->and($execution->sanitized_arguments['reference'])->toBe('ORD-1234');
});

it('redacts the result the same way', function (): void {
    /** @var ToolExecution $execution */
    $execution = ToolExecution::query()->where('run_id', $this->secretRun->getKey())->firstOrFail();

    expect($execution->sanitized_result['data']['password'])->toBe('[redacted]')
        ->and($execution->sanitized_result['data']['reference'])->toBe('ORD-1234');
});

it('never writes a credential into the run trace', function (): void {
    $steps = $this->secretRun->steps()
        ->whereIn('type', [RunStepType::ToolRequest->value, RunStepType::ToolResult->value])
        ->get();

    $encoded = (string) json_encode($steps->pluck('payload')->all());

    expect($encoded)->not->toContain('sk-live-abcdefghijklmnopqrstuvwxyz')
        ->and($encoded)->not->toContain('hunter2')
        ->and($encoded)->toContain('[redacted]');
});

it('never writes a credential into the audit log', function (): void {
    $encoded = (string) json_encode(
        AuditLog::query()->where('run_id', $this->secretRun->getKey())->pluck('metadata')->all(),
    );

    expect($encoded)->not->toContain('sk-live-abcdefghijklmnopqrstuvwxyz')
        ->and($encoded)->not->toContain('hunter2');
});

it('redacts the arguments an approval card would show', function (): void {
    /** @var ToolExecution $execution */
    $execution = ToolExecution::query()->where('run_id', $this->secretRun->getKey())->firstOrFail();

    // The approval card reads sanitized_arguments, never arguments. A human
    // deciding does not need the credential, and a screenshot of a card is a
    // real way for one to leave the building.
    expect((string) json_encode($execution->sanitized_arguments))
        ->not->toContain('sk-live-abcdefghijklmnopqrstuvwxyz');
});

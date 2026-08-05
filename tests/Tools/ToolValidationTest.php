<?php

declare(strict_types=1);

use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Pandora\Pandora\Exceptions\ToolInputInvalid;
use Pandora\Pandora\Tests\Fixtures\Tools\LookupOrderTool;
use Pandora\Pandora\Tools\ToolInput;

/**
 * Phase 2 acceptance criterion 3 — arguments a model invented are rejected
 * before a single line of tool code runs, and the model is told why.
 */
function validateWith(array $arguments): ToolInput
{
    return (new LookupOrderTool)->validate($arguments, app(ValidationFactory::class));
}

it('passes valid arguments through as a typed input bag', function (): void {
    $input = validateWith(['reference' => 'ORD-1234', 'include_lines' => true]);

    expect($input->string('reference'))->toBe('ORD-1234')
        ->and($input->boolean('include_lines'))->toBeTrue();
});

it('rejects a missing required argument', function (): void {
    expect(fn () => validateWith([]))->toThrow(ToolInputInvalid::class);
});

it('rejects an argument violating its constraints', function (): void {
    expect(fn () => validateWith(['reference' => 'no']))->toThrow(ToolInputInvalid::class);
});

it('discards arguments the tool never declared', function (): void {
    // The model inventing `admin => true` must not reach the tool, however
    // convincingly it was persuaded to send it.
    $input = validateWith(['reference' => 'ORD-1234', 'admin' => true]);

    expect($input->toArray())->not->toHaveKey('admin');
});

it('tells the model which field was wrong so it can correct itself', function (): void {
    try {
        validateWith(['reference' => 'x']);
        $this->fail('Expected ToolInputInvalid.');
    } catch (ToolInputInvalid $e) {
        expect($e->modelMessage())->toContain('reference')
            ->and($e->errors)->toHaveKey('reference')
            ->and($e->tool)->toBe('lookup_order');
    }
});

it('exposes a safe message to the user and a specific one to the model', function (): void {
    $exception = ToolInputInvalid::make('lookup_order', ['reference' => ['The reference is required.']]);

    expect($exception->userMessage())->not->toContain('reference')
        ->and($exception->modelMessage())->toContain('reference')
        ->and($exception->errorCode())->toBe('pandora.tool_input_invalid');
});

it('hydrates a typed value object from validated arguments', function (): void {
    $input = new ToolInput(['reference' => 'ORD-1', 'include_lines' => '1', 'quantity' => '3']);

    $dto = $input->as(LookupOrderInput::class);

    expect($dto->reference)->toBe('ORD-1')
        ->and($dto->includeLines)->toBeTrue()
        ->and($dto->quantity)->toBe(3);
});

it('falls back to constructor defaults for absent arguments', function (): void {
    $dto = (new ToolInput(['reference' => 'ORD-1']))->as(LookupOrderInput::class);

    expect($dto->includeLines)->toBeFalse()->and($dto->quantity)->toBe(1);
});

final class LookupOrderInput
{
    public function __construct(
        public string $reference,
        public bool $includeLines = false,
        public int $quantity = 1,
    ) {}
}

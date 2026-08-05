<?php

declare(strict_types=1);

use Illuminate\Validation\Rule;
use Pandora\Pandora\Exceptions\UnsupportedValidationRule;
use Pandora\Pandora\Runs\Enums\RunStepType;
use Pandora\Pandora\Tests\Fixtures\Tools\LookupOrderTool;
use Pandora\Pandora\Tools\Schema\RuleSchemaGenerator;

/**
 * Phase 2 acceptance criteria 1 and 2.
 *
 * The schema the model is shown is generated from the rules that validate what
 * it sends. If these two ever disagree, the tool tells the model to send
 * something the tool will then reject.
 */
function schema(array $rules, array $descriptions = []): array
{
    return (new RuleSchemaGenerator)->generate('test_tool', $rules, $descriptions);
}

it('generates an object schema with required fields and descriptions', function (): void {
    $generated = (new LookupOrderTool)->schema(new RuleSchemaGenerator);

    expect($generated)->toBe([
        'type' => 'object',
        'properties' => [
            'reference' => [
                'type' => 'string',
                'description' => 'The customer-facing order reference.',
                'minLength' => 3,
                'maxLength' => 32,
            ],
            'include_lines' => ['type' => 'boolean'],
        ],
        'additionalProperties' => false,
        'required' => ['reference'],
    ]);
});

it('refuses additional properties so an invented argument is visible', function (): void {
    expect(schema(['a' => 'string'])['additionalProperties'])->toBeFalse();
});

it('maps each scalar type rule', function (string $rule, string $expected): void {
    expect(schema(['field' => $rule])['properties']['field']['type'])->toBe($expected);
})->with([
    ['string', 'string'],
    ['integer', 'integer'],
    ['numeric', 'number'],
    ['boolean', 'boolean'],
    ['array', 'array'],
]);

it('interprets min and max according to the declared type', function (
    string $rules,
    array $expected,
): void {
    expect(schema(['field' => $rules])['properties']['field'])->toMatchArray($expected);
})->with([
    ['string|min:2|max:8', ['minLength' => 2, 'maxLength' => 8]],
    ['integer|min:2|max:8', ['minimum' => 2, 'maximum' => 8]],
    ['numeric|min:1.5', ['minimum' => 1.5]],
    ['array|min:1|max:3', ['minItems' => 1, 'maxItems' => 3]],
    ['string|between:2,8', ['minLength' => 2, 'maxLength' => 8]],
    ['integer|size:4', ['const' => 4]],
    ['string|size:4', ['minLength' => 4, 'maxLength' => 4]],
]);

it('refuses to guess what a bound means without a declared type', function (): void {
    expect(fn () => schema(['field' => 'required|min:3']))
        ->toThrow(UnsupportedValidationRule::class, 'no type rule declared');
});

it('maps format and pattern rules', function (): void {
    $properties = schema([
        'mail' => 'email',
        'site' => 'url',
        'key' => 'uuid',
        'id' => 'ulid',
        'ip' => 'ipv4',
        'slug' => 'alpha_dash',
        'code' => 'digits:6',
        'name' => 'string|starts_with:pd_',
    ])['properties'];

    expect($properties['mail']['format'])->toBe('email')
        ->and($properties['site']['format'])->toBe('uri')
        ->and($properties['key']['format'])->toBe('uuid')
        ->and($properties['id']['pattern'])->toBe('^[0-7][0-9A-HJKMNP-TV-Z]{25}$')
        ->and($properties['ip']['format'])->toBe('ipv4')
        ->and($properties['slug']['pattern'])->toBe('^[A-Za-z0-9_-]+$')
        ->and($properties['code']['pattern'])->toBe('^[0-9]{6}$')
        ->and($properties['name']['pattern'])->toBe('^(pd_)')
        // A format rule implies the type even when it is not stated.
        ->and($properties['mail']['type'])->toBe('string');
});

it('strips PHP delimiters from a regex rule', function (): void {
    expect(schema(['field' => ['string', 'regex:/^AB[0-9]+$/i']])['properties']['field']['pattern'])
        ->toBe('^AB[0-9]+$');
});

it('turns in rules into an enum', function (): void {
    expect(schema(['status' => 'in:open,closed'])['properties']['status']['enum'])
        ->toBe(['open', 'closed']);
});

it('reads the cases behind a Rule::enum()', function (): void {
    expect(schema(['type' => ['required', Rule::enum(RunStepType::class)]])['properties']['type']['enum'])
        ->toContain('model_request', 'tool_result');
});

it('accepts rule objects and closures as runtime-only constraints', function (): void {
    $generated = schema([
        'name' => ['required', 'string', 'max:10', Rule::in(['a', 'b'])],
        'other' => ['string', function (): void {}],
    ]);

    expect($generated['properties']['name']['enum'])->toBe(['a', 'b'])
        ->and($generated['properties']['other'])->toBe(['type' => 'string'])
        ->and($generated['required'])->toBe(['name']);
});

it('omits runtime-only rules from the schema without failing', function (): void {
    // `exists` cannot be expressed, but it only ever narrows what is accepted,
    // so the schema is less specific rather than wrong.
    expect(schema(['order_id' => 'required|string|exists:orders,id'])['properties']['order_id'])
        ->toBe(['type' => 'string']);
});

it('marks a nullable field as accepting null', function (): void {
    expect(schema(['note' => 'nullable|string'])['properties']['note']['type'])
        ->toBe(['string', 'null']);
});

it('builds nested object schemas from dotted rules', function (): void {
    $generated = schema([
        'customer' => 'required|array',
        'customer.email' => 'required|email',
        'customer.age' => 'integer|min:0',
    ]);

    expect($generated['properties']['customer']['items']['properties']['email']['format'])->toBe('email')
        ->and($generated['properties']['customer']['items']['required'])->toBe(['email'])
        ->and($generated['required'])->toBe(['customer']);
});

it('builds array item schemas from wildcard rules', function (): void {
    $generated = schema([
        'lines' => 'required|array|max:5',
        'lines.*.sku' => 'required|string',
        'lines.*.qty' => 'required|integer|min:1',
    ]);

    expect($generated['properties']['lines']['type'])->toBe('array')
        ->and($generated['properties']['lines']['maxItems'])->toBe(5)
        ->and($generated['properties']['lines']['items']['properties']['sku']['type'])->toBe('string')
        ->and($generated['properties']['lines']['items']['properties']['qty']['minimum'])->toBe(1)
        ->and($generated['properties']['lines']['items']['required'])->toBe(['sku', 'qty']);
});

it('throws on an unknown rule rather than silently producing a wrong schema', function (): void {
    expect(fn () => schema(['field' => 'string|totally_made_up:3']))
        ->toThrow(UnsupportedValidationRule::class, 'totally_made_up');
});

it('names the tool, the field and the rule when it throws', function (): void {
    expect(fn () => schema(['weird_field' => 'string|totally_made_up']))
        ->toThrow(UnsupportedValidationRule::class, 'Tool [test_tool]')
        ->and(fn () => schema(['weird_field' => 'string|totally_made_up']))
        ->toThrow(UnsupportedValidationRule::class, 'field [weird_field]');
});

it('rejects upload rules outright, because a model sends JSON not files', function (string $rule): void {
    expect(fn () => schema(['field' => $rule]))->toThrow(UnsupportedValidationRule::class);
})->with(['file', 'image', 'mimes:pdf', 'dimensions:width=1']);

it('produces an empty object schema for a tool taking no arguments', function (): void {
    expect(schema([]))->toBe([
        'type' => 'object',
        'properties' => [],
        'additionalProperties' => false,
    ]);
});

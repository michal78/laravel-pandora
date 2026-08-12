<?php

declare(strict_types=1);

use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Pandora\Mcp\RemoteTool;
use Pandora\Tools\Tool;
use Pandora\Tools\ToolRegistry;

/**
 * `carriesUndeclaredArguments()` is protected, and deliberately so -- it is a
 * decision a tool makes about itself, not a switch anything else may read.
 * Asserting it is exactly the case reflection is for.
 */
function carriesUndeclared(Tool $tool): bool
{
    $method = new ReflectionMethod($tool, 'carriesUndeclaredArguments');

    return (bool) $method->invoke($tool);
}

/**
 * A `RemoteTool` with no models behind it.
 *
 * Its constructor wants a discovered `McpTool` row and its `McpServer`, and
 * building those means a fake server, a discovery run and an approval -- all of
 * which `tests/Mcp` already covers, and none of which validation touches.
 * `rules()` returns a constant and `carriesUndeclaredArguments()` returns true;
 * neither reads a single property. Constructing it fully here would test the
 * fixture rather than the thing under test.
 */
function bareRemoteTool(): RemoteTool
{
    /** @var RemoteTool $tool */
    $tool = (new ReflectionClass(RemoteTool::class))->newInstanceWithoutConstructor();

    return $tool;
}

/**
 * Phase 9, criterion 21 — an argument the model sent has to reach `handle()`.
 *
 * The second Phase 6 defect, and the quieter of the two. `RemoteTool` declared
 * rules for one key, `arguments`. Laravel's validator returns only the keys it
 * was given rules for -- which is a security property, and the right default,
 * and the reason an undeclared key cannot reach a tool and be acted on. But a
 * model forms its call against the schema the *server* advertised, so for a
 * remote tool every key is top-level and undeclared by construction. Every
 * remote call arrived as `{"arguments":{}}`, succeeded, and was audited as
 * allowed. The far end answered a question nobody asked:
 * `Invoice UNKNOWN: 4 800,00 DKK`.
 *
 * It survived a suite with twelve MCP test files because every one of them
 * built a `ToolInput` by hand and handed it straight to `handle()`. Validation
 * -- the only step that could lose an argument -- was the step the tests
 * skipped, because constructing the input *is* skipping it.
 *
 * The invariant below is deliberately structural rather than a round trip with
 * fabricated values. **Every property a tool advertises must be a property it
 * validates**, unless it has explicitly declared that it carries undeclared
 * arguments. Advertising a key you do not validate is precisely how an
 * argument disappears, and it is checkable for every tool that will ever be
 * registered without inventing a plausible value for each one.
 */
it('validates every argument it advertises, for every registered tool', function (): void {
    $registry = app(ToolRegistry::class);

    $offenders = [];

    foreach ($registry->all() as $tool) {
        $schema = $registry->schema($tool);

        /** @var array<string, mixed> $properties */
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];

        if ($properties === []) {
            continue;
        }

        // A tool that says so is exempt, and the exemption is the point: it is
        // a single greppable method rather than an accident of which keys
        // happened to have rules.
        if (carriesUndeclared($tool)) {
            continue;
        }

        // Rules may be declared as `key` or as `key.*` / `key.nested`. The
        // top-level segment is what the validator keys its output on.
        $declared = [];

        foreach (array_keys($tool->rules()) as $rule) {
            $declared[explode('.', $rule)[0]] = true;
        }

        foreach (array_keys($properties) as $advertised) {
            if (! isset($declared[$advertised])) {
                $offenders[] = $tool::class.' advertises ['.$advertised.'] and validates no rule for it';
            }
        }
    }

    expect($offenders)->toBe([]);
});

/**
 * The exemption is not a loophole, so it is bounded and asserted.
 *
 * If a core tool ever quietly returns true here, undeclared keys start
 * reaching `handle()` everywhere and the stripping that makes the rule above
 * meaningful is gone. Exactly one class in the system may do this.
 */
it('lets only remote MCP tools carry undeclared arguments', function (): void {
    $registry = app(ToolRegistry::class);

    foreach ($registry->all() as $tool) {
        expect(carriesUndeclared($tool))->toBeFalse(
            $tool::class.' carries undeclared arguments; only RemoteTool may.',
        );
    }

    expect(carriesUndeclared(bareRemoteTool()))->toBeTrue();
});

/**
 * The regression itself, through the step the Phase 6 tests skipped.
 */
it('carries a remote tool\'s arguments through validation to the input', function (): void {
    // Top-level keys, formed against the server's schema -- which is how they
    // arrive from a real model, and what the old rules discarded.
    $input = bareRemoteTool()->validate(
        ['invoice' => 'INV-4471', 'currency' => 'DKK'],
        app(ValidationFactory::class),
    );

    expect($input->toArray())->toMatchArray(['invoice' => 'INV-4471', 'currency' => 'DKK']);
});

/**
 * And the other direction, because the stripping is a feature everywhere else.
 */
it('still strips an undeclared argument from a core tool', function (): void {
    $tool = app(ToolRegistry::class)->get('remember');

    $input = $tool->validate(
        [
            'content' => 'The invoice was paid on the second.',
            'about' => 'conversation',
            // Nobody declared this, and nothing should act on it.
            'scope' => 'global',
        ],
        app(ValidationFactory::class),
    );

    expect($input->toArray())->not->toHaveKey('scope')
        ->and($input->toArray())->toHaveKey('content');
});

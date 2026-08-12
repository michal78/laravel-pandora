<?php

declare(strict_types=1);

use Pandora\Mcp\Namespacing;
use Pandora\Tools\Tool;
use Pandora\Tools\ToolRegistry;

/**
 * Phase 9, criterion 20 — a tool's name has to be sayable to a provider.
 *
 * This is the Phase 6 defect written down as a test. The default MCP namespace
 * separator was `.`, every approved remote tool was advertised as
 * `orders.lookup_invoice`, and OpenAI and Anthropic both hold function names
 * to `^[a-zA-Z0-9_-]+$`. The provider answered
 * `400 Invalid 'tools[0].function.name'`, the run failed with a sentence naming
 * neither MCP nor the tool nor the name that was rejected, and thirty green
 * acceptance criteria had nothing to say about it.
 *
 * Nothing could have caught it. `FakeProvider` accepts any function name a tool
 * cares to have, because a fake that enforced a vendor's grammar would be a
 * vendor. So the grammar is asserted here directly, against the names rather
 * than against a provider, and that is the point: **this test does not send
 * anything anywhere.** It is the one shape of test that could have failed on
 * the day the separator was chosen.
 */

/**
 * The intersection of what the shipped providers accept.
 *
 * OpenAI: `^[a-zA-Z0-9_-]{1,64}$`. Anthropic: `^[a-zA-Z0-9_-]{1,64}$`. They
 * agree, which is why one constant is honest here — and the length bound is
 * part of it, not a detail. A name is rejected for being 65 characters exactly
 * as hard as for containing a dot.
 */
const PROVIDER_FUNCTION_NAME = '/^[a-zA-Z0-9_-]{1,64}$/';

it('gives every built-in tool a name a provider will accept', function (): void {
    $registry = app(ToolRegistry::class);

    $names = $registry->names();

    expect($names)->not->toBeEmpty();

    foreach ($names as $name) {
        expect($name)->toMatch(PROVIDER_FUNCTION_NAME);
    }
});

it('composes a namespaced remote name a provider will accept', function (): void {
    // The shape that failed: a namespace, a separator, and a server-supplied
    // tool name.
    $namespaced = Namespacing::qualify('orders', 'lookup_invoice');

    expect($namespaced)->toMatch(PROVIDER_FUNCTION_NAME);
});

/**
 * The separator is the character the whole defect lived in.
 */
it('ships a separator that is legal in a function name', function (): void {
    expect(Namespacing::separator())->toMatch(PROVIDER_FUNCTION_NAME);
});

it('keeps the separator legal when an operator configures one badly', function (): void {
    // `separator()` falls back when the configured value is not a usable
    // string. A fallback is a default nobody chose, so it is held to the same
    // grammar as the one they did -- otherwise a misconfiguration reintroduces
    // the Phase 6 failure with no way to see it in the config file.
    foreach (['', null, 123, []] as $bad) {
        config()->set('pandora.mcp.client.namespace_separator', $bad);

        expect(Namespacing::separator())->toMatch(PROVIDER_FUNCTION_NAME);
    }
});

it('rejects a namespace or tool name that would compose an illegal one', function (): void {
    // The guard that already exists, restated in the provider's terms: a
    // namespace with a dot or a space in it cannot be allowed, because the
    // composed name is what goes on the wire.
    expect(Namespacing::isValidNamespace('orders.eu'))->toBeFalse()
        ->and(Namespacing::isValidNamespace('order s'))->toBeFalse()
        ->and(Namespacing::isValidNamespace('orders'))->toBeTrue();
});

/**
 * The regression guard proper.
 *
 * A tool is free to name itself, and a future one naming itself
 * `orders.lookup` or `read file` would fail exactly the way Phase 6 did. This
 * asserts the rule over whatever is in the registry rather than over today's
 * sixteen tools, so a tool added next year is covered by a test written this
 * year.
 */
it('holds every registered tool to the grammar, including ones added later', function (): void {
    $registry = app(ToolRegistry::class);

    $offenders = [];

    foreach ($registry->all() as $tool) {
        expect($tool)->toBeInstanceOf(Tool::class);

        if (preg_match(PROVIDER_FUNCTION_NAME, $tool->name()) !== 1) {
            $offenders[] = $tool::class.' → '.$tool->name();
        }
    }

    expect($offenders)->toBe([]);
});

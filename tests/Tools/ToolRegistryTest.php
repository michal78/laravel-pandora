<?php

declare(strict_types=1);

use Pandora\Exceptions\InvalidConfiguration;
use Pandora\Exceptions\ToolNotFound;
use Pandora\Exceptions\UnsupportedValidationRule;
use Pandora\PandoraServiceProvider;
use Pandora\Tests\Fixtures\Tools\LegacyLookupTool;
use Pandora\Tests\Fixtures\Tools\LookupOrderTool;
use Pandora\Tests\Fixtures\Tools\RefundOrderTool;
use Pandora\Tools\Tool;
use Pandora\Tools\ToolRegistry;
use Pandora\Tools\ToolResult;

/**
 * Phase 2 acceptance criterion 4 — the catalogue resolves by name, alias and
 * version, and a deprecated tool still resolves.
 */
beforeEach(function (): void {
    $this->registry = app(ToolRegistry::class)->flush();
});

it('resolves a registered tool by name', function (): void {
    $this->registry->register(LookupOrderTool::class);

    expect($this->registry->get('lookup_order'))->toBeInstanceOf(LookupOrderTool::class)
        ->and($this->registry->has('lookup_order'))->toBeTrue();
});

it('accepts an already-constructed instance', function (): void {
    $tool = new LookupOrderTool;
    $this->registry->register($tool);

    expect($this->registry->get('lookup_order'))->toBe($tool);
});

it('resolves an alias to the tool that claims it', function (): void {
    $this->registry->register(RefundOrderTool::class);

    expect($this->registry->get('issue_refund')->name())->toBe('refund_order');
});

it('resolves an exact version and defaults a bare name to the newest', function (): void {
    $this->registry->registerMany([LegacyLookupTool::class, LookupOrderTool::class]);

    expect($this->registry->get('lookup_order@0.9')->version())->toBe('0.9')
        ->and($this->registry->get('lookup_order@1.0')->version())->toBe('1.0')
        ->and($this->registry->get('lookup_order')->version())->toBe('1.0');
});

it('resolves a deprecated tool rather than breaking a live conversation', function (): void {
    $this->registry->registerMany([LegacyLookupTool::class, LookupOrderTool::class]);

    $legacy = $this->registry->get('lookup_order@0.9');

    expect($legacy->deprecated())->toContain('lookup_order@1.0');
});

it('tells the model about a deprecation in the advertised description', function (): void {
    $this->registry->registerMany([LegacyLookupTool::class, LookupOrderTool::class]);

    $described = $this->registry->describe([$this->registry->get('lookup_order@0.9')]);

    expect($described[0]->description)->toContain('Deprecated:');
});

it('throws a specific exception for an unregistered name', function (): void {
    expect(fn () => $this->registry->get('shell_exec'))
        ->toThrow(ToolNotFound::class, 'shell_exec')
        ->and($this->registry->has('shell_exec'))->toBeFalse()
        ->and($this->registry->find('shell_exec'))->toBeNull();
});

it('refuses to register the same name and version twice', function (): void {
    $this->registry->register(LookupOrderTool::class);

    expect(fn () => $this->registry->register(LookupOrderTool::class))
        ->toThrow(InvalidConfiguration::class, 'registered twice');
});

it('refuses an alias that collides with a registered tool name', function (): void {
    $this->registry->register(new class extends Tool
    {
        public function name(): string
        {
            return 'issue_refund';
        }

        public function description(): string
        {
            return 'Something else entirely.';
        }

        public function handle($input, $context): ToolResult
        {
            return ToolResult::success('ok');
        }
    });

    expect(fn () => $this->registry->register(RefundOrderTool::class))
        ->toThrow(InvalidConfiguration::class, 'already a registered tool name');
});

it('refuses to register something that is not a tool', function (): void {
    expect(fn () => $this->registry->register(stdClass::class))
        ->toThrow(InvalidConfiguration::class, 'not a Pandora tool');
});

it('groups tools and lists the groups', function (): void {
    $this->registry->registerMany([LookupOrderTool::class, RefundOrderTool::class]);

    expect($this->registry->groups())->toBe(['billing', 'general'])
        ->and($this->registry->group('billing'))->toHaveCount(1)
        ->and($this->registry->group('billing')[0]->name())->toBe('refund_order');
});

it('lists the newest of each tool, and every version on request', function (): void {
    $this->registry->registerMany([LegacyLookupTool::class, LookupOrderTool::class, RefundOrderTool::class]);

    expect($this->registry->all())->toHaveCount(2)
        ->and($this->registry->allVersions())->toHaveCount(3)
        ->and($this->registry->names())->toBe(['lookup_order', 'refund_order']);
});

it('generates each tool schema once, at registration', function (): void {
    // The point of generating eagerly: an inexpressible rule fails when the
    // application boots, not mid-conversation.
    $this->registry->register(LookupOrderTool::class);

    $schema = $this->registry->schema($this->registry->get('lookup_order'));

    expect($schema['required'])->toBe(['reference']);
});

it('fails at registration when a tool declares an inexpressible rule', function (): void {
    expect(fn () => $this->registry->register(new class extends Tool
    {
        public function name(): string
        {
            return 'broken';
        }

        public function description(): string
        {
            return 'Declares a rule that cannot be a schema.';
        }

        public function rules(): array
        {
            return ['field' => 'string|totally_made_up'];
        }

        public function handle($input, $context): ToolResult
        {
            return ToolResult::success('ok');
        }
    }))->toThrow(UnsupportedValidationRule::class);
});

it('describes tools for a provider without leaking how they are implemented', function (): void {
    $this->registry->register(RefundOrderTool::class);

    $described = $this->registry->describe($this->registry->all());
    $encoded = (string) json_encode($described);

    expect($described[0]->name)->toBe('refund_order')
        ->and($described[0]->schema['required'])->toBe(['reference', 'amount_minor'])
        // The model learns what it may ask for, never what will be checked.
        ->and($encoded)->not->toContain('RefundOrderTool')
        ->and($encoded)->not->toContain('high');
});

it('registers tools listed in configuration when the package boots', function (): void {
    config()->set('pandora.tools.registered', [LookupOrderTool::class]);

    // Re-boot the provider the way a fresh application would.
    (new PandoraServiceProvider(app()))->boot();

    expect(app(ToolRegistry::class)->has('lookup_order'))->toBeTrue();
});

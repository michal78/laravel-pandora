<?php

declare(strict_types=1);

use Pandora\Context\ContextBuilder;
use Pandora\Context\Providers\RecentMessagesProvider;
use Pandora\Context\Providers\RunToolLoopProvider;
use Pandora\Context\Providers\SystemInstructionsProvider;

/**
 * A published config that predates a provider must not silently disable it.
 *
 * `config/pandora.php` is the host's file once published, and it is a snapshot:
 * it keeps whatever the package shipped on the day it was copied. That is fine
 * for a setting, where an old value is a stale preference. It is not fine for
 * `RunToolLoopProvider`, whose absence does not read as a missing feature --
 * it reads as a run repeating one tool call until its budget dies, with
 * nothing in any log to say why.
 *
 * So the list is honoured as written, and this one entry is added when it is
 * missing.
 */
it('adds the tool-loop provider to a configured list that predates it', function (): void {
    config()->set('pandora.context.providers', [
        SystemInstructionsProvider::class,
        RecentMessagesProvider::class,
    ]);

    $builder = $this->app->make(ContextBuilder::class);

    expect(providersOf($builder))->toBe([
        SystemInstructionsProvider::class,
        RecentMessagesProvider::class,
        RunToolLoopProvider::class,
    ]);
});

it('does not add it twice, or reorder a list that already names it', function (): void {
    config()->set('pandora.context.providers', [
        RunToolLoopProvider::class,
        SystemInstructionsProvider::class,
    ]);

    $builder = $this->app->make(ContextBuilder::class);

    expect(providersOf($builder))->toBe([
        RunToolLoopProvider::class,
        SystemInstructionsProvider::class,
    ]);
});

/**
 * @return list<class-string>
 */
function providersOf(ContextBuilder $builder): array
{
    $property = new ReflectionProperty($builder, 'providers');

    /** @var list<class-string> $providers */
    $providers = $property->getValue($builder);

    return $providers;
}

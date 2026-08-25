<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Pandora\Tests\Support\MakesRuns;
use Pandora\UI\Livewire\AgentDetail;
use Pandora\UI\Livewire\AutomationDetail;
use Pandora\UI\Livewire\ChannelLink;
use Pandora\UI\Livewire\RunDetail;
use Pandora\UI\Livewire\RunsIndex;
use Pandora\UI\Livewire\ToolsIndex;

/**
 * Phase 9, T13 -- every control-center page is behind a gate.
 *
 * `routes/web.php` states the rule and the reason: *"Authorization is enforced
 * inside each component -- route middleware alone is not treated as
 * sufficient."* All eighteen components follow it. Nothing made them.
 *
 * Twenty-five ablations over the page gates and the four that gate separately
 * found **six** that could be deleted with the whole suite green:
 * `AgentDetail`, `AutomationDetail`, `RunDetail`, `RunsIndex`, `ChannelLink`,
 * and the `tools.io.view` check guarding tool schemas on `ToolsIndex`.
 *
 * The distribution is the interesting part. Every INDEX page but one has a
 * "denies a user without pandora.access" test; the DETAIL pages have none at
 * all. Detail pages are where an index's one line becomes a prompt, a webhook
 * secret or a full execution trace, so the pages that went unasserted are the
 * ones with the most to show. Nobody decided that -- the index tests were
 * written as a set and the detail tests were written for what the page
 * displays.
 *
 * This file closes both halves: an architectural test so a NINETEENTH page
 * cannot be added ungated, and a denial test for each page that had none.
 */
uses(MakesRuns::class);

beforeEach(function (): void {
    $this->user = $this->actingAsUser();
});

/**
 * The general rule, asserted the way T15's `$guarded` rule and T6a's
 * outbound-HTTP rule are: over the source, so the day someone forgets is the
 * day CI goes red rather than the day an auditor happens to look.
 *
 * Deliberately "somewhere in the class" rather than "inside mount()".
 * `ChannelLink` authorizes from a private `guard()` it calls from `mount()`,
 * which is correct and which a mount-only rule would flag as a violation --
 * and a rule that reports false violations gets an exemption list, then gets
 * ignored.
 */
it('gates every control-center page component', function (): void {
    $components = glob(dirname(__DIR__, 2).'/src/UI/Livewire/*.php') ?: [];

    expect($components)->not->toBeEmpty('no Livewire components found -- has the path moved?');

    $ungated = [];

    foreach ($components as $path) {
        $source = (string) file_get_contents($path);

        if (! str_contains($source, 'PandoraGate::authorize(')) {
            $ungated[] = basename($path, '.php');
        }
    }

    expect($ungated)->toBe([], 'these page components authorize nothing: '.implode(', ', $ungated));
});

/**
 * Every ability the configuration advertises is actually consulted.
 *
 * A configured ability is a promise to an operator: grant this and something
 * changes, withhold it and something else does. An ability nothing reads is a
 * switch wired to no lamp, and the operator cannot tell the difference from
 * outside.
 */
it('consults every ability it advertises, or names the exception', function (): void {
    /** @var array<string, string> $abilities */
    $abilities = config('pandora.abilities', []);

    // Recursive over the whole of src/, because the gate is consulted from
    // Livewire, Realtime and Approvals alike -- a glob of one directory would
    // report abilities as unused that are merely read somewhere else.
    $directory = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src'),
    );

    $source = '';

    foreach ($directory as $file) {
        /** @var SplFileInfo $file */
        if ($file->getExtension() === 'php') {
            $source .= (string) file_get_contents($file->getPathname());
        }
    }

    $unused = [];

    foreach (array_keys($abilities) as $key) {
        if (! str_contains($source, "'".$key."'")) {
            $unused[] = $key;
        }
    }

    // TWO of the twenty are wired to nothing, for the same reason: the ability
    // was declared for a surface that was never built.
    //
    //   - `audit.view` -- there is no audit PAGE. Phase 6 closed "no audit
    //     page" as an open decision and the ability was left pointing at it.
    //   - `tools.manage` -- the Tools page is read-only. Its only action,
    //     `toggle()`, expands a row; there is nothing to manage.
    //
    // Neither exposes anything: no audit record reaches any view, and no tool
    // can be altered from the UI at all. These are promises with nothing behind
    // them rather than holes. An operator granting or withholding either sees
    // no difference, which they cannot discover from outside.
    //
    // Listed here rather than deleted from the config, because removing a
    // published key breaks a host that references it -- and listed rather than
    // ignored, because the NEXT unused ability must not be able to hide behind
    // these two. Both belong in the v1.0 support statement (criterion 33).
    expect($unused)->toBe(
        ['tools.manage', 'audit.view'],
        'abilities declared but never consulted: '.implode(', ', $unused),
    );
});

it('denies the agent detail page to a user without pandora.access', function (): void {
    // The page that renders an agent's role instructions.
    $agent = $this->makeAgent();

    Gate::define('pandora.access', static fn (): bool => false);

    Livewire::test(AgentDetail::class, ['agent' => $agent->slug])->assertForbidden();
});

it('denies the automation detail page to a user without pandora.access', function (): void {
    Gate::define('pandora.access', static fn (): bool => false);

    Livewire::test(AutomationDetail::class, ['automation' => 'anything'])->assertForbidden();
});

it('denies the run detail page to a user without pandora.access', function (): void {
    // The whole execution trace lives here.
    $run = $this->makeRun(['conversation_id' => $this->makeConversation()->getKey()]);

    Gate::define('pandora.access', static fn (): bool => false);

    Livewire::test(RunDetail::class, ['run' => (string) $run->getKey()])->assertForbidden();
});

it('denies the runs index to a user without pandora.access', function (): void {
    Gate::define('pandora.access', static fn (): bool => false);

    Livewire::test(RunsIndex::class)->assertForbidden();
});

it('denies the channel link page to a user without pandora.access', function (): void {
    Gate::define('pandora.access', static fn (): bool => false);

    Livewire::test(ChannelLink::class)->assertForbidden();
});

it('hides tool schemas from a user without pandora.tools.io.view', function (): void {
    // The separate gate on ToolsIndex. A tool's input schema is tool I/O: it
    // names arguments an operator may not be cleared to see.
    //
    // TWO assertions, because `render()` reads the ability twice and only one
    // of the two was asserted. `canViewSchemas` is the flag the template
    // branches on -- the braces. The `if` above it decides whether the schemas
    // are ASSEMBLED at all -- the belt. Deleting the belt left the whole suite
    // green, because the template still hid what it was handed.
    //
    // Nothing leaked either way: view data that is never echoed does not reach
    // the browser. But the belt is the half that does not depend on every
    // future template getting its branch right, and it was the unasserted one.
    Gate::define('pandora.access', static fn (): bool => true);
    Gate::define('pandora.tools.io.view', static fn (): bool => false);

    Livewire::test(ToolsIndex::class)
        ->assertOk()
        ->assertViewHas('canViewSchemas', false)
        ->assertViewHas('schemas', []);
});

it('shows tool schemas to a user who holds the ability', function (): void {
    // Without this the assertion above is satisfied by a page that never
    // assembles schemas for anyone.
    Gate::define('pandora.access', static fn (): bool => true);
    Gate::define('pandora.tools.io.view', static fn (): bool => true);

    Livewire::test(ToolsIndex::class)
        ->assertOk()
        ->assertViewHas('canViewSchemas', true)
        ->assertViewHas('schemas', fn (array $schemas): bool => $schemas !== []);
});

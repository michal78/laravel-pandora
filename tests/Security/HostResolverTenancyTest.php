<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Pandora\Agents\Agent;
use Pandora\Contracts\TenantResolver;
use Pandora\Core\Tenancy\TenantContext;
use Pandora\Core\Tenancy\TenantManager;
use Pandora\Tests\Fixtures\SwitchableTenantResolver;
use Pandora\Tests\Support\MakesRuns;
use Pandora\UI\Livewire\WorkspacesIndex;
use Pandora\Workspaces\Workspace;
use Pandora\Workspaces\WorkspaceRoots;

uses(MakesRuns::class);

/**
 * Phase 9, T2 — tenancy as a *host* configures it.
 *
 * This file exists because of a gap that took a walkthrough to notice and a
 * grep to confirm. Every tenancy test in this suite -- and there are many, and
 * they are good -- reaches a tenant through `inTenant()`, which is
 * `TenantManager::with()`: the override path, the one a queued job uses to
 * re-enter a tenant it carries in its payload. The path a host application
 * uses is the other one. A host binds `pandora.tenancy.resolver`, and
 * `TenantManager::current()` consults that resolver only when nothing has
 * overridden it.
 *
 * So `$this->resolver->current()` was a line no test reached, guarded by
 * `NullTenantResolver`, which returns null unconditionally. A suite green
 * against that resolver cannot tell "Pandora asked, and the answer was null"
 * apart from "Pandora never asked" -- and in a single-tenant application those
 * two produce identical output forever. This is the Phase 6 shape exactly: a
 * fake at a boundary makes the boundary untested by construction.
 *
 * The two boxes in `phase-7-walkthrough.md` under *Tenancy, if the host has
 * it* were left undriven because `laravel-test` runs with a null tenant, so
 * driving them would have proved nothing. They are driven here instead,
 * against a resolver that answers and whose answer changes, which is what
 * "the host has it" actually means.
 *
 * **Verified by removal**, which is the Phase 9 bar: deleting the
 * `pandora.tenancy.resolver` line in `beforeEach` fails eight of these nine.
 * The ninth is the null-tenant case, which passes either way and should --
 * `NullTenantResolver` answering null is the behaviour it asserts.
 */
beforeEach(function (): void {
    SwitchableTenantResolver::reset();

    config()->set('pandora.tenancy.resolver', SwitchableTenantResolver::class);

    // Both are singletons, so a `NullTenantResolver` already built earlier in
    // the test case would survive the config line above and this whole file
    // would pass while testing the default resolver.
    //
    // Insurance, not a proven necessity: removing these two lines was tried and
    // the file still passed, because nothing here resolves either binding
    // before `beforeEach` runs. They stay because that is a property of the
    // current test order rather than of the code, and the failure they prevent
    // is silent.
    app()->forgetInstance(TenantResolver::class);
    app()->forgetInstance(TenantManager::class);

    $this->base = sys_get_temp_dir().'/pandora-hostres-'.bin2hex(random_bytes(6));
    mkdir($this->base, 0777, true);

    config()->set('pandora.workspaces.roots', [
        'scratch' => [
            'label' => 'Scratch space',
            'disk' => 'local',
            'base_prefix' => $this->base,
        ],
    ]);
});

afterEach(function (): void {
    SwitchableTenantResolver::reset();
});

/**
 * The load-bearing one. Everything else in this file is a consequence.
 */
it('consults the host resolver when nothing has overridden it', function (): void {
    SwitchableTenantResolver::$tenant = 'acme';

    expect(app(TenantManager::class)->currentId())->toBe('acme');

    SwitchableTenantResolver::$tenant = 'globex';

    expect(app(TenantManager::class)->currentId())->toBe('globex');
});

it('stamps the resolver\'s tenant on a record created with no override', function (): void {
    SwitchableTenantResolver::$tenant = 'acme';

    $agent = $this->makeAgent();

    // Not `inTenant('acme', ...)`. The tenant arrived the way it arrives in a
    // host application: nobody passed it.
    expect($agent->tenant_id)->toBe('acme');
});

it('hides a record from a host request resolving to another tenant', function (): void {
    SwitchableTenantResolver::$tenant = 'acme';
    $acme = $this->makeAgent();

    SwitchableTenantResolver::$tenant = 'globex';

    expect(Agent::query()->find($acme->getKey()))->toBeNull()
        ->and(Agent::query()->count())->toBe(0);
});

/**
 * `phase-7-walkthrough.md`, box 1 — two tenants, one workspace name.
 */
it('gives two tenants different roots for the same workspace name', function (): void {
    $roots = app(WorkspaceRoots::class);
    $root = $roots->get('scratch');

    SwitchableTenantResolver::$tenant = 'acme';
    $acmePath = $roots->compose($root, 'invoices');

    SwitchableTenantResolver::$tenant = 'globex';
    $globexPath = $roots->compose($root, 'invoices');

    expect($acmePath)->not->toBe($globexPath)
        ->and($acmePath)->toEndWith('/acme/invoices')
        ->and($globexPath)->toEndWith('/globex/invoices');
});

it('does not let one tenant list another\'s workspace through the page', function (): void {
    Gate::define('pandora.access', static fn (): bool => true);
    Gate::define('pandora.workspaces.access', static fn (): bool => true);
    config()->set('pandora.features.workspaces', true);
    $this->actingAsUser();

    SwitchableTenantResolver::$tenant = 'acme';

    $root = $this->base.'/acme/invoices';
    mkdir($root, 0777, true);
    file_put_contents($root.'/secret.txt', 'acme only');

    Workspace::query()->create([
        'name' => 'Acme invoices',
        'slug' => 'acme-invoices',
        'disk' => 'local',
        'root_path' => $root,
    ]);

    SwitchableTenantResolver::$tenant = 'globex';

    Livewire::test(WorkspacesIndex::class)
        ->assertDontSee('Acme invoices')
        ->call('select', 'acme-invoices')
        ->assertDontSee('secret.txt');
});

/**
 * `phase-7-walkthrough.md`, box 2 — the forged action.
 *
 * The walkthrough asks for a 404 and specifically not a 403, because a 403
 * confirms the slug exists and the slug is the thing being guessed. A page
 * that hides a row and then acts on it when handed its slug is not isolated,
 * it is politely arranged.
 */
it('finds nothing to act on when handed another tenant\'s slug', function (): void {
    Gate::define('pandora.access', static fn (): bool => true);
    Gate::define('pandora.workspaces.access', static fn (): bool => true);
    Gate::define('pandora.workspaces.manage', static fn (): bool => true);
    config()->set('pandora.features.workspaces', true);
    $this->actingAsUser();

    SwitchableTenantResolver::$tenant = 'acme';

    $root = $this->base.'/acme/invoices';
    mkdir($root, 0777, true);
    file_put_contents($root.'/secret.txt', 'acme only');

    $acme = Workspace::query()->create([
        'name' => 'Acme invoices',
        'slug' => 'acme-invoices',
        'disk' => 'local',
        'root_path' => $root,
    ]);

    SwitchableTenantResolver::$tenant = 'globex';

    Livewire::test(WorkspacesIndex::class)
        ->call('select', 'acme-invoices')
        ->call('recount')
        ->call('delete', 'acme-invoices');

    SwitchableTenantResolver::$tenant = 'acme';

    // The row is untouched and so are the files. A cross-tenant `remove()`
    // that quietly succeeded would be a deletion with no attacker in the logs.
    expect(Workspace::query()->find($acme->getKey()))->not->toBeNull()
        ->and(is_file($root.'/secret.txt'))->toBeTrue();
});

/**
 * The one a host walkthrough could not have proved by clicking.
 *
 * A worker has no request, so it has no subdomain, no session and no path
 * segment -- the resolver in a worker answers for whatever the *worker*
 * looks like, which is nothing. Jobs therefore carry `tenantId` and re-enter
 * it through `withPandoraContext()`. If the override ever stopped winning over
 * the resolver, every queued run would silently execute as the wrong tenant,
 * and the failure would be invisible in a single-tenant application and
 * catastrophic in any other.
 */
it('lets a carried tenant win over the resolver, the way a queue worker needs', function (): void {
    SwitchableTenantResolver::$tenant = 'globex';

    $seen = app(TenantManager::class)->with(
        new TenantContext('acme'),
        static fn (): ?string => app(TenantManager::class)->currentId(),
    );

    expect($seen)->toBe('acme')
        ->and(app(TenantManager::class)->currentId())->toBe('globex');
});

it('restores the resolver\'s tenant after a carried one is done', function (): void {
    SwitchableTenantResolver::$tenant = 'globex';

    app(TenantManager::class)->with(new TenantContext('acme'), static function (): void {
        // A job inside a job -- delegation dispatches one from within another.
        app(TenantManager::class)->with(new TenantContext('initech'), static function (): void {
            expect(app(TenantManager::class)->currentId())->toBe('initech');
        });

        expect(app(TenantManager::class)->currentId())->toBe('acme');
    });

    expect(app(TenantManager::class)->currentId())->toBe('globex');
});

/**
 * The null tenant is a real answer, not an absent one.
 */
it('still applies no scope when the host resolver answers null', function (): void {
    SwitchableTenantResolver::$tenant = null;

    $agent = $this->makeAgent();

    expect($agent->tenant_id)->toBeNull()
        ->and(Agent::query()->find($agent->getKey()))->not->toBeNull();
});

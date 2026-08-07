<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Pandora\Audit\AuditLogger;
use Pandora\Exceptions\WorkspaceDenied;
use Pandora\Memory\Enums\MemoryScope;
use Pandora\Memory\Enums\MemorySource;
use Pandora\Memory\Enums\MemoryType;
use Pandora\Memory\MemoryExporter;
use Pandora\Memory\MemoryItem;
use Pandora\Memory\MemoryQuery;
use Pandora\Memory\MemoryRetriever;
use Pandora\Memory\MemoryScopeSet;
use Pandora\Workspaces\Workspace;
use Pandora\Workspaces\WorkspaceFiles;

/**
 * Phase 5, criterion 28 -- a tenant reaches none of another tenant's memory or
 * files, through any route.
 *
 * `ScopingTest` proves the retrieval predicate. This proves the doors around
 * it: export, workspace lookup, workspace contents, and direct model access.
 * A boundary that holds in one query and not in the four ways around it is not
 * a boundary.
 */
beforeEach(function (): void {
    Gate::define('pandora.memory.manage', static fn (): bool => true);
    Gate::define('pandora.workspaces.access', static fn (): bool => true);
    $this->actingAsUser();

    $this->root = sys_get_temp_dir().'/pandora-tenancy-'.bin2hex(random_bytes(6));
    mkdir($this->root.'/acme', 0777, true);
    mkdir($this->root.'/globex', 0777, true);
    file_put_contents($this->root.'/acme/secret.txt', 'acme confidential');
});

afterEach(function (): void {
    foreach (['acme', 'globex'] as $dir) {
        foreach (glob($this->root.'/'.$dir.'/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->root.'/'.$dir);
    }

    @rmdir($this->root);
});

it('does not export another tenant\'s memory', function (): void {
    inTenant('acme', function (): void {
        MemoryItem::query()->create([
            'scope' => MemoryScope::User->value,
            'scope_id' => 'App\\Models\\User#1',
            'type' => MemoryType::UserFact->value,
            'content' => 'acme confidential fact',
            'source' => MemorySource::User->value,
        ]);
    });

    $export = inTenant(
        'globex',
        fn () => app(MemoryExporter::class)->export(MemoryScope::User, 'App\\Models\\User#1', includeInactive: true),
    );

    expect($export['count'])->toBe(0)
        ->and(json_encode($export, JSON_THROW_ON_ERROR))->not->toContain('acme confidential');
});

it('does not retrieve another tenant\'s memory even with an identical scope id', function (): void {
    inTenant('acme', function (): void {
        MemoryItem::query()->create([
            'scope' => MemoryScope::User->value,
            'scope_id' => 'App\\Models\\User#1',
            'type' => MemoryType::UserFact->value,
            'content' => 'orchid is the acme codeword',
            'source' => MemorySource::User->value,
        ]);
    });

    $found = inTenant('globex', function () {
        $scopes = MemoryScopeSet::of(
            [['scope' => MemoryScope::User, 'scope_id' => 'App\\Models\\User#1']],
            'globex',
        );

        return app(MemoryRetriever::class)->retrieve($scopes, MemoryQuery::for('orchid codeword'));
    });

    expect($found)->toBe([]);
});

it('hides another tenant\'s memory from a direct model query', function (): void {
    inTenant('acme', function (): void {
        MemoryItem::query()->create([
            'scope' => MemoryScope::Tenant->value,
            'scope_id' => null,
            'type' => MemoryType::AgentCurated->value,
            'content' => 'acme note',
            'source' => MemorySource::Agent->value,
        ]);
    });

    inTenant('globex', function (): void {
        // Including `find()`, which is where cross-tenant leaks usually come
        // from.
        $acme = MemoryItem::acrossAllTenants()->first();

        expect(MemoryItem::query()->count())->toBe(0)
            ->and(MemoryItem::query()->find($acme->getKey()))->toBeNull();
    });
});

it('hides another tenant\'s workspace', function (): void {
    inTenant('acme', function (): void {
        Workspace::query()->create([
            'name' => 'Acme files',
            'slug' => 'files',
            'disk' => 'local',
            'root_path' => $this->root.'/acme',
        ]);
    });

    inTenant('globex', function (): void {
        expect(Workspace::query()->count())->toBe(0)
            ->and(Workspace::query()->where('slug', 'files')->first())->toBeNull();
    });
});

it('lets two tenants use the same workspace slug without colliding', function (): void {
    inTenant('acme', fn () => Workspace::query()->create([
        'name' => 'Files', 'slug' => 'files', 'disk' => 'local', 'root_path' => $this->root.'/acme',
    ]));

    inTenant('globex', fn () => Workspace::query()->create([
        'name' => 'Files', 'slug' => 'files', 'disk' => 'local', 'root_path' => $this->root.'/globex',
    ]));

    expect(Workspace::acrossAllTenants()->count())->toBe(2);
});

it('does not read another tenant\'s workspace contents', function (): void {
    inTenant('acme', fn () => Workspace::query()->create([
        'name' => 'Files', 'slug' => 'files', 'disk' => 'local', 'root_path' => $this->root.'/acme',
    ]));

    inTenant('globex', function (): void {
        $globex = Workspace::query()->create([
            'name' => 'Files', 'slug' => 'files', 'disk' => 'local', 'root_path' => $this->root.'/globex',
        ]);

        $files = new WorkspaceFiles($globex, app(AuditLogger::class));

        // Each workspace's root is its own, and traversal between them is
        // refused like any other escape.
        expect($files->list())->toBe([])
            ->and(fn () => $files->read('../acme/secret.txt'))
            ->toThrow(WorkspaceDenied::class);
    });
});

it('stamps the tenant on a memory written inside one', function (): void {
    $item = inTenant('acme', fn () => MemoryItem::query()->create([
        'scope' => MemoryScope::Tenant->value,
        'scope_id' => null,
        'type' => MemoryType::AgentCurated->value,
        'content' => 'acme note',
        'source' => MemorySource::Agent->value,
    ]));

    expect($item->tenant_id)->toBe('acme');
});

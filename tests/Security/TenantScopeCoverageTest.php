<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Pandora\Core\Tenancy\Concerns\BelongsToTenant;
use Pandora\Providers\Credentials\ProviderCredential;

/*
 * Phase 9, criterion 2 -- T2, the opt-in nobody checks.
 *
 * `Security/TenantIsolationTest` proves the scope works, and the audit of
 * 2026-08-19 proved it load-bearing: delete the `where` clause from
 * `BelongsToTenant` and seventeen tests go red. What none of that proves is
 * that any *particular* model asked for the scope. Removing `use
 * BelongsToTenant;` from several of the twenty-six models that carry it left
 * the entire suite green -- an approval, an audit log, a webhook delivery and
 * a channel delivery among them, which is to say the record of who authorised
 * a destructive call and the record that it happened.
 *
 * Per-model behavioural tests cannot fix that, because the model this is
 * really about is the one somebody adds next month: a table that has not been
 * written yet has no test to go red. So this asks the schema instead. Any
 * model whose table carries a `tenant_id` column must use the trait, and the
 * only way to be exempt is to be named below, in a list a reviewer reads.
 *
 * This lives under Security rather than beside T15's `$fillable` rule in
 * `Architecture/ModuleBoundaryTest`, which is the closest relative in shape.
 * That file runs without the Laravel `TestCase` -- see the `uses()` list in
 * `tests/Pest.php`, which omits `Architecture` -- so it has no schema to ask,
 * and the whole point here is to ask the real schema rather than to parse
 * migrations and hope the parse stays accurate.
 */

/**
 * Models allowed to carry a `tenant_id` without the global scope.
 *
 * @return list<class-string<Model>>
 */
function tenantScopeExempt(): array
{
    return [
        // A deployment-wide credential has a null `tenant_id` on purpose, and
        // the global scope would hide it the moment a tenant resolves --
        // which is exactly when a run needs it. Isolation is a WHERE clause in
        // `DatabaseCredentialResolver` instead, and
        // `Security/CredentialIsolationTest` is what proves it. See the class
        // docblock on `ProviderCredential`.
        ProviderCredential::class,
    ];
}

/**
 * Every model in `src/` whose table carries a `tenant_id` column.
 *
 * @return array<class-string<Model>, string>
 */
function tenantScopedTables(): array
{
    $tables = [];

    foreach (array_keys(pandoraSourceClasses()) as $class) {
        if (! is_subclass_of($class, Model::class)) {
            continue;
        }

        /** @var Model $model */
        $model = new $class;
        $table = $model->getTable();

        if (Schema::hasColumn($table, 'tenant_id')) {
            $tables[$class] = $table;
        }
    }

    return $tables;
}

it('scopes every model whose table has a tenant_id', function (): void {
    $offenders = [];

    foreach (tenantScopedTables() as $class => $table) {
        if (in_array($class, tenantScopeExempt(), true)) {
            continue;
        }

        if (! in_array(BelongsToTenant::class, class_uses_recursive($class), true)) {
            $offenders[] = "{$class} ({$table}) has a tenant_id column and does not use BelongsToTenant";
        }
    }

    expect($offenders)->toBe([]);
});

it('finds tenant-scoped tables to check', function (): void {
    // The guard on the guard, in the manner of `ModuleBoundaryTest`'s "finds
    // models to check". A rule that iterates a collection proves nothing when
    // the collection is empty, and this one selects through `Schema::hasColumn`
    // against a migrated database -- a predicate with two ways to quietly
    // start matching nothing.
    expect(count(tenantScopedTables()))->toBeGreaterThanOrEqual(25);
});

it('exempts nothing but the credential the resolver scopes by hand', function (): void {
    // The exemption list is the one part of this rule that can be edited to
    // make a failure go away. Pinning it means widening it is a deliberate act
    // that shows up in a diff and has to be argued for here, rather than a
    // quiet third entry appended under a deadline.
    expect(tenantScopeExempt())->toBe([ProviderCredential::class]);

    // And the exemption has to stay true: the day `ProviderCredential` gains
    // the trait, this file is wrong about why it is on the list.
    expect(class_uses_recursive(ProviderCredential::class))
        ->not->toContain(BelongsToTenant::class);
});

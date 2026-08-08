<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Pandora\Workspaces\Workspace;

/**
 * Phase 7, criterion 8 — Pandora stores no object-storage credential.
 *
 * ADR-0013 keeps every key, secret and endpoint in the host's
 * `filesystems.php`. A workspace names a disk and nothing more, so there is no
 * second secret store to leak, and rotation happens where the host already
 * rotates.
 *
 * The reason this is a criterion rather than a note is that the pressure to
 * add "just an endpoint field" arrives the first time somebody wants a
 * per-tenant bucket. A field that accepts an endpoint is a field that accepts
 * somebody else's endpoint, and it arrives with a key field beside it. If that
 * changes it should change through the ADR, and this test failing is what
 * makes it a decision rather than a commit.
 */
it('has no credential column on the workspaces table', function (): void {
    $columns = Schema::connection(config('pandora.database.connection'))
        ->getColumnListing((new Workspace)->getTable());

    foreach ($columns as $column) {
        expect($column)->not->toMatch('/secret|access_key|credential|token|password|endpoint/i');
    }
});

it('has no credential attribute a request could fill', function (): void {
    // Mass assignment is the other way in: a column that does not exist cannot
    // be written, but a model that forwards unknown keys can be pointed at one
    // added later without anybody revisiting this.
    foreach ((new Workspace)->getFillable() as $attribute) {
        expect($attribute)->not->toMatch('/secret|access_key|credential|token|password|endpoint/i');
    }
});

it('names a disk and lets the host say what that disk is', function (): void {
    /** @var Workspace $workspace */
    $workspace = Workspace::query()->create([
        'name' => 'Bucket',
        'slug' => 'bucket',
        'disk' => 'spaces',
        'root_path' => 'workspaces/bucket',
    ]);

    // Everything the workspace knows about where its bytes live. A reader of
    // this row learns the name of a disk, which is useless without the
    // application's own configuration.
    expect($workspace->disk)->toBe('spaces')
        ->and($workspace->toArray())->not->toHaveKey('endpoint')
        ->and(json_encode($workspace->toArray(), JSON_THROW_ON_ERROR))
        ->not->toContain('secret');
});

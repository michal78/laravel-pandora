<?php

declare(strict_types=1);

use Pandora\Audit\AuditLog;
use Pandora\Mcp\McpServer;
use Pandora\Mcp\SkillDiscovery;
use Pandora\Skills\Skill;

/**
 * Phase 6, criterion 29 — a skill discovered from MCP lands unapproved, is
 * stored as instructions only, and is never executed.
 *
 * The second source Phase 5 anticipated, and precisely the case ADR-0008 was
 * written for. A skill from a remote server is the description attack without
 * the tool call: the text does not have to persuade a model to do something,
 * because it simply becomes part of what the agent was told.
 */
beforeEach(function (): void {
    /** @var McpServer $server */
    $server = McpServer::query()->create([
        'name' => 'Ledger', 'slug' => 'ledger', 'namespace' => 'ledger',
        'endpoint' => 'https://mcp.example.test/rpc',
    ]);

    $this->server = $server;
    $this->ingest = fn (array $descriptors): array => app(SkillDiscovery::class)
        ->ingest($this->server, $descriptors);
});

it('stores a discovered skill', function (): void {
    ($this->ingest)([
        ['name' => 'Invoice triage', 'description' => 'How to triage invoices.', 'instructions' => 'Check the total first.'],
    ]);

    /** @var Skill $skill */
    $skill = Skill::query()->firstOrFail();

    expect($skill->name)->toBe('Invoice triage')
        ->and($skill->instructions)->toBe('Check the total first.')
        ->and($skill->source)->toBe('mcp');
});

it('lands it disabled', function (): void {
    ($this->ingest)([
        ['name' => 'Invoice triage', 'instructions' => 'Check the total first.'],
    ]);

    // A remote server that could ship an ENABLED skill could write an agent's
    // instructions from the far side of the boundary.
    expect(Skill::query()->firstOrFail()->enabled)->toBeFalse();
});

it('attaches it to no agent', function (): void {
    ($this->ingest)([
        ['name' => 'Invoice triage', 'instructions' => 'Check the total first.'],
    ]);

    /** @var Skill $skill */
    $skill = Skill::query()->firstOrFail();

    // Discovery writes a row. Nothing is granted to anybody by discovery, and
    // that is the same rule the tool half follows.
    expect($skill->agents()->count())->toBe(0);
});

it('namespaces the slug so two servers cannot collide', function (): void {
    ($this->ingest)([['name' => 'Triage', 'instructions' => 'Ours.']]);

    /** @var McpServer $other */
    $other = McpServer::query()->create([
        'name' => 'Other', 'slug' => 'other', 'namespace' => 'other',
        'endpoint' => 'https://other.example.test/rpc',
    ]);

    app(SkillDiscovery::class)->ingest($other, [['name' => 'Triage', 'instructions' => 'Theirs.']]);

    expect(Skill::query()->count())->toBe(2)
        ->and(Skill::query()->pluck('slug')->all())->toBe(['ledger-triage', 'other-triage']);
});

it('records that it enabled nothing', function (): void {
    ($this->ingest)([['name' => 'Triage', 'instructions' => 'Ours.']]);

    /** @var AuditLog $entry */
    $entry = AuditLog::query()
        ->where('action', 'mcp.discovery_completed')
        ->firstOrFail();

    expect($entry->metadata['skills_enabled'] ?? null)->toBe(0);
});

it('skips a descriptor with nothing to store', function (): void {
    $result = ($this->ingest)([
        ['name' => '', 'instructions' => 'orphaned'],
        ['name' => 'No instructions'],
        ['name' => '...', 'instructions' => 'unusable slug'],
    ]);

    expect($result['discovered'])->toBe(0)
        ->and($result['skipped'])->toBe(3)
        ->and(Skill::query()->count())->toBe(0);
});

it('has nowhere to put executable content, whatever the server sends', function (): void {
    ($this->ingest)([[
        'name' => 'Sneaky',
        'instructions' => '<?php system("rm -rf /"); ?>',
        // Fields that would matter if anything ever executed a skill. None of
        // them exist on the model, so they land nowhere.
        'code' => 'system("rm -rf /");',
        'command' => '/bin/sh',
        'handler' => 'App\\Evil::run',
    ]]);

    /** @var Skill $skill */
    $skill = Skill::query()->firstOrFail();

    // ADR-0008: the payload is TEXT. It is stored verbatim, it is never
    // parsed looking for something to run, and there is no column that could
    // hold something executable.
    expect($skill->instructions)->toBe('<?php system("rm -rf /"); ?>')
        ->and($skill->getAttributes())->not->toHaveKey('code')
        ->and($skill->getAttributes())->not->toHaveKey('command')
        ->and($skill->getAttributes())->not->toHaveKey('handler');
});

it('bounds what a server can store', function (): void {
    ($this->ingest)([[
        'name' => str_repeat('n', 5000),
        'description' => str_repeat('d', 50000),
        'instructions' => str_repeat('i', 500000),
    ]]);

    /** @var Skill $skill */
    $skill = Skill::query()->firstOrFail();

    expect(mb_strlen($skill->name))->toBe(191)
        ->and(mb_strlen((string) $skill->description))->toBe(2000)
        ->and(mb_strlen($skill->instructions))->toBe(100000)
        // The slug is DERIVED from the unbounded name, so bounding the name
        // did not bound it. `pandora_skills.slug` is `varchar(191)`; SQLite
        // ignores declared widths and MySQL, MariaDB and PostgreSQL do not, so
        // this assertion was the difference between a green suite and three red
        // CI legs.
        ->and(mb_strlen($skill->slug))->toBeLessThanOrEqual(191);
});

it('keeps two over-long skill names on two rows', function (): void {
    // Truncating to fit would collapse these onto one slug and the second
    // would silently overwrite the first -- a remote server could retire a
    // skill by naming a new one after it.
    $prefix = str_repeat('n', 400);

    ($this->ingest)([
        ['name' => $prefix.' alpha', 'instructions' => 'First.'],
        ['name' => $prefix.' beta', 'instructions' => 'Second.'],
    ]);

    $slugs = Skill::query()->pluck('slug');

    expect(Skill::query()->count())->toBe(2)
        ->and($slugs->unique())->toHaveCount(2)
        ->and($slugs->every(fn (string $slug): bool => mb_strlen($slug) <= 191))->toBeTrue();
});

it('updates a skill in place rather than accumulating versions of it', function (): void {
    ($this->ingest)([['name' => 'Triage', 'instructions' => 'First version.']]);
    ($this->ingest)([['name' => 'Triage', 'instructions' => 'Second version.']]);

    expect(Skill::query()->count())->toBe(1)
        ->and(Skill::query()->firstOrFail()->instructions)->toBe('Second version.')
        // And still disabled: a rewrite does not sneak past the human either.
        ->and(Skill::query()->firstOrFail()->enabled)->toBeFalse();
});

<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Pandora\Agents\Agent;
use Pandora\Agents\AgentRunner;
use Pandora\Audit\AuditLog;
use Pandora\Conversations\Conversation;
use Pandora\Core\Tenancy\TenantContext;
use Pandora\Core\Tenancy\TenantManager;
use Pandora\Exceptions\ImmutableRecord;
use Pandora\Messages\Message;
use Pandora\Providers\Catalog\CatalogModel;
use Pandora\Providers\Catalog\ModelCatalog;
use Pandora\Providers\Credentials\CredentialManager;
use Pandora\Providers\Credentials\ProviderCredential;
use Pandora\Runs\Run;
use Pandora\Runs\RunStep;
use Pandora\Tests\Support\MakesRuns;
use Pandora\Tests\TestCase;
use Pandora\Usage\UsageRecord;

uses(MakesRuns::class);

/**
 * `pandora:flush` clears what an agent DID, not what the deployment IS.
 */
function seedActivity(): Run
{
    /** @var TestCase $test */
    $test = test();

    $test->fakeProvider()->willRespondWith('Done.');

    return app(AgentRunner::class)
        ->agent($test->makeAgent())
        ->inConversation($test->makeConversation())
        ->run('Hello');
}

it('deletes conversations, runs, traces, messages and usage', function (): void {
    seedActivity();

    expect(Run::query()->count())->toBeGreaterThan(0)
        ->and(RunStep::query()->count())->toBeGreaterThan(0)
        ->and(UsageRecord::query()->count())->toBeGreaterThan(0);

    $this->artisan('pandora:flush', ['--force' => true])->assertSuccessful();

    expect(Run::query()->count())->toBe(0)
        ->and(RunStep::query()->count())->toBe(0)
        ->and(Message::query()->count())->toBe(0)
        ->and(Conversation::query()->count())->toBe(0)
        ->and(UsageRecord::query()->count())->toBe(0);
});

it('keeps the things that would have to be set up again', function (): void {
    seedActivity();

    app(CredentialManager::class)->issue('openai', 'sk-still-needed');
    app(ModelCatalog::class)->seedFromConfig([['provider' => 'openai', 'key' => 'gpt-4o-mini']]);

    $this->artisan('pandora:flush', ['--force' => true])->assertSuccessful();

    // Losing these would turn "clear the chats" into "set the whole thing up
    // again".
    expect(Agent::query()->count())->toBeGreaterThan(0)
        ->and(ProviderCredential::query()->count())->toBe(1)
        ->and(CatalogModel::query()->count())->toBe(1);
});

it('keeps the audit log unless asked', function (): void {
    seedActivity();

    expect(AuditLog::query()->count())->toBeGreaterThan(0);

    $this->artisan('pandora:flush', ['--force' => true])->assertSuccessful();

    // The audit log is the record of what happened, including things that no
    // longer exist. Deleting it is a separate decision.
    expect(AuditLog::query()->count())->toBeGreaterThan(0);

    $this->artisan('pandora:flush', ['--force' => true, '--audit' => true])->assertSuccessful();

    expect(AuditLog::query()->count())->toBe(0);
});

it('deletes everything with --all', function (): void {
    seedActivity();
    app(CredentialManager::class)->issue('openai', 'sk-going-away');

    $this->artisan('pandora:flush', ['--force' => true, '--all' => true])->assertSuccessful();

    expect(Agent::query()->count())->toBe(0)
        ->and(ProviderCredential::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('deletes one tenant without touching another', function (): void {
    $tenants = app(TenantManager::class);

    $tenants->with(new TenantContext('acme'), fn () => seedActivity());
    $tenants->with(new TenantContext('globex'), fn () => seedActivity());

    $this->artisan('pandora:flush', ['--force' => true, '--tenant' => 'acme'])->assertSuccessful();

    $tenants->with(new TenantContext('acme'), function (): void {
        expect(Run::query()->count())->toBe(0);
    });

    $tenants->with(new TenantContext('globex'), function (): void {
        expect(Run::query()->count())->toBe(1);
    });
});

it('deletes immutable records, which nothing else may', function (): void {
    seedActivity();

    // Run steps, audit entries and usage records refuse deletion through
    // Eloquent on purpose. This is the prune path their docblocks refer to.
    expect(fn () => RunStep::query()->first()?->delete())
        ->toThrow(ImmutableRecord::class);

    $this->artisan('pandora:flush', ['--force' => true])->assertSuccessful();

    expect(RunStep::query()->count())->toBe(0);
});

it('reports what it removed', function (): void {
    seedActivity();

    $this->artisan('pandora:flush', ['--force' => true])
        ->expectsOutputToContain('pandora_runs')
        ->expectsOutputToContain('row(s)')
        ->assertSuccessful();
});

it('says nothing was there rather than failing on an empty database', function (): void {
    $this->artisan('pandora:flush', ['--force' => true])
        ->expectsOutputToContain('Deleted 0 row(s).')
        ->assertSuccessful();
});

it('leaves a run created afterwards alone', function (): void {
    seedActivity();

    $this->artisan('pandora:flush', ['--force' => true])->assertSuccessful();

    $run = seedActivity();

    expect(Run::query()->count())->toBe(1)
        ->and($run->created_at)->toBeGreaterThanOrEqual(Carbon::now()->subMinute());
});

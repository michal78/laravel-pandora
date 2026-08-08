<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Pandora\Audit\AuditLog;
use Pandora\Channels\ChannelAccount;
use Pandora\Channels\ChannelInbox;
use Pandora\Channels\Data\InboundOutcome;
use Pandora\Runs\Run;
use Pandora\Tests\Support\MakesChannels;

/**
 * Phase 8, criterion 17 — a disabled account is inert in both directions.
 *
 * "Enabled" has to mean something on the inbound side as well as the outbound
 * one. An account an operator switched off while investigating something is an
 * account that must stop accepting traffic, not one that keeps creating runs and
 * merely declines to answer.
 */
uses(MakesChannels::class);

beforeEach(function (): void {
    $this->inbox = app(ChannelInbox::class);
});

it('accepts nothing while disabled', function (): void {
    $user = $this->actingAsUser();
    app('auth')->logout();

    $account = $this->makeChannelAccount(['enabled' => false]);
    $this->makeIdentity($account, 'U-1', $user);

    $result = $this->inbox->receive($this->fakeChannel()->message('U-1', 'hello'));

    expect($result->outcome)->toBe(InboundOutcome::Refused)
        ->and(Run::query()->count())->toBe(0)
        ->and($this->fakeChannel()->sent())->toHaveCount(0);
});

it('accepts nothing while no agent is bound', function (): void {
    $user = $this->actingAsUser();
    app('auth')->logout();

    $account = $this->makeChannelAccount();
    $account->forceFill(['agent_id' => null])->save();

    $this->makeIdentity($account, 'U-1', $user);

    // Not a broken account — one an operator has registered and not yet aimed.
    // A message with nowhere to go is better refused than queued against a
    // default nobody chose.
    $result = $this->inbox->receive($this->fakeChannel()->message('U-1', 'hello'));

    expect($result->outcome)->toBe(InboundOutcome::Refused)
        ->and(Run::query()->count())->toBe(0);
});

it('holds no credential of its own', function (): void {
    $columns = Schema::connection(config('pandora.database.connection'))
        ->getColumnListing((new ChannelAccount)->getTable());

    foreach ($columns as $column) {
        expect($column)->not->toMatch('/secret|token|password|api_key|access_key|bearer|signing/i');
    }

    foreach ((new ChannelAccount)->getFillable() as $attribute) {
        expect($attribute)->not->toMatch('/secret|token|password|api_key|access_key|bearer|signing/i');
    }
});

it('cannot register the same workspace twice on one channel', function (): void {
    $this->makeChannelAccount(['external_id' => 'W-1']);

    // Two accounts claiming one workspace is an ambiguous inbound route, and
    // the ambiguity would be resolved by whichever row a query happened to
    // return first.
    expect(fn () => $this->makeChannelAccount(['external_id' => 'W-1']))
        ->toThrow(QueryException::class);
});

it('audits a refusal for an account with no agent bound', function (): void {
    $user = $this->actingAsUser();
    app('auth')->logout();

    $account = $this->makeChannelAccount();
    $account->forceFill(['agent_id' => null])->save();

    $this->makeIdentity($account, 'U-1', $user);

    $this->inbox->receive($this->fakeChannel()->message('U-1', 'hello'));

    // A refusal that is correct, bounded and invisible is the defect Phase 6's
    // walkthrough found in delegation. Every refusal reaches the audit log with
    // a reason an operator can act on.
    $log = AuditLog::query()->where('action', 'channel.message_refused')->firstOrFail();

    expect($log->severity)->toBe('warning')
        ->and($log->metadata['reason'] ?? null)->toBe('no_agent_bound');
});

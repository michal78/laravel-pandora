<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Pandora\Automation\Automation;
use Pandora\Automation\AutomationDispatcher;
use Pandora\Automation\AutomationRun;
use Pandora\Automation\AutomationScheduler;
use Pandora\Automation\Enums\AutomationTrigger;
use Pandora\Automation\Webhooks\WebhookSignature;
use Pandora\Jobs\RunAutomation;
use Pandora\Runs\Run;
use Pandora\Tests\Fixtures\AutomationFactory;

/**
 * Phase 4, criterion 26 -- one tenant's automations are not another's.
 *
 * The awkward one here is the scheduler, which deliberately crosses tenants:
 * a tick runs in the console where nobody is resolved, and an automation
 * belonging to tenant B must not stop firing because nobody is tenant B at
 * 3am. That is a documented escape hatch (`acrossAllTenants()`), so the thing
 * worth proving is that the escape ends there -- the job carries the tenant,
 * the worker re-enters it, and every other path is scoped as usual.
 */
it('hides another tenant\'s automation from a query', function (): void {
    inTenant('acme', fn (): Automation => AutomationFactory::make(['slug' => 'acme-report']));

    inTenant('globex', function (): void {
        expect(Automation::query()->count())->toBe(0)
            ->and(Automation::query()->where('slug', 'acme-report')->first())->toBeNull();
    });
});

it('answers 404 to a webhook for another tenant\'s automation', function (): void {
    // Indistinguishable from a slug that never existed, which is the point:
    // a prober must not be able to enumerate other tenants' automations.
    inTenant('acme', fn (): Automation => AutomationFactory::make([
        'slug' => 'acme-inbound',
        'trigger_type' => AutomationTrigger::Webhook->value,
        'cron_expression' => null,
        'webhook_secret' => 'acme-secret',
    ]));

    $body = json_encode(['x' => 1]);

    inTenant('globex', function () use ($body): void {
        test()->call(
            'POST',
            '/pandora/webhooks/acme-inbound',
            server: ['HTTP_X_PANDORA_SIGNATURE' => WebhookSignature::sign('acme-secret', $body)],
            content: $body,
        )->assertStatus(404);
    });

    expect(Run::query()->count())->toBe(0);
});

it('stamps the automation\'s tenant on the occurrence and the run', function (): void {
    $automation = inTenant('acme', fn (): Automation => AutomationFactory::due(['slug' => 'acme-report']));

    inTenant('acme', function () use ($automation): void {
        $occurrence = app(AutomationDispatcher::class)->dispatch($automation, Carbon::now());

        expect($occurrence->tenant_id)->toBe('acme');

        /** @var Run $run */
        $run = Run::query()->findOrFail($occurrence->run_id);

        expect($run->tenant_id)->toBe('acme');
    });
});

it('lets the scheduler see every tenant, and hands each job its own', function (): void {
    Bus::fake();

    inTenant('acme', fn (): Automation => AutomationFactory::due(['slug' => 'acme-report']));
    inTenant('globex', fn (): Automation => AutomationFactory::due(['slug' => 'globex-report']));

    // No tenant resolved -- exactly the console's situation.
    $keys = app(AutomationScheduler::class)->tick();

    expect($keys)->toHaveCount(2);

    Bus::assertDispatched(
        RunAutomation::class,
        static fn (RunAutomation $job): bool => $job->tenantId === 'acme',
    );

    Bus::assertDispatched(
        RunAutomation::class,
        static fn (RunAutomation $job): bool => $job->tenantId === 'globex',
    );
});

it('keeps one tenant\'s occurrence history out of another\'s', function (): void {
    $automation = inTenant('acme', fn (): Automation => AutomationFactory::due(['slug' => 'acme-report']));

    inTenant('acme', function () use ($automation): void {
        app(AutomationDispatcher::class)->dispatch($automation, Carbon::now());
    });

    inTenant('globex', function (): void {
        expect(AutomationRun::query()->count())->toBe(0);
    });
});

it('refuses to run another tenant\'s automation from the console', function (): void {
    inTenant('acme', fn (): Automation => AutomationFactory::due(['slug' => 'acme-report']));

    inTenant('globex', function (): void {
        test()->artisan('pandora:automation:run', ['automation' => 'acme-report'])
            ->assertFailed();
    });

    expect(Run::query()->count())->toBe(0);
});

it('lists only the current tenant\'s automations', function (): void {
    inTenant('acme', fn (): Automation => AutomationFactory::make(['slug' => 'acme-report']));
    inTenant('globex', fn (): Automation => AutomationFactory::make(['slug' => 'globex-report']));

    inTenant('globex', function (): void {
        test()->artisan('pandora:automation:list')
            ->expectsOutputToContain('globex-report')
            ->doesntExpectOutputToContain('acme-report')
            ->assertSuccessful();
    });
});

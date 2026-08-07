<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Pandora\Runs\Enums\RunStepType;
use Pandora\Runs\RunStepRecorder;
use Pandora\Tests\Support\MakesRuns;
use Pandora\UI\Livewire\Dashboard;
use Pandora\UI\Livewire\RunDetail;
use Pandora\UI\Livewire\RunsIndex;

uses(MakesRuns::class);

beforeEach(function (): void {
    $this->user = $this->actingAsUser();
});

it('renders the dashboard', function (): void {
    Livewire::test(Dashboard::class)->assertOk()->assertSee('Agents');
});

it('denies the dashboard to an unauthorized user', function (): void {
    Gate::define('pandora.access', static fn (): bool => false);

    Livewire::test(Dashboard::class)->assertForbidden();
});

it('renders the runs index', function (): void {
    $this->makeRun();

    Livewire::test(RunsIndex::class)->assertOk();
});

it('renders a run trace', function (): void {
    $run = $this->makeRun();

    app(RunStepRecorder::class)->record(
        $run, RunStepType::ModelRequest, label: 'fake / fake-model',
    );

    Livewire::test(RunDetail::class, ['run' => (string) $run->getKey()])
        ->assertOk()
        ->assertSee('Model request')
        ->assertSee('fake / fake-model');
});

it('hides internal error detail from a user without trace permission', function (): void {
    $run = $this->makeRun([
        'error_class' => 'RuntimeException',
        'error_message' => 'INTERNAL STACK DETAIL /var/www/src/Secret.php:42',
    ]);

    Livewire::test(RunDetail::class, ['run' => (string) $run->getKey()])
        ->assertOk()
        ->assertDontSee('INTERNAL STACK DETAIL')
        ->assertSee('administrators only');
});

it('shows internal error detail to an administrator', function (): void {
    Gate::define('pandora.runs.trace.view', static fn (): bool => true);

    $run = $this->makeRun([
        'error_class' => 'RuntimeException',
        'error_message' => 'INTERNAL STACK DETAIL',
    ]);

    Livewire::test(RunDetail::class, ['run' => (string) $run->getKey()])
        ->assertOk()
        ->assertSee('INTERNAL STACK DETAIL');
});

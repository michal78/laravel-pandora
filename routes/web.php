<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Pandora\Pandora\UI\Livewire\AgentDetail;
use Pandora\Pandora\UI\Livewire\AgentsIndex;
use Pandora\Pandora\UI\Livewire\ApprovalsIndex;
use Pandora\Pandora\UI\Livewire\AutomationDetail;
use Pandora\Pandora\UI\Livewire\AutomationsIndex;
use Pandora\Pandora\UI\Livewire\Chat;
use Pandora\Pandora\UI\Livewire\Dashboard;
use Pandora\Pandora\UI\Livewire\MemoryIndex;
use Pandora\Pandora\UI\Livewire\ProvidersIndex;
use Pandora\Pandora\UI\Livewire\RunDetail;
use Pandora\Pandora\UI\Livewire\RunsIndex;
use Pandora\Pandora\UI\Livewire\ToolsIndex;
use Pandora\Pandora\UI\Livewire\UsageIndex;
use Pandora\Pandora\UI\Livewire\WorkspacesIndex;

/*
|--------------------------------------------------------------------------
| Pandora control center
|--------------------------------------------------------------------------
|
| Registered only when both `routes.enabled` and `ui.enabled` are true and
| Livewire is installed. Authorization is enforced inside each component --
| route middleware alone is not treated as sufficient.
|
*/

Route::get('/', Dashboard::class)->name('dashboard');
Route::get('/chat/{conversation?}', Chat::class)->name('chat');
Route::get('/agents', AgentsIndex::class)->name('agents');
Route::get('/agents/{agent}', AgentDetail::class)->name('agents.show');
Route::get('/automations', AutomationsIndex::class)->name('automations');
Route::get('/automations/{automation}', AutomationDetail::class)->name('automations.show');
Route::get('/runs', RunsIndex::class)->name('runs');
Route::get('/runs/{run}', RunDetail::class)->name('runs.show');
Route::get('/tools', ToolsIndex::class)->name('tools');
Route::get('/approvals', ApprovalsIndex::class)->name('approvals');
Route::get('/memory', MemoryIndex::class)->name('memory');
Route::get('/workspaces', WorkspacesIndex::class)->name('workspaces');
Route::get('/providers', ProvidersIndex::class)->name('providers');
Route::get('/usage', UsageIndex::class)->name('usage');

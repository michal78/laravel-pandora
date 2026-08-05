<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Pandora\Pandora\UI\Livewire\Chat;
use Pandora\Pandora\UI\Livewire\Dashboard;
use Pandora\Pandora\UI\Livewire\RunDetail;
use Pandora\Pandora\UI\Livewire\RunsIndex;

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
Route::get('/runs', RunsIndex::class)->name('runs');
Route::get('/runs/{run}', RunDetail::class)->name('runs.show');

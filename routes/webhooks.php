<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Pandora\Pandora\Automation\Http\WebhookController;

/*
|--------------------------------------------------------------------------
| Pandora automation webhooks
|--------------------------------------------------------------------------
|
| Registered under the Pandora route prefix but OUTSIDE the control center's
| middleware stack. An inbound webhook has no session, no CSRF token and no
| authenticated user; asking it for any of those would only mean an integrator
| disabling middleware until it worked.
|
| Authentication is the HMAC signature, and replay protection is the delivery
| nonce. Both are enforced in WebhookReceiver, not here -- a route file is not
| a security boundary.
|
*/

Route::post('{automation}', WebhookController::class)
    ->where('automation', '[A-Za-z0-9\-_]+')
    ->name('webhook');

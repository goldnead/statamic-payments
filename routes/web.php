<?php

use Goldnead\StatamicPayments\Http\Controllers\WebhookController;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;

/*
 * CSRF is dropped: the caller is the payment provider's server, not a browser.
 * It is safe without a token because the endpoint trusts nothing in the request
 * — see WebhookController.
 */
Route::post('/!/statamic-payments/webhook', WebhookController::class)
    ->middleware([ThrottleRequests::class.':'.(int) config('statamic-payments.rate_limit', 60).',1'])
    ->withoutMiddleware([
        // Drei Namen, nicht einer. In Laravel 12/13 steht in der `web`-Gruppe
        // `PreventRequestForgery`; `VerifyCsrfToken` ist dessen **Unterklasse**,
        // und `Router::resolveMiddleware()` entfernt nur, was Unterklasse des
        // Ausgeschlossenen ist — die Unterklasse auszuschliessen entfernt die
        // Oberklasse also nicht. Die Prüfung bleibt stehen, der Anbieter
        // schickt kein Token, und jede echte Zustellung endet mit 419.
        //
        // Im Testlauf ist das unsichtbar: `PreventRequestForgery::handle()`
        // steigt bei `runningUnitTests()` sofort aus. Deshalb prüft ein Test
        // die aufgesammelte Middleware-Liste statt eine Anfrage.
        //
        // Dieselben drei Namen schliesst Statamic selbst aus
        // (`vendor/statamic/cms/routes/web.php:106`).
        'App\Http\Middleware\VerifyCsrfToken',
        'Illuminate\Foundation\Http\Middleware\VerifyCsrfToken',
        'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken',
        'Illuminate\Foundation\Http\Middleware\PreventRequestForgery',
    ])
    ->name('statamic-payments.webhook');

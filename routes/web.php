<?php

use Goldnead\StatamicPayments\Http\Controllers\Legal\CancellationController as LegalCancellationController;
use Goldnead\StatamicPayments\Http\Controllers\Legal\WithdrawalController;
use Goldnead\StatamicPayments\Http\Controllers\OfferController;
use Goldnead\StatamicPayments\Http\Controllers\Portal\CancellationController;
use Goldnead\StatamicPayments\Http\Controllers\Portal\InvoiceController;
use Goldnead\StatamicPayments\Http\Controllers\Portal\MagicLinkController as PortalMagicLinkController;
use Goldnead\StatamicPayments\Http\Controllers\Portal\OrdersController;
use Goldnead\StatamicPayments\Http\Controllers\Portal\PaymentMethodController;
use Goldnead\StatamicPayments\Http\Controllers\WebhookController;
use Goldnead\StatamicPayments\Http\Middleware\SetBrandFromPortalSession;
use Goldnead\StatamicPayments\Portal\TrackingParameters;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ValidateSignature;
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

/*
 * Accepting a follow-up offer.
 *
 * Unlike the webhook above, this one keeps CSRF: the caller is a browser, a
 * person and an order. Dropping it would let a page on another site place an
 * order on this one.
 */
Route::post('/!/statamic-payments/offer', OfferController::class)
    ->middleware(['web', ThrottleRequests::class.':10,1'])
    ->name('statamic-payments.offer.accept');

/*
|--------------------------------------------------------------------------
| Kundenselbstbedienung — the buyer's own screens
|--------------------------------------------------------------------------
|
| Public routes for somebody with no account: their orders, their invoice,
| ending an agreement, changing the card it is charged against. The way in is a
| magic link to the address on the order.
|
| **Every parameter name here is prefixed `pay`. That is not style.** A
| `Route::bind()` is application-wide, not per package: a binding another addon
| registers for `{order}`, `{link}` or `{subscription}` applies to every route
| with that name in every installed addon, including these, and resolves it
| against a repository that has never heard of these values. That is exactly how
| goldnead/statamic-leadhub 1.8.0 shipped a delete button that did nothing. For
| the same reason nothing here uses implicit model binding: the id arrives as a
| string and is looked up through `Portal\Orders`, which is the only place that
| knows both conditions — the address *and* the brand — that make a row this
| person's.
|
| Numeric ids are constrained at the router. A non-numeric segment is a 404 from
| the routing layer rather than a `(int)` cast that turns "abc" into order 0.
|
*/

Route::prefix(config('statamic-payments.portal.prefix', '!/statamic-payments/konto'))
    ->middleware((array) config('statamic-payments.portal.middleware', ['web']))
    ->name('statamic-payments.portal.')
    ->group(function () {

        // Asking for a link is public and unauthenticated by definition — that
        // is the whole point of it — which is why the service behind it is
        // throttled twice and says nothing about who has bought anything.
        Route::get('/anmelden', [PortalMagicLinkController::class, 'form'])->name('request');

        /*
         * § 312k BGB, step one: the cancellation button's destination.
         *
         * Its own URL so that a site can put „Verträge hier kündigen" in a
         * footer and satisfy "directly reachable" — a page behind a login, or
         * one that has to be searched for, is the thing the statute was written
         * against. It has to be usable by somebody who cannot yet prove who they
         * are, so what it shows is the identification form.
         */
        Route::get('/kuendigen', [PortalMagicLinkController::class, 'cancellationEntry'])->name('cancel.entry');

        Route::post('/anmelden', [PortalMagicLinkController::class, 'send'])
            ->middleware(ThrottleRequests::class.':'.(int) config('statamic-payments.portal.request_rate_limit', 10).',1')
            ->name('request.send');

        /*
         * The signature is checked while overlooking the parameters a mail
         * service provider appends on the way here — Brevo's `_se` and its
         * relatives, each named in `portal.ignored_query_parameters` with the
         * provider that adds it. `Portal\TrackingParameters` says what that
         * costs and why it is affordable on this route and nowhere else; the
         * short version is that the payload is in the path and `expires` stays
         * signed.
         */
        Route::get('/link/{payLink}', [PortalMagicLinkController::class, 'open'])
            ->name('link')
            ->middleware(ValidateSignature::absolute(TrackingParameters::ignored()));

        Route::post('/abmelden', [PortalMagicLinkController::class, 'close'])->name('close');

        // Everything past here needs the note a followed link left behind. The
        // middleware puts the sealed brand back into the ambient scope for the
        // siblings; it is not what keeps one tenant's rows away from another's
        // — that is `Brands::only()`, inside every query `Portal\Orders` builds.
        Route::middleware(SetBrandFromPortalSession::class)->group(function () {
            Route::get('/', [OrdersController::class, 'index'])->name('show');

            Route::get('/bestellung/{payOrder}', [OrdersController::class, 'order'])
                ->whereNumber('payOrder')
                ->name('order');

            Route::get('/bestellung/{payOrder}/rechnung', InvoiceController::class)
                ->whereNumber('payOrder')
                ->name('invoice');

            // § 312k step two and three: the confirmation page, and the button
            // on it. A GET that shows and a POST that acts, never one route
            // doing both — a cancellation must not be reachable by following a
            // link, and least of all by a mail client prefetching one.
            Route::get('/abo/{paySubscription}/kuendigen', [CancellationController::class, 'confirm'])
                ->whereNumber('paySubscription')
                ->name('cancel.confirm');

            Route::post('/abo/{paySubscription}/kuendigen', [CancellationController::class, 'cancel'])
                ->whereNumber('paySubscription')
                ->middleware(ThrottleRequests::class.':10,1')
                ->name('cancel.run');

            Route::post('/abo/{paySubscription}/zahlungsmittel', [PaymentMethodController::class, 'start'])
                ->whereNumber('paySubscription')
                ->middleware(ThrottleRequests::class.':10,1')
                ->name('method.start');

            Route::get('/zahlungsmittel/zurueck', [PaymentMethodController::class, 'returned'])->name('method.return');
        });
    });

/*
|--------------------------------------------------------------------------
| Widerruf (§ 356a BGB) und Kündigung ohne Login (§ 312k BGB)
|--------------------------------------------------------------------------
|
| Deliberately **outside** the portal group: neither needs a mailed link, and
| the statute is the reason. § 356a allows a login only where the contract
| itself requires an account; § 312k names a button, a confirmation page and an
| acknowledgement, with no identification step in between. So both flows are
| public, two steps each, and say nothing about whether an order exists.
|
| Same parameter-name discipline as the portal: `payWithdrawal` and
| `payCancellation`, never `{id}` or `{withdrawal}`, because a `Route::bind()`
| from another addon applies to every route with that parameter name.
|
| Throttled on the POSTs only. The GETs are what a person refreshes.
|
*/

Route::prefix(config('statamic-payments.withdrawal.prefix', '!/statamic-payments/widerruf'))
    ->middleware(['web'])
    ->name('statamic-payments.withdrawal.')
    ->group(function () {
        $throttle = ThrottleRequests::class.':'.(string) config('statamic-payments.withdrawal.throttle', '6,10');

        Route::get('/', [WithdrawalController::class, 'form'])->name('form');
        Route::post('/', [WithdrawalController::class, 'declare'])->middleware($throttle)->name('declare');
        Route::get('/{payWithdrawal}', [WithdrawalController::class, 'show'])->name('show');
        Route::post('/{payWithdrawal}/bestaetigen', [WithdrawalController::class, 'confirm'])->middleware($throttle)->name('confirm');
    });

Route::prefix(config('statamic-payments.cancellation.prefix', '!/statamic-payments/kuendigung'))
    ->middleware(['web'])
    ->name('statamic-payments.cancellation.')
    ->group(function () {
        $throttle = ThrottleRequests::class.':'.(string) config('statamic-payments.cancellation.throttle', '6,10');

        Route::get('/', [LegalCancellationController::class, 'form'])->name('form');
        Route::post('/', [LegalCancellationController::class, 'declare'])->middleware($throttle)->name('declare');
        Route::get('/{payCancellation}', [LegalCancellationController::class, 'show'])->name('show');
        Route::post('/{payCancellation}/bestaetigen', [LegalCancellationController::class, 'confirm'])->middleware($throttle)->name('confirm');
    });

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mollie
    |--------------------------------------------------------------------------
    |
    | The live or test key from your Mollie dashboard. A test key starts with
    | `test_` and moves no money, which is what you want until the whole path
    | has run once end to end.
    |
    */

    'key' => env('MOLLIE_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Products
    |--------------------------------------------------------------------------
    |
    | What may be bought, and for how much. **The amount is read from here and
    | never from the request** — a checkout that accepted a posted price would
    | let anyone buy anything for a cent, which is the oldest mistake in online
    | payments and still the most common.
    |
    | `amount_cent` is an integer in minor units. Not a float: a float here is
    | how a cent goes missing every thousand orders.
    |
    | Empty as shipped. An addon that carried prices would be wrong about every
    | site that installed it.
    |
    |     'noten-paket' => [
    |         'name' => 'Notenpaket „Frühling"',
    |         'amount_cent' => 1900,
    |     ],
    |
    */

    'products' => [],

    'currency' => 'EUR',

    /*
    |--------------------------------------------------------------------------
    | Where the buyer comes back to
    |--------------------------------------------------------------------------
    |
    | The provider sends them here after paying. It is **not** where fulfilment
    | happens: a buyer who closes the tab still paid, and a buyer who reaches
    | this page has not necessarily paid. Only the webhook decides.
    |
    */

    'return_url' => '/danke',

    /*
    |--------------------------------------------------------------------------
    | Rate limit
    |--------------------------------------------------------------------------
    |
    | Per minute, per IP, on the webhook.
    |
    */

    'rate_limit' => 60,

    /*
    |--------------------------------------------------------------------------
    | Entitlements
    |--------------------------------------------------------------------------
    |
    | Optional. With `goldnead/statamic-entitlements` installed and this turned
    | on, a paid product whose entry carries a `grants` key gives the buyer that
    | entitlement:
    |
    |     'noten-paket' => [
    |         'name' => 'Notenpaket „Frühling"',
    |         'amount_cent' => 1900,
    |         'grants' => 'noten-fruehling',
    |     ],
    |
    | Off by default, and off again for any product without `grants`. A payment
    | addon that granted access by default would be deciding something that is
    | the site's to decide.
    |
    */

    'entitlements' => [
        'enabled' => env('STATAMIC_PAYMENTS_ENTITLEMENTS', false),
    ],
];

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

    /*
    |--------------------------------------------------------------------------
    | Where the provider tells us what happened
    |--------------------------------------------------------------------------
    |
    | Null uses this addon's own route, which is right in production.
    |
    | A string overrides it: on a development machine the provider checks that
    | the URL is reachable **from its side** and refuses `localhost` outright,
    | so a tunnel's address goes here.
    |
    | `false` omits it entirely. Then nothing is pushed and the status has to be
    | pulled — `Fulfilment::handle($providerId)` does exactly what the webhook
    | would. That is fine for a demo and **wrong for production**: a buyer who
    | closes the tab is never followed up.
    |
    */

    'webhook_url' => env('STATAMIC_PAYMENTS_WEBHOOK_URL'),

    /*
    |--------------------------------------------------------------------------
    | Abandoned checkouts
    |--------------------------------------------------------------------------
    |
    | Somebody started a checkout and did not finish it. `payments:sweep-abandoned`
    | announces those as `CheckoutAbandoned`, once each, and a sequence in
    | statamic-automations can pick it up from there.
    |
    | **Off by default, and that is not caution about the code.** The address on
    | an unfinished checkout was given to complete a purchase, not to receive
    | advertising. Whether a reminder may go out is a question of consent — ask
    | it before switching this on, and put the suppression list in front of the
    | send either way.
    |
    | `after_minutes` is in minutes and not hours because the line between
    | "still typing" and "gone" is not the same on a nine-euro download as on a
    | course that costs two thousand.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Unpaid checkouts
    |--------------------------------------------------------------------------
    |
    | After how many days `payments:prune-unpaid` deletes a checkout that was
    | started and never paid. `0` switches it off.
    |
    | The reason is not tidiness. A paid order carries a retention obligation; an
    | abandoned checkout carries the opposite — what sits in the row is the name
    | and address of somebody with whom no contract was ever concluded. Pick a
    | number that fits how long a reminder sequence may still be running; 30 is
    | a common one, and it is your decision rather than this addon's.
    |
    */

    'prune_unpaid_after_days' => env('STATAMIC_PAYMENTS_PRUNE_UNPAID_DAYS', 0),

    /*
    |--------------------------------------------------------------------------
    | The largest quantity anybody may buy at once
    |--------------------------------------------------------------------------
    |
    | A safety net, not a business rule. The quantity is the one number a
    | checkout accepts from a request — the unit price never is — so a mistyped
    | or hostile figure must not become a five-figure charge.
    |
    | A product that offers a *variable* quantity (a donation, a pay-what-you-
    | want) says so itself with `min_quantity` and `max_quantity`, and those win.
    |
    */

    'max_quantity' => env('STATAMIC_PAYMENTS_MAX_QUANTITY', 1000),

    'abandoned' => [
        'enabled' => env('STATAMIC_PAYMENTS_ABANDONED', false),
        'after_minutes' => env('STATAMIC_PAYMENTS_ABANDONED_AFTER', 60),
    ],

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

    /*
    |--------------------------------------------------------------------------
    | Follow-up offers
    |--------------------------------------------------------------------------
    |
    | An offer shown after a payment, charged without asking for card details a
    | second time. Off by default, and there is more to switching it on than
    | this flag:
    |
    | 1. `collect_mandate` has to be on as well. It makes the first payment ask
    |    the provider to remember the buyer, which is the thing that makes a
    |    later charge possible at all. The buyer has to be told, on the checkout
    |    page, that this is happening.
    |
    | 2. **The offer page still needs its own order button.** Saving the card
    |    details does not save the consent: under § 312j BGB the button must be
    |    labelled unambiguously ("Zahlungspflichtig bestellen") with the
    |    essential details directly above it. `docs/follow-up-offers.md` has the
    |    whole list. This is not legal advice, and the wording is worth twenty
    |    minutes of a lawyer's time before it goes live.
    |
    */

    'follow_up' => [
        'enabled' => env('STATAMIC_PAYMENTS_FOLLOW_UP', false),
        'collect_mandate' => env('STATAMIC_PAYMENTS_COLLECT_MANDATE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | CRM
    |--------------------------------------------------------------------------
    |
    | Write a paid purchase to the contact's timeline and into their lifetime
    | total in statamic-leadhub. Off by default like every other bridge here:
    | two addons installed for unrelated reasons must not start exchanging
    | customer data because they happen to be in the same vendor directory.
    |
    | What travels: the buyer's email and name, what they bought, what they
    | paid, and the campaign the checkout froze. Turning this on is a decision
    | about personal data, which is why it is a switch and not a default.
    |
    */

    'leadhub' => [
        'enabled' => env('STATAMIC_PAYMENTS_LEADHUB', false),
    ],

    'entitlements' => [
        'enabled' => env('STATAMIC_PAYMENTS_ENTITLEMENTS', false),
    ],
];

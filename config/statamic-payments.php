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

        /*
        | The reminder itself. Its own switch, because announcing an abandoned
        | checkout (an event a sequence may pick up) and mailing the person are
        | two decisions. With `enabled` here the addon sends one mail per
        | announced checkout, to the address on it, unless statamic-suppression
        | is installed and lists that address.
        |
        | `template` is an email-templates slug; with the sibling installed and
        | the slug resolving, that template is sent with the variables
        | `buyer.email`, `buyer.name`, `order.lines`, `order.total`,
        | `order.currency`, `resume_url`. Without it, a plain built-in mail goes
        | out (`resources/views/abandoned/mail`, publishable).
        |
        | `resume_url` is where the button points. Null builds a signed link that
        | starts the checkout again with the same lines; a string of your own
        | may carry `{payment}` for the id. `resume_days` is how long that
        | signed link works.
        |
        | The consent question above applies here twice over.
        */
        'mail' => [
            'enabled' => env('STATAMIC_PAYMENTS_ABANDONED_MAIL', false),
            'template' => env('STATAMIC_PAYMENTS_ABANDONED_TEMPLATE'),
            'subject' => null,
            'resume_url' => null,
            'resume_days' => 14,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment methods
    |--------------------------------------------------------------------------
    |
    | Which Mollie methods the hosted checkout offers. `null` sends no `method`
    | and Mollie shows what the account has switched on, which is right for
    | most sites. A list restricts it: `['creditcard', 'paypal', 'ideal']`.
    | The environment variable takes a comma-separated list.
    |
    | This matters for subscriptions and payment plans. Only cards, SEPA direct
    | debit, PayPal, Apple Pay and Google Pay let the provider charge again
    | without the buyer; Klarna, bank transfer, invoice, TWINT and the rest do
    | not, and a first payment with those cannot leave a mandate. The checkout
    | therefore asks Mollie to remember the buyer only when at least one listed
    | method can hold one. `Support\PaymentMethods` has the two lists and the
    | README has the table.
    |
    */

    'methods' => env('STATAMIC_PAYMENTS_METHODS'),

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
    | `grants` may also be a list, and that is how a bundle is expressed: one
    | line, one price, several things handed over.
    |
    |     'fruehlings-buendel' => [
    |         'name' => 'Frühlings-Bündel',
    |         'amount_cent' => 4900,
    |         'grants' => ['noten-fruehling', 'playback-fruehling', 'workshop-mitschnitt'],
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

    /*
    |--------------------------------------------------------------------------
    | Kundenselbstbedienung
    |--------------------------------------------------------------------------
    |
    | The buyer's own screens: their orders, their invoice, ending a
    | subscription, changing the card it is charged against. No account and no
    | password — the way in is a signed, expiring link to the address on the
    | order.
    |
    | **On by default, and that is deliberate.** § 312k BGB requires a
    | cancellation button on the site where a recurring consumer contract was
    | concluded. An addon that ships the requirement switched off ships it to
    | nobody. A site that genuinely has no consumer subscriptions can turn it
    | off; a site that has them and does not know about the statute is exactly
    | the site this default is for.
    |
    | `prefix` starts with `!/` like every other route this addon serves, which
    | is Statamic's convention for a URL that belongs to a package rather than
    | to the content tree. Change it if the words should be in another language;
    | the route *names* never change.
    |
    */

    'portal' => [

        'enabled' => env('STATAMIC_PAYMENTS_PORTAL', true),

        'prefix' => env('STATAMIC_PAYMENTS_PORTAL_PREFIX', '!/statamic-payments/konto'),

        'middleware' => ['web'],

        /*
        | How long a mailed link works, in minutes. This is the whole of the
        | revocation story — there is no token table to revoke against — so
        | shorten it rather than lengthen it. Thirty minutes is long enough for
        | a mail to be delivered and read.
        */
        'link_ttl_minutes' => 30,

        /*
        | How long the visit lasts once the link has been followed, in minutes.
        | Its own clock, independent of the session lifetime the host has set
        | for people who log in — a buyer on a shared machine is not a member
        | of staff at a desk.
        */
        'session_minutes' => 60,

        /*
        | Both limiters, and both are needed. Per address so one mailbox cannot
        | be flooded; per origin so the endpoint cannot be pointed at a list of
        | addresses somebody else owns. One without the other is not a limit.
        */
        'throttle' => [
            'per_address' => ['max' => 3, 'decay_minutes' => 60],
            'per_origin' => ['max' => 10, 'decay_minutes' => 60],
        ],

        /*
        | The floor the "we have sent you a link" response is held open to, in
        | milliseconds. Identical wording with a 12 ms "never bought anything"
        | and a 340 ms "mail sent" is a customer-list oracle with good manners.
        */
        'min_response_ms' => 350,

        /*
        | Requests per minute per IP on the form itself, on top of the two
        | limiters above. This one protects the worker, not the mailbox.
        */
        'request_rate_limit' => 10,

        /*
        | Query parameters a mail service provider appends to the link in
        | transit, which the signature check may overlook. Each name says who
        | adds it. `expires` and `signature` can never be listed here whatever
        | is written — see Portal\TrackingParameters for why that guard exists
        | rather than being left to good sense.
        */
        'ignored_query_parameters' => [
            '_se',   // Brevo, appended by its click counter
            'utm_source', 'utm_medium', 'utm_campaign',
        ],

        /*
        | Who the two portal mails come from. Left empty, the application's own
        | `mail.from` is used, which is right on a single-brand install.
        */
        'from' => [
            'address' => env('STATAMIC_PAYMENTS_PORTAL_FROM'),
            'name' => env('STATAMIC_PAYMENTS_PORTAL_FROM_NAME'),
        ],

        /*
        | The most rows a buyer's page shows. A ceiling on the page, not a
        | business rule: somebody with four hundred orders should not be sent a
        | four-hundred-row page from a mail client on a phone.
        */
        'max_rows' => 100,

        /*
        | What a buyer is charged to put a new payment method on file, in minor
        | units.
        |
        | **This charges real money and there is no way around it.** Mollie has
        | no zero-amount authorisation and no hosted "update your card" screen;
        | a mandate comes from a payment made with `sequenceType: first` and
        | from nothing else. One cent is the usual answer for cards. Some
        | methods have a higher floor and will refuse — raise it for those.
        |
        | The buyer is shown this amount before the button. See
        | `portal.method_note` in the translations.
        */
        'mandate_verification_cent' => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | Einwilligung — § 356 Abs. 5 BGB
    |--------------------------------------------------------------------------
    |
    | `accepted_texts`: the consent sentences your pages actually show, on top
    | of this addon's own `messages.order_consent` (German and English). The
    | follow-up offer endpoint writes a submitted `consent_text` onto the row
    | only if it is one of these; anything else is replaced by the addon's
    | wording and logged, because a hidden field is a field anybody can edit,
    | and a record whose text the buyer chose proves nothing.
    |
    | Empty by default. Add the exact strings of your own checkout here.
    |
    */

    'consent' => [
        'accepted_texts' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Zeitzone der Belege
    |--------------------------------------------------------------------------
    |
    | The zone the date and time in an acknowledgement are stated in. Null uses
    | `app.timezone`. Set it where the application runs in UTC and the shop
    | does not: the time on a receipt should be the merchant's.
    |
    */

    'legal' => [
        'timezone' => env('STATAMIC_PAYMENTS_LEGAL_TIMEZONE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Widerrufsbutton — § 356a BGB
    |--------------------------------------------------------------------------
    |
    | Since 19 June 2026 a shop that concludes distance contracts with consumers
    | through a website has to offer an electronic withdrawal function: a button
    | reading „Vertrag widerrufen", permanently available, prominently placed and
    | easy to reach during the withdrawal period; a form for name, contract and
    | contact details; a confirming button reading „Widerruf bestätigen"; and an
    | immediate acknowledgement of receipt stating the time.
    |
    | This addon ships that shape, **public and without a login**. A login step
    | is allowed by the statute only where the contract itself requires an
    | account, and a shop that sells a download to a guest cannot claim that.
    |
    | What the form does *not* do is tell the visitor whether an order exists.
    | Any address plus any reference goes through; matching to a payment happens
    | on the server afterwards, on an unambiguous hit only, and an unmatched
    | declaration is still a declaration — it is reported to you rather than
    | refused. Otherwise the form would be an oracle for "has this address bought
    | here". The same goes for a right of withdrawal that has already expired
    | (digital content with recorded consent, `payments.consent_at`): the form
    | does not assert that beforehand, the consumer always receives the
    | acknowledgement, and you get the expiry as a hint on the row.
    |
    | On by default, for the same reason the portal is: an addon that ships a
    | statutory requirement switched off ships it to nobody. B2B-only shops may
    | turn it off.
    |
    | The link belongs in your footer during the whole withdrawal period:
    | `{{ payments:withdrawal_url }}` in Antlers, `Legal\Links::withdrawal()` in
    | PHP, labelled „Vertrag widerrufen". The model withdrawal instruction is not
    | part of this addon; put its URL in `policy_url` and the form links to it.
    |
    | These are legal decisions taken on 1 September 2026 and documented for
    | review, not legal advice.
    |
    */

    'withdrawal' => [

        'enabled' => env('STATAMIC_PAYMENTS_WITHDRAWAL', true),

        'prefix' => env('STATAMIC_PAYMENTS_WITHDRAWAL_PREFIX', '!/statamic-payments/widerruf'),

        /*
        | The two POST routes, per IP: requests per decay minutes, behind the
        | named limiter `statamic-payments.withdrawal` (which a host may
        | redefine with `RateLimiter::for()`). Six in ten minutes is one full
        | flow (declare, confirm) three times over — generous for a person,
        | tight for a script.
        */
        'throttle' => '6,10',

        /*
        | Who is told about a withdrawal. Left empty, `portal.from.address`
        | is used, and after that the application's own `mail.from.address`.
        | The consumer's acknowledgement goes out either way; this is the
        | merchant's copy, with the matched payment and the hints.
        */
        'notify' => env('STATAMIC_PAYMENTS_WITHDRAWAL_NOTIFY'),

        /*
        | Where your withdrawal instruction (Widerrufsbelehrung) lives. Optional.
        | Linked from the form page when set; the instruction itself is the
        | host's document, not this addon's.
        */
        'policy_url' => env('STATAMIC_PAYMENTS_WITHDRAWAL_POLICY_URL'),

        /*
        | The statutory period in days, counted from the purchase. Used only to
        | tell you whether a declaration arrived inside it — the consumer is
        | never refused on the strength of this number, because whether the
        | period has run is a legal question the row cannot settle by itself.
        */
        'days' => 14,
    ],

    /*
    |--------------------------------------------------------------------------
    | Kündigungsbutton — § 312k BGB, ohne Login
    |--------------------------------------------------------------------------
    |
    | The customer portal above already offers cancellation, behind a mailed
    | link. Under the prevailing reading of § 312k the *declaration* has to be
    | possible without logging in — the statute names a button, a confirmation
    | page and an immediate acknowledgement, and none of them may sit behind an
    | identification step the consumer has to pass first. So this is a second
    | way in, built like the withdrawal flow: public, two steps, acknowledged at
    | once. The portal's cancellation stays as the convenient way for somebody
    | who is already looking at their contract.
    |
    | Where the declaration names one running agreement unambiguously (address
    | plus the contract or subscription id), that agreement is cancelled at the
    | provider immediately, through the same `Subscriptions::cancel()` the
    | portal uses — provider first, row second. Where it does not, nothing is
    | cancelled automatically and you are told; the consumer still receives the
    | acknowledgement, because the declaration has reached you either way.
    |
    | Both button labels („Verträge hier kündigen", „jetzt kündigen") are the
    | statutory ones and live in `lang/de/cancellation.php` (and `en`).
    |
    | Legal decisions taken on 1 September 2026, documented for review, not
    | legal advice.
    |
    */

    'cancellation' => [

        'enabled' => env('STATAMIC_PAYMENTS_CANCELLATION', true),

        'prefix' => env('STATAMIC_PAYMENTS_CANCELLATION_PREFIX', '!/statamic-payments/kuendigung'),

        'throttle' => '6,10',

        'notify' => env('STATAMIC_PAYMENTS_CANCELLATION_NOTIFY'),

        /*
        | Optional. A page of yours that explains notice periods and the like;
        | linked from the form when set.
        */
        'policy_url' => env('STATAMIC_PAYMENTS_CANCELLATION_POLICY_URL'),
    ],
];

# Changelog

## 1.22.0 — 2026-09-08

The two things 1.21.1 knowingly left open.

### A delayed payment that was refused is now `failed`, not `open` for ever

SEPA, Sofort and the other delayed methods leave the Checkout Session `complete` and the payment
`unpaid` for days, then either settle or do not. Both outcomes look identical at the session level.
Stripe announces the difference as an event type (`checkout.session.async_payment_failed`) — and a
webhook's claim about what happened is exactly what this package refuses to believe.

So the PaymentIntent is read instead, on the object that was fetched anyway:
`requires_payment_method` **with a `last_payment_error`** on a completed session is a payment that
was attempted and refused. Without the error it is a buyer who has not paid yet, which stays `open`;
a `canceled` intent is `canceled`. Nothing an intent says can turn an unpaid session into a paid
one — `payment_status` already settled that, and there is a test for the direction that must not
exist.

Left as `open`, a failed direct debit sat in the till for ever: no fulfilment, no `PaymentFailed`
for a listener to react to, and an order that looked like it was still coming.

### CI proves the claims on MySQL and Postgres, not only SQLite

Two money paths are guarded by a unique index rather than a row lock, because `lockForUpdate()`
compiles to an empty string on SQLite. That a unique index holds everywhere was an argument, not a
measurement, while CI only ever ran SQLite.

A new job runs the claim tests again against `mysql:8` and `postgres:16` as services. Not the whole
suite, and that is measured rather than assumed: testbench migrates and rolls back per test, which
costs about three seconds on SQLite, twenty on Postgres and two minutes on MySQL for these tests —
the full suite would be well over twenty minutes on MySQL for a property none of the other tests are
about. What runs there is what the engine actually decides: both claim tables, the fulfilment claim,
and the resolution that keeps one provider's ids out of the other's rows.

`tests/TestCase.php` takes the connection from `DB_CONNECTION` and still defaults to in-memory
SQLite, so nothing changes for a local run. The job runs with `--fail-on-empty-test-suite`, and
`DatabaseDriverTest` asserts the suite really is on the driver it was told to use — the way a job
like this fails is by going green having proved nothing.

### Also

A follow-up charge Stripe declined is `failed` too, not `open`. It sat at
`requires_payment_method` exactly like an intent nobody has paid yet — except that on an
off-session charge there is no buyer on a page to pay it, so it would have looked like it was still
going through for ever. And an intent status this package has not met, on a completed session, now
says so in the log instead of landing on `open` in silence.

## 1.21.1 — 2026-09-08

Two defects in 1.21.0's Stripe endpoint, both found by review rather than by anything failing.

### Fixed: a delivery during a Stripe outage was never redelivered

`Fulfilment::fetch()` catches everything a gateway throws, logs it and carries on with a null.
That is right for Mollie — an id this account never issued is a stray call, and Mollie redelivers
on its own schedule whatever the endpoint answers.

On the Stripe path it was fatal. The event id is claimed **before** the work runs, so a timeout, a
502 or a rate limit produced a quiet `200` with the claim still standing. Stripe then never came
back — not on a retry, and not from the Resend button, which sends the same `evt_` id into the same
claim. A buyer paid, one warning landed in the log, and the order was never fulfilled.

A gateway now throws `Support\ProviderUnavailable` when the answer is "ask again later" — a 5xx, a
429, a connection that never came up. `Fulfilment` lets that one through instead of swallowing it,
the endpoint releases its claim and answers `503`, and Stripe redelivers. A 404 is still an answer
and still a quiet `200`.

### Fixed: the only alarm this package has fired on every single sale

`payment_intent.succeeded` was in the README's recommended event list. Stripe sends it alongside
`checkout.session.completed` for the same purchase — but the row is stamped with the session id, so
the intent matched nothing and `Fulfilment` logged "webhook for an unknown payment id". That line
exists for a buyer who paid into thin air. Firing it on every order made it worthless.

A PaymentIntent event is now only acted on when a row actually carries that id, which is the
follow-up-offer case and nothing else. The README lists the events properly, including the failure
ones, and says which two are only for sites using follow-up offers.

### Also

- `Refunds::book()` lost the remainder when another refund booked between its read and its write:
  the claim stood, so the money was never booked and no redelivery could fix it. It now books what
  still fits.
- The README's binding example casts the config value, so an unset `STRIPE_KEY` is a clear message
  rather than a `TypeError`.

## 1.21.0 — 2026-09-08

**A second payment provider, and the resolution that had to come first.** Stripe ships as an
adapter beside Mollie. Nothing about a Mollie site changes.

### The `provider` column is finally read

It was written on every payment and every agreement from the first version, and read by nothing.
The container bound `PaymentGateway` once, globally, so whatever arrived was handed to whichever
provider happened to be bound.

On a site with one provider that is invisible. On a site with two it is the worst failure this
package can have: a webhook from the second provider asks the first about an id it has never seen,
the lookup finds no row, and the buyer's order is never fulfilled. **No error, no alarm.**

`Support\Gateways` resolves a handle to an adapter, and a host extends it:

```php
app(Gateways::class)->register('paypal', fn () => new PayPalGateway);
```

A handle nobody registered **throws** rather than falling back to the default — a silent fallback
is the bug, not the cure. `free` (an order the catalogue priced at zero) resolves through the same
registry to a `FreeGateway` that never reaches a provider.

Read off the row now, not off the binding: which provider is asked about an agreement
(`Subscriptions::refresh()`, `cancel()`), which one holds a buyer's stored card
(`FollowUp::accept()`), and which one the customer portal asks whether a card can be changed.

### Stripe

`Gateways\StripeGateway` satisfies `PaymentGateway`, `FollowUpGateway` and `SubscriptionGateway` —
no fourth contract. Hosted Checkout Sessions, subscriptions with the same six states
`Subscriptions::refresh()` already mirrored from Mollie, off-session follow-up charges, and refunds
recorded as an amount with a time.

Built on Laravel's HTTP client rather than `stripe/stripe-php`: no new dependency on sites that
only ever wanted Mollie, and the tests check the wire format instead of a mocked method call.

**Its own webhook endpoint**, `/!/statamic-payments/webhook/stripe`. Mollie's body is an id and
needs no signature; Stripe's carries an event type and a refund amount, so it is verified before it
is parsed (HMAC-SHA256 over `<timestamp>.<raw body>`, `hash_equals`, five-minute tolerance). Stripe
redelivers until it gets a 2xx, so the event id is claimed with a unique index — an insert, not a
lookup, because read-then-write loses to two redeliveries milliseconds apart. A delivery whose work
throws releases the claim so Stripe retries.

Set `STRIPE_KEY` and `STRIPE_WEBHOOK_SECRET`. Without the signing secret the endpoint refuses
everything, which is the right way round.

### Fixed: two refunds arriving at once could lose one and then book it twice

`Refunds::record()` read the payment, decided whether the reference was already noted and how much
was still outstanding, and then saved — all outside any lock. With Mollie that never bit: refunds
are entered by hand in a dashboard, one at a time. Stripe announces two partial refunds as two
events, which can land in two processes at once.

The second write then went over the first: its amount gone from `refunded_cent`, its reference gone
from `meta['refunds']`. And because a Stripe refund event carries the charge's **whole** refund
list, the next delivery saw the lost reference as new and booked it a second time. Nothing failed,
nothing was logged, and the wrong number reached the annual figures.

A refund reference is now claimed by inserting it into `payment_refunds`, which carries a unique
index — the same shape as the webhook replay guard, and for the same reason. A row lock would not
have done: Laravel's `lockForUpdate()` compiles to an empty string on SQLite, and this addon asks
for "a database", not for one that can lock a row. The amount goes on with a conditional `UPDATE`
that cannot take back more than came in, and `meta['refunds']` is now derived from the claim rows
rather than appended to.

Existing references in `meta['refunds']` are still honoured, so a refund booked before this version
is not booked again after it. Run `php artisan migrate`.

### Fixed: the Control Panel screens answered 500 before the migrations ran

`composer require` puts the payments and subscriptions screens in the navigation; `php artisan
migrate` puts their tables in the database. Opening either in the gap between the two hit an
unguarded query. Both now check the table first, say so in the log, and render their empty state.

### Fixed: a yen subscription went out at a hundredth of its price

`Subscription::amount()` still divided by 100 and formatted two decimals, and that string is what
is handed to the provider when an agreement is created. `Payment::amount()` stopped doing this in
1.11.0; the agreement did not. Now both go through `Support\Money`.

## 1.20.0 — 2026-09-07

**A payment plan now survives a checkout with a cart — and says on the invoice which instalment
it is.** Three changes that belong together, because they are the same sale.

### An agreement may start from a cart

`Subscriptions::start()` took exactly one handle. A checkout with order bumps (statamic-funnels)
could therefore not call it and went to `Checkout::start()` — which knows nothing about plans.

The outcome was a **silent partial payment**: an offer with `interval` (statamic-offers 1.8.0)
was charged once, access was granted in full, and the missing instalments turned up nowhere. No
error, no message, no outstanding claim. The catalogue handed out the plan correctly; on this
path nobody asked it.

`start()` now takes `string|array`. The rhythm is set by the **first** handle, and the following
charges only debit that handle's amount — a bump next to an instalment option is therefore bought
once and not again with every instalment. Plus an optional `$discount` as the fifth parameter,
for the voucher the flow has already calculated; a trial period still takes precedence.

New: `Subscriptions::canStart()`. Whether this installation can start agreements at all
(provider plus mandate collection), without starting a purchase to find out. A flow that
**shows** an instalment option has to know that beforehand — finding out inside `start()` as a
`null` is a dead end in the middle of the checkout.

### Choosing an instalment no longer shows payment methods that cannot do instalments

`Checkout::start()` set `sequenceType: first` and passed the configured method list on unchanged.
If Klarna or a bank transfer sat next to the card in it, the buyer saw both, picked one of them,
and the provider refused — after everything had been filled in.

When a mandate is being collected, only the configured methods from
`PaymentMethods::MANDATE_FIRST` remain. An **empty** configuration stays empty: it means "the
provider decides", and on a first payment the provider shows only what can leave a mandate anyway.
Inventing a list here would switch off a payment method the provider enables tomorrow.

### Every invoice says which instalment it is

Three instalments produced three invoices with the same sentence and the same amount three times.
The main line now carries the addition: "Chorleitungskurs — Rate 1 von 3 (Gesamt 1.560,00 €)", or
the rhythm instead on an open-ended subscription. **The amount is unaffected** — § 14 UStG wants
the amount of the service being invoiced, and that is the instalment. The addition only says what
it belongs to.

Two defects picked up along the way that nobody had seen before:

- **A cycle's line carried the raw handle.** `Fulfilment::openCycle()` wrote
  `offer:choiraccelerator-raten` as the name, while the first payment took "ChoirAccelerator" from
  the catalogue. From the second instalment onwards the label on the invoice changed silently.
  `InvoiceWriter` prints `item.name` unchanged and fetches nothing for a line item that is already
  there.
- **`trial_discount` was in neither of the two language files.**
  `Subscriptions::trialDiscount()` called the key, and the key itself ended up on the payment.

Plus `Money::display()` and `Money::symbol()`: the same amount for a human rather than for the
wire. `format()` still writes "1560.00" for the provider.

## 1.19.0 — 2026-09-07

**A settings page in the Control Panel.** § 356a BGB (the German withdrawal button) and § 312k
BGB (the German cancellation button) both require a place to report to, and both allow linking to
a policy of one's own. Those four values — `withdrawal.notify`, `withdrawal.policy_url`,
`cancellation.notify`, `cancellation.policy_url` — were only in `.env` so far: required by law
and out of reach for the operator they belong to. Alongside them: the customer portal, abandoned
checkouts, consent sentences and the switches to the sibling addons.

The page is not built here. The addon only registers its field list (`Support\Settings`,
`Goldnead\BrandContext\Contracts\ProvidesSettings`) with the `SettingsRegistry`; screen, form,
validation, storage, brands and routes come from `statamic-brand-context`. That package stays
optional (`require-dev`, now `^1.12`) — without it everything runs as before, only without the
section: the registration sits behind a `class_exists`, and `Support\Settings` is then never
loaded.

- New permission `manage payments settings`, always registered, even without `brand-context`.
- **The Mollie key is not on the page** and never will be: it moves money and does not belong in
  a database backup. Nor does `suite.license_key`.
- Not on the page, because they are read at boot: `rate_limit`, `portal.prefix`,
  `portal.middleware`, `portal.request_rate_limit`, `withdrawal.prefix`, `withdrawal.throttle`,
  `cancellation.prefix`, `cancellation.throttle`. `SettingsManager::apply()` runs from
  `app->booted()`, `routes/web.php` reads before that — a change there would only arrive after
  the next deploy.
- Also not: `webhook_url` (deployment), `products` and `portal.throttle` (nested), `methods` (the
  config holds a comma-separated string there, not a list) and `portal.min_response_ms` (the
  floor against a timing oracle).

## 1.18.0 — 2026-09-05

One finding from Adrian's pass on 2026-09-03 (F36), plus a test that no longer started under
Laravel 13.

### Sales screens in a section of their own in the sidebar

Payments, subscriptions, withdrawals and cancellations are registered as Statamic utilities and
therefore sat under "Utilities", between the cache, PHP info and search. Now one nav entry each
points at the same route, in a section named after what one does there. Routes and permissions are
unchanged; the utility registration stays, because it carries the route, the permission and the
middleware. The entry under "Utilities" is unhooked for that (`Nav::remove('Tools', 'Utilities',
…)`). The first attempt, on 2026-09-04, had merely put the new section beside it, and every screen
appeared twice. Under "Utilities" only Statamic's own five remain: Cache, Email, Licensing, PHP
Info, Search.

The section name lives in `Cp\SuiteNav::section()`, because Statamic does not translate section
names: the NavBuilder shows the key it is given, as it is. Two addons with "Sales" and "Shop"
would produce two half-filled sections side by side. `statamic-offers`, `statamic-funnels` and
`statamic-products` call that method from their next versions onwards and need this version for
it.

### Test suite under Laravel 13

`InsightsMetricsTest` declared a helper method `query()` as `protected`. Orchestra Testbench 11,
the Laravel 13 leg of the matrix, ships a public method of the same name on `TestCase`, and PHP
aborts while loading the class, before a test runs. The helper is now called `metricQuery()`. Only
the suite was affected, not the package.

## 1.17.1 — 2026-09-02

Three findings from a real purchase test on staging (Mollie test mode, payments 29/30/31).

### `payment_items.offer` on a follow-up offer

`FollowUp::accept()` did not write the column — on precisely the row it was built for. The upsell
report in `statamic-insights` therefore attributed that revenue to no offer. Now in the same order
as at the checkout: what the caller says through `PaymentDetails` (`offer_handles`), otherwise what
the catalogue attached to the product (`statamic-offers` supplies `offer` with it), otherwise
`null`. Callers that pass nothing keep running unchanged.

### Card details: each field on its own, and only what is evidenced

`card_last4` and `card_label` hung together: if the provider named the card brand without the
number, the brand was lost; if it named the number without the brand, a brand already recorded was
overwritten with `null`. On a follow-up offer's page there was then either nothing or something
assembled from two answers. Each field is now written individually, only from an answer that
evidences it, and only while it is empty — frozen stays frozen.

On the field finding itself: in test mode, Mollie names a different card number for a recurring
charge than for the first payment (6787 instead of 9996, both as "Mastercard", although a VISA
test card was used). The addon reports what the provider says for **this** payment; the
discrepancy comes from Mollie's test data, not from here.

### Communication log

The two mails to the merchant — withdrawal reported (`withdrawal_notice`), cancellation reported
(`cancellation_notice`) — were sent but not recorded. They are now in the log, with the recipient
and the case reference.

Stated more plainly, in the README and in the panel's empty state: **the log is a log, not a
listener.** On an ordinary purchase this package sends no mail at all — the purchase confirmation,
the credentials and the welcome message come from the site, and the site has to record them itself
with `PaymentLog::mail(…)`. An empty panel after a purchase is therefore not a defect but a
missing call. `statamic-invoices` only records its invoice mail if one went out; with
`INVOICES_DELIVER=false` there is correctly nothing there.

## 1.17.0 — 2026-09-02

### Payment detail page with a communication log

Utilities → Payments → clicking a row (or "Details" in the row menu) opens
`cp/utilities/payments/{id}`: a header with amount, status and timestamps; panels for line items
(kind, quantity, unit price, offer), buyer (email, name, country, address from `meta.address`, VAT
ID from `meta.vat_id`), consent under § 356 Abs. 5 BGB (timestamp, wording, version of the policy),
access window (`meta.access`), attribution (UTM, referrer, landing page), payment method, refunds,
links (first order, follow-up offers, subscription, invoice, withdrawals, cancellations) and
**communication**. If `statamic-webhook-manager` is installed, a "Webhook deliveries" panel
(`WebhookLog::forSubject('payment', id)`). The same permission as the listing; on multi-brand
installations with a brand set, another brand's payment is a 404. Register S·8.

New: the table `payment_communications` and the `PaymentLog` facade — `PaymentLog::mail($payment,
'invoice', $to, $subject)`, `::note()`, `::record()`, `::for()`. A failure while writing is logged
and never breaks a purchase path. The addon records these itself: the portal link (on the
address's most recent order), the withdrawal acknowledgement (where a payment could be matched),
the cancellation acknowledgement and the cancellation confirmation from the portal (on the
subscription's most recent payment), the abandonment reminder. `statamic-invoices` records its
invoice mail. Event `PaymentCommunicationLogged`.

### Abandoned cart mail

`abandoned.mail.enabled` sends one reminder per announced checkout to the address on it — not if
`statamic-suppression` lists the address (then a note in the log). `template` takes an
email-templates slug with the variables `buyer.email`, `buyer.name`, `order.lines`, `order.total`,
`order.currency`, `resume_url`; without a template a built-in, publishable Blade mail goes out
(de/en). `resume_url` is a signed link (`abandoned.mail.resume_days`, default 14) to an order page
in the portal layout: line items, total, withdrawal notice, the § 356 Abs. 5 checkbox with the
wording from `meta.withdrawal` (otherwise `messages.order_consent`) and the button
"Zahlungspflichtig bestellen" (the German wording § 312j Abs. 3 prescribes for an order button).
The GET creates nothing; only the signed POST starts the same cart as a new payment through
`Checkout::resume()` — same line items, buyer, attribution, discount and brand,
`meta.resumed_from` points back, the consent is fresh (now, the wording shown, only with the box
ticked) and is never copied. A second click within an hour finds the open checkout again. Or an
address of your own with `{payment}`. New column `payments.recovered_at`: set when a payment that
was reminded is paid after all, including through the restarted checkout. Register K·8.

### Payment methods

`methods` (a list of Mollie identifiers, or `STATAMIC_PAYMENTS_METHODS` with commas) goes into the
Mollie request as `method`; with nothing given, no key. The buyer is only registered for
remembering (`customerId`, `sequenceType: first`) if at least one of the methods can leave a
mandate. `Support\PaymentMethods` holds the two lists, the README holds the table. Register K·18.

### Stragglers

- `EntitlementsBridge::grantFor()` passes `meta.access` (`starts_at`, `days`, from
  `Offer::accessWindow()`) on to `Entitlements::grant()` as `startsAt`/`expiresAt`. Register K·5.
- `payment_items.offer`: the offer a line item was sold through — from
  `PaymentDetails::offer_handles` (product handle → offer handle) or from the `offer` key the
  catalogue attaches to the line; otherwise null.
- `payments:prune-legal-drafts` deletes unconfirmed withdrawal and cancellation declarations after
  seven days (`--days`, `--dry-run`).
- No more `email:filter` in the addon; the forms use `EmailAddress::rule()` (they already did).

### Withdrawal button under § 356a BGB

Mandatory in Germany since 2026-06-19, absent until here. New: a public, two-step path without a
login under `!/statamic-payments/widerruf` (config `withdrawal.prefix`). Step 1 takes name, email,
order reference, means of contact and a message; step 2 shows the entries and the "confirm
withdrawal" button; immediately afterwards the acknowledgement goes to the consumer, carrying a
reference (`W-` plus eight characters without 0/O/1/I), date, time and timezone, and a
notification goes to `withdrawal.notify` (otherwise `portal.from`, otherwise `mail.from`). Step 3
shows the reference and the time, nothing else, and stays readable for anyone holding the
reference; step 2 only for the browser that declared. Idempotent: a second click is one
withdrawal, one mail, one timestamp.

Table `payment_withdrawals`. The match to a payment happens after the confirmation, server-side,
only on an unambiguous hit (address plus our id or the provider's); the form never reveals whether
an order exists. A consent under § 356 Abs. 5 BGB on the matched payment is passed to the merchant
as `right_expired_hint`, never held against the consumer; the same goes for whether the
declaration arrived after `withdrawal.days` (default 14).

Footer: `{{ payments:withdrawal_url }}`, `Legal\Links::withdrawal()`, label from
`withdrawal.button` ("Vertrag widerrufen"). Control Panel: the "Withdrawals" utility with the
match, the hints, an open/handled filter and the "mark as handled" action (a note); permissions
`access withdrawals utility` and `handle payment withdrawals`.

Legal decisions in this version, to be reviewed by Adrian, not legal advice: no login; no oracle
about what exists; unmatched is permitted and is reported; an expired right is a hint, not a
refusal; the IP only as a salted hash; the model policy stays the host's business
(`withdrawal.policy_url`). The read permission is core's utility permission, not a second
`view payment withdrawals` — one switch per door.

### Cancellation button under § 312k BGB, without a login

The portal path (`/konto/kuendigen` → magic link) remains as the convenient route. New beside it,
in the same mechanics as the withdrawal: `!/statamic-payments/kuendigung` (config
`cancellation.prefix`), the button "Verträge hier kündigen" (the German wording § 312k prescribes),
a confirmation page with the kind of cancellation (ordinary or for cause, the latter with a
mandatory reason), identification and a desired date under "cancel now", then a confirmation by
mail and on the page with date, time and the date named. Table `payment_cancellations`.

An unambiguously matched **running** subscription is cancelled with the provider immediately
through `Subscriptions::cancel()` (provider first, row afterwards; `provider_cancelled_at`).
Ambiguous, not running, or refused by the provider: nothing changed on the subscription, the
merchant notified, and the consumer gets the acknowledgement regardless. Footer:
`{{ payments:cancellation_url }}`. Control Panel: the "Cancellations" utility, permissions
`access cancellations utility` and `handle payment cancellations`.

A legal decision in this version, to be reviewed by Adrian: a date named in the future does not
hold the cancellation back at the provider — what is cancelled is the next charge, and the date
appears in the row and in the notification. Not legal advice.

Changed after criticism (2026-09-02): only what was matched through the **provider's identifier**
is cancelled at the provider; a hit through our own sequential number is matched but not
cancelled, and the merchant is told "matched by customer number, please check" (the number is
guessable, the identifier is not). `OfferController` only stores a submitted `consent_text` if it
matches `messages.order_consent` (de/en) or an entry in `consent.accepted_texts` — otherwise the
server-side wording plus `Log::warning('consent text mismatch')`. On top of that: named limiters
`statamic-payments.withdrawal` / `.cancellation` instead of an anonymous `throttle:`,
`legal.timezone` for the time on documents, the reason for a cancellation for cause appears in the
confirmation mail, an expired session leads back to the form instead of to a 404,
`MerchantAddress` warns in the log when it falls back to `mail.from`, and the cancellation listing
hides "kind" and "desired date" by default.

In passing: `Tags\Offer` is now `Tags\Payments` (handle unchanged, `payments`), and
`Portal\EmailAddress::rule()` is the address check as a validation rule — `email:filter` would
have refused every address containing an umlaut.

### The consent is recorded instead of discarded (§ 356 Abs. 5 BGB)

`payments` gets two columns, `consent_at` and `consent_text`. Until here `confirmed => accepted`
was checked and then forgotten; the comment in the code called that "the record", and there was
none. Now the timestamp and the **complete wording** that stood next to the checkbox go into the
row with the first INSERT — through `PaymentDetails`, like `country`. The text itself and not a
version number, because the wording changes and "consented" evidences nothing without the version.

Both columns are immutable: rewriting or deleting them later throws a `LogicException`. From null
to a value happens exactly once. Existing rows stay null.

`OfferController` writes the consent onto the follow-up payment (wording from the hidden field
`consent_text`, otherwise the new language string `messages.order_consent`); `FollowUp::accept()`
does **not** inherit it from the first order.

Legal decisions in this version, to be reviewed by Adrian, not legal advice: both values or
neither; the timestamp never in the future; the wording not empty and at most 4000 characters,
refused rather than truncated; every purchase carries its own consent; the timestamp is when the
form arrived at the server, not the click in the browser. Anyone using the addon without
`statamic-funnels` builds the order summary, the button and the consent text themselves and passes
`consent_at`/`consent_text` — the addon renders no checkout.

## 1.16.0 — 2026-08-31

### A mandate belongs to the person, not to the device

`FollowUp::eligible()` now additionally takes the address of the buyer currently in front of the
screen, and refuses if it does not match the payment that is to be charged against. The same goes
for `accept()`, which takes the address as a fifth argument and passes it on to the check. Callers
that pass nothing get the previous behaviour — there are callers that know their buyer from a
signed session and have no address at hand.

The occasion was a reproduced case in `statamic-funnels`: there, the question "who is this" hung
on a visit cookie with a thirty-day lifetime. Whoever went through the same funnel second on the
same machine got no card form any more. Mollie charged the first purchase's customer by stored
mandate with `sequenceType: recurring`, and access and invoice both ran on that person's address —
the freshly entered one was simply overwritten by `FollowUp`. On a family computer, in an office
or in a library that is not an edge case.

This version does not remove the possibility, it only requires evidence. If either side has no
address, there is nothing to contradict, and the remaining conditions apply as before.

### How the buyer recognises their card

New columns `payments.card_last4` and `payments.card_label`, filled from what the provider supplies
with the payment anyway (`RemotePayment::$cardLast4` / `$cardLabel`). They are needed on a
follow-up offer's page: it must not charge without saying what it charges with beforehand —
§ 312j Abs. 3 BGB requires the essential details directly above the button, the payment method
included. They can only be obtained at the moment of payment; later it costs a provider call while
rendering a page.

Four digits and a name such as "Mastercard" are not a card number and do not fall under PCI DSS.
Nothing more is stored. Existing rows stay null, and every page has to cope with that.

### Migration

`2026_08_31_220000_add_card_hint_to_payments_table` — two nullable columns on `payments`.

## 1.15.0 — 2026-08-30

### Added: `Brands::readerId()` — the missing half of `Brands::only()`

`only()` takes a nullable brand id and does the right thing in every case. But every calling site
had to obtain that id itself, and the obvious wrong answer sat right next to it: `stampId()`. That
one answers "which brand is this **new** row written to" and returns a **null** wherever no brand
is set. Passed on to `only()`, null does not mean "show nothing" but "show the rows nobody has
claimed" — that is, everything a webhook or a console command created.

A listing written that way looks right on a single-brand installation, looks right on a
multi-brand installation with a brand selected, and silently shows the ownerless rows as soon as
somebody opens it without a brand.

```php
Brands::only($query, Brands::readerId());   // right
Brands::only($query, Brands::stampId());    // the ownerless rows
```

Null here, null there — two different questions, as the class comment already said. The comment
alone was not enough.

### Added: `Catalogue::contribute()` — the catalogue can enumerate now

`Catalogue::extend()` answers "what does this handle cost". It cannot answer "what is there at
all", because a resolver only ever gets to see a single handle. Every screen that offers a product
list was therefore blind to everything not in the config file: the product picker in the offer
form showed three of six products and then refused to save with a 422, because the `Rule::in()`
was built from the same blind list.

```php
Catalogue::contribute(fn () => [
    'atemkurs' => ['name' => 'Atemkurs', 'amount_cent' => 4900],
]);
```

**Two seams, not one, and that is deliberate.** `contribute()` enumerates, `extend()` prices.
`find()` therefore stays a config lookup plus a few cheap resolvers and never runs through a
database because somebody asked for a handle that does not exist — `find()` is reachable by
anything a browser sends. The price of that separation is a rule: **whoever contributes has to
resolve as well.** An addon that enumerates a handle it cannot price puts an unsellable row into
the picker.

**Config wins on a tie.** A price in a file is under version control and was written down
deliberately; a database row must not silently overrule a deploy.

Contributed entries without an integer `amount_cent` >= 0 are not listed. Config entries are still
listed unchecked — checking them now would mean a running installation's mistyped price
*disappearing* from its own picker on upgrade, and that reads as "nothing to sell".

## 1.14.0 — 2026-08-29

### Fixed: revenue figures counted every brand, and lost the last second

Three defects of the same family, none of them with a failing test.

**The brand.** `paidInPeriod()` and `refundedInPeriod()` summed every brand, no matter what the
brand picker in the top right said. A test that puts one own row against two foreign ones reports
13,000 instead of 1,000 cents against the old state. `brandScoped()` here is transcribed word for
word from `TableMetric::brandScoped()` — this class does not build on it, it is older and reads
two tables and a join — because two spellings of one rule are the path by which two tiles side by
side count different things.

**The window.** The upper bound was inclusive, and a binding formats `23:59:59.999999` as
`Y-m-d H:i:s`. On a millisecond column that dropped every sale in the last second: on SQLite
always, on plain MySQL timestamps accidentally correct, invisible in both cases. Half-open now.
The test writes one millisecond and reports 1 instead of 2 against the old state.

**The join.** `productRows()` does not go through `paidInPeriod()` but starts at the line items and
joins back — exactly the shape that slips past a centrally applied filter. Every condition is
written out there now.

### Added: seven figures in Insights

Gross, net, refunded, orders, buyers, average order value and refund rate, with splits by campaign,
source, product and country. `statamic-insights` no longer reads **any** table of this addon for
that — the arithmetic now sits on the side of the fence that owns the data. The coupling is a
`suggest` in both directions, never a `require`.


### Added: `grants` may be a list

A product could grant exactly one access. For a bundle — one line, one price, three things — that
was not enough, and `statamic-offers` 1.4 sells exactly that.

`grants` now also takes a list:

    'fruehlings-buendel' => [
        'name' => 'Frühlings-Bündel',
        'amount_cent' => 4900,
        'grants' => ['noten-fruehling', 'playback-fruehling', 'workshop-mitschnitt'],
    ],

A single string stays allowed and is still the ordinary case; old configurations do not change.
Duplicate slugs are granted once — two rows saying the same thing are not a second access.

All four paths an access hangs on are affected: purchase, renewal, cancellation and refund. Each
slug is an attempt of its own, so that the second one failing does not prevent the third — and the
line in the log names the missing slug instead of "the bundle".

**Before this it was a silent total failure, not a partial one.** A list fell out at `is_string()`,
and `slugFor()` returned `null`: not the first item, but nothing. Payment through, invoice written,
no access, no error message.


### Added: self-service for buyers

A buyer can now view their orders, download their invoice, cancel their subscription and change
their payment method without an account. The way in is a signed, expiring link to the address on
the order — no password, no account, because somebody buying a book of sheet music never wanted to
create one.

The mechanics are those of `statamic-preference-center`, taken over instead of reinvented: double
throttling, one answer for every outcome, response time held to a floor, session id renewed on
opening. The same look, the same palette, no build step — the page is opened from a mail client
and has to be there at the first byte.

**§ 312k BGB ships with the addon.** A cancellation button with a URL of its own, a confirmation
page naming the contract, and after that a confirmation in text form with date and time — as a
mail, not as a green box that is gone on reload. **Every prescribed wording lives in
`lang/*/portal.php`** and in no PHP file; it belongs in front of a lawyer, and the rule has been
amended once already. `--tag=statamic-payments-translations`.

Cancelling runs through `Subscriptions::cancel()`: the provider is asked first, and its answer is
what gets written. If it does not answer — or accepts the call and lets the subscription keep
running — the row stays untouched and the buyer gets an honest message instead of a confirmation.

### Added: `brand_id` on `payments` and `subscriptions`

Nothing here carried a brand so far, and `statamic-invoices` wrote exactly that into an exception
class: "a brand is not recoverable from the payment either". For the customer portal that gap is
not affordable — on a multi-tenant host the brand on the order is the only thing keeping brand A's
link away from brand B's order.

The seam knows **three** states, not two: "no tenants", "tenants, and the current one is known" and
"the sibling addon is there and did not answer". A `bool` collapses the last two, and a
`catch (Throwable) { return false; }` around `multiBrandEnabled()` would have turned a throwing
licence callback — which the host writes itself — into "this installation has no tenants", that
is, into "no filter". A defensive catch that opens outwards is worse than no catch: it produces a
page that works.

`default(0)`, no foreign key, no hard dependency on `brand-context`: on every single-brand
installation it is 0 everywhere and nothing changes. The stamp comes from the brand the row comes
into being in; if it comes into being in the webhook for another row — a subscription cycle, a
follow-up payment — it inherits that row's brand instead of guessing one. In multi-tenant
operation a row on 0 belongs to nobody and is shown to nobody.

**The existing data is derived, not guessed.** The first version of this migration took the lowest
brand id and wrote it onto every existing payment and subscription. On the demo playground that
made eleven payments belong to "nordlicht", and `invoices:brand-check` found seven invoices sitting
in a different brand's series than the payment they belong to. The invoices were right — and since
`statamic-invoices` reads the column, the guessed answer would have been passed on into new
documents from then on.

Three paths, strongest first: a payment with an invoice gets the invoice's brand, a subscription
the brand of its first payment, a recurring charge the brand of the row it belongs to. Run until
nothing more is added, because each path feeds the next. Whatever is left after that stays on `0`
and is written to the log; the default brand is not used anywhere. The access to `invoices` runs
through `Schema::hasTable()` and is a hint, not a requirement: the invoicing addon is a `suggest`,
and the real dependency runs the other way round.

### Added: `payments:brand-backfill`

The broken migration is committed and has already run on at least two installations; there it
never runs again, and the rows sit on the wrong brand instead of on `0`. This command runs the
**same** derivation (one place, `Support\BrandBackfill`, not written twice) and only corrects a row
where a derived source contradicts it. A row for which nothing can be derived stays as it is — even
if it carries the guessed brand: missing evidence is not evidence. Both are counted and printed.

`--dry-run` only shows. Without the option it writes, with a summary of how many rows came from
which source.

### Added: a soft seam to the invoice

`Contracts\InvoiceSource` + `Support\Invoices` (a registry, like `Catalogue`). Without the
invoicing addon the order shows without a download instead of breaking.
`Integrations\InvoiceBridge` recognises `goldnead/statamic-invoices` **by shape, not by type**: a
single string names its facade, everything after that is `method_exists`. PDF and delivery are
being built there in parallel right now; a bridge against today's classes would be a bet on
unfinished work.

### Added: `Contracts\MandateGateway`

Changing the payment method through the provider's mandate path. `MollieGateway` implements it; the
customer portal asks whether the bound gateway can do it and never names Mollie anywhere. On Mollie
this costs the buyer one cent — there is no zero-amount authorisation there — and the amount is
stated above the button rather than later on the bank statement.

### Fixed

- `Payment` did not know the attribution columns from 1.13 in its `@property` block.
- `Checkout` asked `request()` with `?->`, which is never null.

### Added

- **Seven metrics for `statamic-insights`** — gross revenue, net, refunded,
  orders, buyers, average order and refund rate, with splits by campaign,
  source, product and country. The addon that owns the data now owns the query;
  Insights owns the screen. Optional in both directions: a `suggest`, a
  `class_exists` guard, and nothing loaded when the sibling is absent.
- `HasFilterOptions` on every metric, so the currency switch on the reporting
  screen is filled by this addon rather than guessed by the other one.

## 1.13.0

### Added: a seam for details the package attaches no meaning to

`FollowUp::accept()`, `Checkout::start()` and `Subscriptions::start()` now take a `$details`
parameter: `meta`, `country` and `country_source`. What is passed there is written in the same
transaction as the payment itself and is therefore settled **before** the provider is called.
Described in `docs/follow-up-offers.md`.

What the caller passes overwrites nothing the package sets itself. Amount, product, status, the
provider's identifiers, the link to the parent payment: anyone sending those gets an
`InvalidArgumentException` before a row is created and before money has moved. An amount discarded
silently would look to the caller like an amount that was set, and the difference would only show
up on the bank statement.

### Fixed: the follow-up payment had nowhere for its details to land

`FollowUp::accept()` created a payment and called the provider without the calling flow being able
to attach anything to that payment. Anyone who needed the address or a reference of their own added
both **after** the call, and that is a race against the webhook: if the provider reports the
payment faster than the caller writes, the invoice writer reads a row without an address.

This could only surface from €250 upwards. Below that the Kleinbetragsrechnung under § 33 UStDV
(the German small-amount invoice) suffices, which does without the recipient's address; above it,
it is a missing mandatory detail on a document that is no longer corrected but reversed and
rewritten. A follow-up offer is typically the cheap thing beside the order, and that is exactly why
the gap keeps quiet until somebody puts an expensive one next to it.

### Fixed: a subscription payment from the second cycle on never had an address

A cycle comes into being in the webhook, because the provider charges of its own accord. The row
was built exclusively from the subscription and inherited nothing from the payment that started
it. Every cycle invoice therefore lacked the address, and a subscription is the kind of revenue
most likely to break the €250 line. To make it worse: the `subscription_id` column is not yet set
at the time of `PaymentPaid`, so a listener did not even have a pointer to look it up.

A cycle now inherits the first payment's `meta`, without the keys the package maintains itself, and
carries the identifiers of the subscription and the first payment in `meta['cycle_of']`. The
country is deliberately not inherited: the provider supplies that afterwards, and its evidence
weighs more.

### Fixed: a trial period without an amount never became a subscription

`Subscriptions::start()` only wrote `subscription_intent` after `Checkout::start()` had returned.
With a trial period that costs nothing today, the payment is already fulfilled at that point: the
catalogue prices it at zero, `Checkout::start()` fulfils it itself, `PaymentPaid` fires,
`startFromPayment()` looks for the intent and finds none. Result: a paid order, no subscription, no
log line, no difference from an ordinary one-off payment.

The intent now goes into the checkout instead of after it. With a trial period without an amount it
remains the case that no subscription comes into being (no charge means no mandate, and no mandate
means no subscription), but it is now an error in the log instead of nowhere at all.

## 1.12.0

### Fixed — buying through an offer granted no access

`EntitlementsBridge` read `config('statamic-payments.products')` directly and thereby bypassed every
resolver another addon has registered with the `Catalogue` — and `statamic-offers` registers one.
Every order through an offer therefore granted **nothing at all**: payment successful, money there,
access never.

As silent as a defect can be. "This product grants nothing" and "I do not know this product" both
came back as the same `null` — no error, no log line, no difference from a product that legitimately
grants nothing.

`slugFor()` and `grantLine()` now go through the catalogue. The same applied to `productName()` in
the Control Panel, where the raw handle `offer:fruehling-upsell` stood instead of a name.

Evidenced against the real `statamic-entitlements`, not against a stand-in: the last time this
bridge was only checked against a double, it had never worked on a single real installation.

## 1.11.0

### What's new

- **Quantity bounds in the catalogue.** The quantity is the only figure a checkout accepts from a
  request — the unit price never is — so a product that offers a *variable* one now says what it
  allows: `min_quantity` and `max_quantity`. That is what makes a donation or a pay-what-you-want
  possible without the rule falling: the unit price stays server-side, and what comes from the
  browser is a bounded integer.

  Opt-in. A product that says nothing behaves exactly as before, capped by a new global
  `max_quantity` (default 1000) that exists only so a mistyped or hostile figure cannot become a
  five-figure charge.

### What's fixed

- **A currency is not always divided by a hundred.** `amount_cent` is minor units and `amount()`
  hard-coded two decimals. The Japanese yen has none and the Tunisian dinar three, so 1.000 ¥ went
  to the provider as either ten times or a hundredth of the price. `Support\Money` knows the
  zero- and three-decimal currencies; two remains the default, because a table of every ISO 4217
  code is one nobody maintains.

- **The return URL is checked against this application.** No shipped code path feeds it from a
  request, so this was never a hole in the addons — it was a trapdoor for hosts: an application
  passing `$request->input('return')` through would build an open redirect, and one with unusually
  good cover, because it sits behind a real and successful payment. An external target is now
  dropped rather than refused: the buyer has paid by then, and failing the checkout over a bad
  return address would take their money and show them an error.

  Approach taken from `thomasvantuycom/statamic-mollie` (MIT, checked at the repository).

## 1.10.0

### What's new

- **Refunds are recorded, and a full one withdraws the access.** Until now the refund happened in the
  provider's dashboard, nothing here heard about it, and somebody who was repaid kept their course
  indefinitely. The sibling has had `revoke()` with a mandatory reason all along — nobody called it.

  `Refunds::record()` notes an **amount and a time**, never a status: an order half repaid is still a
  paid order, and a status forced to choose would be wrong about the other half. Idempotent per the
  provider's refund id, because "the customer was refunded three times" is the kind of number that
  ends up in an annual return.

  A **full** refund revokes every product line of the order. A **partial** one does not: half the
  money back is not half a course, and there is no honest way to withdraw half an access — so it is
  recorded and left to a person.

  Verified against the real entitlements addon, not a stand-in.

- **`payments:prune-unpaid`** deletes checkouts that were started and never paid, after a number of
  days the site names (`prune_unpaid_after_days`, off by default). A paid order carries a retention
  obligation; an abandoned checkout carries the opposite. Deleted rather than anonymised — an
  anonymised record with no purpose is still a record.

  Everything paid, fulfilled, refunded or in a final status is left alone, as is anything inside a
  running reminder sequence: an automation whose trigger vanishes underneath it fails halfway through.

## 1.9.0

### What's new

- **The two facts an invoice needs, recorded while they still exist.** Neither can be reconstructed
  later, which is why they land here rather than in an invoicing addon: every real sale that happens
  before this is a row that can never be invoiced correctly.

  **`payments.country`** and `country_source` — the buyer's country, frozen at checkout, normalised
  to ISO 3166-1 alpha-2. Anything else is dropped rather than stored: a column that holds
  "Deutschland", "DE" and "de" is one nobody can compute a rate from, and a wrong rate looks like an
  answer. Where the checkout has none, fulfilment fills the gap from the provider — which is the
  better evidence anyway, since it comes from the card issuer.

  **`payment_items.discount_cent`** — the share of the discount that fell on each line, distributed
  proportionally to line value. From a single total, a voucher across a 7% line and a 19% line
  cannot be split, and the invoice is then not visibly wrong but indeterminate. Rounding is a named
  rule, not a hope: integer division, leftover cents to the largest lines first, so the parts always
  add up to the whole. A percentage voucher and the amount it produces split identically — which is
  what makes the rule safe to apply after the fact.

  Existing rows keep `null` and `0`. That is the honest state.

## 1.8.0

### What's new

- **A subscription now keeps its entitlement in step.** `SubscriptionRenewed` pushes the window to
  the provider's own `next_payment_at`; `SubscriptionCancelled` and `SubscriptionEnded` close an
  open-ended grant at the end of the paid period. Until now every installation wrote these three
  listeners itself.

  Each rule is deliberately not the obvious one. A renewal calls the sibling's `renew()`, not
  `grant()` — that call refuses to widen an existing window on purpose, so once a month would mean
  twelve grants a year and "does this person have access" would become an aggregation. Cancelling
  **closes** rather than revokes: somebody who cancels has paid for the period they are in. And a
  renewal without a date from the provider changes nothing and logs why — a guessed end is a grant
  that stops too early or too late, and the customer finds out first.

  Requires `statamic-entitlements` 1.1. Against an older sibling the bridge stays quiet rather than
  writing the wrong thing.

  Covered twice: once against a stand-in as strict as the real class, and once **against the sibling
  itself** — three cycles, one entitlement, ending on the third date. The last time this bridge was
  tested only against a stand-in, it had never worked on a single real installation.

## 1.7.0

### What's new

- **Abandoned checkouts.** `payments:sweep-abandoned` announces every checkout that was started and
  left unpaid past a waiting period, as `CheckoutAbandoned`, once each. With `statamic-automations`
  installed the trigger **Checkout Abandoned** appears under Payments and needs no code.

  Once-only is claimed with a conditional update on a new `abandoned_notified_at` column, the same
  way fulfilment and failure already are: the sweep runs on a schedule and may overlap itself, and a
  reminder arriving twice is a support ticket nobody can reproduce. A payment that arrives afterwards
  clears the claim, so a sequence can end on `PaymentPaid`.

  **Off by default, and that is not caution about the code.** The address on an unfinished checkout
  was given to complete a purchase, not to receive advertising.

## 1.6.0 — 2026-08-25

### Fixed — the entitlements bridge had never once worked

The bridge handed the buyer's **email string** to `statamic-entitlements`, which
refuses a bare string on purpose: a grant belongs to a `(type, id)` subject so it
can outlive the record it points at. So every paid order on a real installation
logged *"the entitlements bridge failed"* and granted nothing.

Built, wired, documented, tested — and never working, because the tests bound a
stub that accepted anything. A mock that says yes to everything proves you made
a call, not that the call was accepted. Found by installing both addons side by
side and paying with a real card.

The bridge now passes a `SubjectReference('email', …)`, falling back to the old
string for an older sibling. `statamic-entitlements` is a dev dependency of this
package **because of the test**: a skipped test is what let this through.

### New — the webhook URL is configurable

A provider checks that a webhook URL is reachable from *its* side before it will
create a payment, so a developer on `localhost` cannot check out at all. Mollie
answers 422 and the checkout is refused.

- `webhook_url` as a string overrides the route: a tunnel's address goes there.
- `false` omits it, and the status has to be pulled instead —
  `Fulfilment::handle($providerId)` is the same method the webhook route calls.
  Fine for a demo, **wrong for production**, and the config comment says so.

### Changed

- `$actions` and `$scopes` are no longer declared: core discovers `src/Actions/`
  and `src/Scopes/`, and an explicit list goes stale the moment somebody adds a
  class.

## 1.5.0 — 2026-08-25

### Being charged again, on a rhythm

**One mechanism, three faces.** A subscription runs until somebody stops it, a payment plan stops
counting, and a trial starts late. Not three features: a plan is a subscription with an end, and
building them apart would have meant three cancellation paths and three ways to get the last
instalment wrong.

- `Subscriptions::start()` takes a first payment, and the agreement is created **only after the
  webhook confirms it** — a mandate is what a provider needs, and a payment is what leaves one.
- Every cycle after that is an ordinary `Payment`: same `PaymentPaid`, same one-time claim. A
  subscription therefore grants access every month without any listener knowing subscriptions exist.
- A cycle the provider charged on its own gets a row and a line, built from the **agreement** and
  never from the webhook.
- `SubscriptionStarted`, `SubscriptionRenewed`, `SubscriptionCancelled`, `SubscriptionEnded` and
  `SubscriptionStartFailed`.
- A **Subscriptions** utility screen: both faces in one listing, a read-only detail with the payments
  made against each agreement, and cancelling as a row and bulk action.

**A trial is honest about its trade.** Mollie cannot store a card without charging something — no
SetupIntent, no zero authorisation. So `trial_amount_cent` says what the trial charges, and a site
that sets it to nothing gets no card and a buyer who has to come back.

### Found by a reviewer, and worth naming

- **The provider call was inside a database transaction.** Anything failing after it rolled the local
  row back while the provider kept a running subscription: somebody charged every month, forever,
  with no row here and no alarm — a cycle for an unknown agreement is indistinguishable from a stray
  webhook. Now the row is committed first and the event fires after, the pattern `Checkout` and
  `FollowUp` already follow.
- **`add('1 month')` on 31 January lands on 3 March.** February is skipped and the provider bills on
  the 3rd for ever after. Measured. Months are now clamped to the end of one.
- The provider is asked how an agreement is doing on every cycle, so a suspension after failed
  charges reaches the row.
- A straggler no longer ends a finished plan twice.
- A cycle carries a `PaymentItem`, so reports built over lines stop leaving out all recurring revenue.

### Found by looking at the screen

- **The Control Panel toasts everything green.** A returned value is toasted as success, and a thrown
  exception becomes `success: false`, which is *also* toasted green. A refused cancellation therefore
  arrived with a tick. The action now pushes `Toast::error()` and returns `['message' => false]`.
- Sorting by "next charge" pulled cancelled agreements (NULL) to the top.

## 1.4.0 — 2026-08-25

### What's new

- **A product may cost zero.** The provider is never called; the payment is marked paid and fulfilled
  on the spot, through the same one-time claim and the same `PaymentPaid` event, so a listener that
  grants access cannot tell the difference.
- **`Discount`** — an optional fourth argument to `Checkout::start()`, for a total lower than its
  lines. This addon still knows nothing about coupons: what a code is worth belongs to pricing, and
  pricing lives in `statamic-offers`. What lives here is `discount_code` and `discount_cent` on the
  payment, so an old receipt keeps saying what came off after the coupon has expired or changed.
- The discount is clamped: never more than the total, never negative. A bug upstream should cost a
  wrong price, not a payment the provider rejects.

### Changed

- `Catalogue::find()` now accepts `amount_cent => 0`. A **missing or mistyped** price is still
  refused — `null`, a negative number and `'19,00'` all still return nothing. `0` is a statement;
  those are mistakes, and a mistake must not become a giveaway. The test that asserted zero was
  unsellable has been changed to say so, not deleted.

## 1.3.0

### What's new

- **`Catalogue::extend()`** — a seam another addon can contribute priced things through.
  `goldnead/statamic-offers` uses it so that an upsell with its own price resolves like any other
  product, and every guard in here applies to it unchanged. **The configured catalogue always wins**:
  an addon may add, never reprice what the site has already decided.
- **`Checkout::start(..., $returnUrl)`** — where the provider sends the buyer back to. A funnel
  passes its own page, because a buyer who returns outside the flow they were walking has been
  dropped halfway through a purchase, and whatever was meant to follow the sale never happens.


## 1.2.0

### What's new

- **A payment carries lines, not one product.** An order bump — a checkbox at
  checkout adding a second item — is now one payment with two lines:
  `start(['noten-paket', 'uebungsblaetter'])`, or with quantities
  `start(['noten-paket' => 1, 'uebungsblaetter' => 3])`. The total is their sum,
  each line keeps the name it was sold under, and a line is never a second
  payment.
  **All or none:** a handle that is not in the catalogue refuses the whole
  checkout instead of quietly dropping the line, and two currencies in one
  payment are refused for the same reason.
  Done now rather than later on purpose: the schema change costs nothing while
  the addon has no installs, and would be a migration on other people's servers
  afterwards.
- **Follow-up offers**, off by default. An offer shown after a payment, charged
  without asking for card details a second time. `docs/follow-up-offers.md` is
  the whole story, and most of it is not technical: in Germany a follow-up order
  still needs its own unambiguously labelled button with the essential details
  directly above it. What is saved is the card number, **not** the consent.
- The offer disappears once taken. A second click, a double submit, a reloaded
  confirmation — all of them would otherwise charge again for the same thing. A
  *refused* charge does not count as taken.
- A follow-up is never treated as paid on acceptance. A recurring charge is
  accepted now and settled later; only the webhook decides, exactly as at
  checkout.
- The entitlements bridge grants **every** paid line, not only the first. A bump
  the buyer ticked and paid for is as bought as the thing they came for.

### What's fixed

- A payment's lines are deleted with it even where the database does not enforce
  the foreign key — which on SQLite it quietly does not. Orphaned lines would
  have counted towards every revenue report ever run.


## 1.1.0

### What's new

- **A screen in the Control Panel.** Utilities → Payments: when, what, how much, paid, **fulfilled**,
  and who bought it. Built on core's `Listing`, so it behaves like the rest of the CP.
- The column that earns the screen is `Fulfilled`, and the filter *Paid, not fulfilled* narrows the
  list to the one case worth chasing: money arrived, nothing delivered. Mollie cannot answer that
  question; only the site can.
- Status and fulfilment are real Statamic filters, so they show a badge, survive sorting and paging,
  and can be saved as a view. A query parameter of my own would have been dropped by the listing
  after the first fetch.
- Read-only. Refunds and disputes stay at Mollie, where the record is complete.
- Access is the `access payments utility` permission, registered by core along with the screen.
- CI now rebuilds the committed Control Panel bundle and fails if it differs from the sources.


## 1.0.0

Initial release. Mollie checkout behind a provider-agnostic seam, a webhook that trusts nothing in
the request, fulfilment that runs exactly once, and two events.

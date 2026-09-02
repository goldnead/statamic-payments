<!-- statamic:hide -->
# Statamic Payments
> Take payments in Statamic with Mollie — and never believe the caller.
<!-- /statamic:hide -->

## Requirements

Statamic 6 · PHP 8.2+ · a database · a Mollie account.

Mollie rather than Stripe because this is built for a German and European audience: SEPA direct
debit, Sofort, iDEAL and Bancontact are what people here actually reach for, and there is no
monthly floor — which matters on a client site that takes four payments a month.

## Installation

```bash
composer require goldnead/statamic-payments
php artisan migrate
php please vendor:publish --tag=statamic-payments-config
```

Set `MOLLIE_KEY` in your environment, then list what you sell.

## Usage

### Products

```php
// config/statamic-payments.php
'products' => [
    'noten-paket' => [
        'name' => 'Notenpaket „Frühling"',
        'amount_cent' => 1900,
    ],
],
```

`amount_cent` is an integer in minor units. Not a float: a float is how a cent goes missing every
thousand orders.

### Starting a payment

```php
use Goldnead\StatamicPayments\Support\Checkout;

$checkout = app(Checkout::class)->start('noten-paket', [
    'email' => $request->input('email'),
    'name' => $request->input('name'),
]);

abort_if($checkout === null, 404);          // no such product

$checkout->payment;                          // the row
return redirect()->away($checkout->checkoutUrl);
```

**The amount is looked up, never accepted.** Anything the buyer sends is a label on the order, not a
term of it — a checkout that took a posted price would sell a €19 thing for a cent.

### Reacting

```php
use Goldnead\StatamicPayments\Events\PaymentPaid;

Event::listen(PaymentPaid::class, function (PaymentPaid $event) {
    $event->payment->product;   // what was bought
    $event->payment->email;     // by whom
    $event->payment->amount_cent;
});
```

**Dispatched once per payment**, guaranteed by a conditional `UPDATE` rather than by a check, and
the claim stands in the database *before* any listener runs. A listener may therefore grant access
without carrying its own idempotency for the ordinary case — redelivery, a duplicated request, two
deliveries landing together. `PaymentFailed` works the same way, on its own column.

**The one case a listener must still think about: its own exception.** If a listener throws, the
claim is released, the exception reaches the caller, the webhook answers non-2xx and the provider
delivers again — so that listener, and every other listener on the event, runs a second time. The
alternative would be keeping the claim, and the failure mode of that is a customer who paid, got
nothing, and no retry ever comes, silently, because the row says fulfilled. Given the choice, this
package repeats rather than loses. Make irreversible work in a listener idempotent, or queue it.

What a payment *means* belongs to your site. There is one optional exception, below.

### Entitlements (optional)

With [`goldnead/statamic-entitlements`](https://github.com/goldnead/statamic-entitlements) installed:

```php
// config/statamic-payments.php
'entitlements' => ['enabled' => true],

'products' => [
    'noten-paket' => ['name' => '…', 'amount_cent' => 1900, 'grants' => 'noten-fruehling'],
],
```

Off unless all three are true: the sibling installed, the flag on, and the product carrying
`grants`. A failure in the sibling is logged and swallowed — the money was taken and the row says
so; an entitlements outage must not send the whole webhook round again.

## In the Control Panel

Utilities → **Payments**. When, what, how much, paid or not, **fulfilled or not**, and who bought it.
Built on core's `Listing`, so search, sorting and column choice behave like the rest of the CP.

The column that earns the screen is `Fulfilled`. Mollie can tell you the money arrived; only the site
knows whether the buyer got anything for it. The **Filters** menu has one entry for exactly that
case: *Paid, not fulfilled*. It survives sorting, paging and a reload, and it can be kept as a saved
view.

Read-only. Refunds and disputes belong at Mollie, where they are complete and where the audit trail
is.

Access is the `access payments utility` permission, which appears in Statamic's own permission list
once the addon is installed.

### One payment, whole

Clicking a row (or **Details** in its menu) opens `cp/utilities/payments/{id}`: the amount, status
and timestamps at the top, then panels the way a publish form groups its fields — **lines** (kind,
quantity, unit price, the offer it was sold through), **buyer** (email, name, country, the address
from `meta.address`, the VAT ID from `meta.vat_id`), **consent** under § 356 (5) BGB (when, the exact
wording, the version of the instruction), the **access window** from `meta.access`, **origin** (UTM,
referrer, landing page), **payment method**, **refunds**, **related** (original order, follow-up
offers, subscription, invoice, withdrawals, cancellations) and **communication** — see below. With
`statamic-webhook-manager` installed, a panel lists that payment's webhook deliveries.

Same permission as the listing. On a multi-brand install with a brand selected, a payment of another
brand is a 404, not a 403: "does not exist" gives away less than "exists, not yours".

### The communication log

What went out for an order — the invoice mail, the welcome mail, a note from support — is otherwise
in nobody's memory once the mail log has rotated. `payment_communications` keeps one line per event,
append-only, and the detail page shows them newest first ("Nothing sent yet" until there is one).

The addon writes its own: the portal link (on the address's latest order), the withdrawal
acknowledgement (where a payment matched), the cancellation acknowledgement and the portal's
cancellation confirmation (on the subscription's latest payment), the abandoned-checkout reminder.
`statamic-invoices` writes its invoice mail. Your site and other addons write theirs through the
facade:

```php
use Goldnead\StatamicPayments\Facades\PaymentLog;

PaymentLog::mail($payment, 'purchase_confirmation', $to, $subject);        // $payment or its id
PaymentLog::mail($payment, 'access', $to, $subject, 'failed', ['error' => $e->getMessage()]);
PaymentLog::note($payment, 'support', 'Zugang von Hand verlängert bis 31.12.');
PaymentLog::record($payment, 'export', 'datev', ['reference' => $batchId]);
PaymentLog::for($payment);                                                  // newest first
```

`kind` is yours (64 characters); the screen translates the ones it knows (`invoice`,
`purchase_confirmation`, `access`, `receipt`, `portal_link`, …) and shows the rest as written.
Channels are `mail`, `webhook`, `export`, `note`; statuses `sent`, `failed`, `queued`. Every write
dispatches `PaymentCommunicationLogged`.

**A write that fails never throws.** A missing table, a lost connection — the mail is out either way,
and a checkout must not fail because its diary did. The failure is logged as a warning, loudly, so a
gap in the log is never mistaken for "nothing was sent".

### Subscriptions

Utilities → **Subscriptions**, next to it: the agreements rather than the money. Product, whether it
is a subscription or a payment plan, what one cycle costs, the rhythm, how many cycles have been
charged (`2 / 3` while there is an end to count towards), the next charge, the status and the buyer.
Sorted by what is charged next, so what is about to happen is at the top.

Two filters: **Status**, and **Still running** — the second is `isLive()` asked of the query, which
is the "who is still being charged" list.

Clicking a product opens a read-only slide-over with the whole agreement and the cycles it has
actually been paid: date, amount, status. The cycles are ordinary payments, so they also appear on
the Payments screen.

**Cancelling** is available from a row's menu and from the bulk toolbar, and both run the same
`Support\Subscriptions::cancel()`: the provider is told first and its answer is what gets written. A
provider that refuses, or that accepts the call and goes on reporting the agreement as running,
produces a **red** toast and leaves the row untouched. Nothing here creates a subscription — one is
what a confirmed first payment leaves behind, never something typed into a form.

Access is the separate `access subscriptions utility` permission. "May read the till" is not the same
authority as "may end an agreement".

## Order bumps and follow-up offers

A payment carries **lines**, not a single product, so a checkbox at checkout
adding a second item is one payment with two lines:

```php
app(Checkout::class)->start(['noten-paket', 'uebungsblaetter'], $buyer);
app(Checkout::class)->start(['noten-paket' => 1, 'uebungsblaetter' => 3], $buyer);
```

**All or none.** A handle that is not in the catalogue refuses the whole
checkout rather than quietly dropping the line — dropping it would charge the
buyer for less than the page offered, and the first anyone hears of it is a
customer who paid for two things and got one. Two currencies in one payment are
refused for the same reason.

An offer *after* the payment, charged without new card details, is off by
default and has its own page: [docs/follow-up-offers.md](docs/follow-up-offers.md).
Read it before switching it on — the technical part is small, the part that
decides whether you may ship it is not.

## Consent, withdrawal and cancellation

Three things German consumer law wants from a shop that sells digital goods to
consumers, and what this addon does about each. **None of this is legal
advice**; the wording and the decisions below are documented so a lawyer can
check them, not so you can skip the lawyer.

### Recording the consent (§ 356 Abs. 5 BGB)

For digital content delivered at once, the right of withdrawal ends only if the
buyer expressly agreed to immediate delivery and acknowledged losing the right.
A checkbox that is validated and forgotten proves nothing afterwards, so the
payment row carries two columns, written in the same INSERT as everything else:

| Column | What it is |
|---|---|
| `consent_at` | when the buyer agreed |
| `consent_text` | the exact wording that stood next to the checkbox, in full |

The text itself is stored and not a version key: the wording will change, and
"agreed" without the version agreed to is worthless. **Both columns are
immutable** once set — a later save that changes or erases either throws a
`LogicException`. Rows that predate the column stay `null`, which is the honest
state.

Hand both in through the details, together or not at all:

```php
app(Checkout::class)->start('noten-paket', $buyer, null, null, [
    'consent_at' => now(),
    'consent_text' => __('statamic-payments::messages.order_consent'),
]);
```

`consent_at` takes a Carbon, a `DateTimeInterface` or an ISO-8601 string and
must not lie in the future; `consent_text` must be non-empty and at most 4000
characters. A follow-up offer accepted through `POST /!/statamic-payments/offer`
records its own consent — the wording arrives in a hidden `consent_text` field,
falling back to `messages.order_consent` — and **does not inherit** the
original order's: every purchase is its own contract. A hidden field is a
field anybody can edit, so the submitted text is written only if it is one of
the sentences your pages actually show: `messages.order_consent` (German and
English) plus whatever you list in `consent.accepted_texts`. Anything else is
replaced by the addon's wording and logged as `consent text mismatch`.

**If you use `statamic-payments` without `statamic-funnels`, you build the
order summary, the button label and the consent text yourself** and pass
`consent_at`/`consent_text` when starting the checkout. This addon renders no
checkout page; the Button-Lösung of § 312j BGB is the host's to satisfy, and
the row can only record what the host hands it.

### The withdrawal button (§ 356a BGB, since 19 June 2026)

A shop that concludes distance contracts with consumers through a website has
to offer an electronic withdrawal function. The addon ships it, **public and
without a login**, in two steps:

```
GET  /!/statamic-payments/widerruf                       step 1: name, email, order reference, contact, message
POST /!/statamic-payments/widerruf                       …creates the declaration, leads to step 2
GET  /!/statamic-payments/widerruf/{W-…}                 step 2 (this browser only) / step 3 (anyone with the reference)
POST /!/statamic-payments/widerruf/{W-…}/bestaetigen     „Widerruf bestätigen" — the withdrawal itself
```

Step 2 shows the entered details above a single button reading „Widerruf
bestätigen" (§ 356a Abs. 3). Pressing it sets `confirmed_at`, mails the consumer
an acknowledgement at once (§ 356a Abs. 4: reference `W-XXXXXXXX`, date, time
and time zone, the order reference they typed) and notifies you at
`withdrawal.notify` (falling back to `portal.from`, then `mail.from`). Step 3
shows the reference and the time, nothing else, and stays readable for anyone
who has the reference — the mail says the same. A second press is one
withdrawal, one mail, one time.

**The form says nothing about whether an order exists.** Matching to a payment
happens after confirmation, on the server, on an unambiguous hit only (address,
case-insensitively, plus our id or the provider's id), and the result reaches
you and not the consumer. An unmatched declaration is still a declaration and
still acknowledged. A recorded consent under § 356 Abs. 5 on the matched
payment is passed to you as a **hint** (`right_expired_hint`), never asserted
to the consumer beforehand; so is a declaration arriving after
`withdrawal.days` (default 14) from the payment. Whether the right has run is a
question for a person with the file in front of them.

**Put the link in the footer, on every page, labelled „Vertrag widerrufen".**
§ 356a Abs. 1 wants the button „während des Laufs der Widerrufsfrist auf der
Online-Benutzeroberfläche ständig verfügbar, hervorgehoben platziert und für
den Verbraucher leicht zugänglich". The wording is in
`withdrawal.button`; the address comes from

```
{{ payments:withdrawal_url }}                       Antlers
Goldnead\StatamicPayments\Legal\Links::withdrawal()  PHP, null when switched off
route('statamic-payments.withdrawal.form')
```

Your withdrawal instruction (Widerrufsbelehrung) is not part of this addon; put
its URL in `withdrawal.policy_url` and the form links to it.

Withdrawals appear in the Control Panel under Utilities → Withdrawals
(permission `access withdrawals utility`), with the matched payment, the hints
and a „Mark as handled" action that takes a note (permission `handle payment
withdrawals`). The screen lists confirmed declarations only.

### The cancellation button without a login (§ 312k BGB)

The customer portal already cancels a subscription behind a mailed link. Under
the prevailing reading of § 312k the *declaration* must be possible without an
identification step, so there is a second way in, built like the withdrawal:

```
GET  /!/statamic-payments/kuendigung                     „Verträge hier kündigen": name, email, contract reference, type, reason, date
POST /!/statamic-payments/kuendigung
GET  /!/statamic-payments/kuendigung/{K-…}               confirmation page / acknowledgement
POST /!/statamic-payments/kuendigung/{K-…}/bestaetigen   „jetzt kündigen"
```

The confirmation page carries what § 312k Abs. 2 Nr. 1 names — type of
cancellation (ordinary or extraordinary, the latter with its reason), the
identification, the requested date — under a button reading „jetzt kündigen".
Both button labels are statutory and live in `cancellation.button` and
`cancellation.confirm_button`. Confirming acknowledges by mail and on the page
with date, time and the requested date, and notifies you.

Where the declaration names **one running** subscription unambiguously **by
the provider's id** (address plus e.g. `sub_…`), it is cancelled at the
provider immediately through `Subscriptions::cancel()` — provider first, row
second, exactly as the portal does — and `provider_cancelled_at` is set. A
match by our own running number (`subscriptions.id`) is attached to the row
but **not** cancelled automatically: that number is guessable, the provider's
is not, and an address plus "105" must not be enough to end somebody's
contract. Your notification says "matched by customer number, please check and
cancel in the Control Panel". Where nothing matches, or the provider will not
confirm, nothing is written to the subscription and your notification says so.
The consumer receives the acknowledgement either way: the declaration has
reached you.

A requested date in the future does **not** hold the provider-side cancellation
back. What the provider cancels is the next charge, and a charge after a
received cancellation is the harm the button exists to prevent; the date is
recorded and reported so you can carry the service to it where it is owed.
(Decision of 1 September 2026, documented for review.)

Footer link: `{{ payments:cancellation_url }}`, `Legal\Links::cancellation()`,
`route('statamic-payments.cancellation.form')`. Control Panel: Utilities →
Cancellations, permissions `access cancellations utility` and `handle payment
cancellations`.

Both flows are on by default and under `statamic-payments.withdrawal` and
`statamic-payments.cancellation` (`enabled`, `prefix`, `throttle`, `notify`,
`policy_url`, and `days` for the withdrawal). Every word the consumer reads is in
`lang/*/withdrawal.php` and `lang/*/cancellation.php`.

## Extending the catalogue

Another addon can contribute priced things:

```php
use Goldnead\StatamicPayments\Support\Catalogue;

Catalogue::extend(function (string $handle): ?array {
    return $handle === 'offer:fruehling'
        ? ['name' => 'Frühlingsangebot', 'amount_cent' => 1200]
        : null;
});
```

**The configured catalogue wins.** A resolver may add handles, never reprice one the site has
already decided about — config is the site owner's word, an addon is a helper. And the amount still
never comes from a request: a resolver runs on the server, which is the whole reason this is a seam
rather than a parameter.

`goldnead/statamic-offers` is built on it.

## Free products, and discounts

A product may cost **zero**. It is looked up in the catalogue like any other, the provider is never
called, and the payment is marked paid and fulfilled on the spot — same `PaymentPaid` event, same
one-time claim, so a listener that grants access cannot tell the difference and a free product is
not an account with nothing in it.

A **missing or mistyped** price is still refused. That is the distinction worth keeping: `0` is
somebody saying "this one is free"; `null`, a negative number, or `'19,00'` is a mistake, and a
mistake must never become a giveaway.

For a total lower than its lines, hand `start()` a `Discount`:

```php
use Goldnead\StatamicPayments\Support\Discount;

app(Checkout::class)->start(
    ['kurs', 'begleit-cd'],
    ['email' => $email],
    $returnUrl,
    new Discount(code: 'FRUEHLING', amountCent: 2500),
);
```

This addon knows nothing about coupons and should not: what a code is worth, who may use it and how
often are questions about **pricing**, and pricing lives in `statamic-offers`. What lives here is the
consequence — the payment records `discount_code` and `discount_cent`, so an old receipt keeps
saying what came off even after the coupon is edited or expires.

A `Discount` is built by server-side code that looked something up, never from input. The checkout
clamps it anyway: it cannot exceed the total and cannot be negative, because a bug upstream should
cost a wrong price, not a payment the provider rejects.

## Refunds

This addon does not *make* refunds. That happens in the provider's dashboard, where somebody with
the authority to move money does it — a button for it behind a Control Panel permission would be a
way to refund a customer by misclicking. What it does is take note, so everything downstream can
react:

```php
app(Refunds::class)->record($payment, 3000, 're_provider_id');
```

An **amount and a time**, never a status. An order half repaid is still a paid order — the money
moved and the thing was delivered — and a status forced to choose between "paid" and "refunded"
would be wrong about the other half.

`PaymentRefunded` carries both what came back this time and whether everything has now been repaid,
because those answer different questions. Passing the provider's own refund id makes it idempotent:
a re-announced refund is not booked twice.

**On a full refund the access goes with the money.** With the entitlements bridge on, every product
line of the order is revoked with a reason. This is the one place in the bridge that revokes — a
cancelled subscription keeps its paid period, because it *was* paid for; a refund is the opposite
fact. A **partial** refund leaves access alone: half the money back is not half a course, and there
is no honest way to withdraw half an access.

## Deleting checkouts that were never paid

```php
// config/statamic-payments.php
'prune_unpaid_after_days' => 30,
```

```bash
php artisan payments:prune-unpaid --dry-run
```

The reason is not tidiness. A paid order carries a retention *obligation*; an abandoned checkout
carries the opposite — the row holds the name and email of somebody with whom no contract was ever
concluded. Deleted rather than anonymised: an anonymised record with no purpose is still a record.

Never touched: anything paid, fulfilled, refunded, or that reached a final status — a failed attempt
may still be a question later — and anything inside a running reminder sequence, because an
automation whose trigger vanishes underneath it fails halfway through.

## What an invoice will need

Two facts are recorded at checkout because they cannot be recovered afterwards.

**The buyer's country**, frozen on the payment — not a reference to a customer record that changes
later. Pass it in the buyer array; it is normalised to ISO 3166-1 alpha-2 and anything else is
dropped rather than stored, because a wrong VAT rate looks like an answer:

```php
app(Checkout::class)->start(['kurs'], [
    'email' => 'wer@example.com',
    'country' => 'AT',
]);
```

If the checkout has no country, the provider fills the gap at fulfilment wherever it recorded one —
`country_source` then names the provider instead of `checkout`. That distinction matters: the EU
asks for two non-contradictory pieces of evidence for a consumer's location, and "the card issuer
said so" is worth more than "somebody typed it".

**The discount, per line.** A payment records one discount amount; an invoice has to place it across
lines that may sit at different VAT rates. Sheet music at 7%, a course at 19%, one voucher across
both — from the total alone that split is unrecoverable. `payment_items.discount_cent` carries it,
distributed proportionally to line value, with leftover cents going to the largest lines first so
the parts always add up to the whole.

Existing rows keep `null` for the country and `0` for the line discounts. That is the honest state,
and everything downstream has to tolerate it rather than guess.

## Where the sale came from

A payment can carry the campaign that produced it: `utm_source`, `utm_medium`, `utm_campaign`,
`utm_term`, `utm_content`, `referrer` and `landing_page`. The names are LeadHub's, letter for
letter, so the two sides never have to translate.

**Hand them in at the start of the checkout, not at the end.** The addon reads no request, ever —
the amount does not come from one and neither does this. Where you read them out of is your
decision (a session, a cookie, a signed link); what the addon refuses to do is invent them.

```php
app(Checkout::class)->start(['noten-paket'], $buyer, $returnUrl, null, [
    'utm_source' => session('utm.source'),
    'utm_campaign' => session('utm.campaign'),
    'landing_page' => session('utm.landing_page'),
]);
```

The reason for the timing is that there is no later. A visitor arrives from a newsletter, browses
for three days and buys; by the time the money lands, the campaign lives nowhere but in that
session. Read from the success redirect it is already gone, and "which newsletter sold anything"
stays unanswerable forever.

A wrong **type** throws — that is your code being wrong. An over-long **value** is cut to the
column width — that is a link builder appending noise, and no purchase may fail over it. A blank
value is dropped rather than stored, because "came from nowhere" and "we did not look" are the same
statement and both are `null`.

A follow-up offer inherits the original's attribution, so the upsell counts towards the campaign
that earned it.

## Sending purchases to LeadHub

Off by default. `STATAMIC_PAYMENTS_LEADHUB=true` (or `statamic-payments.leadhub.enabled`) and
`goldnead/statamic-leadhub` installed, and a paid order then leaves two facts in the CRM: a line on
the buyer's timeline saying what they bought, and an entry in their revenue ledger saying what it
was worth. A refund corrects both. The contact is created if it does not exist — a purchase may do
that, unlike a tracking pixel.

Both halves are idempotent: the timeline entry is keyed by a dedupe key, the ledger line by
`payments:payment:<id>`. A redelivered webhook changes nothing.

`php artisan payments:leadhub-backfill` sends paid orders that never arrived — because the bridge
was switched on afterwards, or the CRM was briefly unavailable. Safe to repeat.

**One limit, stated plainly:** a payment has no brand. On a multi-brand install
(`BRAND_CONTEXT_MULTI_BRAND=true`) the CRM resolves the contact against whichever brand is active,
and a payment webhook has none — so the bridge is built for single-brand sites today. The ledger
entry itself takes its brand from the contact rather than from the ambient context, so nothing is
mis-filed; what a multi-brand site would see is a contact not found and a warning in the log.

**Anything else your invoice needs goes in the same insert.** `Checkout::start()`,
`FollowUp::accept()` and `Subscriptions::start()` all take a `$details` array; besides the
attribution keys above it accepts `meta`, `country` and `country_source`, and writes them in the
same transaction as the payment:

```php
app(FollowUp::class)->accept($original, 'begleit-cd', $context, [
    'meta' => ['address' => $anschrift],
    'country' => 'DE',
]);
```

Adding it *after* the call would be a race against the webhook, and losing that race produces an
invoice without the buyer's address: a missing mandatory detail above 250 EUR, on a document that
gets cancelled and reissued rather than corrected. Fields the package owns (the amount, the product,
the status, the provider's ids) are refused with an exception rather than quietly dropped.

Subscription cycles have no caller to be given anything: the provider charges on its own and the row
is written in the webhook. They inherit instead, taking the first payment's `meta` minus the keys the
package runs itself, plus a `meta['cycle_of']` pointer, because the `subscription_id` column is still
empty when `PaymentPaid` fires. See `docs/follow-up-offers.md`.

## A subscription and the access it pays for

With `statamic-entitlements` installed and `entitlements.enabled` on, a subscription keeps its grant
in step by itself: a renewal pushes the window to the provider's own `next_payment_at`, and
cancelling or ending closes an open-ended grant at the end of the paid period.

Three rules, and each of them is deliberately *not* the obvious thing:

- **A renewal is not a second grant.** `grant()` refuses to widen an existing window on purpose — a
  retry is not a renewal — so calling it once a month would write a grant a month, and a year of
  membership would be twelve rows. Requires `statamic-entitlements` 1.1, which grew a `renew()` verb
  for this; against an older sibling the bridge stays quiet rather than writing the wrong thing.
- **Cancelling is not revoking.** Somebody who cancels has paid for the period they are in and keeps
  it to the end. Revoking would take away time they bought, and in the sibling a revocation carries a
  reason precisely because it means "taken away deliberately".
- **A renewal without a date from the provider changes nothing**, and says so in the log. The
  provider knows when it will charge again; a guess here is a grant that ends too early or too late,
  and either way the customer finds out first.

## Abandoned checkouts

Somebody started a checkout and did not finish it. Off by default:

```php
// config/statamic-payments.php
'abandoned' => [
    'enabled' => true,
    'after_minutes' => 60,
],
```

Then run the sweep on a schedule:

```php
// routes/console.php
Schedule::command('payments:sweep-abandoned')->hourly();
```

Each unpaid checkout past the waiting period dispatches `CheckoutAbandoned` **once** — claimed with a
conditional update on its own column, so overlapping sweeps cannot both announce the same one. A
payment that arrives afterwards clears the claim, and the sequence should end on `PaymentPaid`, which
is the honest signal that they bought it.

`failed`, `expired` and `canceled` are not abandoned: those have `PaymentFailed` already, and
announcing both would mean two mails about one thing.

With `statamic-automations` installed, the trigger **Checkout Abandoned** appears under Payments and
needs no code at all.

> **Before you build a mail step on this.** The address on an unfinished checkout was given to
> complete a purchase, not to receive advertising. Whether a reminder may go out is a question of
> consent, not of configuration — and the suppression list belongs in front of the send either way.
> That is why this ships switched off.

### The reminder mail

If the consent question is answered, the addon can send the reminder itself:

```php
'abandoned' => [
    'enabled' => true,
    'after_minutes' => 60,
    'mail' => [
        'enabled' => true,
        'template' => 'warenkorb-erinnerung',   // an email-templates slug, or null
        'subject' => null,                       // null: the built-in subject
        'resume_url' => null,                    // null: a signed link that restarts the checkout
        'resume_days' => 14,
    ],
],
```

One mail per announced checkout, to the address on it. With `statamic-suppression` installed the
address is checked first; a suppressed one gets no mail and a note in the communication log instead.
Without that addon there is no list to ask — put your own in front, or leave this off.

With `statamic-email-templates` installed and `template` resolving, that template is sent exactly as
its preview shows, with the variables `{{ buyer.email }}`, `{{ buyer.name }}`, `{{ order.lines }}`
(an HTML list), `{{ order.total }}`, `{{ order.currency }}` and `{{ resume_url }}`. Without it, a
plain built-in mail goes out — German and English, no images, publishable under
`views/vendor/statamic-payments/abandoned/mail`.

`resume_url` is where the button points. Left `null`, it is a signed link
(`/!/statamic-payments/weiter/{id}`, valid `resume_days`) to an **order page** in the portal layout:
the lines, the total, the withdrawal note, the § 356 (5) consent box with the wording from
`meta.withdrawal` (or `messages.order_consent`), and one button reading „Zahlungspflichtig
bestellen". Opening the link creates nothing — a mail client prefetching it must not order. The
button is a signed POST that runs `Checkout::resume()`: the same lines, buyer, origin and discount as
a **new** payment whose `meta.resumed_from` points back (the provider's original checkout URL expires
within minutes). The consent is **fresh** — `now()` and the wording shown, only if the box was
ticked; nothing is copied from the abandoned row. A second press within an hour reuses the open
checkout instead of creating a third payment. A paid or emptied payment answers with a one-sentence
page instead. Your own URL may carry `{payment}`.

**Recovered revenue.** When a reminded payment is paid after all — itself, or through the restarted
checkout — `payments.recovered_at` is set on the reminded row. `abandoned_notified_at` is cleared as
before; `recovered_at` is what a report sums.

## Customer self-service

A buyer with no account can see their orders, download the invoice, cancel a subscription and put a
different card on file. There is no password: the way in is a signed, expiring link mailed to the
address on the order.

```
GET  /!/statamic-payments/konto/anmelden            ask for a link
POST /!/statamic-payments/konto/anmelden            …and send it
GET  /!/statamic-payments/konto/kuendigen           the § 312k cancellation button's destination
GET  /!/statamic-payments/konto/link/{payLink}      follow one (signed, expiring)
GET  /!/statamic-payments/konto/                    orders and running contracts
GET  /!/statamic-payments/konto/bestellung/{payOrder}
GET  /!/statamic-payments/konto/bestellung/{payOrder}/rechnung
GET  /!/statamic-payments/konto/abo/{paySubscription}/kuendigen    the confirmation page
POST /!/statamic-payments/konto/abo/{paySubscription}/kuendigen    „Jetzt kündigen"
POST /!/statamic-payments/konto/abo/{paySubscription}/zahlungsmittel
```

On by default, because § 312k BGB requires the cancellation button on the site where the contract
was concluded and an addon that ships the requirement switched off ships it to nobody. Everything
about it is under `statamic-payments.portal`.

**Link to it from your site.** The two URLs worth putting in a footer are
`route('statamic-payments.portal.request')` and, for the statutory button,
`route('statamic-payments.portal.cancel.entry')`.

### The link

Signed, encrypted, and good for thirty minutes. There is no token table: the payload rides inside
the URL, which means there is nothing to revoke against and the lifetime *is* the revocation story
— shorten it rather than reaching for a table.

Requesting one is public and unauthenticated by definition, so it is throttled twice (by address and
by origin), the response says the same sentence whatever happened, and it is held open to a floor so
the outcome cannot be read off a stopwatch either. An address that has bought nothing gets no link
and no hint that it got none. Following a link regenerates the session id, so a session somebody
else fixed does not become a way into a stranger's order history.

### Cancelling, and § 312k BGB

The statute prescribes a shape and the addon ships that shape: a button reading „Verträge hier
kündigen", a confirmation page naming the contract with „Jetzt kündigen" on it, and an immediate
confirmation in Textform carrying the date and the time. The confirmation is a **mail**, not a green
box on the page — a screen is gone on reload and proves nothing afterwards.

**Every one of those words is a translation key.** Not one of them is compiled into a PHP file:

```bash
php artisan vendor:publish --tag=statamic-payments-translations
```

The wording of a statutory button belongs in front of a lawyer, the statute has already been amended
once, and a site that needs a different phrase must not have to wait for a release of this addon.
What the code owns is the sequence; what `lang/*/portal.php` owns is the text. **None of this is
legal advice.**

The cancellation itself goes through `Subscriptions::cancel()`, which asks the provider first and
writes the provider's answer. A provider that will not answer — and a provider that accepts the call
while the agreement keeps running — both leave the row untouched, and the buyer is told plainly that
nothing happened and to try again. A screen that said "cancelled" on a local flag is how somebody
keeps being charged for a thing their account says they ended.

### The invoice

`statamic-payments` does not write invoices and must not have to be installed with something that
does, so the download comes through a seam. With no invoicing addon the order page shows the order
without a download button, which is also what an order with no invoice yet looks like.

`goldnead/statamic-invoices` is picked up automatically where it is installed — by shape rather than
by type, so that the PDF and delivery work happening in that package lands here without a change on
this side. A host with its own accounting system registers an answer directly:

```php
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\InvoiceDocument;
use Goldnead\StatamicPayments\Support\Invoices;

Invoices::extend(fn (Payment $payment) => new InvoiceDocument(
    number: 'RE2026-08-001',
    issuedAt: $payment->paid_at,
    contents: fn () => $pdfBytes,          // called only on the download route
    filename: 'RE2026-08-001.pdf',
    contentType: 'application/pdf',
));
```

Returning `null` is a normal answer. The document is served by this addon rather than linked into
another one, because the only thing that says the buyer may have it is the portal session this route
sits behind.

### Changing the payment method

Offered where the bound gateway implements `Contracts\MandateGateway`, and hidden where it does not
— the screen asks the gateway what it can do and never asks it by name, so a second provider is a
class and a binding rather than a change to the portal.

**On Mollie this charges the buyer.** There is no zero-amount authorisation and no hosted "update
your card" screen: a mandate comes from a payment made with `sequenceType: first` and from nothing
else. The amount is `portal.mandate_verification_cent` (one cent by default) and the buyer is shown
it above the button. No local row is written for that charge and it deliberately carries no webhook
URL — a paid payment with no local row reaches the fulfilment path as a phantom purchase and is
logged as an alarm about something that went exactly to plan.

### Multi-brand

With `goldnead/statamic-brand-context` in multi-brand mode, a link for one brand shows only that
brand's orders — and its subscriptions, and its invoices. The brand is sealed into the link next to
the address, so it travels with the link and never with the session.

`payments.brand_id` and `subscriptions.brand_id` are what make that possible; they are stamped from
the brand a row was created in, inherited from the parent row where a webhook creates one, and `0`
on the single-brand installs that are the great majority. In multi-brand mode a row on `0` belongs
to nobody and is shown to nobody. Fail-closed, on purpose.

#### Rows that predate the column

The migration that adds `brand_id` **derives** the brand of existing rows rather than picking one.
Three routes, strongest first: a payment with an invoice takes the invoice's brand, an agreement
takes the brand of its first payment, and a cycle or follow-up charge takes the brand of the row it
belongs to. Applied until nothing more resolves, because each route feeds the next.

What none of them answers stays on `0` and is written to the log. `0` already means "belongs to no
brand" and is shown to nobody, and a reported gap is worth more than a quiet wrong answer. The
default brand is never written onto a row that could not be resolved.

```
php artisan payments:brand-backfill --dry-run
php artisan payments:brand-backfill
```

The same derivation as a repair pass, for installs that already migrated with the first version of
that backfill — which stamped the lowest brand id onto everything. It writes a row **only where a
derived source contradicts it**, leaves rows nothing can be derived for exactly as they are, and
counts both. Where `statamic-invoices` is installed, `php artisan invoices:brand-check` is the
measurement afterwards: it compares every invoice against the brand of its payment.

Nothing happens at all without `goldnead/statamic-brand-context`, or with multi-brand off. Then
every row is `0`, and `0` is right.

## Configuration

| Key | Default | What happens when it is wrong |
|---|---|---|
| `products` | **none** | Nothing can be bought. An addon that shipped prices would be wrong about every site. |
| `currency` | `EUR` | Must match what your Mollie account accepts. |
| `return_url` | `/danke` | Where the buyer lands after paying. **Not** where fulfilment happens. |
| `rate_limit` | `60` | Per minute, per IP, on the webhook. |
| `entitlements.enabled` | `false` | On, plus a `grants` key on a product, grants that entitlement to the buyer. |
| `methods` | `null` | A list of Mollie method ids restricts the hosted checkout; `null` lets Mollie decide. See below. |
| `abandoned.mail.enabled` | `false` | On, the addon mails the reminder itself. Consent first. |

## Payment methods

`methods` names which Mollie methods the hosted checkout offers — `['creditcard', 'paypal', 'ideal']`,
or `STATAMIC_PAYMENTS_METHODS=creditcard,paypal,ideal`. Nothing configured sends no `method` key and
Mollie shows what the account has switched on.

What matters is subscriptions and payment plans. Only some methods let the provider charge again
**without the buyer**; with the others the customer triggers every instalment by hand, which makes
them no method at all for a recurring agreement. The checkout therefore asks Mollie to remember the
buyer (`customerId`, `sequenceType: first`) only when at least one listed method can leave a mandate.

| Method (Mollie id) | Charged again automatically? | Note |
|---|---|---|
| Card (`creditcard`) | **yes** | card mandate |
| SEPA direct debit (`directdebit`) | **yes** | the mandate comes from a first payment via iDEAL, Bancontact, SOFORT, EPS, KBC/CBC, Belfius, Przelewy24 or Pay by Bank |
| PayPal (`paypal`) | **yes** | PayPal billing agreement |
| Apple Pay (`applepay`), Google Pay (`googlepay`) | **yes** | tokenised card payment; the follow-up runs on the card mandate |
| iDEAL, Bancontact, SOFORT, EPS, KBC, Belfius, Przelewy24, Pay by Bank | first payment only | leaves a SEPA mandate, is not charged itself again |
| Klarna, bank transfer, invoice/`billie`, `in3`, TWINT, paysafecard, gift cards, vouchers | **no** | the customer pays each instalment |

Sources: Mollie's recurring-payments guide, and the ablefy note in the suite register (K·18): automatic
charging works for card, SEPA, Google and Apple Pay; everything else the customer triggers per
instalment. `Support\PaymentMethods::RECURRING` and `::MANDATE_FIRST` are the two lists in code.

## Security

**The webhook has no signature, and does not need one.** Mollie posts a payment id; this package
reads nothing else from the request. The status is fetched from Mollie by that id, so the worst a
forged call can do is make the server ask about a payment that is not paid.

That is a stronger position than a shared secret, because it does not depend on the secret staying
secret. It is also the only design that survives someone replaying a genuine delivery.

Three consequences worth knowing:

- **Fulfilment runs once.** The claim is staked with a conditional `UPDATE`, before any listener
  runs, so two simultaneous deliveries cannot both win it. A read-then-write guard loses that race,
  and a redelivery arriving twice within milliseconds is exactly that race. The exception is a
  listener throwing, above.
- **An id this site never issued creates nothing** — even if it really is paid at Mollie. An id we
  did not issue is not evidence of an order here.
- **The endpoint answers identically for known and unknown ids**, and asks Mollie in both cases.
  Asking only about known ids would answer, in the response time, the question the flat `200`
  refuses: which payment ids this site has seen.
- **A payment nobody can match is logged, loudly.** The one way a real site loses money is a
  checkout dying between Mollie creating the payment and the id reaching the database. The buyer
  pays, the webhook matches nothing. This package sends its own row id along as metadata and
  recovers the payment from it; if even that fails, `Log::warning` says so instead of a silent
  `200`.

**The return URL proves nothing.** A buyer who closes the tab still paid; a buyer who reaches that
page has not necessarily paid. Only the webhook decides.

## What it stores

Provider and its id, product handle, amount and currency, status, buyer name and address, and the
timestamps for paid, fulfilled and failure-announced. A row is `initiated` until Mollie has
acknowledged it — deliberately not `open`, so a checkout that died mid-flight is not counted as an
order in flight. An address the buyer typed is never overwritten by the one on
their Mollie account — those are often different people.

`payment_items` carries one line per thing bought, with `offer` naming the offer it was sold through
where the caller said (`PaymentDetails` key `offer_handles`, product handle → offer handle) or the
catalogue entry carried it; otherwise `null`. `payment_communications` is the communication log
above: payment, brand, channel, kind, recipient, subject, status, reference, `meta`, `happened_at`.
`payments.recovered_at` marks a reminded checkout that was paid after all.

Declarations under § 356a and § 312k that were begun and never confirmed are deleted by
`payments:prune-legal-drafts` after seven days (`--days`, `--dry-run`); confirmed ones never are.
Like the sweep, it is not scheduled for you:

```php
// routes/console.php
Schedule::command('payments:prune-legal-drafts')->daily();
```

## Multi-site

Payments are not site-scoped. A payment is a transaction, not content.

## Support

Only the latest version is supported. <https://github.com/goldnead/statamic-payments/issues>

## Changelog · License

[CHANGELOG.md](CHANGELOG.md) · [LICENSE.md](LICENSE.md)

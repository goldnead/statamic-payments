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

## Configuration

| Key | Default | What happens when it is wrong |
|---|---|---|
| `products` | **none** | Nothing can be bought. An addon that shipped prices would be wrong about every site. |
| `currency` | `EUR` | Must match what your Mollie account accepts. |
| `return_url` | `/danke` | Where the buyer lands after paying. **Not** where fulfilment happens. |
| `rate_limit` | `60` | Per minute, per IP, on the webhook. |
| `entitlements.enabled` | `false` | On, plus a `grants` key on a product, grants that entitlement to the buyer. |

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

## Multi-site

Payments are not site-scoped. A payment is a transaction, not content.

## Support

Only the latest version is supported. <https://github.com/goldnead/statamic-payments/issues>

## Changelog · License

[CHANGELOG.md](CHANGELOG.md) · [LICENSE.md](LICENSE.md)

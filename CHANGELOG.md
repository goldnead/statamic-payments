# Changelog

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

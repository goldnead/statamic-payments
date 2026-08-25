# Changelog

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

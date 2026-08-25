# Changelog

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

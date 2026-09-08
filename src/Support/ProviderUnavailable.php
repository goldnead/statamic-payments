<?php

namespace Goldnead\StatamicPayments\Support;

use RuntimeException;

/**
 * The provider could not be asked, as opposed to having answered.
 *
 * The difference decides whether a webhook is retried, and getting it wrong is
 * the most expensive failure this package has.
 *
 * `Fulfilment::fetch()` swallows every error a gateway throws and carries on
 * with a null, which is right for the ordinary case: an id this account never
 * issued is a stray or forged call, and there is nothing to retry. But a
 * timeout, a 502 from a load balancer or a connection refused looks identical
 * from there — and on the Stripe path that identity is fatal. Stripe's event id
 * is claimed before the work runs, so a delivery answered `200` during an
 * outage is a delivery that never comes back. Not from a retry, not from the
 * Resend button, which sends the same `evt_` id into the same claim. A buyer
 * paid, one line landed in the log, and the order was never fulfilled.
 *
 * So a gateway throws this, and only this, when the answer is "ask again
 * later". `Fulfilment` lets it through instead of swallowing it, the Stripe
 * endpoint releases its claim and answers 503, and Stripe redelivers.
 *
 * **A 404 is not this.** A payment the provider does not know is an answer, and
 * a truthful one.
 */
class ProviderUnavailable extends RuntimeException {}

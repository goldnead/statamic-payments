<?php

namespace Goldnead\StatamicPayments\Gateways;

use Goldnead\StatamicPayments\Contracts\SubscriptionGateway;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Support\CheckoutSession;
use Goldnead\StatamicPayments\Support\Money;
use Goldnead\StatamicPayments\Support\ProviderUnavailable;
use Goldnead\StatamicPayments\Support\RemotePayment;
use Goldnead\StatamicPayments\Support\RemoteSubscription;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

/**
 * Stripe, behind the same seam as Mollie.
 *
 * `SubscriptionGateway` extends `FollowUpGateway` extends `PaymentGateway`, so
 * declaring the one satisfies all three — no fourth contract was added for this
 * provider, which was the point of cutting them the way they are cut.
 *
 * **No SDK.** `stripe/stripe-php` would be a second HTTP client, a second
 * retry policy and a dependency on every site that only ever wanted Mollie, in
 * exchange for form-encoding a handful of requests. Laravel's client is already
 * here, its `asForm()` encoding is exactly the bracket syntax Stripe's API
 * reads, and `Http::fake()` plus `stripe/stripe-mock` give a better test than a
 * mocked SDK would: the wire format is checked, not a method call.
 *
 * **Amounts never touch a hard-coded 100.** Stripe bills in the smallest unit
 * of the currency and, like this package since 1.11.0, knows that not every
 * currency has two decimal places. {@see Money} owns that table; this class
 * only converts through it, in one place, without floats.
 *
 * **Tax is not Stripe's here.** No Stripe Tax, no `automatic_tax`. The rates
 * live in `statamic-invoices` and stay there — two systems with an opinion
 * about the same VAT rate is two invoices that disagree.
 *
 * **Which payment methods appear is the Stripe account's business**, exactly as
 * it is Mollie's. No method picker, no wallet buttons, nothing set here.
 */
class StripeGateway implements SubscriptionGateway
{
    /** Pinned, so Stripe changing its default shape is a decision and not a Tuesday. */
    public const API_VERSION = '2024-06-20';

    public function __construct(
        protected string $key = '',
        protected string $base = 'https://api.stripe.com',
    ) {}

    public function provider(): string
    {
        return 'stripe';
    }

    public function supportsFollowUp(): bool
    {
        return true;
    }

    public function supportsSubscriptions(): bool
    {
        return true;
    }

    // ---------------------------------------------------------------- payments

    /**
     * A hosted Checkout Session — Stripe's equivalent of Mollie's hosted page.
     *
     * The payload arrives in this package's own shape, the one `Checkout` built
     * for Mollie: an amount as currency plus decimal string, a description, a
     * return URL, metadata. Translating it here rather than reshaping the
     * caller is the whole reason the seam exists.
     *
     * `webhookUrl` is accepted and ignored, and that is worth saying out loud:
     * Stripe has no per-payment webhook. The endpoint is registered once in the
     * Stripe dashboard, which is why the signing secret is configuration.
     */
    public function createPayment(array $payload): CheckoutSession
    {
        $currency = $this->currency($payload);

        $form = [
            'mode' => 'payment',
            'success_url' => (string) ($payload['redirectUrl'] ?? ''),
            'cancel_url' => (string) ($payload['redirectUrl'] ?? ''),
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => $this->minorUnits($payload['amount']['value'] ?? '0', $currency),
                    'product_data' => ['name' => $this->description($payload)],
                ],
            ]],
        ];

        if ($metadata = $this->metadata($payload)) {
            $form['metadata'] = $metadata;
        }

        if (is_string($payload['customerId'] ?? null) && $payload['customerId'] !== '') {
            $form['customer'] = $payload['customerId'];
        }

        // The mandate, in Stripe's vocabulary. `sequenceType: first` is what
        // this package says when the buyer agreed to be charged again; Stripe
        // calls it keeping the payment method for later use off-session.
        if (($payload['sequenceType'] ?? null) === 'first') {
            $form['payment_intent_data'] = ['setup_future_usage' => 'off_session'];
        }

        $session = $this->post('/v1/checkout/sessions', $form);

        $url = (string) ($session['url'] ?? '');

        if ($url === '') {
            // Stripe returns no URL for a session it will not host — an amount
            // below the minimum, a currency the account cannot take. Sending
            // the buyer to an empty string would be a blank page instead of an
            // error, which is the shape of failure this package refuses.
            throw new RuntimeException('statamic-payments: Stripe created a checkout session without a URL to send the buyer to.');
        }

        return new CheckoutSession(
            providerId: (string) ($session['id'] ?? ''),
            checkoutUrl: $url,
        );
    }

    /**
     * What Stripe says this payment really is.
     *
     * Three kinds of id reach this method, and each is a different object:
     * `cs_` a Checkout Session (a first payment), `in_` an invoice (a
     * subscription cycle Stripe charged on its own), `pi_` a PaymentIntent (a
     * follow-up charged off-session). Anything else is refused rather than
     * guessed at — a wrong guess here would ask Stripe about somebody else's
     * object.
     */
    public function fetch(string $providerId): RemotePayment
    {
        return match (true) {
            str_starts_with($providerId, 'cs_') => $this->fetchSession($providerId),
            str_starts_with($providerId, 'in_') => $this->fetchInvoice($providerId),
            str_starts_with($providerId, 'pi_') => $this->fetchIntent($providerId),
            default => throw new RuntimeException("statamic-payments: [{$providerId}] is not an id Stripe would know."),
        };
    }

    protected function fetchSession(string $id): RemotePayment
    {
        $session = $this->get("/v1/checkout/sessions/{$id}", [
            'expand' => ['payment_intent', 'payment_intent.payment_method'],
        ]);

        $intent = is_array($session['payment_intent'] ?? null) ? $session['payment_intent'] : [];
        $method = is_array($intent['payment_method'] ?? null) ? $intent['payment_method'] : [];
        $card = is_array($method['card'] ?? null) ? $method['card'] : [];

        return new RemotePayment(
            providerId: $id,
            status: $this->normaliseSession($session),
            metadata: $this->readMetadata($session),
            email: $this->firstString([
                $session['customer_details']['email'] ?? null,
                $session['customer_email'] ?? null,
            ]),
            // Only ever set where Stripe itself says this session opened an
            // agreement. A caller cannot assert it: this is Stripe's answer.
            subscriptionId: $this->firstString([$session['subscription'] ?? null]),
            country: $this->country([
                $session['customer_details']['address']['country'] ?? null,
                $card['country'] ?? null,
            ]),
            cardLast4: $this->last4($card['last4'] ?? null),
            cardLabel: $this->cardLabel($card['brand'] ?? null),
            // Stripe's mandate is the PaymentMethod. It is what a later
            // off-session charge names, and the four digits above are its own.
            mandateId: $this->firstString([$method['id'] ?? null]),
        );
    }

    protected function fetchInvoice(string $id): RemotePayment
    {
        $invoice = $this->get("/v1/invoices/{$id}");

        return new RemotePayment(
            providerId: $id,
            status: $this->normaliseInvoice((string) ($invoice['status'] ?? '')),
            metadata: $this->readMetadata($invoice),
            email: $this->firstString([$invoice['customer_email'] ?? null]),
            subscriptionId: $this->firstString([$invoice['subscription'] ?? null]),
        );
    }

    protected function fetchIntent(string $id): RemotePayment
    {
        $intent = $this->get("/v1/payment_intents/{$id}", ['expand' => ['payment_method']]);

        $method = is_array($intent['payment_method'] ?? null) ? $intent['payment_method'] : [];
        $card = is_array($method['card'] ?? null) ? $method['card'] : [];

        return new RemotePayment(
            providerId: $id,
            status: $this->normaliseIntent((string) ($intent['status'] ?? '')),
            metadata: $this->readMetadata($intent),
            email: $this->firstString([$intent['receipt_email'] ?? null]),
            country: $this->country([$card['country'] ?? null]),
            cardLast4: $this->last4($card['last4'] ?? null),
            cardLabel: $this->cardLabel($card['brand'] ?? null),
            mandateId: $this->firstString([$method['id'] ?? null]),
        );
    }

    /**
     * The Checkout Session that paid for a given PaymentIntent.
     *
     * Used by the webhook when Stripe announces a refund: a refund names a
     * charge and a charge names an intent, but the row here was stamped with
     * the session id. Asked of Stripe rather than reconstructed locally, for
     * the usual reason — the caller is not an authority on which order this is.
     */
    public function sessionIdForIntent(string $intentId): ?string
    {
        $list = $this->get('/v1/checkout/sessions', ['payment_intent' => $intentId, 'limit' => 1]);

        $first = $list['data'][0]['id'] ?? null;

        return is_string($first) && $first !== '' ? $first : null;
    }

    /**
     * Every refund on a charge, as amount and provider reference.
     *
     * Asked of Stripe rather than read out of the webhook body. A charge in an
     * event carries at most its first ten refunds, and `amount_refunded` is a
     * running total — booking that would count the first refund a second time
     * when the second one arrives.
     *
     * @return array<int, array{id: string, amount: int}>
     */
    public function refundsFor(string $chargeId): array
    {
        $list = $this->get('/v1/refunds', ['charge' => $chargeId, 'limit' => 100]);

        $refunds = [];

        foreach ((array) ($list['data'] ?? []) as $refund) {
            if (! is_array($refund)) {
                continue;
            }

            $id = $refund['id'] ?? null;
            $amount = $refund['amount'] ?? null;

            // Only a refund that actually succeeded. Stripe keeps `failed` and
            // `canceled` ones in the same list, and booking those would show a
            // buyer money back that never left the account.
            if (is_string($id) && $id !== '' && is_int($amount) && $amount > 0
                && in_array($refund['status'] ?? 'succeeded', ['succeeded', 'pending'], true)) {
                $refunds[] = ['id' => $id, 'amount' => $amount];
            }
        }

        return $refunds;
    }

    // ----------------------------------------------------------- follow-up

    public function rememberBuyer(array $buyer): string
    {
        $customer = $this->post('/v1/customers', array_filter([
            'name' => $this->firstString([$buyer['name'] ?? null]),
            'email' => $this->firstString([$buyer['email'] ?? null]),
        ], fn ($value) => $value !== null));

        return (string) ($customer['id'] ?? '');
    }

    /**
     * Charge a buyer who is not on the page any more.
     *
     * `off_session` plus `confirm` is Stripe's own name for exactly what
     * `chargeAgain()` describes. A `mandateId` in the payload pins the payment
     * method — the offer page named one card, and Stripe must not reach for
     * another. Absent, Stripe picks the customer's default, which is the
     * documented behaviour of leaving the key out.
     */
    public function chargeAgain(string $customerReference, array $payload): RemotePayment
    {
        $currency = $this->currency($payload);

        $form = [
            'amount' => $this->minorUnits($payload['amount']['value'] ?? '0', $currency),
            'currency' => $currency,
            'customer' => $customerReference,
            'description' => $this->description($payload),
            'off_session' => 'true',
            'confirm' => 'true',
        ];

        if ($metadata = $this->metadata($payload)) {
            $form['metadata'] = $metadata;
        }

        // Present means present. A blank or whitespace-only value is a caller
        // mistake, not an instruction, and sending it on would have Stripe
        // reject the charge with a message about an empty payment method.
        if ($mandate = $this->firstString([$payload['mandateId'] ?? null])) {
            $form['payment_method'] = $mandate;
        }

        $intent = $this->post('/v1/payment_intents', $form);

        return $this->fetchIntent((string) ($intent['id'] ?? ''));
    }

    // -------------------------------------------------------- subscriptions

    /**
     * An agreement on a rhythm.
     *
     * The first instalment has already been taken by `Checkout`, so the
     * agreement starts one interval later: `trial_end` is Stripe's way of
     * saying "do not bill until then", and `proration_behavior=none` keeps it
     * from inventing a part-period charge on the way. `cancel_at` ends a plan
     * with a fixed number of instalments — Stripe has no `times`.
     */
    public function createSubscription(string $customerReference, array $payload): RemoteSubscription
    {
        $currency = $this->currency($payload);
        [$unit, $count] = $this->interval((string) ($payload['interval'] ?? ''));

        $start = isset($payload['startDate']) && is_string($payload['startDate']) && $payload['startDate'] !== ''
            ? Carbon::parse($payload['startDate'])
            : Carbon::now();

        $form = [
            'customer' => $customerReference,
            'items' => [[
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => $this->minorUnits($payload['amount']['value'] ?? '0', $currency),
                    'recurring' => ['interval' => $unit, 'interval_count' => $count],
                    // An id, not an inline `product_data`. A Checkout Session
                    // accepts the latter; a subscription item does not, and
                    // Stripe answers 400 for it. Found against stripe-mock, not
                    // by reading — see StripeMockTest.
                    'product' => $this->productFor($this->description($payload)),
                ],
            ]],
            'off_session' => 'true',
            'proration_behavior' => 'none',
            // Stripe may need a further action on the first invoice. Refusing
            // to create the agreement in that case would lose the row that was
            // already written; `Subscriptions` reads the state back afterwards.
            'payment_behavior' => 'allow_incomplete',
        ];

        if ($start->isFuture()) {
            $form['trial_end'] = $start->getTimestamp();
        }

        $times = $payload['times'] ?? null;

        if (is_int($times) && $times > 0) {
            $form['cancel_at'] = $this->after($start, $unit, $count * $times)->getTimestamp();
        }

        if ($mandate = $this->firstString([$payload['mandateId'] ?? null])) {
            $form['default_payment_method'] = $mandate;
        }

        if ($metadata = $this->metadata($payload)) {
            $form['metadata'] = $metadata;
        }

        return $this->asRemoteSubscription($this->post('/v1/subscriptions', $form));
    }

    /**
     * The Stripe product a subscription's price hangs off.
     *
     * ponytail: one product per agreement, created as it is needed. Stripe has
     * no upsert by name, so reusing one would mean a lookup, a cache and a
     * decision about what to do when two agreements race — for a row in a
     * dashboard nobody bills against. Give it a catalogue-keyed lookup if the
     * Stripe product list ever needs to stay tidy.
     */
    protected function productFor(string $name): string
    {
        $product = $this->post('/v1/products', ['name' => $name]);

        $id = $product['id'] ?? null;

        if (! is_string($id) || $id === '') {
            throw new RuntimeException('statamic-payments: Stripe created a product without an id to price against.');
        }

        return $id;
    }

    /**
     * Stop one.
     *
     * Stripe refuses to cancel an agreement that is already cancelled. That is
     * the outcome the caller wanted, so the state is read back and returned
     * instead of thrown — a second cancel click must not look like a failure.
     * Only a refusal that leaves the thing *running* is an error.
     */
    public function cancelSubscription(string $customerReference, string $subscriptionId): RemoteSubscription
    {
        $this->guardOwnership($customerReference, $subscriptionId);

        try {
            return $this->asRemoteSubscription($this->delete("/v1/subscriptions/{$subscriptionId}"));
        } catch (RuntimeException $e) {
            $remote = $this->fetchSubscription($customerReference, $subscriptionId);

            if ($remote->isLive()) {
                throw $e;
            }

            return $remote;
        }
    }

    public function fetchSubscription(string $customerReference, string $subscriptionId): RemoteSubscription
    {
        $subscription = $this->get("/v1/subscriptions/{$subscriptionId}");

        $this->assertBelongsTo($subscription, $customerReference, $subscriptionId);

        return $this->asRemoteSubscription($subscription);
    }

    /**
     * That this agreement is this customer's.
     *
     * The contract passes the customer with every subscription call and says
     * why: an id on its own would let a mixed-up value reach into somebody
     * else's account. Mollie enforces it by routing the call through the
     * customer; Stripe's subscription endpoints do not, so it is enforced here.
     */
    protected function guardOwnership(string $customerReference, string $subscriptionId): void
    {
        $this->assertBelongsTo($this->get("/v1/subscriptions/{$subscriptionId}"), $customerReference, $subscriptionId);
    }

    /** @param  array<string, mixed>  $subscription */
    protected function assertBelongsTo(array $subscription, string $customerReference, string $subscriptionId): void
    {
        $owner = $subscription['customer'] ?? null;
        $owner = is_array($owner) ? ($owner['id'] ?? null) : $owner;

        if (is_string($owner) && $owner !== '' && $owner !== $customerReference) {
            throw new RuntimeException("statamic-payments: the agreement [{$subscriptionId}] does not belong to [{$customerReference}].");
        }
    }

    /** @param  array<string, mixed>  $subscription */
    protected function asRemoteSubscription(array $subscription): RemoteSubscription
    {
        $next = $subscription['current_period_end'] ?? null;

        return new RemoteSubscription(
            providerId: (string) ($subscription['id'] ?? ''),
            status: $this->normaliseSubscription((string) ($subscription['status'] ?? '')),
            nextPaymentAt: is_int($next) || (is_string($next) && $next !== '')
                ? Carbon::createFromTimestampUTC((int) $next)->toIso8601String()
                : null,
            // Stripe does not count instalments on the agreement, and this
            // package only ever reads the count for display. A guess would be
            // worse than nothing.
            timesCharged: null,
            meta: $this->readMetadata($subscription),
        );
    }

    // ------------------------------------------------------------ vocabulary

    /**
     * Stripe's subscription words, mapped to ours.
     *
     * Same rule as Mollie's: anything unknown lands on `suspended`, never on
     * `active`. Treating an agreement nobody recognises as running is how
     * access stays open after a card stopped working.
     *
     * `completed` has no Stripe equivalent and is not invented: a fixed-length
     * plan reaches its `cancel_at` and Stripe calls the result `canceled`.
     * Both are non-live here, so nothing downstream reads it differently.
     */
    protected function normaliseSubscription(string $status): string
    {
        $known = match ($status) {
            'active', 'trialing' => Subscription::STATUS_ACTIVE,
            'incomplete' => Subscription::STATUS_PENDING,
            'canceled', 'cancelled', 'incomplete_expired' => Subscription::STATUS_CANCELLED,
            'past_due', 'unpaid', 'paused' => Subscription::STATUS_SUSPENDED,
            default => null,
        };

        if ($known === null) {
            Log::warning('statamic-payments: unknown Stripe subscription status, treated as suspended.', ['status' => $status]);

            return Subscription::STATUS_SUSPENDED;
        }

        return $known;
    }

    /**
     * A Checkout Session's two words, read together.
     *
     * `payment_status` is the one that says whether money moved; `status` says
     * whether the buyer is still on the page. Read separately, either one alone
     * gets an order wrong: a `complete` session can be unpaid, and an `open`
     * one is simply somebody still typing.
     *
     * @param  array<string, mixed>  $session
     */
    protected function normaliseSession(array $session): string
    {
        $payment = (string) ($session['payment_status'] ?? '');
        $status = (string) ($session['status'] ?? '');

        if ($payment === 'paid' || $payment === 'no_payment_required') {
            return Payment::STATUS_PAID;
        }

        return match ($status) {
            'expired' => Payment::STATUS_EXPIRED,
            'open', 'complete' => Payment::STATUS_OPEN,
            default => $this->unknown('checkout session status', $status.'/'.$payment),
        };
    }

    protected function normaliseInvoice(string $status): string
    {
        return match ($status) {
            'paid' => Payment::STATUS_PAID,
            'open', 'draft' => Payment::STATUS_OPEN,
            'void' => Payment::STATUS_CANCELED,
            'uncollectible' => Payment::STATUS_FAILED,
            default => $this->unknown('invoice status', $status),
        };
    }

    protected function normaliseIntent(string $status): string
    {
        return match ($status) {
            'succeeded' => Payment::STATUS_PAID,
            'canceled', 'cancelled' => Payment::STATUS_CANCELED,
            'requires_payment_method', 'requires_confirmation', 'requires_action', 'requires_capture', 'processing' => Payment::STATUS_OPEN,
            default => $this->unknown('payment intent status', $status),
        };
    }

    /**
     * A word this package has not met.
     *
     * `open` is the safe landing — never `paid`, which would deliver an order
     * nobody paid for, and never `failed`, which would cancel one that is
     * merely pending. Logged, because otherwise Stripe adding a status would
     * change what this package does and leave no trace.
     */
    protected function unknown(string $kind, string $status): string
    {
        Log::warning("statamic-payments: unknown Stripe {$kind}, treated as open.", ['status' => $status]);

        return Payment::STATUS_OPEN;
    }

    // ---------------------------------------------------------------- webhook

    /**
     * That a webhook body really came from Stripe.
     *
     * Mollie is not signed and does not need to be — its webhook carries an id
     * and nothing else, and the status is fetched afterwards. Stripe's carries
     * the whole object, so an unsigned endpoint would be a way to hand this
     * package a forged event. Verified before anything else is read.
     *
     * Stripe's scheme, in full: the header is `t=<unix>,v1=<hex>[,v1=<hex>]`,
     * the signed payload is `t` and the raw body joined by a dot, and the
     * comparison is HMAC-SHA256 under the endpoint's signing secret. Compared
     * with `hash_equals`, because a `===` on a MAC leaks its own answer through
     * how long it takes to say no.
     *
     * The timestamp is checked too. Without it a body captured once could be
     * replayed for ever, and a signature that never expires is a signature that
     * only has to leak once.
     */
    public static function verifySignature(string $payload, ?string $header, string $secret, int $toleranceSeconds = 300): bool
    {
        if ($header === null || $header === '' || $secret === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);

            if (count($pair) !== 2) {
                continue;
            }

            [$name, $value] = $pair;

            if ($name === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            }

            if ($name === 'v1') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === []) {
            return false;
        }

        if (abs(Carbon::now()->getTimestamp() - $timestamp) > $toleranceSeconds) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        foreach ($signatures as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------ money

    /**
     * A decimal string as Stripe wants it: an integer of minor units.
     *
     * Parsed as text, not through a float. `(int) round(19.99 * 100)` is 1999
     * on most inputs and 1998 on the one that matters, and the difference is a
     * cent that disappears from somebody's takings without a trace.
     *
     * How many places a currency has comes from {@see Money} — the same table
     * that formats the amount on the way out — so the yen keeps its zero and
     * the dinar its three.
     */
    protected function minorUnits(string|int|float $value, string $currency): int
    {
        try {
            return Money::toMinorUnits($value, $currency);
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException("statamic-payments: [{$value}] is not an amount Stripe could be given.", 0, $e);
        }
    }

    /** @param  array<string, mixed>  $payload */
    protected function currency(array $payload): string
    {
        $currency = $payload['amount']['currency'] ?? $payload['currency'] ?? '';

        return strtolower(trim((string) $currency));
    }

    /** @param  array<string, mixed>  $payload */
    protected function description(array $payload): string
    {
        $description = trim((string) ($payload['description'] ?? ''));

        // Stripe rejects an empty product name outright, and a checkout that
        // died on a missing label would be an error on the buyer's screen for
        // something nobody can see.
        return $description === '' ? __('statamic-payments::messages.order') : mb_substr($description, 0, 250);
    }

    /**
     * Metadata Stripe will accept.
     *
     * Strings only, and short ones: Stripe takes at most 50 keys, 40 characters
     * per key and 500 per value, and rejects the whole request over one that is
     * too long. A nested array — which this package does put in metadata — would
     * be rejected as well, so it is dropped rather than flattened into
     * something a reader would have to guess at.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    protected function metadata(array $payload): array
    {
        $metadata = $payload['metadata'] ?? null;

        if (! is_array($metadata)) {
            return [];
        }

        $clean = [];

        foreach ($metadata as $key => $value) {
            if (! is_scalar($value) || $value === '') {
                continue;
            }

            $clean[mb_substr((string) $key, 0, 40)] = mb_substr((string) $value, 0, 500);

            if (count($clean) >= 50) {
                break;
            }
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array<string, mixed>
     */
    protected function readMetadata(array $object): array
    {
        $metadata = $object['metadata'] ?? null;

        return is_array($metadata) ? $metadata : [];
    }

    /**
     * Stripe's `month` plus a count, out of this package's `3 months`.
     *
     * @return array{0: string, 1: int}
     */
    protected function interval(string $interval): array
    {
        if (preg_match('/^(\d+)\s*(day|week|month|year)s?$/i', trim($interval), $m) !== 1) {
            throw new RuntimeException("statamic-payments: [{$interval}] is not a rhythm Stripe could be given.");
        }

        return [strtolower($m[2]), max(1, (int) $m[1])];
    }

    protected function after(Carbon $start, string $unit, int $count): Carbon
    {
        // `addMonthsNoOverflow`, for the reason `Subscriptions` gives: adding a
        // month to the 31st of January otherwise lands in March and every
        // later billing date is wrong by the same jump.
        return match ($unit) {
            'day' => $start->copy()->addDays($count),
            'week' => $start->copy()->addWeeks($count),
            'year' => $start->copy()->addYearsNoOverflow($count),
            default => $start->copy()->addMonthsNoOverflow($count),
        };
    }

    // ------------------------------------------------------------------ shape

    /** @param  array<int, mixed>  $candidates */
    protected function firstString(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    /** @param  array<int, mixed>  $candidates */
    protected function country(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && preg_match('/^[A-Za-z]{2}$/', $candidate) === 1) {
                return strtoupper($candidate);
            }
        }

        return null;
    }

    /** Four digits or nothing, so nothing that could pass for a card number is stored. */
    protected function last4(mixed $candidate): ?string
    {
        return is_string($candidate) && preg_match('/^\d{4}$/', $candidate) === 1 ? $candidate : null;
    }

    /** Stripe says `mastercard`; a buyer reads „Mastercard". */
    protected function cardLabel(mixed $candidate): ?string
    {
        if (! is_string($candidate) || $candidate === '') {
            return null;
        }

        return mb_substr(ucwords(str_replace('_', ' ', $candidate)), 0, 32);
    }

    // ------------------------------------------------------------------- wire

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    protected function get(string $path, array $query = []): array
    {
        return $this->send(fn () => $this->client()->get($path, $query), 'GET '.$path);
    }

    /**
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    protected function post(string $path, array $form): array
    {
        return $this->send(fn () => $this->client()->asForm()->post($path, $form), 'POST '.$path);
    }

    /** @return array<string, mixed> */
    protected function delete(string $path): array
    {
        return $this->send(fn () => $this->client()->delete($path), 'DELETE '.$path);
    }

    /**
     * The call, with a connection that never came up told apart from a refusal.
     *
     * A timeout or a refused connection throws before there is any response to
     * read, so `read()` never sees it. Left as a plain exception it would be
     * indistinguishable from "no such payment" one layer up — and that is the
     * distinction the whole retry behaviour hangs on.
     *
     * @param  callable(): Response  $call
     * @return array<string, mixed>
     */
    protected function send(callable $call, string $label): array
    {
        try {
            $response = $call();
        } catch (ConnectionException $e) {
            throw new ProviderUnavailable("statamic-payments: Stripe could not be reached for [{$label}]: {$e->getMessage()}", 0, $e);
        }

        return $this->read($response, $label);
    }

    protected function client(): PendingRequest
    {
        if (trim($this->key) === '') {
            throw new RuntimeException('statamic-payments: no Stripe key is configured. Set STRIPE_KEY.');
        }

        return Http::baseUrl(rtrim($this->base, '/'))
            ->withToken($this->key)
            ->acceptJson()
            ->withHeaders(['Stripe-Version' => self::API_VERSION])
            // A provider that stops answering must fail, not hang: without
            // these the whole request sits on an open socket until PHP's own
            // limit, and the buyer sees a white page for thirty seconds.
            ->connectTimeout(10)
            ->timeout(20);
    }

    /**
     * Stripe's answer, or a refusal that says which call failed.
     *
     * A non-2xx is never read as an empty result. That is the failure this
     * package hunts: a gateway returning "nothing happened" for "the provider
     * refused" would let an unpaid order look like a pending one.
     *
     * @return array<string, mixed>
     */
    protected function read(Response $response, string $call): array
    {
        if ($response->failed()) {
            $message = (string) ($response->json('error.message') ?? $response->body());
            $status = $response->status();

            // Told apart on purpose. A 404 is an answer — this account does not
            // know that id — and there is nothing to gain by asking again. A
            // 5xx or a rate limit is not an answer at all, and a webhook that
            // treated it as one would burn its only delivery during an outage.
            if ($status >= 500 || $status === 429) {
                throw new ProviderUnavailable("statamic-payments: Stripe could not answer [{$call}] ({$status}): {$message}");
            }

            throw new RuntimeException("statamic-payments: Stripe refused [{$call}] with {$status}: {$message}");
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new RuntimeException("statamic-payments: Stripe answered [{$call}] with something that is not an object.");
        }

        return $body;
    }
}

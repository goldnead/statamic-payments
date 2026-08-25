<?php

namespace Goldnead\StatamicPayments\Support;

use Goldnead\StatamicPayments\Contracts\FollowUpGateway;
use Goldnead\StatamicPayments\Contracts\PaymentGateway;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\PaymentItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Starts a payment and hands back somewhere to send the buyer.
 *
 * The amount is looked up, never accepted. Everything the buyer supplies is
 * treated as a label on the order, not as a term of it — including *which*
 * things they are buying: a handle that is not in the catalogue does not make
 * the payment smaller, it refuses the checkout.
 */
class Checkout
{
    public function __construct(
        protected PaymentGateway $gateway,
        protected Catalogue $catalogue,
    ) {}

    /**
     * @param  string|list<string>|array<string, int>  $products  One handle, a
     *                                                            list of handles (the first is what the buyer came for, the rest are
     *                                                            bumps), or a map of handle => quantity.
     * @param  array<string, mixed>  $buyer
     * @param  string|null  $returnUrl  Where the provider sends the buyer back
     *                                  to. Defaults to the configured page. A funnel passes its own, because a
     *                                  buyer who lands outside the flow they were walking has been dropped
     *                                  halfway through a purchase.
     */
    public function start(string|array $products, array $buyer = [], ?string $returnUrl = null, ?Discount $discount = null): ?CheckoutResult
    {
        $lines = $this->lines($products);

        if ($lines === []) {
            return null;
        }

        $primary = $lines[0];
        $gross = array_sum(array_map(fn (array $l) => $l['amount_cent'] * $l['quantity'], $lines));

        // Clamped here rather than trusted, even though a Discount is always
        // built by server-side code. A discount bigger than the basket would
        // otherwise become a negative amount, which providers reject with an
        // error the buyer sees and nobody can explain.
        $off = $discount?->against($gross) ?? 0;
        $total = $gross - $off;

        $currency = $primary['currency'];

        // Created before the provider is called, with the id filled in after.
        // The other order — provider first, row second — loses the payment
        // entirely if the process dies in between, and the buyer has by then
        // been charged.
        $payment = DB::transaction(function () use ($lines, $primary, $total, $off, $discount, $currency, $buyer): Payment {
            $payment = Payment::create([
                'provider' => $this->gateway->provider(),
                'provider_id' => 'pending-'.Str::uuid(),
                // The primary handle stays on the payment as well as in the
                // lines. It is what a report is grouped by and what the listing
                // shows, and reading it should not mean joining a table.
                'product' => $primary['handle'],
                'amount_cent' => $total,
                'discount_code' => $discount?->code,
                'discount_cent' => $off ?: null,
                'currency' => $currency,
                // Not `open`: until the provider has answered, this is not a
                // payment anybody can make, and every report the host builds
                // over `status = open` would otherwise count it as in flight.
                'status' => Payment::STATUS_INITIATED,
                'email' => $buyer['email'] ?? null,
                'name' => $buyer['name'] ?? null,
                // Eingefroren, nicht verwiesen. Der Steuersatz haengt am Land
                // des Kaeufers zum Zeitpunkt der Leistung, und eine Adresse,
                // die sich spaeter aendert, macht eine alte Rechnung falsch.
                // Fehlt es hier, traegt der Anbieter es spaeter nach.
                'country' => self::country($buyer['country'] ?? null),
                'country_source' => isset($buyer['country']) ? 'checkout' : null,
            ]);

            // Der Rabatt, aufgeteilt. Ein Betrag auf der Zahlung reicht nicht:
            // liegen die Zeilen auf verschiedenen Steuersaetzen, ist aus einer
            // Zahl nicht ableitbar, welcher Teil auf welchen Satz faellt, und
            // die Rechnung wird unbestimmt statt erkennbar falsch.
            $anteile = DiscountSplit::across($lines, $off);

            foreach ($lines as $index => $line) {
                PaymentItem::create([
                    'payment_id' => $payment->id,
                    'product' => $line['handle'],
                    // The name as it is today. A product renamed next year must
                    // not change what an old order says was bought.
                    'name' => $line['name'],
                    'amount_cent' => $line['amount_cent'],
                    'quantity' => $line['quantity'],
                    'discount_cent' => $anteile[$index] ?? 0,
                    'kind' => $index === 0 ? PaymentItem::KIND_PRIMARY : PaymentItem::KIND_BUMP,
                ]);
            }

            return $payment;
        });

        // Nothing to charge, so nobody is charged.
        //
        // A provider will not take a payment of zero, and a free offer that
        // failed at the checkout would be the most confusing possible outcome:
        // the buyer was told it costs nothing and then told it did not work.
        // The payment is marked paid on the spot and fulfilment runs, which is
        // the same thing the webhook would have done.
        if ($total <= 0) {
            return $this->free($payment, $returnUrl);
        }

        $payload = [
            'amount' => [
                'currency' => $payment->currency,
                'value' => $payment->amount(),
            ],
            'description' => $this->description($lines),
            'redirectUrl' => $returnUrl ?: $this->url('return_url', ['payment' => $payment->id]),
            'metadata' => [
                'payment_id' => $payment->id,
                'product' => $primary['handle'],
                'email' => $payment->email,
            ],
        ];

        if ($webhook = $this->webhookUrl()) {
            $payload['webhookUrl'] = $webhook;
        }

        // Only if the site asked for it. Asking the provider to remember
        // somebody's payment method is a thing that buyer has to be told about
        // on the checkout page; doing it by default would decide that for every
        // site that installed this addon.
        if ($reference = $this->rememberBuyer($buyer)) {
            $payload['customerId'] = $reference;
            $payload['sequenceType'] = 'first';
            $payment->forceFill(['customer_reference' => $reference])->save();
        }

        $session = $this->gateway->createPayment($payload);

        $payment->forceFill([
            'provider_id' => $session->providerId,
            'status' => Payment::STATUS_OPEN,
        ])->save();

        return new CheckoutResult($payment->fresh() ?? $payment, $session->checkoutUrl);
    }

    /**
     * Where the provider should tell us what happened, or null to not be told.
     *
     * Configurable, and that is not a convenience: a provider checks that the
     * URL is reachable from its own side, so a developer on `localhost` cannot
     * create a payment at all. Refusing every checkout during development is a
     * bad enough experience to be worth a config key.
     *
     * `false` omits it. Then nothing is pushed and the status has to be pulled
     * — which is fine for a demo and wrong for production, so the config comment
     * says so.
     */

    /**
     * A country code, or nothing.
     *
     * Two letters, upper case, ISO 3166-1 alpha-2 — the shape every tax rule
     * and every provider speaks. Anything else is dropped rather than stored:
     * a column that sometimes holds "Deutschland", sometimes "DE" and sometimes
     * "de" is a column nobody can compute a rate from, and the wrong rate is
     * worse than a missing one because it looks like an answer.
     */
    private static function country(mixed $wert): ?string
    {
        if (! is_string($wert)) {
            return null;
        }

        $wert = strtoupper(trim($wert));

        return preg_match('/^[A-Z]{2}$/', $wert) === 1 ? $wert : null;
    }

    protected function webhookUrl(): ?string
    {
        $konfiguriert = config('statamic-payments.webhook_url');

        if ($konfiguriert === false) {
            return null;
        }

        if (is_string($konfiguriert) && $konfiguriert !== '') {
            return $konfiguriert;
        }

        return route('statamic-payments.webhook');
    }

    /**
     * A payment of nothing.
     *
     * Deliberately not a shortcut around fulfilment: the same `PaymentPaid`
     * event fires, the same claim is staked, and a listener cannot tell the
     * difference. A free product that skipped the entitlement would be a
     * customer with an empty account and a receipt.
     */
    protected function free(Payment $payment, ?string $returnUrl): CheckoutResult
    {
        $payment->forceFill([
            // Its own provider marker, so a report can tell a free order from a
            // real one without guessing from the amount.
            'provider' => 'free',
            'provider_id' => 'free-'.$payment->id,
        ])->save();

        $payment = app(Fulfilment::class)->fulfilFree($payment->fresh() ?? $payment);

        return new CheckoutResult(
            $payment,
            $returnUrl ?: $this->url('return_url', ['payment' => $payment->id]),
        );
    }

    /**
     * The provider's handle for this buyer, if the site collects mandates.
     *
     * A failure here does not fail the checkout: the buyer is trying to pay for
     * something, and losing that sale because a *later, optional* offer could
     * not be prepared would be the wrong trade.
     *
     * @param  array<string, mixed>  $buyer
     */
    protected function rememberBuyer(array $buyer): ?string
    {
        if (! config('statamic-payments.follow_up.collect_mandate', false)) {
            return null;
        }

        if (! $this->gateway instanceof FollowUpGateway || ! $this->gateway->supportsFollowUp()) {
            return null;
        }

        try {
            $reference = $this->gateway->rememberBuyer($buyer);
        } catch (Throwable $e) {
            Log::warning('statamic-payments: could not remember the buyer; the checkout continues without a mandate.', [
                'exception' => $e->getMessage(),
            ]);

            return null;
        }

        return $reference === '' ? null : $reference;
    }

    /**
     * Turn what the caller asked for into priced lines, or nothing.
     *
     * **All or none.** A bump handle that is not in the catalogue refuses the
     * whole checkout rather than quietly dropping the line: dropping it would
     * charge the buyer for less than the page offered them, and the first
     * anyone hears of it is a customer who paid for two things and got one.
     *
     * @param  string|list<string>|array<string, int>  $products
     * @return list<array<string, mixed>>
     */
    protected function lines(string|array $products): array
    {
        $wanted = [];

        foreach ((array) $products as $key => $value) {
            if (is_int($key)) {
                $handle = (string) $value;
                $quantity = 1;
            } else {
                $handle = (string) $key;
                $quantity = (int) $value;
            }

            if ($handle === '' || $quantity < 1) {
                return [];
            }

            // A handle listed twice is a quantity, not a second line.
            $wanted[$handle] = ($wanted[$handle] ?? 0) + $quantity;
        }

        $lines = [];

        foreach ($wanted as $handle => $quantity) {
            $product = $this->catalogue->find($handle);

            if (! $product) {
                return [];
            }

            $lines[] = $product + ['quantity' => $quantity];
        }

        // One currency per payment. A provider is handed a single amount and a
        // single currency; two currencies in one order is not a rounding
        // problem, it is a wrong charge.
        $currencies = array_unique(array_column($lines, 'currency'));

        return count($currencies) === 1 ? $lines : [];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    protected function description(array $lines): string
    {
        $names = array_map(fn (array $l) => $l['name'], $lines);

        // What the buyer will read on their bank statement.
        return count($names) === 1
            ? $names[0]
            : $names[0].' + '.(count($names) - 1);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    protected function url(string $key, array $query = []): string
    {
        $configured = (string) config('statamic-payments.'.$key, '/');

        return url($configured).($query === [] ? '' : '?'.http_build_query($query));
    }
}

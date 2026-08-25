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
     */
    public function start(string|array $products, array $buyer = []): ?CheckoutResult
    {
        $lines = $this->lines($products);

        if ($lines === []) {
            return null;
        }

        $primary = $lines[0];
        $total = array_sum(array_map(fn (array $l) => $l['amount_cent'] * $l['quantity'], $lines));
        $currency = $primary['currency'];

        // Created before the provider is called, with the id filled in after.
        // The other order — provider first, row second — loses the payment
        // entirely if the process dies in between, and the buyer has by then
        // been charged.
        $payment = DB::transaction(function () use ($lines, $primary, $total, $currency, $buyer): Payment {
            $payment = Payment::create([
                'provider' => $this->gateway->provider(),
                'provider_id' => 'pending-'.Str::uuid(),
                // The primary handle stays on the payment as well as in the
                // lines. It is what a report is grouped by and what the listing
                // shows, and reading it should not mean joining a table.
                'product' => $primary['handle'],
                'amount_cent' => $total,
                'currency' => $currency,
                // Not `open`: until the provider has answered, this is not a
                // payment anybody can make, and every report the host builds
                // over `status = open` would otherwise count it as in flight.
                'status' => Payment::STATUS_INITIATED,
                'email' => $buyer['email'] ?? null,
                'name' => $buyer['name'] ?? null,
            ]);

            foreach ($lines as $index => $line) {
                PaymentItem::create([
                    'payment_id' => $payment->id,
                    'product' => $line['handle'],
                    // The name as it is today. A product renamed next year must
                    // not change what an old order says was bought.
                    'name' => $line['name'],
                    'amount_cent' => $line['amount_cent'],
                    'quantity' => $line['quantity'],
                    'kind' => $index === 0 ? PaymentItem::KIND_PRIMARY : PaymentItem::KIND_BUMP,
                ]);
            }

            return $payment;
        });

        $payload = [
            'amount' => [
                'currency' => $payment->currency,
                'value' => $payment->amount(),
            ],
            'description' => $this->description($lines),
            'redirectUrl' => $this->url('return_url', ['payment' => $payment->id]),
            'webhookUrl' => route('statamic-payments.webhook'),
            'metadata' => [
                'payment_id' => $payment->id,
                'product' => $primary['handle'],
                'email' => $payment->email,
            ],
        ];

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

<?php

namespace Goldnead\StatamicPayments\Support;

use Goldnead\StatamicPayments\Contracts\PaymentGateway;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Support\Str;

/**
 * Starts a payment and hands back somewhere to send the buyer.
 *
 * The amount is looked up, never accepted. Everything the buyer supplies is
 * treated as a label on the order, not as a term of it.
 */
class Checkout
{
    public function __construct(
        protected PaymentGateway $gateway,
        protected Catalogue $catalogue,
    ) {}

    /**
     * @param  array<string, mixed>  $buyer
     */
    public function start(string $productHandle, array $buyer = []): ?CheckoutResult
    {
        $product = $this->catalogue->find($productHandle);

        if (! $product) {
            return null;
        }

        // Created before the provider is called, with the id filled in after.
        // The other order — provider first, row second — loses the payment
        // entirely if the process dies in between, and the buyer has by then
        // been charged.
        $payment = Payment::create([
            'provider' => $this->gateway->provider(),
            'provider_id' => 'pending-'.Str::uuid(),
            'product' => $product['handle'],
            'amount_cent' => $product['amount_cent'],
            'currency' => $product['currency'],
            // Not `open`: until the provider has answered, this is not a
            // payment anybody can make, and every report the host builds over
            // `status = open` would otherwise count it as one in flight.
            'status' => Payment::STATUS_INITIATED,
            'email' => $buyer['email'] ?? null,
            'name' => $buyer['name'] ?? null,
        ]);

        $session = $this->gateway->createPayment([
            'amount' => [
                'currency' => $payment->currency,
                'value' => $payment->amount(),
            ],
            'description' => $product['name'],
            'redirectUrl' => $this->url('return_url', ['payment' => $payment->id]),
            'webhookUrl' => route('statamic-payments.webhook'),
            'metadata' => [
                'payment_id' => $payment->id,
                'product' => $product['handle'],
                'email' => $payment->email,
            ],
        ]);

        $payment->forceFill([
            'provider_id' => $session->providerId,
            'status' => Payment::STATUS_OPEN,
        ])->save();

        return new CheckoutResult($payment->fresh() ?? $payment, $session->checkoutUrl);
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

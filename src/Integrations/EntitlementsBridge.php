<?php

namespace Goldnead\StatamicPayments\Integrations;

use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The optional path from "paid" to "may have it".
 *
 * Off unless three things are true: the sibling is installed, the site turned
 * the bridge on, and the product says which entitlement it grants. That order
 * matters — a bridge that granted by default would hand out access on the first
 * site that installed both addons for unrelated reasons.
 *
 * Two rules learned elsewhere in this family and repeated here:
 *
 * 1. **`class_exists` on the class actually called**, not `interface_exists` on
 *    a contract the sibling may rename.
 * 2. **Never `method_exists()` on a Facade.** A Facade forwards through
 *    `__callStatic` and declares none of the methods it forwards, so the probe
 *    is always false. Ask the object behind it.
 */
class EntitlementsBridge
{
    protected const FACADE = '\Goldnead\Entitlements\Facades\Entitlements';

    public function available(): bool
    {
        if (! config('statamic-payments.entitlements.enabled', false)) {
            return false;
        }

        $facade = self::FACADE;

        if (! class_exists($facade)) {
            return false;
        }

        try {
            $root = $facade::getFacadeRoot();
        } catch (Throwable) {
            return false;
        }

        return $root !== null && method_exists($root, 'grant');
    }

    /**
     * Grant what this payment bought, if the product says what that is.
     *
     * A failure here is logged, not thrown. The money is taken and the row says
     * so; an entitlements outage must not release the fulfilment claim and send
     * the whole webhook round again.
     */
    public function grantFor(Payment $payment): void
    {
        if (! $this->available()) {
            return;
        }

        $subject = $payment->email;

        if (! is_string($subject) || $subject === '') {
            // Nothing to grant it to. Already logged loudly by the fulfilment.
            return;
        }

        // Every line, not just the primary one. An order bump the buyer ticked
        // and paid for is as bought as the thing they came for; granting only
        // the first would take money for the second and hand over nothing.
        foreach ($payment->items as $item) {
            $this->grantLine($payment, $item->product, $subject);
        }

        // A payment written before line items existed, or by something that
        // does not use the checkout, still has its handle on the payment.
        if ($payment->items->isEmpty()) {
            $this->grantLine($payment, $payment->product, $subject);
        }
    }

    protected function grantLine(Payment $payment, ?string $handle, string $subject): void
    {
        if (! is_string($handle) || $handle === '') {
            return;
        }

        $products = config('statamic-payments.products', []);
        $product = is_array($products) ? ($products[$handle] ?? null) : null;
        $slug = is_array($product) ? ($product['grants'] ?? null) : null;

        if (! is_string($slug) || $slug === '') {
            return;
        }

        try {
            $facade = self::FACADE;
            $facade::grant(
                $subject,
                $slug,
                'statamic-payments',
                (string) $payment->provider_id,
            );
        } catch (Throwable $e) {
            Log::error('statamic-payments: the entitlements bridge failed; the payment stands, the grant does not.', [
                'payment_id' => $payment->getKey(),
                'product' => $handle,
                'grants' => $slug,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}

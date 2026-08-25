<?php

namespace Goldnead\StatamicPayments\Integrations;

use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Subscription;
use Illuminate\Support\Carbon;
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

    /**
     * A cycle was charged. The access that goes with it runs one interval longer.
     *
     * Not a second `grant()`. That call refuses to widen an existing window on
     * purpose — a retry is not a renewal — so calling it once a month writes a
     * grant a month, and after a year the question "does this person have
     * access" has twelve answers. The sibling grew a `renew()` verb for exactly
     * this; if it is an older version without one, this stays quiet rather than
     * writing the wrong thing.
     *
     * The new end comes from the provider's own `next_payment_at` wherever it
     * has one. It knows when it will charge again; computing it here would mean
     * two clocks that drift.
     */
    public function renewFor(Subscription $subscription, Payment $payment): void
    {
        if (! $this->available() || ! $this->canRenew()) {
            return;
        }

        $subject = $subscription->email ?: $payment->email;

        if (! is_string($subject) || $subject === '') {
            return;
        }

        $bis = $subscription->next_payment_at;

        if ($bis === null) {
            // Kein Datum vom Anbieter: lieber nichts verlaengern als raten. Ein
            // geratenes Ende ist ein Zugang, der zu frueh oder zu spaet endet,
            // und beides merkt erst der Kunde.
            Log::warning('statamic-payments: a renewal without a next date; the entitlement was left as it was.', [
                'subscription_id' => $subscription->getKey(),
            ]);

            return;
        }

        $slug = $this->slugFor($subscription->product);

        if ($slug === null) {
            return;
        }

        try {
            $facade = self::FACADE;
            $verlaengert = $facade::renew($this->subjectFor($subject), $slug, $bis);

            // Nichts zu verlaengern heisst: es gab noch keinen Zugang. Das ist
            // der erste Zyklus eines Abos, das vor dieser Bruecke begann, oder
            // eine Installation, die sie gerade erst eingeschaltet hat. Dann
            // ist Vergeben richtig.
            if ($verlaengert === null) {
                $facade::grant(
                    $this->subjectFor($subject),
                    $slug,
                    'statamic-payments',
                    (string) $subscription->provider_id,
                    expiresAt: $bis,
                );
            }
        } catch (Throwable $e) {
            Log::error('statamic-payments: the entitlements bridge could not renew; the charge stands, the access does not follow.', [
                'subscription_id' => $subscription->getKey(),
                'product' => $subscription->product,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The subscription was cancelled, or it ran out.
     *
     * Deliberately **not** a revocation. Somebody who cancels has paid for the
     * period they are in and keeps it to its end; revoking would take away time
     * they bought, and `revoke()` in the sibling carries a reason precisely
     * because it means "taken away deliberately". Ending a subscription means
     * the window simply stops being pushed forward — which needs no call at all.
     *
     * What this does do is close an open-ended grant. A membership granted
     * without an expiry date would otherwise outlive the subscription that paid
     * for it, forever, silently.
     */
    public function closeFor(Subscription $subscription): void
    {
        if (! $this->available() || ! $this->canRenew()) {
            return;
        }

        $subject = $subscription->email;

        if (! is_string($subject) || $subject === '') {
            return;
        }

        $slug = $this->slugFor($subscription->product);

        if ($slug === null) {
            return;
        }

        // Bis zum Ende des bezahlten Zeitraums, und wenn es keinen gibt, bis
        // jetzt. Das Datum des Anbieters ist auch hier die Wahrheit.
        $bis = $subscription->next_payment_at ?? $subscription->ended_at ?? Carbon::now();

        try {
            $facade = self::FACADE;
            $grant = $facade::forSubject($this->subjectFor($subject))
                ->where('product_slug', $slug)
                ->whereNull('expires_at')
                ->orderByDesc('id')
                ->first();

            if ($grant === null) {
                // Ein Zugang mit Ablaufdatum laeuft von selbst aus. Nichts zu tun.
                return;
            }

            $grant->forceFill(['expires_at' => $bis])->save();
        } catch (Throwable $e) {
            Log::error('statamic-payments: the entitlements bridge could not close an open-ended grant.', [
                'subscription_id' => $subscription->getKey(),
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /** Whether the installed sibling knows how to renew. */
    protected function canRenew(): bool
    {
        try {
            $root = (self::FACADE)::getFacadeRoot();
        } catch (Throwable) {
            return false;
        }

        return $root !== null && method_exists($root, 'renew');
    }

    /** What a product grants, or null if it grants nothing. */
    protected function slugFor(?string $handle): ?string
    {
        if (! is_string($handle) || $handle === '') {
            return null;
        }

        $products = config('statamic-payments.products', []);
        $product = is_array($products) ? ($products[$handle] ?? null) : null;
        $slug = is_array($product) ? ($product['grants'] ?? null) : null;

        return is_string($slug) && $slug !== '' ? $slug : null;
    }

    /**
     * Money went back, so the access goes with it.
     *
     * **This is the one place in the bridge that revokes.** Everywhere else the
     * rule is that a window simply stops being extended — cancelling a
     * subscription leaves the paid period intact, because it was paid for. A
     * refund is the opposite fact: it was *not* paid for after all, and letting
     * somebody keep a course they were repaid for is the failure this whole
     * method exists to end.
     *
     * Only on a **full** refund. Half the money back does not mean half a
     * course, and there is no honest way to withdraw half an access — so a
     * partial refund is recorded and left to a person, which is what the
     * Control Panel screen is for.
     *
     * The reason is mandatory in the sibling, on purpose: a revocation nobody
     * can explain later is a revocation somebody undoes.
     */
    public function revokeFor(Payment $payment, bool $isFull): void
    {
        if (! $isFull || ! $this->available()) {
            return;
        }

        $subject = $payment->email;

        if (! is_string($subject) || $subject === '') {
            return;
        }

        $handles = $payment->items->isNotEmpty()
            ? $payment->items->pluck('product')->all()
            : [$payment->product];

        foreach ($handles as $handle) {
            $slug = $this->slugFor($handle);

            if ($slug === null) {
                continue;
            }

            try {
                $facade = self::FACADE;

                $grants = $facade::forSubject($this->subjectFor($subject))
                    ->where('product_slug', $slug)
                    ->get();

                foreach ($grants as $grant) {
                    $facade::revoke($grant, 'Zahlung erstattet');
                }
            } catch (Throwable $e) {
                // Laut, nicht still: hier bleibt jemand mit Zugang zurueck, den
                // er zurueckgezahlt bekommen hat. Das ist der Fall, in dem ein
                // Mensch nachsehen muss.
                Log::error('statamic-payments: a refund was recorded but the access could not be withdrawn.', [
                    'payment_id' => $payment->getKey(),
                    'product' => $handle,
                    'grants' => $slug,
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * The buyer, in the shape the entitlements addon accepts.
     *
     * A bare email string is refused there, deliberately: a grant is a fact
     * about a *subject*, and a subject is a `(type, id)` pair so that it can
     * outlive the record it points at. This bridge used to hand over the string
     * and every paid order logged an error and granted nothing — built, wired,
     * documented and never once working, because the tests mocked the facade
     * and a mock accepts anything.
     *
     * `email` as the type is the honest answer here. A payment knows an address
     * and nothing else: there may be no user account and no contact, and
     * inventing one to hang a grant on would be worse than saying what we have.
     * A host that wants grants against its own users binds its own
     * `SubjectResolver`, which is what that seam is for.
     */
    protected function subjectFor(string $email): mixed
    {
        $klasse = '\\Goldnead\\Entitlements\\Support\\SubjectReference';

        if (! class_exists($klasse)) {
            // An older sibling that still takes a string. Handing it the pair
            // would break it, so the string stands.
            return $email;
        }

        return new $klasse('email', mb_strtolower(trim($email)));
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
                $this->subjectFor($subject),
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

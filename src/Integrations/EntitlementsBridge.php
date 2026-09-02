<?php

namespace Goldnead\StatamicPayments\Integrations;

use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Support\Catalogue;
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

        // Je Slug einzeln, und der Fehlschlag des einen haelt den naechsten
        // nicht auf: ein Abo auf ein Buendel verlaengert drei Zugaenge, und
        // zwei verlaengerte sind besser als keiner.
        foreach ($this->slugsFor($subscription->product) as $slug) {
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
                    'grants' => $slug,
                    'exception' => $e->getMessage(),
                ]);
            }
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

        // Bis zum Ende des bezahlten Zeitraums, und wenn es keinen gibt, bis
        // jetzt. Das Datum des Anbieters ist auch hier die Wahrheit.
        $bis = $subscription->next_payment_at ?? $subscription->ended_at ?? Carbon::now();

        foreach ($this->slugsFor($subscription->product) as $slug) {
            try {
                $facade = self::FACADE;
                $grant = $facade::forSubject($this->subjectFor($subject))
                    ->where('product_slug', $slug)
                    ->whereNull('expires_at')
                    ->orderByDesc('id')
                    ->first();

                if ($grant === null) {
                    // Ein Zugang mit Ablaufdatum laeuft von selbst aus. Nichts
                    // zu tun — aber die uebrigen Slugs noch pruefen, statt hier
                    // die ganze Schleife zu verlassen.
                    continue;
                }

                $grant->forceFill(['expires_at' => $bis])->save();
            } catch (Throwable $e) {
                Log::error('statamic-payments: the entitlements bridge could not close an open-ended grant.', [
                    'subscription_id' => $subscription->getKey(),
                    'grants' => $slug,
                    'exception' => $e->getMessage(),
                ]);
            }
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

    /**
     * What a product grants. Empty when it grants nothing.
     *
     * **One product may grant several things, and it has to.** A bundle is one
     * line at one price that hands over three courses; `statamic-offers` sells
     * exactly that, and before this returned a list, a bundle handed over the
     * first slug and dropped the rest — `is_string()` on an array is false, so
     * a `grants` list granted *nothing at all*. Silently: the payment settled,
     * the invoice was written, and no access arrived.
     *
     * @return list<string>
     */
    protected function slugsFor(?string $handle): array
    {
        if (! is_string($handle) || $handle === '') {
            return [];
        }

        // Through the catalogue, not past it. Reading the config array
        // directly skips every resolver another addon registered — and
        // `statamic-offers` registers one. Anything bought through an offer
        // therefore granted nothing at all: the customer paid, the payment
        // settled, and the access never arrived. Nothing errored, because
        // "this product grants nothing" and "I could not find this product"
        // came back as the same null.
        $product = app(Catalogue::class)->find($handle);

        if ($product === null) {
            // Said out loud, because the catalogue is stricter than the raw
            // config array this used to read: an entry without an integer
            // `amount_cent` does not exist to it at all. For a payment that
            // was never sellable through the checkout — imported, entered by
            // hand, left over from an older catalogue — that turns "grants
            // nothing" into "grants nothing, and nobody notices".
            Log::warning('statamic-payments: no catalogue entry for a paid product, so no access was granted', [
                'product' => $handle,
            ]);

            return [];
        }

        return self::slugList($product['grants'] ?? null);
    }

    /**
     * One slug, a list of them, or nothing.
     *
     * A single string stays allowed and is the common case; wrapping it here
     * rather than at every call site is what keeps `'grants' => 'kurs'` in a
     * config file that was written years ago working unchanged.
     *
     * Duplicates go: granting the same entitlement twice from one payment is
     * two rows that say the same thing, and the second one has no meaning the
     * first did not already carry.
     *
     * @return list<string>
     */
    protected static function slugList(mixed $grants): array
    {
        if (is_string($grants)) {
            $grants = [$grants];
        }

        if (! is_array($grants)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            $grants,
            static fn (mixed $slug): bool => is_string($slug) && $slug !== '',
        )));
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
            // Ein Buendel gibt mehrere Zugaenge her, und eine Erstattung nimmt
            // alle zurueck. Einen davon stehen zu lassen waere die Haelfte
            // einer Rueckabwicklung.
            foreach ($this->slugsFor($handle) as $slug) {
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

    /**
     * `meta.access` = `['starts_at' => 'Y-m-d'|null, 'days' => int|null]`.
     *
     * Der Beginn ist der Tagesanfang des genannten Datums in der Zeitzone der
     * Anwendung; das Ende liegt `days` Tage nach dem Beginn — oder nach
     * `$from`, wenn kein Beginn genannt ist: beim Vergeben ist das jetzt, für
     * eine Anzeige später der Zahlungszeitpunkt. Ein unlesbares Datum wird
     * ignoriert und gemeldet, statt einen Zugang zu verhindern: der Kunde hat
     * bezahlt.
     *
     * Öffentlich, weil die Detailseite dieselbe Rechnung zeigt und zwei
     * Rechnungen zwei Antworten wären.
     *
     * @return array{0: Carbon|null, 1: Carbon|null}
     */
    public static function accessWindow(Payment $payment, ?Carbon $from = null): array
    {
        $access = data_get($payment->meta, 'access');

        if (! is_array($access)) {
            return [null, null];
        }

        $startsAt = null;

        if (is_string($access['starts_at'] ?? null) && trim($access['starts_at']) !== '') {
            try {
                $startsAt = Carbon::parse(trim($access['starts_at']))->startOfDay();
            } catch (Throwable) {
                Log::warning('statamic-payments: meta.access.starts_at on a payment is not a date; access starts at once.', [
                    'payment_id' => $payment->getKey(),
                    'starts_at' => $access['starts_at'],
                ]);
            }
        }

        $days = $access['days'] ?? null;
        $days = is_numeric($days) ? (int) $days : null;

        $expiresAt = $days !== null && $days > 0
            ? ($startsAt?->copy() ?? $from?->copy() ?? Carbon::now())->addDays($days)
            : null;

        return [$startsAt, $expiresAt];
    }

    protected function grantLine(Payment $payment, ?string $handle, string $subject): void
    {
        if (! is_string($handle) || $handle === '') {
            return;
        }

        // Same reason as slugsFor(): the catalogue is where a handle becomes a
        // product, and an offer's handle only resolves there.
        $product = app(Catalogue::class)->find($handle);
        $slugs = self::slugList(is_array($product) ? ($product['grants'] ?? null) : null);

        // Je Slug ein eigener Versuch. Scheitert der zweite von drei, sind die
        // anderen beiden trotzdem vergeben — und die Zeile im Log nennt genau
        // den, der fehlt, statt „das Buendel".
        // Das Zugangsfenster des Angebots, wenn der Funnel eines mitgegeben hat
        // (`meta.access`, aus `Offer::accessWindow()`): ab wann, wie lange.
        // Ohne Angabe bleibt es beim Sofort-und-unbefristet, das immer galt.
        [$startsAt, $expiresAt] = self::accessWindow($payment);

        foreach ($slugs as $slug) {
            try {
                $facade = self::FACADE;
                $facade::grant(
                    $this->subjectFor($subject),
                    $slug,
                    'statamic-payments',
                    (string) $payment->provider_id,
                    startsAt: $startsAt,
                    expiresAt: $expiresAt,
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
}

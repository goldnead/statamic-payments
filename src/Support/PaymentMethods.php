<?php

namespace Goldnead\StatamicPayments\Support;

/**
 * Zahlungsarten bei Mollie, und was jede davon für Folgeabbuchungen kann.
 *
 * Zwei Listen, weil es zwei Fragen sind. `RECURRING` beantwortet „kann der
 * Anbieter davon später ohne den Kunden abbuchen": Karte, SEPA-Lastschrift und
 * PayPal. `MANDATE_FIRST` beantwortet „kann eine erste Zahlung damit ein Mandat
 * hinterlassen": dazu kommen die Methoden, die als erste Zahlung ein
 * SEPA-Mandat erzeugen (iDEAL, Bancontact, SOFORT, EPS, KBC/CBC, Belfius,
 * Przelewy24, Pay by Bank), und Apple Pay / Google Pay als tokenisierte
 * Kartenzahlung.
 *
 * Alles andere — Klarna, Überweisung, Vorkasse, Rechnung, TWINT, Gutscheine —
 * löst der Kunde je Rate selbst aus. Für ein Abo oder eine Ratenzahlung ist
 * das keine Zahlungsart; deshalb bittet {@see Checkout} bei solchen Methoden
 * den Anbieter gar nicht erst, sich den Käufer zu merken.
 *
 * Quellen: Mollie, „Recurring payments" (docs.mollie.com/guides/recurring),
 * und der ablefy-Hinweis im Suite-Register (K·18): automatisch abbuchen lässt
 * sich bei Karte, SEPA, Google und Apple Pay. Stand 02.09.2026; die Liste ist
 * Konfiguration, keine Wahrheit, und wird angepasst, wenn Mollie etwas
 * freischaltet.
 */
final class PaymentMethods
{
    /** Davon bucht der Anbieter später von selbst ab. */
    public const RECURRING = [
        'creditcard',
        'directdebit',
        'paypal',
        'applepay',
        'googlepay',
    ];

    /** Damit kann eine erste Zahlung ein Mandat hinterlassen. */
    public const MANDATE_FIRST = [
        'creditcard',
        'directdebit',
        'paypal',
        'applepay',
        'googlepay',
        'ideal',
        'bancontact',
        'sofort',
        'eps',
        'kbc',
        'belfius',
        'przelewy24',
        'paybybank',
    ];

    /**
     * Die konfigurierten Methoden, bereinigt. Leer heißt: Mollie entscheidet.
     *
     * @return list<string>
     */
    public static function configured(): array
    {
        $methods = config('statamic-payments.methods');

        if (is_string($methods)) {
            $methods = explode(',', $methods);
        }

        if (! is_array($methods)) {
            return [];
        }

        $clean = [];

        foreach ($methods as $method) {
            if (! is_string($method)) {
                continue;
            }

            $method = strtolower(trim($method));

            if ($method !== '' && preg_match('/^[a-z0-9]+$/', $method) === 1) {
                $clean[$method] = true;
            }
        }

        return array_keys($clean);
    }

    /**
     * Ob wenigstens eine der Methoden ein Mandat hinterlassen kann.
     *
     * Leer heißt „Mollie entscheidet", und dann zeigt Mollie bei einer
     * `sequenceType: first` von sich aus nur Methoden, die es können.
     *
     * @param  list<string>  $methods
     */
    public static function canHoldMandate(array $methods): bool
    {
        if ($methods === []) {
            return true;
        }

        foreach ($methods as $method) {
            if (in_array($method, self::MANDATE_FIRST, true)) {
                return true;
            }
        }

        return false;
    }

    /** Ob der Anbieter von dieser Methode später ohne den Kunden abbucht. */
    public static function chargesAutomatically(string $method): bool
    {
        return in_array(strtolower(trim($method)), self::RECURRING, true);
    }
}

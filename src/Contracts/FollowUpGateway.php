<?php

namespace Goldnead\StatamicPayments\Contracts;

use Goldnead\StatamicPayments\Support\RemotePayment;

/**
 * Charging a buyer again without asking for their card details a second time.
 *
 * A separate interface from `PaymentGateway`, not more methods on it, because
 * not every provider can do this and an implementation that cannot should not
 * be forced to declare stubs that throw. The site asks `supportsFollowUp()` and
 * gets a truthful answer instead of a surprise at the till.
 *
 * **What this is not.** It is not "one click" in the American sense. In Germany
 * a follow-up offer still needs its own order button, labelled unambiguously,
 * with the essential details directly above it (§ 312j BGB, the Button-Lösung).
 * What is saved is the card details, not the consent. The package therefore
 * charges only on an explicit confirmation, and the shipped page carries the
 * button; see `docs/follow-up-offers.md`.
 */
interface FollowUpGateway extends PaymentGateway
{
    /** Whether this provider can charge a returning buyer without new details. */
    public function supportsFollowUp(): bool;

    /**
     * Ask the provider to remember this buyer, and say what to call them later.
     *
     * Called during the first checkout, and only when the site has switched
     * `collect_mandate` on — remembering somebody's payment method is a thing
     * they have to be told about, not a default.
     *
     * @param  array<string, mixed>  $buyer
     */
    public function rememberBuyer(array $buyer): string;

    /**
     * Charge a buyer who has already paid once and agreed to be charged again.
     *
     * @param  string  $customerReference  What the first payment left behind —
     *                                     for Mollie the customer id, for another provider whatever identifies
     *                                     the stored agreement.
     * @param  array<string, mixed>  $payload  Amount, currency, description,
     *                                         webhook and metadata, exactly as `createPayment()` takes them.
     *
     *                                         Dazu **optional `mandateId`**: welches Einzugsrecht belastet werden
     *                                         soll. Wer den Schluessel setzt, verlangt genau dieses Mandat; eine
     *                                         Implementierung darf dann nicht auf ein anderes ausweichen, sondern
     *                                         muss ablehnen, wenn es ungueltig oder widerrufen ist. Das ist der
     *                                         Punkt: die Seite, auf der das Nachfassangebot angenommen wurde, hat
     *                                         eine bestimmte Karte angekuendigt.
     *
     *                                         Fehlt der Schluessel, waehlt der Anbieter wie bisher selbst.
     *                                         **Weglassen heisst weglassen** — nicht `null` und nicht `''`
     *                                         mitgeben. Dieser Vertrag ist anbieterunabhaengig, und ein
     *                                         Gateway darf einen gesetzten Schluessel als Angabe lesen.
     *                                         (Mollies eigener Client waere hier gutmuetig: sein
     *                                         `DataTransformer` wirft `null` und `''` vor dem Senden selbst
     *                                         heraus, `vendor/mollie/mollie-api-php/src/Utils/DataTransformer.php`.
     *                                         Ein `'   '` laesst er aber durch, und das geht dann als echte
     *                                         Angabe raus. Der Aufrufer soll sich auf keins von beidem
     *                                         verlassen muessen.)
     */
    public function chargeAgain(string $customerReference, array $payload): RemotePayment;
}

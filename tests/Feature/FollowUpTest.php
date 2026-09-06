<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\PaymentItem;
use Goldnead\StatamicPayments\Support\Catalogue;
use Goldnead\StatamicPayments\Support\Checkout;
use Goldnead\StatamicPayments\Support\FollowUp;
use Goldnead\StatamicPayments\Support\Fulfilment;
use Goldnead\StatamicPayments\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The offer after the payment.
 *
 * Every test here is a refusal except two. That ratio is the point: charging a
 * card a second time is the single easiest way for this package to do real
 * damage, so almost everything about it is a condition that has to hold first.
 */
class FollowUpTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic-payments.products', [
            'noten-paket' => ['name' => 'Notenpaket', 'amount_cent' => 1900],
            'begleit-cd' => ['name' => 'Begleit-CD', 'amount_cent' => 1200],
        ]);
        $app['config']->set('statamic-payments.follow_up.enabled', true);
    }

    protected function paidPayment(array $overrides = []): Payment
    {
        $payment = app(Checkout::class)->start('noten-paket', ['email' => 'kaeufer@example.com'])->payment;

        $payment->forceFill(array_merge([
            'status' => Payment::STATUS_PAID,
            'paid_at' => now(),
            'fulfilled_at' => now(),
            'customer_reference' => 'cst_maria',
        ], $overrides))->save();

        // Was an der Zahlung steht, kennt der Anbieter auch — sonst prueft der
        // Fake gegen eine leere Liste und lehnt jedes Mandat ab. Hier statt in
        // jedem Test, damit niemand es vergessen kann: der Fake ist absichtlich
        // streng, und die Strenge soll an ihm haengen, nicht an der Disziplin
        // des naechsten Testschreibers.
        $mandat = trim((string) ($payment->fresh()->mandate_id ?? ''));

        if ($mandat !== '' && ! in_array($mandat, $this->gateway->knownMandates, true)) {
            $this->gateway->knownMandates[] = $mandat;
        }

        return $payment->fresh();
    }

    #[Test]
    public function an_accepted_offer_is_charged_without_new_card_details(): void
    {
        $original = $this->paidPayment();
        $this->gateway->mandates[] = 'cst_maria';

        $follow = app(FollowUp::class)->accept($original, 'begleit-cd');

        $this->assertNotNull($follow);
        $this->assertSame(1200, $follow->amount_cent);
        $this->assertSame($original->id, $follow->parent_payment_id);
        $this->assertSame('kaeufer@example.com', $follow->email);
        $this->assertSame(PaymentItem::KIND_UPSELL, $follow->items()->first()->kind);
    }

    #[Test]
    public function an_accepted_offer_is_not_treated_as_paid(): void
    {
        $original = $this->paidPayment();
        $this->gateway->mandates[] = 'cst_maria';

        $follow = app(FollowUp::class)->accept($original, 'begleit-cd');

        // A recurring charge is accepted first and confirmed later. Treating
        // acceptance as payment would grant access before the money moved,
        // which is the exact mistake this package exists to avoid. Only the
        // webhook decides, exactly as at checkout.
        $this->assertNotSame(Payment::STATUS_PAID, $follow->status);
        $this->assertNull($follow->fulfilled_at);
    }

    #[Test]
    public function without_an_agreement_nothing_is_charged(): void
    {
        // The buyer never agreed to be charged again: no mandate, no customer
        // reference. This is the default for every payment.
        $original = $this->paidPayment(['customer_reference' => null]);

        $this->assertFalse(app(FollowUp::class)->eligible($original));
        $this->assertNull(app(FollowUp::class)->accept($original, 'begleit-cd'));
        $this->assertSame(1, Payment::count());
    }

    #[Test]
    public function a_provider_that_refuses_leaves_a_failed_row_and_no_charge(): void
    {
        $original = $this->paidPayment();
        // Reference present, but the provider has no mandate behind it.
        $this->gateway->refuseFollowUp = true;

        $this->assertNull(app(FollowUp::class)->accept($original, 'begleit-cd'));

        // The row stays, marked failed: evidence that the offer was accepted
        // and the charge did not happen. Deleting it would hide the case
        // somebody has to look into.
        $follow = Payment::where('parent_payment_id', $original->id)->first();
        $this->assertNotNull($follow);
        $this->assertSame(Payment::STATUS_FAILED, $follow->status);
        $this->assertNull($follow->fulfilled_at);
    }

    #[Test]
    public function an_unpaid_original_cannot_carry_a_follow_up(): void
    {
        $original = $this->paidPayment(['status' => Payment::STATUS_OPEN, 'paid_at' => null]);
        $this->gateway->mandates[] = 'cst_maria';

        // Offering more to somebody whose first payment has not gone through
        // charges them for the second thing before the first is settled.
        $this->assertFalse(app(FollowUp::class)->eligible($original));
        $this->assertNull(app(FollowUp::class)->accept($original, 'begleit-cd'));
    }

    #[Test]
    public function it_is_off_unless_the_site_switches_it_on(): void
    {
        config(['statamic-payments.follow_up.enabled' => false]);

        $original = $this->paidPayment();
        $this->gateway->mandates[] = 'cst_maria';

        // Installing the addon must not make it possible to charge anybody
        // twice. The flag is the site saying it has thought about this.
        $this->assertFalse(app(FollowUp::class)->available());
        $this->assertNull(app(FollowUp::class)->accept($original, 'begleit-cd'));
    }

    #[Test]
    public function an_unknown_product_is_refused(): void
    {
        $original = $this->paidPayment();
        $this->gateway->mandates[] = 'cst_maria';

        $this->assertNull(app(FollowUp::class)->accept($original, 'gibt-es-nicht'));
        $this->assertSame(1, Payment::count());
    }

    #[Test]
    public function the_amount_comes_from_the_catalogue_and_not_from_the_offer(): void
    {
        $original = $this->paidPayment();
        $this->gateway->mandates[] = 'cst_maria';

        // There is no way to pass a price in — the signature takes a handle.
        // This is the test that keeps it that way: a follow-up offer is exactly
        // where a "special price for you" parameter would be invented, and it
        // would be a price anybody could post.
        $follow = app(FollowUp::class)->accept($original, 'begleit-cd', ['offer' => 'danke-seite']);

        $this->assertSame(1200, $follow->amount_cent);
        $this->assertSame(['offer' => 'danke-seite'], $follow->items()->first()->meta);
    }

    #[Test]
    public function no_mandate_is_collected_unless_the_site_asks(): void
    {
        // The default. Installing this addon must not start remembering
        // people's payment methods at a provider.
        $this->assertFalse(config('statamic-payments.follow_up.collect_mandate'));

        $payment = app(Checkout::class)->start('noten-paket', ['email' => 'k@example.com'])->payment;

        $this->assertNull($payment->customer_reference);
    }

    #[Test]
    public function with_the_flag_on_the_first_payment_leaves_something_to_charge_against(): void
    {
        config(['statamic-payments.follow_up.collect_mandate' => true]);

        $payment = app(Checkout::class)->start('noten-paket', ['email' => 'k@example.com'])->payment;

        // Without this, `customer_reference` would never be set and a follow-up
        // could never be charged — the feature would be documented and dead.
        $this->assertNotNull($payment->customer_reference);
        $this->assertContains($payment->customer_reference, $this->gateway->mandates);
    }

    #[Test]
    public function a_provider_that_will_not_remember_does_not_break_the_sale(): void
    {
        config(['statamic-payments.follow_up.collect_mandate' => true]);
        $this->gateway->refuseToRemember = true;

        // The buyer is trying to pay for something. Losing that sale because a
        // later, optional offer could not be prepared would be the wrong trade.
        $result = app(Checkout::class)->start('noten-paket', ['email' => 'k@example.com']);

        $this->assertNotNull($result);
        $this->assertSame(1900, $result->payment->amount_cent);
        $this->assertNull($result->payment->customer_reference);
    }

    #[Test]
    public function the_same_offer_cannot_be_taken_twice(): void
    {
        $original = $this->paidPayment();
        $this->gateway->mandates[] = 'cst_maria';

        $first = app(FollowUp::class)->accept($original, 'begleit-cd');
        $second = app(FollowUp::class)->accept($original, 'begleit-cd');

        // Two clicks, a double submit, a reloaded confirmation: all of them
        // arrive here, and all of them would otherwise charge again for the
        // same thing.
        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertSame(1, Payment::where('parent_payment_id', $original->id)->count());
    }

    #[Test]
    public function a_refused_charge_may_be_offered_again(): void
    {
        $original = $this->paidPayment();
        $this->gateway->refuseFollowUp = true;

        $this->assertNull(app(FollowUp::class)->accept($original, 'begleit-cd'));

        // The buyer got nothing, so this is not "already taken". Blocking it
        // would leave them unable to buy after a provider hiccup.
        $this->assertFalse(app(FollowUp::class)->alreadyTaken($original, 'begleit-cd'));

        $this->gateway->refuseFollowUp = false;
        $this->gateway->mandates[] = 'cst_maria';

        $this->assertNotNull(app(FollowUp::class)->accept($original, 'begleit-cd'));
    }

    #[Test]
    public function somebody_else_at_the_same_screen_is_not_charged_on_the_first_buyers_card(): void
    {
        $original = $this->paidPayment();
        $this->gateway->mandates[] = 'cst_maria';

        // Derselbe Rechner, andere Person. Ein Mandat gehoert dem Menschen,
        // nicht dem Geraet — wer das verwechselt, bucht bei der zweiten Person
        // die Karte der ersten ab und liefert an deren Adresse.
        $this->assertFalse(
            app(FollowUp::class)->eligible($original, 'jemand-anderes@example.com')
        );

        $this->assertNull(
            app(FollowUp::class)->accept($original, 'begleit-cd', [], [], 'jemand-anderes@example.com')
        );

        // Keine Zeile, kein Anbieter-Aufruf: die Ablehnung faellt, bevor
        // irgendetwas angelegt wird.
        $this->assertSame(1, Payment::count());
    }

    #[Test]
    public function the_same_buyer_is_recognised_regardless_of_case_and_spaces(): void
    {
        $original = $this->paidPayment();
        $this->gateway->mandates[] = 'cst_maria';

        // Ein Mensch, der seine Adresse beim zweiten Mal anders tippt, ist
        // derselbe Mensch. Ein Vergleich, der daran scheitert, schickt
        // Wiederkaeufer grundlos noch einmal durch die Karteneingabe.
        $this->assertTrue(
            app(FollowUp::class)->eligible($original, '  KAEUFER@Example.COM ')
        );

        $this->assertNotNull(
            app(FollowUp::class)->accept($original, 'begleit-cd', [], [], '  KAEUFER@Example.COM ')
        );
    }

    #[Test]
    public function a_caller_that_knows_no_address_gets_the_old_behaviour(): void
    {
        $original = $this->paidPayment();
        $this->gateway->mandates[] = 'cst_maria';

        // Es gibt Strecken, die ihren Kaeufer aus einer signierten Sitzung
        // kennen und keine Adresse zur Hand haben. Denen wird nichts
        // weggenommen — sie bekommen nur auch keinen zusaetzlichen Schutz.
        $this->assertTrue(app(FollowUp::class)->eligible($original));
        $this->assertTrue(app(FollowUp::class)->eligible($original, null));
    }

    #[Test]
    public function a_payment_without_an_address_cannot_contradict_anybody(): void
    {
        $original = $this->paidPayment(['email' => null]);
        $this->gateway->mandates[] = 'cst_maria';

        // Steht an der ersten Zahlung keine Adresse, gibt es nichts zu
        // vergleichen. Dann bleibt es bei den uebrigen Bedingungen, statt
        // jeden Wiederkauf pauschal abzulehnen.
        $this->assertTrue(app(FollowUp::class)->eligible($original, 'kaeufer@example.com'));
    }

    #[Test]
    public function the_card_the_buyer_would_recognise_is_kept_from_the_first_payment(): void
    {
        $payment = app(Checkout::class)->start('noten-paket', ['email' => 'kaeufer@example.com'])->payment;

        $this->gateway->markPaid($payment->provider_id, 'kaeufer@example.com', '9996', 'Mastercard');

        app(Fulfilment::class)->handle($payment->provider_id);

        // Gebraucht wird es auf der Seite des Nachfassangebots: die darf nicht
        // abbuchen, ohne vorher zu sagen, womit. Zu holen ist es nur jetzt —
        // spaeter kostet es einen Anbieter-Aufruf beim Rendern einer Seite.
        $payment->refresh();
        $this->assertSame('9996', $payment->card_last4);
        $this->assertSame('Mastercard', $payment->card_label);
    }

    #[Test]
    public function a_payment_method_without_a_card_leaves_no_hint_behind(): void
    {
        $payment = app(Checkout::class)->start('noten-paket', ['email' => 'kaeufer@example.com'])->payment;

        // Ueberweisung, Lastschrift, Gutschein: es gibt keine vier Ziffern.
        // Dann steht dort nichts, und die Seite muss das aushalten, statt sich
        // etwas auszudenken.
        $this->gateway->markPaid($payment->provider_id, 'kaeufer@example.com');

        app(Fulfilment::class)->handle($payment->provider_id);

        $payment->refresh();
        $this->assertNull($payment->card_last4);
        $this->assertNull($payment->card_label);
    }

    #[Test]
    public function a_provider_that_names_the_brand_without_the_digits_still_leaves_the_brand(): void
    {
        $payment = app(Checkout::class)->start('noten-paket', ['email' => 'kaeufer@example.com'])->payment;

        // Wallet-Zahlungen: die Marke steht da, die vier Ziffern nicht. Vorher
        // hing die Marke an den Ziffern und ging mit ihnen verloren — die Seite
        // konnte dann nicht einmal „deine Mastercard" sagen.
        $this->gateway->markPaid($payment->provider_id, 'kaeufer@example.com', null, 'Mastercard');

        app(Fulfilment::class)->handle($payment->provider_id);

        $payment->refresh();
        $this->assertNull($payment->card_last4);
        $this->assertSame('Mastercard', $payment->card_label);
    }

    #[Test]
    public function an_empty_card_column_counts_as_not_set(): void
    {
        $payment = app(Checkout::class)->start('noten-paket', ['email' => 'kaeufer@example.com'])->payment;

        // Ein `''` aus einer aelteren Fassung oder einem Import sieht belegt
        // aus und sagt nichts. Zaehlte es als gesetzt, waere die Spalte fuer
        // immer gesperrt und die Kaufseite koennte nie etwas anzeigen.
        $payment->forceFill(['card_last4' => '', 'card_label' => ''])->save();

        $this->gateway->markPaid($payment->provider_id, 'kaeufer@example.com', '9996', 'Visa');
        app(Fulfilment::class)->handle($payment->provider_id);

        $payment->refresh();
        $this->assertSame('9996', $payment->card_last4);
        $this->assertSame('Visa', $payment->card_label);
    }

    #[Test]
    public function a_payment_the_provider_does_not_know_yet_says_so(): void
    {
        $original = $this->paidPayment();
        $this->gateway->mandates[] = 'cst_maria';

        // Der Platzhalter hat einen Namen, damit Nachbarpakete die Frage
        // stellen koennen, ohne das Praefix zu buchstabieren.
        $frisch = new Payment(['provider_id' => Payment::PLACEHOLDER_PROVIDER_PREFIX.'abc']);
        $this->assertFalse($frisch->hasProviderId());
        $this->assertTrue(Payment::isPlaceholderProviderId($frisch->provider_id));

        $follow = app(FollowUp::class)->accept($original, 'begleit-cd');
        $this->assertNotNull($follow);
        $this->assertTrue($follow->hasProviderId(), 'Nach der Abbuchung steht die Kennung des Anbieters da.');
    }

    #[Test]
    public function each_payment_keeps_the_card_its_own_answer_documented(): void
    {
        $original = $this->paidPayment();
        $original->forceFill(['card_last4' => '9996', 'card_label' => 'Visa'])->save();
        $this->gateway->mandates[] = 'cst_maria';

        $follow = app(FollowUp::class)->accept($original, 'begleit-cd');
        $this->assertNotNull($follow);

        // Was der Anbieter zur Folgeabbuchung sagt: eine andere Kartennummer —
        // er beschreibt die Karte des Mandats, nicht die der Erstzahlung — und
        // keine Marke. Genau so kam es im Testkauf vom 02.09.2026 aus Mollie
        // zurueck (9996/Visa an der Erstzahlung, 6787 ohne Marke an der
        // Folgeabbuchung).
        $this->gateway->markPaid($follow->provider_id, 'kaeufer@example.com', '6787', null);
        app(Fulfilment::class)->handle($follow->provider_id);

        // Jede Zahlung traegt, was fuer sie belegt ist. Nichts wird geerbt, und
        // die fehlende Marke wird nicht von der Nachbarzahlung geliehen —
        // sonst stuende auf einer Kaufseite eine Karte, die aus zwei Antworten
        // zusammengesetzt ist.
        $follow->refresh();
        $this->assertSame('6787', $follow->card_last4);
        $this->assertNull($follow->card_label);

        $original->refresh();
        $this->assertSame('9996', $original->card_last4);
        $this->assertSame('Visa', $original->card_label);
    }

    #[Test]
    public function the_follow_up_line_carries_the_offer_it_was_sold_through(): void
    {
        $original = $this->paidPayment();
        $this->gateway->mandates[] = 'cst_maria';

        // Der Aufrufer sagt, ueber welches Angebot verkauft wurde — dieselbe
        // Form wie an der Kasse. Ohne das war `payment_items.offer` genau bei
        // der Zeile leer, fuer die die Spalte gebaut wurde, und der
        // Upsell-Bericht ordnete den Umsatz keinem Angebot zu.
        $follow = app(FollowUp::class)->accept($original, 'begleit-cd', details: [
            'offer_handles' => ['begleit-cd' => 'cd-angebot'],
        ]);

        $this->assertNotNull($follow);
        $this->assertSame('cd-angebot', $follow->items()->first()->getAttribute('offer'));
    }

    #[Test]
    public function an_older_caller_that_names_no_offer_still_gets_the_one_from_the_catalogue(): void
    {
        $original = $this->paidPayment();
        $this->gateway->mandates[] = 'cst_maria';

        // Eine aeltere Fassung des Aufrufers uebergibt nichts. Dann zaehlt, was
        // der Katalog an das Produkt geheftet hat — `statamic-offers` liefert
        // `offer` mit. Nur wenn auch dort nichts steht, bleibt die Spalte leer.
        Catalogue::forgetResolvers();
        Catalogue::extend(static fn (string $handle): ?array => $handle === 'kurs-angebot:begleit-cd'
            ? ['name' => 'Begleit-CD', 'amount_cent' => 1200, 'offer' => 'kurs-angebot']
            : null);

        $follow = app(FollowUp::class)->accept($original, 'kurs-angebot:begleit-cd');

        $this->assertNotNull($follow);
        $this->assertSame('kurs-angebot', $follow->items()->first()->getAttribute('offer'));
    }

    /*
    |---------------------------------------------------------------------------
    | Welches Mandat belastet wird
    |---------------------------------------------------------------------------
    |
    | Die Seite eines Nachfassangebots kuendigt eine bestimmte Karte an — Marke
    | und letzte vier Ziffern der Erstzahlung. Bis 06.09.2026 bekam der Anbieter
    | nur die Kundenkennung und suchte sich selbst ein gueltiges Mandat aus. Bei
    | einem Kaeufer mit einem Mandat faellt das nie auf; bei zweien sind
    | Ankuendigung und Abbuchung zwei verschiedene Dinge.
    |
    | Mollies Testmodus taugt hier nicht als Beleg: er liefert bei einer
    | Folgeabbuchung ohnehin andere Kartendaten als bei der Erstzahlung. Der
    | Beleg muss aus dem Code kommen, und das ist, was hier steht.
    */

    #[Test]
    public function the_follow_up_charges_the_mandate_the_first_payment_left_behind(): void
    {
        $original = $this->paidPayment(['mandate_id' => 'mdt_erste']);
        $this->gateway->mandates[] = 'cst_maria';
        $this->gateway->knownMandates = ['mdt_erste', 'mdt_zweite'];

        $gesehen = null;
        $this->gateway->whileCalling = function (array $payload) use (&$gesehen): void {
            $gesehen = $payload['mandateId'] ?? null;
        };

        $follow = app(FollowUp::class)->accept($original, 'begleit-cd');

        $this->assertNotNull($follow);
        // Nicht "irgendein gueltiges Mandat des Kunden", sondern genau das der
        // Erstzahlung — auch wenn ein zweites existiert und gueltig waere.
        $this->assertSame('mdt_erste', $gesehen);
    }

    #[Test]
    public function without_a_stored_mandate_the_key_is_left_out_entirely(): void
    {
        // Bestandszeile von vor der Spalte, oder eine Zahlung, die nie ein
        // Mandat hinterlassen hat. Dann laeuft es wie bisher: der Anbieter
        // waehlt. Ein gesetzter, leerer Schluessel waere das Gegenteil von
        // harmlos — Mollie liest ihn als Angabe und lehnt ab.
        $original = $this->paidPayment(['mandate_id' => null]);
        $this->gateway->mandates[] = 'cst_maria';

        $schluesselDa = true;
        $this->gateway->whileCalling = function (array $payload) use (&$schluesselDa): void {
            $schluesselDa = array_key_exists('mandateId', $payload);
        };

        $follow = app(FollowUp::class)->accept($original, 'begleit-cd');

        $this->assertNotNull($follow, 'Ohne Mandat soll weiter abgebucht werden koennen wie bisher.');
        $this->assertFalse($schluesselDa, 'Der Schluessel darf gar nicht erst mitgeschickt werden.');
    }

    #[Test]
    public function an_empty_stored_mandate_counts_as_none(): void
    {
        // Ein `''` aus einer aelteren Fassung oder einem Import sieht belegt
        // aus und sagt nichts. Es darf nicht als Angabe rausgehen.
        $original = $this->paidPayment(['mandate_id' => '   ']);
        $this->gateway->mandates[] = 'cst_maria';

        $schluesselDa = true;
        $this->gateway->whileCalling = function (array $payload) use (&$schluesselDa): void {
            $schluesselDa = array_key_exists('mandateId', $payload);
        };

        $this->assertNotNull(app(FollowUp::class)->accept($original, 'begleit-cd'));
        $this->assertFalse($schluesselDa);
    }

    #[Test]
    public function a_revoked_mandate_leaves_a_failed_row_and_no_charge(): void
    {
        // Der Kaeufer hat sein Einzugsrecht zurueckgezogen. Der Anbieter lehnt
        // ab — und weicht NICHT auf ein anderes Mandat aus, auch wenn eines da
        // waere. Der Kauf laeuft dann nicht in einen Fehler, sondern
        // hinterlaesst eine gescheiterte Zeile als Beleg, genau wie heute,
        // wenn gar kein Mandat existiert.
        $original = $this->paidPayment(['mandate_id' => 'mdt_widerrufen']);
        $this->gateway->mandates[] = 'cst_maria';
        $this->gateway->knownMandates = ['mdt_widerrufen', 'mdt_andere'];
        $this->gateway->revokedMandates = ['mdt_widerrufen'];

        $follow = app(FollowUp::class)->accept($original, 'begleit-cd');

        $this->assertNull($follow, 'Ein widerrufenes Mandat darf keine bezahlte Bestellung ergeben.');

        $zeile = Payment::where('parent_payment_id', $original->id)->first();
        $this->assertNotNull($zeile, 'Die angenommene Bestellung soll als Beleg stehen bleiben.');
        $this->assertSame(Payment::STATUS_FAILED, $zeile->status);
    }

    #[Test]
    public function the_mandate_of_the_first_payment_is_not_overwritten_by_the_follow_up(): void
    {
        // Der Anbieter beschreibt bei einer Folgeabbuchung das Mandat, gegen
        // das sie lief. Duerfte diese Antwort zurueckschreiben, wanderte die
        // Kennung der Erstzahlung weg — und die naechste Abbuchung ginge gegen
        // etwas, das die Seite nie angekuendigt hat. Dieselbe Einfrier-Regel
        // wie bei card_last4 eine Spalte weiter.
        $original = $this->paidPayment(['mandate_id' => 'mdt_erste']);
        $this->gateway->mandates[] = 'cst_maria';
        $this->gateway->knownMandates = ['mdt_erste'];

        $follow = app(FollowUp::class)->accept($original, 'begleit-cd');
        $this->assertNotNull($follow);

        // Der Anbieter beschreibt die Folgeabbuchung mit IHREM Mandat.
        $this->gateway->markPaid($follow->provider_id, mandateId: 'mdt_folge');
        app(Fulfilment::class)->handle($follow->provider_id);

        // Die Erstzahlung behaelt ihre Kennung ...
        $this->assertSame('mdt_erste', $original->fresh()->mandate_id);
        // ... und die Folgezeile traegt ihre eigene, nicht die geerbte.
        $this->assertSame('mdt_folge', $follow->fresh()->mandate_id);
    }
}

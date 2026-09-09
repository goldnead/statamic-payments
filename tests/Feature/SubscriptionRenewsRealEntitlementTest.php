<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\Entitlements\Contracts\SubjectResolver;
use Goldnead\Entitlements\EntitlementManager;
use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Models\Entitlement;
use Goldnead\Entitlements\Support\MorphSubjectResolver;
use Goldnead\Entitlements\Support\SubjectReference;
use Goldnead\IdentityContracts\ServiceProvider;
use Goldnead\StatamicPayments\Events\SubscriptionEnded;
use Goldnead\StatamicPayments\Integrations\EntitlementsBridge;
use Goldnead\StatamicPayments\Listeners\FollowSubscriptionWithEntitlement;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;

/**
 * The same thing again, against the sibling itself.
 *
 * The fake next door is as strict as the real class and still only proves that
 * this side calls correctly. Whether the call is *accepted* is a question only
 * the real addon answers — and the last time this bridge was tested only
 * against a stand-in, it had never worked on a single real installation.
 */
class SubscriptionRenewsRealEntitlementTest extends TestCase
{
    /**
     * Die Geschwister als echte Provider, nicht als Attrappen.
     *
     * entitlements haengt an brand-context und identity-contracts; ohne die
     * beiden loest seine Facade gar nicht auf. Nur in dieser Datei, damit die
     * uebrige Suite weiter ohne die Nachbarschaft laeuft.
     */
    protected function getPackageProviders($app)
    {
        return array_merge(parent::getPackageProviders($app), array_values(array_filter([
            class_exists(ServiceProvider::class) ? ServiceProvider::class : null,
            class_exists(\Goldnead\BrandContext\ServiceProvider::class) ? \Goldnead\BrandContext\ServiceProvider::class : null,
            class_exists(\Goldnead\Entitlements\ServiceProvider::class) ? \Goldnead\Entitlements\ServiceProvider::class : null,
        ])));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__.'/../../vendor/goldnead/statamic-entitlements/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../../vendor/goldnead/statamic-brand-context/database/migrations');

        if (! class_exists(Entitlements::class)) {
            $this->markTestSkipped('the sibling has to be installed for this to mean anything');
        }

        config([
            'statamic-payments.entitlements.enabled' => true,
            'statamic-payments.products.mitgliedschaft' => [
                'name' => 'Mitgliedschaft', 'amount_cent' => 1900, 'grants' => 'mitgliedschaft',
            ],
        ]);
    }

    private function abo(array $werte = []): Subscription
    {
        return Subscription::create(array_merge([
            'provider' => 'fake', 'provider_id' => 'sub_1', 'customer_reference' => 'cst_1',
            'product' => 'mitgliedschaft', 'amount_cent' => 1900, 'currency' => 'EUR',
            'interval' => '1 month', 'times_charged' => 1, 'status' => 'active',
            'next_payment_at' => Carbon::parse('2026-10-01 00:00'),
            'email' => 'wer@example.com',
        ], $werte));
    }

    private function zahlung(): Payment
    {
        return Payment::create([
            'provider' => 'fake', 'provider_id' => 'tr_z', 'product' => 'mitgliedschaft',
            'amount_cent' => 1900, 'currency' => 'EUR', 'status' => Payment::STATUS_PAID,
            'email' => 'wer@example.com',
        ]);
    }

    #[Test]
    public function twelve_cycles_are_one_entitlement_and_not_twelve(): void
    {
        $subject = new SubjectReference('email', 'wer@example.com');

        Entitlements::grant($subject, 'mitgliedschaft', 'statamic-payments', 'sub_1',
            expiresAt: Carbon::parse('2026-09-01 00:00'));

        $bruecke = app(EntitlementsBridge::class);
        $abo = $this->abo();
        $zahlung = $this->zahlung();

        // Dasselbe Abo, drei Zyklen. Genau so kommt es im Betrieb: eine Zeile,
        // deren `next_payment_at` der Anbieter jeden Monat weiterschiebt.
        for ($monat = 10; $monat <= 12; $monat++) {
            $abo->forceFill(['next_payment_at' => Carbon::parse("2026-{$monat}-01 00:00")])->save();
            $bruecke->renewFor($abo->fresh(), $zahlung);
        }

        $this->assertSame(1, Entitlement::count(), 'jeder Zyklus hat einen neuen Zugang geschrieben');
        $this->assertSame('2026-12-01', Entitlement::first()->expires_at->format('Y-m-d'));
    }

    #[Test]
    public function a_first_cycle_without_any_prior_grant_creates_one(): void
    {
        app(EntitlementsBridge::class)->renewFor($this->abo(), $this->zahlung());

        $this->assertSame(1, Entitlement::count());
        $this->assertSame('2026-10-01', Entitlement::first()->expires_at->format('Y-m-d'));
    }

    /**
     * Wer alle Raten bezahlt hat, behält, wofür er bezahlt hat.
     *
     * **Am 09.09.2026 auf staging aufgefallen, an einem echten Testkauf.** Ein
     * Ratenkauf über 3 × 520 € legt eine Vereinbarung mit `times = 3` an. Zahlt
     * der Käufer die letzte Rate, setzt `recordCycle()` die Zeile auf
     * `completed` und feuert `SubscriptionEnded` — und der Zuhörer daneben
     * schloss daraufhin den Zugang. Ergebnis: der Kunde überweist 1.560 €,
     * vollständig, und **verliert in derselben Sekunde**, was er gekauft hat.
     *
     * Zwei Wege enden in demselben Ereignis, und sie bedeuten das Gegenteil
     * voneinander:
     *
     * - `cancelled` — die Mahnstrecke hat aufgegeben, es wurde nicht bezahlt.
     *   Der Zugang läuft zum Ende des bezahlten Zeitraums aus. Richtig.
     * - `completed` — der Plan ist durch, alles bezahlt. Zugang bleibt.
     *
     * Der Unterschied steht auf der Zeile, im `status`. Er war nur nie gelesen
     * worden.
     */
    #[Test]
    public function a_plan_paid_to_the_last_instalment_keeps_its_access(): void
    {
        $subject = new SubjectReference('email', 'wer@example.com');

        // Offen vergeben: so entsteht der Zugang aus der ersten Rate. Ein
        // Ratenkauf verkauft die Sache, nicht einen Zeitraum.
        Entitlements::grant($subject, 'mitgliedschaft', 'statamic-payments', 'sub_1');

        $this->assertNull(Entitlement::first()->expires_at);

        // Die letzte Rate ist durch: `recordCycle()` setzt genau das hier und
        // feuert dann `SubscriptionEnded`.
        $abo = $this->abo([
            'times' => 3,
            'times_charged' => 3,
            'status' => Subscription::STATUS_COMPLETED,
            'ended_at' => Carbon::parse('2026-11-01 00:00'),
            'next_payment_at' => null,
        ]);

        app(FollowSubscriptionWithEntitlement::class)->handleEnded(new SubscriptionEnded($abo));

        $this->assertNull(
            Entitlement::first()->expires_at,
            'der Zugang wurde geschlossen, obwohl alle Raten bezahlt sind',
        );
        $this->assertNull(Entitlement::first()->revoked_at);
    }

    /**
     * Bindet der Host einen eigenen Resolver, erreicht die Brücke seine Zugänge.
     *
     * **Am 09.09.2026 auf adriangoldner.com gemessen.** Dort hängen Zugänge am
     * eigenen `User`, nicht an einer E-Mail. Die Brücke baute das Paar
     * `('email', …)` selbst, `EntitlementManager::reference()` reichte ein
     * fertiges Paar unverändert durch — und der Resolver des Hosts wurde nie
     * gefragt. Ergebnis am System: über die E-Mail gefunden 0, über den Nutzer
     * 1. `renewFor()` und `closeFor()` waren damit stille Nichtstuer, und wer
     * aufhörte zu zahlen, behielt seinen Zugang.
     *
     * Der Test bindet einen Resolver, wie ein Host es täte: eine Adresse wird
     * zu einem ganz anderen Subjekt. Greift die Naht nicht, findet die Brücke
     * den Zugang nicht und schließt nichts — genau der stille Fehlschlag.
     */
    #[Test]
    public function the_bridge_asks_the_host_who_the_buyer_is(): void
    {
        $this->app->bind(SubjectResolver::class, fn () => new class implements SubjectResolver
        {
            public function reference(mixed $subject): SubjectReference
            {
                // Wie ein Host, der seine Nutzer kennt: aus der Adresse wird
                // sein eigenes Subjekt. Alles andere geht an die Vorgabe.
                if (is_string($subject) && $subject === 'wer@example.com') {
                    return new SubjectReference('kunde', '42');
                }

                return (new MorphSubjectResolver)->reference($subject);
            }

            public function label(SubjectReference $reference): ?string
            {
                return null;
            }
        });

        // **Der `EntitlementManager` ist ein Singleton mit dem Resolver im
        // Konstruktor.** Wer ihn schon einmal aufgelöst hat, hält die Vorgabe
        // fest, egal was danach gebunden wird. Hier vergessen wir ihn, damit er
        // den Resolver von oben bekommt.
        //
        // Auf einem echten Host ist das keine Kunst, sondern eine Regel: die
        // Bindung gehört in `register()`, nicht in `boot()`. Wer sie später
        // setzt, bindet gegen einen Manager, der längst steht — und merkt es
        // nicht, weil nichts fehlschlägt, sondern nur nichts gefunden wird.
        $this->app->forgetInstance(EntitlementManager::class);
        Entitlements::clearResolvedInstances();

        // Der Zugang liegt am Subjekt des Hosts, nicht an der Adresse.
        Entitlements::grant(new SubjectReference('kunde', '42'), 'mitgliedschaft', 'statamic-payments', 'sub_1');

        $this->assertNull(Entitlement::first()->expires_at);

        app(FollowSubscriptionWithEntitlement::class)->handleEnded(
            new SubscriptionEnded($this->abo(['status' => Subscription::STATUS_CANCELLED])),
        );

        $this->assertSame(
            '2026-10-01',
            Entitlement::first()->expires_at?->format('Y-m-d'),
            'die Bruecke hat den Zugang des Hosts nicht gefunden',
        );
    }

    #[Test]
    public function without_a_host_resolver_the_email_stays_the_subject(): void
    {
        // Die Gegenprobe. Die Vorgabe `MorphSubjectResolver` kann mit einer
        // Zeichenkette nichts anfangen und wirft — dann muss es beim
        // `email`-Paar bleiben und nicht der ganze Kauf scheitern.
        Entitlements::grant(new SubjectReference('email', 'wer@example.com'), 'mitgliedschaft', 'statamic-payments', 'sub_1');

        app(FollowSubscriptionWithEntitlement::class)->handleEnded(
            new SubscriptionEnded($this->abo(['status' => Subscription::STATUS_CANCELLED])),
        );

        $this->assertSame('2026-10-01', Entitlement::first()->expires_at?->format('Y-m-d'));
    }

    #[Test]
    public function a_plan_the_dunning_gave_up_on_still_loses_its_access(): void
    {
        // Die Gegenprobe zum Test darüber. Dieselbe Meldung, anderer Grund:
        // hier wurde nicht bezahlt, und der Zugang muss auslaufen. Ohne diesen
        // Test wäre „schließe nie bei einem Ende" die bequeme Antwort, und
        // niemand verlöre je einen Zugang.
        $subject = new SubjectReference('email', 'wer@example.com');

        Entitlements::grant($subject, 'mitgliedschaft', 'statamic-payments', 'sub_1');

        $abo = $this->abo([
            'times' => 3,
            'times_charged' => 1,
            'status' => Subscription::STATUS_CANCELLED,
            'ended_at' => Carbon::parse('2026-11-01 00:00'),
        ]);

        app(FollowSubscriptionWithEntitlement::class)->handleEnded(new SubscriptionEnded($abo));

        $this->assertSame('2026-10-01', Entitlement::first()->expires_at->format('Y-m-d'));
    }

    #[Test]
    public function cancelling_leaves_the_paid_period_intact(): void
    {
        $subject = new SubjectReference('email', 'wer@example.com');

        // Offen vergeben: genau der Fall, der sonst ewig weiterläuft.
        Entitlements::grant($subject, 'mitgliedschaft', 'statamic-payments', 'sub_1');

        $this->assertNull(Entitlement::first()->expires_at);

        app(EntitlementsBridge::class)->closeFor($this->abo(['status' => 'cancelled']));

        $zugang = Entitlement::first();

        $this->assertSame('2026-10-01', $zugang->expires_at->format('Y-m-d'));
        // Und nicht widerrufen: der Zeitraum ist bezahlt.
        $this->assertNull($zugang->revoked_at);
    }
}

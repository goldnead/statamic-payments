<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Cp\SuiteLicence;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

/**
 * Der Lizenzhinweis der Suite.
 *
 * Adrian hat am 05.09.2026 Weg B gewaehlt: ein ehrlicher Hinweis, der aus
 * „vergessen" ein „bewusst ignoriert" macht. Kein Schloss.
 *
 * Die drei Regeln sind nicht verhandelbar, sonst wird aus B versehentlich C —
 * und ein Test, der sie nur beschreibt, haelt sie nicht. Deshalb steht hier
 * jede einzeln, mechanisch geprueft.
 */
class SuiteLicenceTest extends TestCase
{
    /**
     * Die Umgebung zurueckdrehen, bevor aufgeraeumt wird.
     *
     * Ohne das laeuft der Migrations-Rollback von Testbench noch unter
     * `production` und fragt „Do you really wish to run this in production?" —
     * an eine Konsole, die niemand bedient. Der Test scheitert dann an seiner
     * eigenen Kulisse statt an der Sache.
     */
    protected function tearDown(): void
    {
        $this->app['env'] = 'testing';

        parent::tearDown();
    }

    #[Test]
    public function without_a_key_in_production_the_notice_is_asked_for(): void
    {
        $this->app['env'] = 'production';
        config(['statamic-payments.suite.license_key' => null]);

        $this->assertTrue(SuiteLicence::noticeNeeded());
    }

    #[Test]
    public function any_key_at_all_silences_it(): void
    {
        // REGEL 3: keine Pruefung des Schluessels. Wer sich einen ausdenkt,
        // bekommt keinen Hinweis mehr — das ist Absicht und der ganze Punkt:
        // die Entscheidung liegt dann sichtbar beim Betreiber. Ein Test, der
        // hier eine Formpruefung erwartet, wuerde aus B ein C machen.
        $this->app['env'] = 'production';
        config(['statamic-payments.suite.license_key' => 'voellig-ausgedacht']);

        $this->assertFalse(SuiteLicence::noticeNeeded());
    }

    #[Test]
    public function an_empty_key_is_no_key(): void
    {
        // `SUITE_LICENSE_KEY=` in der .env ist eine leere Zeile, kein
        // Schluessel. Wer glaubt, er habe etwas eingetragen, soll den Hinweis
        // weiter sehen.
        $this->app['env'] = 'production';

        foreach ([null, '', '   ', "\t"] as $leer) {
            config(['statamic-payments.suite.license_key' => $leer]);
            $this->assertTrue(SuiteLicence::noticeNeeded(), 'Leer zaehlt nicht als Eintrag.');
        }
    }

    #[Test]
    public function nothing_appears_outside_production(): void
    {
        // Niemand soll beim Entwickeln ein Banner wegklicken.
        config(['statamic-payments.suite.license_key' => null]);

        foreach (['local', 'staging', 'testing'] as $umgebung) {
            $this->app['env'] = $umgebung;
            $this->assertFalse(SuiteLicence::noticeNeeded(), "In {$umgebung} darf nichts erscheinen.");
        }
    }

    #[Test]
    public function deciding_makes_no_outgoing_call(): void
    {
        // REGEL 1: kein Netzwerkaufruf. Nicht zu uns, nicht zu Statamic, zu
        // niemandem. Belegt statt behauptet: der HTTP-Client wird gesperrt, und
        // jeder Versuch waere hier eine Ausnahme.
        //
        // Das ist die Regel, die am leisesten bricht. Wer irgendwann eine
        // „richtige" Pruefung nachruestet, faellt zuerst ueber diesen Test —
        // und das soll er.
        Http::preventStrayRequests();

        $this->app['env'] = 'production';
        config(['statamic-payments.suite.license_key' => null]);

        SuiteLicence::noticeNeeded();
        SuiteLicence::hasKey();
        SuiteLicence::forScript();

        Http::assertNothingSent();
    }

    #[Test]
    public function the_key_itself_never_reaches_the_browser(): void
    {
        // Die Seite braucht ihn nicht, und was nicht rausgeht, kann nicht in
        // einem Screenshot oder einem Fehlerbericht landen.
        $this->app['env'] = 'production';
        config(['statamic-payments.suite.license_key' => 'SUITE-TEST-0001']);

        $fuerDenBrowser = SuiteLicence::forScript();

        $this->assertArrayNotHasKey('license_key', $fuerDenBrowser);
        $this->assertStringNotContainsString('SUITE-TEST-0001', (string) json_encode($fuerDenBrowser));
        $this->assertSame(['needed', 'days', 'url'], array_keys($fuerDenBrowser));
    }

    #[Test]
    public function the_quiet_period_is_days_and_at_least_one(): void
    {
        // Statamics eigenes Modal schweigt fuenf Minuten und laesst sich nicht
        // schliessen. Fuer ein Produkt, das ausdruecklich nichts erzwingt,
        // waere das feindselig. Eine 0 oder eine negative Zahl in der Config
        // wuerde daraus versehentlich dasselbe machen.
        config(['statamic-payments.suite.notice_days' => 0]);
        $this->assertGreaterThanOrEqual(1, SuiteLicence::forScript()['days']);

        config(['statamic-payments.suite.notice_days' => -5]);
        $this->assertGreaterThanOrEqual(1, SuiteLicence::forScript()['days']);

        config(['statamic-payments.suite.notice_days' => 30]);
        $this->assertSame(30, SuiteLicence::forScript()['days']);
    }

    #[Test]
    public function it_lives_in_exactly_one_place(): void
    {
        // REGEL „genau einmal, nicht sechzehnmal": bei sechzehn Paketen ist ein
        // Hinweis je Paket der wahrscheinlichste Fehler dieses Tickets. Die
        // Geschwister binden statamic-payments per class_exists ein und bringen
        // nichts Eigenes mit.
        //
        // Geprueft wird der Schluessel, unter dem der Hinweis an den Browser
        // geht: taucht er in einem zweiten Paket auf, gibt es zwei Hinweise.
        $geschwister = glob(dirname(__DIR__, 3).'/statamic-*/src');

        if ($geschwister === false || count($geschwister) < 2) {
            $this->markTestSkipped('Die Geschwister-Repos liegen hier nicht daneben.');
        }

        $fundstellen = [];

        foreach ($geschwister as $pfad) {
            if (str_contains($pfad, 'statamic-payments/')) {
                continue;
            }

            $treffer = shell_exec('grep -rl '.escapeshellarg('statamicPaymentsSuiteLicence').' '.escapeshellarg($pfad).' 2>/dev/null');

            if (is_string($treffer) && trim($treffer) !== '') {
                $fundstellen[] = trim($treffer);
            }
        }

        $this->assertSame([], $fundstellen, 'Der Hinweis darf nur in statamic-payments stehen.');
    }
}

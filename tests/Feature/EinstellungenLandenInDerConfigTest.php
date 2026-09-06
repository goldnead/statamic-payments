<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\BrandContext\ServiceProvider as BrandContextServiceProvider;
use Goldnead\BrandContext\Settings\SettingsManager;
use Goldnead\BrandContext\Settings\SettingsRegistry;
use Goldnead\StatamicPayments\Tests\TestCase;
use ReflectionClass;

/**
 * Der eine Beleg, den diese Umstellung schuldet: **ein gespeicherter Wert
 * landet wirklich in `config()`**.
 *
 * Alles andere an der Einstellungs-Seite gehört `statamic-brand-context` und
 * ist dort geprüft — Formular, Validierung, Rechte, Markendimension. Was hier
 * geprüft wird, überschreitet die Paketgrenze absichtlich: dass **dieses**
 * Addon seine Felder anmeldet, und dass der Weg von einer gespeicherten
 * Abweichung bis zu der Stelle trägt, an der die Widerrufs- und
 * Kündigungsstrecke liest.
 */
class EinstellungenLandenInDerConfigTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(
            dirname((new ReflectionClass(BrandContextServiceProvider::class))->getFileName(), 2).'/database/migrations'
        );

        $this->artisan('migrate')->run();
    }

    protected function getPackageProviders($app): array
    {
        return array_merge([BrandContextServiceProvider::class], parent::getPackageProviders($app));
    }

    /**
     * Die Meldeadresse und die Belehrungs-URL sind der Grund für dieses
     * Ticket: § 356a und § 312k BGB verlangen sie, und bis hierher standen sie
     * ausschließlich in der `.env`.
     */
    public function test_ein_gespeicherter_wert_landet_in_der_config(): void
    {
        $this->assertTrue(
            app(SettingsRegistry::class)->has('payments'),
            'Das Addon hat seine Einstellungen nicht bei der SettingsRegistry angemeldet.'
        );

        $manager = app(SettingsManager::class);

        $manager->for('payments')->save([
            'withdrawal.notify' => 'widerruf@example.test',
            'withdrawal.policy_url' => 'https://example.test/widerrufsbelehrung',
            'withdrawal.days' => 30,
            'cancellation.notify' => 'kuendigung@example.test',
            'portal.enabled' => false,
            'portal.ignored_query_parameters' => ['_se', 'mc_cid'],
        ]);

        // Erzwungen, weil die Schicht die Config im selben Prozess schon einmal
        // gesetzt hat und sonst mit „ist bereits richtig" früh aussteigt.
        $manager->apply(force: true);

        $this->assertSame('widerruf@example.test', config('statamic-payments.withdrawal.notify'));
        $this->assertSame('https://example.test/widerrufsbelehrung', config('statamic-payments.withdrawal.policy_url'));
        $this->assertSame(30, config('statamic-payments.withdrawal.days'));
        $this->assertSame('kuendigung@example.test', config('statamic-payments.cancellation.notify'));
        $this->assertFalse(config('statamic-payments.portal.enabled'));
        $this->assertSame(['_se', 'mc_cid'], config('statamic-payments.portal.ignored_query_parameters'));
    }

    /**
     * Der Mollie-Schlüssel steht nicht auf der Seite, und kein anderes
     * Geheimnis auch nicht.
     *
     * Das ist die Regel, an der dieses Ticket sonst scheitert: was hier
     * angeboten würde, läge in der Datenbank und damit in jeder Sicherung und
     * jedem Export. `key` bewegt Geld, `suite.license_key` ist ebenfalls ein
     * Schlüssel — beide bleiben in der `.env`.
     */
    public function test_kein_geheimnis_steht_auf_der_seite(): void
    {
        $angeboten = array_keys(app(SettingsRegistry::class)->fields('payments'));

        foreach (['key', 'suite.license_key', 'webhook_url'] as $verboten) {
            $this->assertNotContains($verboten, $angeboten);
        }
    }

    /**
     * Kein angebotener Schlüssel wird beim Booten gelesen.
     *
     * Die Falle, an der diese Umstellung sonst scheitert:
     * `SettingsManager::apply()` läuft aus `app->booted()`, also sieht alles,
     * was während des Bootens liest — Routen, Nav, Schedule — noch den
     * Paketwert. Der Betreiber speichert, die Anzeige sagt gespeichert, und die
     * Route antwortet weiter unter der alten Adresse.
     *
     * Zwei Prüfungen, weil `routes/web.php` auf zwei Arten liest. Die
     * Textsuche findet die ausgeschriebenen Pfade (`rate_limit`,
     * `portal.prefix`, `portal.middleware`, `portal.request_rate_limit`,
     * `withdrawal.prefix`, `cancellation.prefix`). Die vier `*.throttle` liest
     * die Datei über einen zusammengesetzten Pfad
     * (`'statamic-payments.'.$flow.'.throttle'`) — für eine Textsuche
     * unsichtbar, weshalb sie eigens genannt sind.
     */
    public function test_kein_angebotener_schluessel_wird_beim_booten_gelesen(): void
    {
        $routen = file_get_contents(__DIR__.'/../../routes/web.php');

        foreach (array_keys(app(SettingsRegistry::class)->fields('payments')) as $key) {
            $this->assertStringNotContainsString(
                'statamic-payments.'.$key,
                $routen,
                "[{$key}] wird beim Registrieren der Routen gelesen und darf deshalb nicht auf der Einstellungs-Seite stehen."
            );

            $this->assertFalse(
                str_ends_with($key, '.throttle'),
                "[{$key}] wird in routes/web.php über einen zusammengesetzten Pfad gelesen und darf deshalb nicht auf der Einstellungs-Seite stehen."
            );
        }
    }
}

<?php

namespace Goldnead\StatamicPayments\Support;

use Goldnead\BrandContext\Contracts\ProvidesSettings;
use Goldnead\BrandContext\Settings\SettingsRegistry;

/**
 * Die Einstellungen, die ein Betreiber im Control Panel ändern darf — und die
 * einzige Stelle, die weiß, welche das sind.
 *
 * **Nur die Feldliste steht hier.** Bildschirm, Formular, Validierung, Routen,
 * Speicher und die Markendimension kommen aus der gemeinsamen
 * Einstellungs-Schicht in `statamic-brand-context`; angemeldet wird diese
 * Klasse mit {@see SettingsRegistry} im Service Provider. Kein eigener
 * Controller, kein eigenes Formular, keine eigene Vue-Seite, und ausdrücklich
 * kein `settingsBlueprint()` — das fasst `config()` nicht an und speichert
 * Vollkopien statt Abweichungen.
 *
 * **Diese Klasse wird nur geladen, wenn `statamic-brand-context` da ist.**
 * Das Paket steht in `require-dev`, nicht in `require`: dieses Addon läuft
 * einmarkig ohne es, und jede Berührung mit ihm geschieht über
 * Zeichenketten-Klassennamen ({@see Brands}). Hier geht das nicht — eine Klasse
 * kann eine Schnittstelle nur implementieren, wenn es sie gibt. Deshalb nennt
 * der Service Provider `Settings::class` erst innerhalb einer
 * `class_exists`-Prüfung; PHP lädt eine Klasse, wenn etwas sie anfasst, und
 * ohne die Prüfung fasst nichts sie an.
 *
 * **Warum dieses Addon die Seite braucht.** § 356a BGB (Widerrufsbutton, seit
 * dem 19.06.2026) und § 312k BGB (Kündigungsbutton) verlangen beide eine
 * Meldestelle und erlauben beide, auf eine eigene Belehrung zu verlinken. Beide
 * Angaben — `withdrawal.notify`, `withdrawal.policy_url`,
 * `cancellation.notify`, `cancellation.policy_url` — standen bis hierher
 * ausschließlich in `.env`: gesetzlich verlangt, und für den Betreiber, dem sie
 * gehören, unerreichbar. Auf einem Mehrmarken-Host kommt hinzu, dass zwei
 * Marken zwei Belehrungen und zwei Postfächer haben, was eine `.env` mit einem
 * Wert je Schlüssel nicht abbilden kann.
 *
 * **Was nicht hier steht, und warum.**
 *
 * - `key`: der Mollie-Schlüssel. Ein Geheimnis, und zwar das, mit dem sich Geld
 *   bewegen lässt. Er bleibt in `.env`; hier angeboten läge er in der Datenbank
 *   und damit in jeder Sicherung und jedem Export.
 * - `suite.license_key`: ebenfalls ein Schlüssel. `suite.buy_url` ist unsere
 *   Adresse, nicht die des Betreibers, und `suite.notice_days` ist die Ruhezeit
 *   eines Hinweises, keine Einstellung des Ladens.
 * - `rate_limit`, `portal.prefix`, `portal.middleware`,
 *   `portal.request_rate_limit`, `withdrawal.prefix`, `withdrawal.throttle`,
 *   `cancellation.prefix`, `cancellation.throttle`: **beim Booten gelesen.**
 *   `routes/web.php` liest sie, während die Routen registriert werden, und
 *   `SettingsManager::apply()` läuft aus `app->booted()` — also danach. Eine
 *   Änderung käme dort nie an: der Betreiber speichert, die Anzeige sagt
 *   gespeichert, und die Route antwortet weiter unter der alten Adresse. Ein
 *   Bedienelement, das erst nach dem nächsten Deploy wirkt, ist schlechter als
 *   keins. Die vier `throttle`-Werte trifft es doppelt — sie werden über einen
 *   zusammengesetzten Pfad gelesen (`routes/web.php`, Zeile 189), also auch
 *   dann, wenn eine Textsuche im Routen-File sie nicht findet.
 * - `webhook_url`: die Adresse, unter der der Anbieter uns erreicht. Auf einer
 *   Entwicklungsmaschine die eines Tunnels, sonst leer — ein Wert des
 *   Deployments.
 * - `products`, `portal.throttle`: verschachtelte Abbildungen. Der Vertrag
 *   kennt `string|text|integer|boolean|list|select`, und ein Typ, der sie alle
 *   trüge, wäre ein Formular-Baukasten (Entscheidung 06.09.2026). Sie bleiben
 *   in `config/statamic-payments.php` und werden auf dem Bildschirm benannt.
 * - `methods`: die Konfiguration hält hier das, was aus der `.env` kommt — eine
 *   kommagetrennte Zeichenkette oder null, aufgetrennt erst in
 *   {@see PaymentMethods}. Eine gespeicherte `list` hätte eine Form, die die
 *   Datei nie hat.
 * - `portal.min_response_ms`: der Boden, auf den die Antwort „wir haben Ihnen
 *   einen Link geschickt" gehalten wird. Er ist der ganze Schutz davor, dass
 *   die Laufzeit verrät, ob eine Adresse hier je gekauft hat. Ein Feld, dessen
 *   einzige Wirkung ist, diesen Schutz zu senken, ist kein Betreiberwert.
 */
class Settings implements ProvidesSettings
{
    /**
     * Der Namensraum, den jede gespeicherte Zeile dieses Addons trägt.
     *
     * `payments`, nicht `statamic-payments`: der Handle des Addons ohne das
     * Paket-Präfix, dieselbe Wahl wie beim Recht (siehe
     * {@see settingsPermission()}). Die Überschrift des Abschnitts findet den
     * Addon-Namen trotzdem — `BrandSettingsController::title()` sucht auf den
     * Slug **oder** auf ein Paket, das auf `/statamic-<namensraum>` endet, und
     * das trifft hier zu.
     *
     * Er steht in `brand_settings.namespace` auf jeder Zeile; ihn später
     * umzubenennen verwaist jede Abweichung, die eine Installation gesetzt hat.
     */
    public static function settingsNamespace(): string
    {
        return 'payments';
    }

    /**
     * Die Config-Wurzel, der nicht gesetzte Werte weiter folgen.
     *
     * `config/statamic-payments.php`. Hier fallen Namensraum und Wurzel
     * **nicht** zusammen, und genau dafür fragt der Vertrag getrennt danach.
     */
    public static function settingsConfigPath(): string
    {
        return 'statamic-payments';
    }

    /**
     * Das Recht, das diesen Abschnitt bewacht.
     *
     * Hingeschrieben, nicht abgeleitet — die Regel vom 06.09.2026 lautet
     * `manage <handle> settings` mit dem Paketnamen ohne `statamic-`-Präfix.
     * Abgeleitet aus der Config-Wurzel wäre es `manage statamic-payments
     * settings` geworden, was mit den Namen der Geschwister nicht
     * zusammenpasst. Angemeldet wird es im Service Provider dieses Addons.
     */
    public static function settingsPermission(): string
    {
        return 'manage payments settings';
    }

    /**
     * @return array<int, array{title: string, description: string, fields: array<int, array<string, mixed>>}>
     */
    public static function settingsGroups(): array
    {
        return [
            [
                'title' => __('statamic-payments::settings.groups.checkout.title'),
                'description' => __('statamic-payments::settings.groups.checkout.description'),
                'fields' => [
                    static::field('currency', 'string'),
                    static::field('return_url', 'string'),
                    static::field('max_quantity', 'integer', ['min' => 1]),
                    // 0 schaltet das Löschen ab und muss erreichbar bleiben.
                    static::field('prune_unpaid_after_days', 'integer', ['min' => 0]),
                ],
            ],
            [
                'title' => __('statamic-payments::settings.groups.withdrawal.title'),
                'description' => __('statamic-payments::settings.groups.withdrawal.description'),
                'fields' => [
                    static::field('withdrawal.enabled', 'boolean'),
                    static::field('withdrawal.notify', 'string', ['nullable' => true]),
                    static::field('withdrawal.policy_url', 'string', ['nullable' => true]),
                    static::field('withdrawal.days', 'integer', ['min' => 1]),
                ],
            ],
            [
                'title' => __('statamic-payments::settings.groups.cancellation.title'),
                'description' => __('statamic-payments::settings.groups.cancellation.description'),
                'fields' => [
                    static::field('cancellation.enabled', 'boolean'),
                    static::field('cancellation.notify', 'string', ['nullable' => true]),
                    static::field('cancellation.policy_url', 'string', ['nullable' => true]),
                ],
            ],
            [
                'title' => __('statamic-payments::settings.groups.portal.title'),
                'description' => __('statamic-payments::settings.groups.portal.description'),
                'fields' => [
                    static::field('portal.enabled', 'boolean'),
                    static::field('portal.link_ttl_minutes', 'integer', ['min' => 1]),
                    static::field('portal.session_minutes', 'integer', ['min' => 1]),
                    static::field('portal.max_rows', 'integer', ['min' => 1]),
                    static::field('portal.mandate_verification_cent', 'integer', ['min' => 1]),
                    static::field('portal.from.address', 'string', ['nullable' => true]),
                    static::field('portal.from.name', 'string', ['nullable' => true]),
                    static::field('portal.ignored_query_parameters', 'list'),
                ],
            ],
            [
                'title' => __('statamic-payments::settings.groups.legal.title'),
                'description' => __('statamic-payments::settings.groups.legal.description'),
                'fields' => [
                    static::field('legal.timezone', 'string', ['nullable' => true]),
                    static::field('consent.accepted_texts', 'list'),
                ],
            ],
            [
                'title' => __('statamic-payments::settings.groups.abandoned.title'),
                'description' => __('statamic-payments::settings.groups.abandoned.description'),
                'fields' => [
                    static::field('abandoned.enabled', 'boolean'),
                    static::field('abandoned.after_minutes', 'integer', ['min' => 1]),
                    static::field('abandoned.mail.enabled', 'boolean'),
                    static::field('abandoned.mail.template', 'string', ['nullable' => true]),
                    static::field('abandoned.mail.subject', 'string', ['nullable' => true]),
                    static::field('abandoned.mail.resume_url', 'string', ['nullable' => true]),
                    static::field('abandoned.mail.resume_days', 'integer', ['min' => 1]),
                ],
            ],
            [
                'title' => __('statamic-payments::settings.groups.bridges.title'),
                'description' => __('statamic-payments::settings.groups.bridges.description'),
                'fields' => [
                    static::field('follow_up.enabled', 'boolean'),
                    static::field('follow_up.collect_mandate', 'boolean'),
                    static::field('leadhub.enabled', 'boolean'),
                    static::field('entitlements.enabled', 'boolean'),
                ],
            ],
        ];
    }

    /**
     * Ein Feld, mit Beschriftung und Erklärung aus den Sprachdateien.
     *
     * Der Übersetzungsschlüssel ist der Config-Pfad mit Unterstrichen statt
     * Punkten: ein Punkt ist dem Übersetzer ein Pfadtrenner, und
     * `settings.fields.abandoned.mail.enabled.label` würde als fünf
     * verschachtelte Arrays gesucht, die es nicht gibt.
     *
     * **`nullable` steht hier nur an den Feldern, deren Paketvorgabe wirklich
     * leer ist — anders als im Geschwisterpaket `statamic-invoices`, wo es die
     * Vorgabe ist.** Das ist kein Versehen. Das Formular schickt immer alle
     * Felder, `required` macht also ein Feld ohne Wert zum Riegel für den
     * ganzen Abschnitt, und ein Host, der `config/statamic-payments.php`
     * veröffentlicht und einen Block kürzt, bekommt für die gekürzten
     * Schlüssel `null`. Der Abschnitt lässt sich dann nicht speichern, bis die
     * Konfiguration wieder vollständig ist. Das ist hier trotzdem die bessere
     * Hälfte des Tauschs: `invoices` liest seine Steuerwerte über einen
     * Zugriff, der `null` als „nimm die Vorgabe" behandelt, dieses Addon liest
     * `(int) config('statamic-payments.withdrawal.days', 14)` — und ein
     * `null` wird dort zu **0**, also zu einer Widerrufsfrist von null Tagen,
     * die jede Erklärung als verspätet meldet. Ein sichtbar gesperrter
     * Abschnitt ist besser als eine still falsche gesetzliche Frist.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected static function field(string $key, string $type, array $extra = []): array
    {
        $handle = str_replace('.', '_', $key);

        return array_merge([
            'key' => $key,
            'type' => $type,
            'label' => __("statamic-payments::settings.fields.{$handle}.label"),
            'description' => __("statamic-payments::settings.fields.{$handle}.description"),
            'nullable' => false,
        ], $extra);
    }
}

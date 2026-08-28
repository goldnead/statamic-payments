<?php

namespace Goldnead\StatamicPayments\Support;

use InvalidArgumentException;

/**
 * Was die aufrufende Strecke an eine Zahlung heften darf, bevor der Anbieter
 * gerufen wird.
 *
 * Es gibt Angaben, die diesem Paket nichts bedeuten und der Anwendung darüber
 * alles: der Verweis, über den eine Danke-Seite Erst- und Folgezahlung
 * zusammenfindet, die Anschrift, aus der eine Rechnung entsteht. Ohne eine
 * Naht dafür trägt der Aufrufer sie **nach**, und das ist ein Rennen gegen den
 * Webhook: kommt der zuerst, schreibt der Rechnungsschreiber ohne Anschrift,
 * und eine Rechnung, der eine Pflichtangabe fehlt, ist danach nicht mehr zu
 * ändern. Über 250 EUR verlangt § 33 UStDV die Anschrift; darunter nicht, und
 * genau deshalb fällt es erst bei der teuren Bestellung auf.
 *
 * Deshalb wird hier geprüft und nicht später: ein Aufrufer, der etwas Falsches
 * mitgibt, erfährt es, bevor eine Zeile angelegt und bevor Geld bewegt wurde.
 *
 * **Drei Schlüssel, mehr nicht.**
 *
 * - `meta` — frei, wird auf der Zahlung abgelegt.
 * - `country` — ISO 3166-1 alpha-2. Eine eigene Spalte und keine Notiz in
 *   `meta`, weil der Steuersatz daran hängt. Bei einem Checkout ist der Weg
 *   dafür weiterhin `$buyer['country']`; was hier ankommt, füllt die Spalte
 *   nur, wenn sie sonst leer bliebe.
 * - `country_source` — woher dieses Land stammt, nur zusammen mit ihm. Ohne
 *   Angabe steht dort `caller`. Den Schlüssel gibt es, weil ein Aufrufer das
 *   Land oft nicht selbst erhebt, sondern von einer früheren Zahlung übernimmt:
 *   dann ist `mollie` die Wahrheit und `caller` eine Verschlechterung. Die EU
 *   will für den Ort eines Verbrauchers zwei nicht widersprechende Nachweise,
 *   und „der Kartenherausgeber sagt es" wiegt schwerer als „jemand hat es
 *   getippt". Diese Spalte ist die Stelle, an der das steht.
 *
 * Alles andere gehört dem Paket: Betrag, Produkt, Status, die Kennungen des
 * Anbieters, die Verbindung zur Eltern-Zahlung. Wer sie mitschickt, bekommt
 * eine Ausnahme statt eines stillen Verwerfens. Ein still verworfener Betrag
 * sähe für den Aufrufer aus wie ein gesetzter.
 */
final class PaymentDetails
{
    /** Was ein Aufrufer setzen darf. */
    public const ALLOWED = ['meta', 'country', 'country_source'];

    /** Die Herkunft eines Landes, das keine bessere nennt. */
    public const SOURCE = 'caller';

    /**
     * Schlüssel in `meta`, die das Paket selbst führt.
     *
     * Sie sind keine Notizen, sondern Zustand: `subscription_intent` entscheidet,
     * ob aus einer bezahlten Erst-Zahlung ein Abo wird, `refunds` verhindert,
     * dass eine erneut zugestellte Erstattung zweimal zählt, `cycle_of` sagt
     * einem Listener, zu welchem Abo eine Zyklus-Zahlung gehört.
     */
    public const RESERVED_META = [
        'subscription_intent',
        'subscription_start_failed_at',
        'subscription_start_error',
        'refunds',
        'cycle_of',
    ];

    /**
     * @param  array<string, mixed>  $meta
     */
    private function __construct(
        private array $meta,
        private ?string $country,
        private ?string $countrySource,
    ) {}

    /**
     * Prüfen, was der Aufrufer mitgibt.
     *
     * Nimmt auch ein bereits geprüftes Objekt entgegen, damit eine Schicht, die
     * nur durchreicht, nicht zweimal prüfen muss.
     *
     * @param  array<string, mixed>|self  $details
     *
     * @throws InvalidArgumentException
     */
    public static function from(array|self $details): self
    {
        if ($details instanceof self) {
            return $details;
        }

        foreach (array_keys($details) as $key) {
            if (! in_array($key, self::ALLOWED, true)) {
                throw new InvalidArgumentException(sprintf(
                    'statamic-payments: `%s` gehört dem Paket und kann einer Zahlung nicht mitgegeben werden. Mitgeben lassen sich: %s.',
                    (string) $key,
                    implode(', ', self::ALLOWED),
                ));
            }
        }

        $country = self::country($details['country'] ?? null);
        $source = self::source($details['country_source'] ?? null);

        // Eine Herkunft ohne Land beschreibt nichts. Sie stillschweigend
        // fallenzulassen hiesse, einen Aufrufer glauben zu lassen, er habe die
        // Spalte gefuellt.
        if ($source !== null && $country === null) {
            throw new InvalidArgumentException(
                'statamic-payments: `country_source` ohne `country` beschreibt nichts.'
            );
        }

        return new self(
            self::meta($details['meta'] ?? []),
            $country,
            $source,
        );
    }

    /**
     * Was das Paket selbst in `meta` ablegt. Es gewinnt.
     *
     * @param  array<string, mixed>  $own
     */
    public function plus(array $own): self
    {
        return new self(array_merge($this->meta, $own), $this->country, $this->countrySource);
    }

    /**
     * Die mitgegebenen Angaben auf die Spalten legen, die das Paket setzt.
     *
     * **Was schon dasteht, bleibt stehen.** Das ist die eigentliche Garantie
     * dieser Klasse: die Prüfung oben hält paket-eigene Spalten aus dem Aufruf
     * heraus, und diese Schleife hält sie auch dann, wenn dem Paket später eine
     * Spalte dazukommt, die zufällig so heißt wie eine erlaubte Angabe.
     *
     * @param  array<string, mixed>  $columns  Was das Paket selbst setzt.
     * @return array<string, mixed>
     */
    public function onto(array $columns): array
    {
        foreach ($this->columns() as $key => $value) {
            if (($columns[$key] ?? null) === null) {
                $columns[$key] = $value;
            }
        }

        return $columns;
    }

    /**
     * @return array<string, mixed>
     */
    private function columns(): array
    {
        $columns = [];

        if ($this->meta !== []) {
            $columns['meta'] = $this->meta;
        }

        if ($this->country !== null) {
            $columns['country'] = $this->country;
            $columns['country_source'] = $this->countrySource ?? self::SOURCE;
        }

        return $columns;
    }

    private static function source(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $source = is_string($value) ? trim($value) : '';

        if ($source === '' || mb_strlen($source) > 32) {
            throw new InvalidArgumentException(
                'statamic-payments: `country_source` muss ein kurzer, nicht leerer Text sein.'
            );
        }

        return $source;
    }

    /**
     * @return array<string, mixed>
     */
    private static function meta(mixed $meta): array
    {
        if ($meta === [] || $meta === null) {
            return [];
        }

        if (! is_array($meta)) {
            throw new InvalidArgumentException('statamic-payments: `meta` muss ein Array sein.');
        }

        foreach (array_keys($meta) as $key) {
            if (in_array($key, self::RESERVED_META, true)) {
                throw new InvalidArgumentException(sprintf(
                    'statamic-payments: `meta.%s` führt das Paket selbst und kann nicht mitgegeben werden.',
                    (string) $key,
                ));
            }
        }

        /** @var array<string, mixed> $meta */
        return $meta;
    }

    /**
     * Zwei Buchstaben, groß, ISO 3166-1 alpha-2.
     *
     * Anders als am Checkout wird ein unlesbarer Wert hier nicht still
     * verworfen, sondern abgelehnt. Am Checkout kommt das Land aus einem
     * Formular, und was ein Käufer tippt, darf am Kauf nichts ändern. Hier
     * kommt es aus dem Code der Anwendung, und „Deutschland" statt „DE" ist
     * dort kein Vertipper eines Kunden, sondern ein Fehler, der sonst erst auf
     * einer Rechnung ohne Steuerland auffällt.
     */
    private static function country(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $code = is_string($value) ? strtoupper(trim($value)) : '';

        if (preg_match('/^[A-Z]{2}$/', $code) !== 1) {
            throw new InvalidArgumentException(
                'statamic-payments: `country` muss ein ISO-3166-1-alpha-2-Code sein, zum Beispiel `DE`.'
            );
        }

        return $code;
    }
}

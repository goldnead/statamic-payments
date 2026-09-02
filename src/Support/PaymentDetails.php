<?php

namespace Goldnead\StatamicPayments\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Throwable;

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
 * Dazu die **Zustimmung nach § 356 Abs. 5 BGB**, als Paar:
 *
 * - `consent_at` — wann der Käufer zugestimmt hat, dass die Lieferung sofort
 *   beginnt und sein Widerrufsrecht damit erlischt. Carbon, DateTimeInterface
 *   oder ISO-8601-Text; wird zu Carbon. Ein Zeitpunkt in der Zukunft ist ein
 *   Programmierfehler und fliegt.
 * - `consent_text` — der Wortlaut, der dabei auf dem Bildschirm stand, im
 *   Ganzen. Nicht ein Schlüssel und nicht eine Versionsnummer: der Text kann
 *   sich ändern, und dann ist „hat zugestimmt" ohne die Fassung wertlos.
 *
 * Beide oder keines. Ein Zeitpunkt ohne Wortlaut ist ein Haken ohne Aussage,
 * ein Wortlaut ohne Zeitpunkt ein Zitat ohne Ereignis; keines von beiden ist
 * ein Beleg, und keines wird stillschweigend zu einem gemacht. Wer die
 * Zustimmung nicht erhoben hat — ein Kauf physischer Ware, ein B2B-Geschäft —
 * lässt beide weg, und die Spalten bleiben ehrlich leer.
 *
 * Alles andere gehört dem Paket: Betrag, Produkt, Status, die Kennungen des
 * Anbieters, die Verbindung zur Eltern-Zahlung. Wer sie mitschickt, bekommt
 * eine Ausnahme statt eines stillen Verwerfens. Ein still verworfener Betrag
 * sähe für den Aufrufer aus wie ein gesetzter.
 */
final class PaymentDetails
{
    /** Was ein Aufrufer setzen darf. */
    public const ALLOWED = [
        'meta', 'country', 'country_source',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'referrer', 'landing_page',
        'consent_at', 'consent_text',
        'offer_handles',
    ];

    /**
     * Wie lang der Wortlaut einer Zustimmung höchstens sein darf.
     *
     * Ein Satz nach § 356 Abs. 5 BGB ist zwei Zeilen lang. Viertausend Zeichen
     * lassen Platz für eine Belehrung darüber; was länger ist, ist kein
     * Einwilligungstext mehr, sondern ein Dokument, das an eine andere Stelle
     * gehört. Abgelehnt und nicht gekürzt: ein gekürzter Beleg ist ein
     * anderer Beleg.
     */
    public const CONSENT_TEXT_MAX = 4000;

    /**
     * Herkunftsangaben und die Breite ihrer Spalte.
     *
     * Die Namen sind die von LeadHub, damit beide Seiten dieselbe Tatsache
     * gleich nennen. Die Breiten stehen hier und nicht nur in der Migration,
     * weil ein zu langer Wert hier gekürzt wird statt später von der Datenbank
     * abgeschnitten oder abgelehnt zu werden — und weil abgeschnitten und
     * abgelehnt zwei sehr verschiedene Kaufabbrüche sind.
     */
    private const ATTRIBUTION = [
        'utm_source' => 255,
        'utm_medium' => 255,
        'utm_campaign' => 255,
        'utm_term' => 255,
        'utm_content' => 255,
        'referrer' => 1024,
        'landing_page' => 1024,
    ];

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
        'resumed_from',
        'resume_checkout_url',
    ];

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, string>  $attribution
     * @param  array<string, string>  $offerHandles  Produkt-Handle → Angebots-Handle
     */
    private function __construct(
        private array $meta,
        private ?string $country,
        private ?string $countrySource,
        private array $attribution = [],
        private ?Carbon $consentAt = null,
        private ?string $consentText = null,
        private array $offerHandles = [],
    ) {}

    /**
     * Über welches Angebot eine Position verkauft wurde, wenn der Aufrufer es
     * gesagt hat. Für `payment_items.offer`; ohne Angabe null, und das ist die
     * ehrliche Lücke — geraten wird hier nichts.
     */
    public function offerFor(string $productHandle): ?string
    {
        return $this->offerHandles[$productHandle] ?? null;
    }

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

        [$consentAt, $consentText] = self::consent($details['consent_at'] ?? null, $details['consent_text'] ?? null);

        return new self(
            self::meta($details['meta'] ?? []),
            $country,
            $source,
            self::attribution($details),
            $consentAt,
            $consentText,
            self::offerHandles($details['offer_handles'] ?? null),
        );
    }

    /**
     * Produkt-Handle → Angebots-Handle, geprüft. Beides kurze Texte; ein
     * falscher Typ ist ein Programmierfehler und fliegt.
     *
     * @return array<string, string>
     */
    private static function offerHandles(mixed $value): array
    {
        if ($value === null || $value === []) {
            return [];
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException('statamic-payments: `offer_handles` muss ein Array Produkt-Handle => Angebots-Handle sein.');
        }

        $handles = [];

        foreach ($value as $product => $offer) {
            if (! is_string($product) || $product === '' || ! is_string($offer) || trim($offer) === '') {
                throw new InvalidArgumentException('statamic-payments: `offer_handles` muss ein Array Produkt-Handle => Angebots-Handle sein.');
            }

            $handles[$product] = mb_substr(trim($offer), 0, 191);
        }

        return $handles;
    }

    /**
     * Was das Paket selbst in `meta` ablegt. Es gewinnt.
     *
     * @param  array<string, mixed>  $own
     */
    public function plus(array $own): self
    {
        return new self(
            array_merge($this->meta, $own),
            $this->country,
            $this->countrySource,
            $this->attribution,
            $this->consentAt,
            $this->consentText,
            $this->offerHandles,
        );
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

        foreach ($this->attribution as $key => $value) {
            $columns[$key] = $value;
        }

        if ($this->consentAt !== null && $this->consentText !== null) {
            $columns['consent_at'] = $this->consentAt;
            $columns['consent_text'] = $this->consentText;
        }

        return $columns;
    }

    /**
     * Die Zustimmung nach § 356 Abs. 5 BGB, geprüft.
     *
     * Rechtliche Entscheidungen (01.09.2026, von Adrian zu prüfen): beide
     * Angaben oder keine; der Zeitpunkt darf nicht in der Zukunft liegen; der
     * Wortlaut darf nicht leer sein und wird nicht gekürzt. Dies ist keine
     * Rechtsberatung.
     *
     * @return array{0: Carbon|null, 1: string|null}
     */
    private static function consent(mixed $at, mixed $text): array
    {
        if ($at === null && $text === null) {
            return [null, null];
        }

        if ($at === null || $text === null) {
            throw new InvalidArgumentException(
                'statamic-payments: `consent_at` und `consent_text` gehören zusammen — ein Zeitpunkt ohne Wortlaut oder ein Wortlaut ohne Zeitpunkt ist kein Beleg.'
            );
        }

        if (! is_string($text)) {
            throw new InvalidArgumentException('statamic-payments: `consent_text` muss ein Text sein.');
        }

        $text = trim($text);

        if ($text === '') {
            throw new InvalidArgumentException('statamic-payments: `consent_text` darf nicht leer sein — der Wortlaut ist der Beleg.');
        }

        if (mb_strlen($text) > self::CONSENT_TEXT_MAX) {
            throw new InvalidArgumentException(sprintf(
                'statamic-payments: `consent_text` ist länger als %d Zeichen. Ein Einwilligungstext dieser Länge gehört in ein Dokument, nicht in diese Spalte.',
                self::CONSENT_TEXT_MAX,
            ));
        }

        $moment = self::moment($at);

        if ($moment->isFuture()) {
            throw new InvalidArgumentException(
                'statamic-payments: `consent_at` liegt in der Zukunft. Niemand hat noch nicht zugestimmt.'
            );
        }

        return [$moment, $text];
    }

    private static function moment(mixed $value): Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy();
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse(trim($value));
            } catch (Throwable) {
                // Fällt durch zur Ausnahme unten.
            }
        }

        throw new InvalidArgumentException(
            'statamic-payments: `consent_at` muss ein Carbon, ein DateTimeInterface oder ein ISO-8601-Text sein.'
        );
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
     * Woher der Kauf kam.
     *
     * Hier wird anders geurteilt als bei `country`, und der Unterschied ist
     * beabsichtigt: ein falscher **Typ** ist ein Programmierfehler und fliegt,
     * ein zu **langer** Wert ist Wirklichkeit und wird gekürzt. UTM-Werte
     * stammen am Ende aus einer URL, die ein Fremder gebaut hat; ein
     * 4000 Zeichen langer `utm_content` ist kein Fehler des Hosts, sondern ein
     * Werkzeug, das Unsinn anhängt. Daran darf kein Kauf scheitern.
     *
     * Leere Werte werden verworfen statt als leerer Text gespeichert. „Kam von
     * nirgendwo" und „wir haben nicht hingesehen" sind dieselbe Aussage, und
     * beide heißen null.
     *
     * @param  array<string, mixed>  $details
     * @return array<string, string>
     */
    private static function attribution(array $details): array
    {
        $values = [];

        foreach (self::ATTRIBUTION as $key => $laenge) {
            $value = $details[$key] ?? null;

            if ($value === null) {
                continue;
            }

            if (! is_string($value)) {
                throw new InvalidArgumentException(sprintf(
                    'statamic-payments: `%s` muss ein Text sein.',
                    $key,
                ));
            }

            $value = trim($value);

            if ($value === '') {
                continue;
            }

            $values[$key] = mb_substr($value, 0, $laenge);
        }

        return $values;
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

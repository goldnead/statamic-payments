<?php

namespace Goldnead\StatamicPayments\Support;

use Goldnead\StatamicPayments\Contracts\FollowUpGateway;
use Goldnead\StatamicPayments\Contracts\PaymentGateway;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\PaymentItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Starts a payment and hands back somewhere to send the buyer.
 *
 * The amount is looked up, never accepted. Everything the buyer supplies is
 * treated as a label on the order, not as a term of it — including *which*
 * things they are buying: a handle that is not in the catalogue does not make
 * the payment smaller, it refuses the checkout.
 */
class Checkout
{
    public function __construct(
        protected PaymentGateway $gateway,
        protected Catalogue $catalogue,
    ) {}

    /**
     * @param  string|list<string>|array<string, int>  $products  One handle, a
     *                                                            list of handles (the first is what the buyer came for, the rest are
     *                                                            bumps), or a map of handle => quantity.
     * @param  array<string, mixed>  $buyer
     * @param  string|null  $returnUrl  Where the provider sends the buyer back
     *                                  to. Defaults to the configured page. A funnel passes its own, because a
     *                                  buyer who lands outside the flow they were walking has been dropped
     *                                  halfway through a purchase.
     * @param  array<string, mixed>|PaymentDetails  $details  Was die aufrufende
     *                                                        Strecke an *diese* Zahlung heften will: `meta`, `country`. Siehe
     *                                                        {@see PaymentDetails}. Steht in derselben Transaktion wie die Zahlung
     *                                                        selbst und damit fest, bevor der Anbieter etwas davon weiß.
     *
     * @throws \InvalidArgumentException wenn $details etwas enthält, das dem Paket gehört
     */
    public function start(string|array $products, array $buyer = [], ?string $returnUrl = null, ?Discount $discount = null, array|PaymentDetails $details = []): ?CheckoutResult
    {
        // Vor allem anderen, damit ein Aufrufer-Fehler folgenlos bleibt: hier
        // ist noch keine Zeile angelegt und kein Anbieter gerufen.
        $details = PaymentDetails::from($details);

        $lines = $this->lines($products);

        if ($lines === []) {
            return null;
        }

        $primary = $lines[0];
        $gross = array_sum(array_map(fn (array $l) => $l['amount_cent'] * $l['quantity'], $lines));

        // Clamped here rather than trusted, even though a Discount is always
        // built by server-side code. A discount bigger than the basket would
        // otherwise become a negative amount, which providers reject with an
        // error the buyer sees and nobody can explain.
        $off = $discount?->against($gross) ?? 0;
        $total = $gross - $off;

        $currency = $primary['currency'];

        // Created before the provider is called, with the id filled in after.
        // The other order — provider first, row second — loses the payment
        // entirely if the process dies in between, and the buyer has by then
        // been charged.
        $payment = DB::transaction(function () use ($lines, $primary, $total, $off, $discount, $currency, $buyer, $details): Payment {
            $payment = Payment::create($details->onto([
                'provider' => $this->gateway->provider(),
                'provider_id' => 'pending-'.Str::uuid(),
                // The primary handle stays on the payment as well as in the
                // lines. It is what a report is grouped by and what the listing
                // shows, and reading it should not mean joining a table.
                'product' => $primary['handle'],
                'amount_cent' => $total,
                'discount_code' => $discount?->code,
                'discount_cent' => $off ?: null,
                'currency' => $currency,
                // Not `open`: until the provider has answered, this is not a
                // payment anybody can make, and every report the host builds
                // over `status = open` would otherwise count it as in flight.
                'status' => Payment::STATUS_INITIATED,
                'email' => $buyer['email'] ?? null,
                'name' => $buyer['name'] ?? null,
                // Eingefroren, nicht verwiesen. Der Steuersatz haengt am Land
                // des Kaeufers zum Zeitpunkt der Leistung, und eine Adresse,
                // die sich spaeter aendert, macht eine alte Rechnung falsch.
                // Fehlt es hier, traegt der Anbieter es spaeter nach.
                'country' => self::country($buyer['country'] ?? null),
                'country_source' => isset($buyer['country']) ? 'checkout' : null,
                // Was der Aufrufer mitgibt, kommt hier dazu und überschreibt
                // nichts von dem, was darüber steht. Das Land des Käufers hat
                // also weiter genau eine Quelle: das Formular, über `$buyer`.
            ]));

            // Der Rabatt, aufgeteilt. Ein Betrag auf der Zahlung reicht nicht:
            // liegen die Zeilen auf verschiedenen Steuersaetzen, ist aus einer
            // Zahl nicht ableitbar, welcher Teil auf welchen Satz faellt, und
            // die Rechnung wird unbestimmt statt erkennbar falsch.
            $anteile = DiscountSplit::across($lines, $off);

            foreach ($lines as $index => $line) {
                PaymentItem::create([
                    'payment_id' => $payment->id,
                    'product' => $line['handle'],
                    // The name as it is today. A product renamed next year must
                    // not change what an old order says was bought.
                    'name' => $line['name'],
                    'amount_cent' => $line['amount_cent'],
                    'quantity' => $line['quantity'],
                    'discount_cent' => $anteile[$index] ?? 0,
                    'kind' => $index === 0 ? PaymentItem::KIND_PRIMARY : PaymentItem::KIND_BUMP,
                ]);
            }

            return $payment;
        });

        // Nothing to charge, so nobody is charged.
        //
        // A provider will not take a payment of zero, and a free offer that
        // failed at the checkout would be the most confusing possible outcome:
        // the buyer was told it costs nothing and then told it did not work.
        // The payment is marked paid on the spot and fulfilment runs, which is
        // the same thing the webhook would have done.
        if ($total <= 0) {
            return $this->free($payment, $returnUrl);
        }

        $payload = [
            'amount' => [
                'currency' => $payment->currency,
                'value' => $payment->amount(),
            ],
            'description' => $this->description($lines),
            'redirectUrl' => $this->safeReturnUrl($returnUrl, $payment),
            'metadata' => [
                'payment_id' => $payment->id,
                'product' => $primary['handle'],
                'email' => $payment->email,
            ],
        ];

        if ($webhook = $this->webhookUrl()) {
            $payload['webhookUrl'] = $webhook;
        }

        // Only if the site asked for it. Asking the provider to remember
        // somebody's payment method is a thing that buyer has to be told about
        // on the checkout page; doing it by default would decide that for every
        // site that installed this addon.
        if ($reference = $this->rememberBuyer($buyer)) {
            $payload['customerId'] = $reference;
            $payload['sequenceType'] = 'first';
            $payment->forceFill(['customer_reference' => $reference])->save();
        }

        $session = $this->gateway->createPayment($payload);

        $payment->forceFill([
            'provider_id' => $session->providerId,
            'status' => Payment::STATUS_OPEN,
        ])->save();

        return new CheckoutResult($payment->fresh() ?? $payment, $session->checkoutUrl);
    }

    /**
     * Where the provider should tell us what happened, or null to not be told.
     *
     * Configurable, and that is not a convenience: a provider checks that the
     * URL is reachable from its own side, so a developer on `localhost` cannot
     * create a payment at all. Refusing every checkout during development is a
     * bad enough experience to be worth a config key.
     *
     * `false` omits it. Then nothing is pushed and the status has to be pulled
     * — which is fine for a demo and wrong for production, so the config comment
     * says so.
     */

    /**
     * Where the provider sends the buyer back to.
     *
     * Checked against this application rather than trusted. No shipped code
     * path feeds this from a request today — funnels builds it from its own
     * graph — so this is not a hole in the addons but a trapdoor for hosts: an
     * application that passes `$request->input('return')` through would build an
     * open redirect, and one with unusually good cover, because it sits behind a
     * real and successful payment.
     *
     * An external URL is dropped rather than refused. The buyer has paid by the
     * time this is read; failing the checkout over a bad return address would
     * take their money and show them an error.
     *
     * The check is `URL::isExternalToApplication()`, the same one Laravel uses
     * for its own redirect safety. The approach is the one
     * `thomasvantuycom/statamic-mollie` takes in `Payments::getRedirectUrl()`
     * (MIT, checked at the repository on 25.08.2026).
     */
    protected function safeReturnUrl(?string $returnUrl, Payment $payment): string
    {
        $fallback = $this->url('return_url', ['payment' => $payment->id]);

        if (! is_string($returnUrl) || trim($returnUrl) === '') {
            return $fallback;
        }

        if ($this->pointsAwayFromHere($returnUrl)) {
            Log::warning('statamic-payments: a return URL pointing away from this site was dropped.', [
                'payment_id' => $payment->getKey(),
            ]);

            return $fallback;
        }

        return $returnUrl;
    }

    /**
     * Does this URL leave the application?
     *
     * A relative path never does. An absolute one has to match the host this
     * site runs on — scheme included, because `//evil.example` is a URL a
     * browser follows and a naive prefix check waves through.
     */
    protected function pointsAwayFromHere(string $url): bool
    {
        $url = trim($url);

        // Protocol-relative: the browser reads `//host/x` as another site.
        if (str_starts_with($url, '//')) {
            return true;
        }

        // Relative path, query or fragment — always this application.
        if (! preg_match('~^[a-zA-Z][a-zA-Z0-9+.-]*:~', $url)) {
            return ! str_starts_with($url, '/') ? true : false;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return true;
        }

        // Zwei zulaessige Quellen fuer "hier": die konfigurierte Adresse und
        // die der laufenden Anfrage. Die zweite, weil eine Installation hinter
        // mehreren Domains liegen kann und ein Kaeufer, der bezahlt hat, nicht
        // auf der falschen Seite landen soll, nur weil app.url eine davon nennt.
        $eigene = array_filter([
            parse_url((string) config('app.url', ''), PHP_URL_HOST),
            request()->getHost(),
        ], fn ($h) => is_string($h) && $h !== '');

        foreach ($eigene as $kandidat) {
            if (strcasecmp($host, $kandidat) === 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Is this quantity one the catalogue allows?
     *
     * Bounds are opt-in: a product that says nothing keeps the behaviour it
     * always had, capped by a generous global limit that exists only so a
     * mistyped or hostile number cannot become a five-figure charge. A product
     * that offers a *variable* quantity — a donation, a pay-what-you-want —
     * declares `min_quantity` and `max_quantity` and gets them enforced.
     *
     * @param  array<string, mixed>  $product
     */
    protected function quantityAllowed(array $product, int $quantity): bool
    {
        $min = isset($product['min_quantity']) ? max(1, (int) $product['min_quantity']) : 1;

        $max = isset($product['max_quantity'])
            ? (int) $product['max_quantity']
            : (int) config('statamic-payments.max_quantity', 1000);

        if ($max < $min) {
            $max = $min;
        }

        return $quantity >= $min && $quantity <= $max;
    }

    /**
     * A country code, or nothing.
     *
     * Two letters, upper case, ISO 3166-1 alpha-2 — the shape every tax rule
     * and every provider speaks. Anything else is dropped rather than stored:
     * a column that sometimes holds "Deutschland", sometimes "DE" and sometimes
     * "de" is a column nobody can compute a rate from, and the wrong rate is
     * worse than a missing one because it looks like an answer.
     */
    private static function country(mixed $wert): ?string
    {
        if (! is_string($wert)) {
            return null;
        }

        $wert = strtoupper(trim($wert));

        return preg_match('/^[A-Z]{2}$/', $wert) === 1 ? $wert : null;
    }

    protected function webhookUrl(): ?string
    {
        $konfiguriert = config('statamic-payments.webhook_url');

        if ($konfiguriert === false) {
            return null;
        }

        if (is_string($konfiguriert) && $konfiguriert !== '') {
            return $konfiguriert;
        }

        return route('statamic-payments.webhook');
    }

    /**
     * A payment of nothing.
     *
     * Deliberately not a shortcut around fulfilment: the same `PaymentPaid`
     * event fires, the same claim is staked, and a listener cannot tell the
     * difference. A free product that skipped the entitlement would be a
     * customer with an empty account and a receipt.
     */
    protected function free(Payment $payment, ?string $returnUrl): CheckoutResult
    {
        $payment->forceFill([
            // Its own provider marker, so a report can tell a free order from a
            // real one without guessing from the amount.
            'provider' => 'free',
            'provider_id' => 'free-'.$payment->id,
        ])->save();

        $payment = app(Fulfilment::class)->fulfilFree($payment->fresh() ?? $payment);

        return new CheckoutResult(
            $payment,
            $this->safeReturnUrl($returnUrl, $payment),
        );
    }

    /**
     * The provider's handle for this buyer, if the site collects mandates.
     *
     * A failure here does not fail the checkout: the buyer is trying to pay for
     * something, and losing that sale because a *later, optional* offer could
     * not be prepared would be the wrong trade.
     *
     * @param  array<string, mixed>  $buyer
     */
    protected function rememberBuyer(array $buyer): ?string
    {
        if (! config('statamic-payments.follow_up.collect_mandate', false)) {
            return null;
        }

        if (! $this->gateway instanceof FollowUpGateway || ! $this->gateway->supportsFollowUp()) {
            return null;
        }

        try {
            $reference = $this->gateway->rememberBuyer($buyer);
        } catch (Throwable $e) {
            Log::warning('statamic-payments: could not remember the buyer; the checkout continues without a mandate.', [
                'exception' => $e->getMessage(),
            ]);

            return null;
        }

        return $reference === '' ? null : $reference;
    }

    /**
     * Turn what the caller asked for into priced lines, or nothing.
     *
     * **All or none.** A bump handle that is not in the catalogue refuses the
     * whole checkout rather than quietly dropping the line: dropping it would
     * charge the buyer for less than the page offered them, and the first
     * anyone hears of it is a customer who paid for two things and got one.
     *
     * @param  string|list<string>|array<string, int>  $products
     * @return list<array<string, mixed>>
     */
    protected function lines(string|array $products): array
    {
        $wanted = [];

        foreach ((array) $products as $key => $value) {
            if (is_int($key)) {
                $handle = (string) $value;
                $quantity = 1;
            } else {
                $handle = (string) $key;
                $quantity = (int) $value;
            }

            if ($handle === '' || $quantity < 1) {
                return [];
            }

            // A handle listed twice is a quantity, not a second line.
            $wanted[$handle] = ($wanted[$handle] ?? 0) + $quantity;
        }

        $lines = [];

        foreach ($wanted as $handle => $quantity) {
            $product = $this->catalogue->find($handle);

            if (! $product) {
                return [];
            }

            // Die Menge ist die einzige Zahl, die aus einem Request stammen
            // darf — und auch nur, weil der Stueckpreis es nicht tut. Damit das
            // traegt, sagt der Katalog, was zulaessig ist: eine Spende ueber 0
            // ist keine, und eine ueber 999999 ist ein Tippfehler oder ein
            // Angriff. Die Grenzen stehen am Produkt und nicht als Parameter an
            // start(), denn ein Parameter waere wieder eine Zahl aus dem
            // Request, nur mit mehr Schritten dazwischen.
            //
            // Ohne Angabe gilt weiter, was immer galt: eine Menge ist erlaubt
            // (drei Hefte sind drei Hefte), gedeckelt durch ein grosszuegiges
            // Netz gegen Unsinn. Ein Produkt, das eine *variable* Menge
            // anbietet, deklariert seine Grenzen und bekommt sie geprueft.
            if (! $this->quantityAllowed($product, $quantity)) {
                return [];
            }

            $lines[] = $product + ['quantity' => $quantity];
        }

        // One currency per payment. A provider is handed a single amount and a
        // single currency; two currencies in one order is not a rounding
        // problem, it is a wrong charge.
        $currencies = array_unique(array_column($lines, 'currency'));

        return count($currencies) === 1 ? $lines : [];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    protected function description(array $lines): string
    {
        $names = array_map(fn (array $l) => $l['name'], $lines);

        // What the buyer will read on their bank statement.
        return count($names) === 1
            ? $names[0]
            : $names[0].' + '.(count($names) - 1);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    protected function url(string $key, array $query = []): string
    {
        $configured = (string) config('statamic-payments.'.$key, '/');

        return url($configured).($query === [] ? '' : '?'.http_build_query($query));
    }
}

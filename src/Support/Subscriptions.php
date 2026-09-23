<?php

namespace Goldnead\StatamicPayments\Support;

use Goldnead\StatamicPayments\Contracts\PaymentGateway;
use Goldnead\StatamicPayments\Contracts\SubscriptionGateway;
use Goldnead\StatamicPayments\Contracts\UpdatesSubscriptions;
use Goldnead\StatamicPayments\Events\SubscriptionCancelled;
use Goldnead\StatamicPayments\Events\SubscriptionEnded;
use Goldnead\StatamicPayments\Events\SubscriptionPlanCompleted;
use Goldnead\StatamicPayments\Events\SubscriptionRenewed;
use Goldnead\StatamicPayments\Events\SubscriptionStarted;
use Goldnead\StatamicPayments\Events\SubscriptionStartFailed;
use Goldnead\StatamicPayments\Models\Cancellation;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Subscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Starting, stopping and following an agreement to be charged again.
 *
 * The awkward truth this class has to live with: **a subscription needs a
 * mandate, and a mandate needs a payment.** No provider will store a card
 * because a page asked nicely. So starting one is two steps, in this order:
 *
 * 1. An ordinary checkout, with the buyer attached to the provider and marked
 *    as a first payment. The buyer sees a normal payment page.
 * 2. When *that* payment is confirmed paid — by the webhook, never by the
 *    browser coming back — the subscription is created against the mandate it
 *    left behind.
 *
 * Doing it the other way round, creating the agreement first and hoping the
 * payment lands, produces subscriptions with no mandate that the provider will
 * refuse forever, silently, on a rhythm.
 *
 * The first payment's **amount is the trade a site has to make**, and this
 * package refuses to make it for them. On Mollie there is no way to store a card
 * without charging something: no SetupIntent, no zero-amount authorisation. So a
 * free trial is one of two things, and the config says which:
 *
 * - a small charge now (`trial_amount_cent`), the card on file, the trial real
 *   for everything after it; or
 * - no charge and no card, which means the buyer has to come back and pay by
 *   hand — honest, and most of them will not.
 *
 * Hiding that behind the word "trial" would be the wrong kind of convenience.
 */
class Subscriptions
{
    public function __construct(
        protected PaymentGateway $gateway,
        protected Catalogue $catalogue,
        protected Checkout $checkout,
    ) {}

    protected ?string $refusal = null;

    /**
     * Why the last `start()` was refused at the door, in words for the page;
     * see `Checkout::refusal()`.
     */
    public function refusal(): ?string
    {
        return $this->refusal ?? $this->checkout->refusal();
    }

    /** Whether this site can run subscriptions at all. */
    public function available(): bool
    {
        return $this->gateway instanceof SubscriptionGateway
            && $this->gateway->supportsSubscriptions();
    }

    /**
     * The provider that speaks for this particular agreement.
     *
     * Read off the row's own `provider` column rather than taken from the
     * binding. On a site with one provider the two are the same value; on a
     * site with two, asking the wrong one about an agreement id gets "no such
     * agreement" and this class would then write that down as the truth.
     *
     * Returns null where the resolved provider cannot run agreements at all —
     * the caller then leaves the row alone, which is what it already did when
     * the site had no subscription provider.
     */
    protected function agreementGateway(Payment|Subscription $row): ?SubscriptionGateway
    {
        return $this->asAgreementGateway(app(Gateways::class)->for($row));
    }

    /**
     * The provider behind one agreement, for the classes that change a running
     * one — pausing, switching, replacing. Same rule as everywhere here: read
     * off the row, never off the binding.
     */
    public function gatewayFor(Subscription $subscription): ?SubscriptionGateway
    {
        return $this->agreementGateway($subscription);
    }

    /**
     * What a provider is handed to start this agreement (again), from a date.
     *
     * Used where an agreement is re-created rather than created: resuming a
     * pause on Mollie, and switching the amount on a provider that cannot
     * change one in place. The amount, rhythm and remaining count come from the
     * row, the name from the catalogue, the webhook from configuration — the
     * same four sources as a first start.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function agreementPayload(Subscription $subscription, Carbon $startDate, array $metadata = []): array
    {
        $catalogue = $this->catalogue->find($subscription->product) ?? [];

        return array_filter([
            // What is charged now, a running coupon included: a pause on Mollie
            // must not quietly end a discount the buyer was promised.
            'amount' => [
                'currency' => $subscription->currency,
                'value' => $subscription->chargedAmount(),
            ],
            'interval' => $subscription->interval,
            'times' => $subscription->remaining(),
            'startDate' => $startDate->toDateString(),
            'description' => (string) ($catalogue['name'] ?? $subscription->product),
            'webhookUrl' => config('statamic-payments.webhook_url') === false
                ? null
                : (config('statamic-payments.webhook_url') ?: route('statamic-payments.webhook')),
            'metadata' => $metadata + ['product' => $subscription->product],
        ], fn ($v) => $v !== null);
    }

    /** The site's own provider, for an agreement that has no row to read it off yet. */
    protected function defaultAgreementGateway(): ?SubscriptionGateway
    {
        return $this->asAgreementGateway($this->gateway);
    }

    protected function asAgreementGateway(PaymentGateway $gateway): ?SubscriptionGateway
    {
        return $gateway instanceof SubscriptionGateway && $gateway->supportsSubscriptions()
            ? $gateway
            : null;
    }

    /**
     * What a product says about its rhythm, or null if it has none.
     *
     * @return array{interval: string, times: int|null, trial_days: int, trial_amount_cent: int|null}|null
     */
    public function planFor(string $handle): ?array
    {
        $product = $this->catalogue->find($handle);

        if (! $product) {
            return null;
        }

        $interval = $product['interval'] ?? null;

        if (! is_string($interval) || trim($interval) === '') {
            return null;
        }

        $times = $product['times'] ?? null;

        return [
            'interval' => trim($interval),
            // Zero would mean "charge nothing, ever", which is a typo rather
            // than an instruction.
            'times' => is_int($times) && $times > 0 ? $times : null,
            'trial_days' => max(0, (int) ($product['trial_days'] ?? 0)),
            // Null means "the ordinary amount". A site wanting a cheap trial
            // says so with a number, in minor units, and knows it is charging.
            'trial_amount_cent' => isset($product['trial_amount_cent']) && is_int($product['trial_amount_cent'])
                ? max(0, $product['trial_amount_cent'])
                : null,
        ];
    }

    /**
     * Ob dieser Betrieb überhaupt eine Vereinbarung beginnen kann.
     *
     * Drei Bedingungen, die nichts miteinander zu tun haben: der Anbieter muss
     * Abrechnungen auf Wiedervorlage können, der Betrieb muss sich die
     * Zahlungsart des Käufers merken dürfen, und unter den angebotenen
     * Zahlarten muss wenigstens eine sein, die dabei ein Mandat hinterlässt.
     * Alle drei sind ohne einen Kauf feststellbar — und genau dafür ist diese
     * Methode da. Eine aufrufende Strecke, die eine Ratenoption **anzeigt**,
     * muss vorher wissen, ob sie sie auch einlösen kann; das erst im `start()`
     * als `null` herauszufinden ist eine Sackgasse mitten in der Kasse.
     *
     * **Die dritte Bedingung war die stille.** Ein Betrieb, der nur Klarna und
     * Überweisung freigeschaltet hat, kam bis hierher durch: `available()` sagt
     * ja, das Mandat ist erlaubt, und dann setzt {@see Checkout::start()} kein
     * `sequenceType: first`, weil keine der Methoden eines tragen kann. Die
     * erste Rate floss, die Absicht hing an der Zahlung, und der Webhook fand
     * kein Mandat, aus dem er eine Vereinbarung hätte bauen können. Bei
     * „3 × 520 €" also einmal 520 € und zwei Raten, die nirgends standen.
     *
     * Anbieterneutral, weil die Frage es ist: gefragt sind die **konfigurierten
     * Zahlarten**, nicht wer sie abwickelt. Eine leere Liste heißt „der Anbieter
     * entscheidet", und der zeigt bei einer ersten Zahlung von selbst nur, was
     * ein Mandat kann — also bleibt sie ein Ja.
     */
    public function canStart(): bool
    {
        return $this->available()
            && (bool) config('statamic-payments.follow_up.collect_mandate', false)
            && PaymentMethods::canHoldMandate(PaymentMethods::configured());
    }

    /**
     * Wie eine Position heißt, die nur einen Teil des Ganzen abrechnet.
     *
     * Ohne diesen Zusatz stehen auf drei Rechnungen dreimal derselbe Satz und
     * derselbe Betrag, und weder Käufer noch Buchhaltung sehen, dass es
     * dieselbe Leistung war. Der Zusatz ändert nichts an der Rechnung selbst:
     * abgerechnet wird weiter der Betrag **dieser** Zahlung, weil § 14 UStG den
     * Betrag der abgerechneten Leistung will und das die Rate ist. Was hier
     * dazukommt, ist die Zuordnung, nicht der Betrag.
     *
     * **Statisch, weil zwei sehr verschiedene Stellen dieselbe Zeile brauchen**
     * und keine von beiden ein `Subscriptions` in der Hand hat: die erste
     * Zahlung entsteht in {@see Checkout::start()}, mitten in einer
     * Transaktion, und ein Zyklus in {@see Fulfilment::openCycle()}, im Webhook
     * des Anbieters. Zweimal getippt wäre es zweimal anders.
     *
     * Ein Rhythmus **ohne** feste Anzahl ist kein Ratenkauf, sondern ein Abo.
     * Dort gibt es keine Gesamtsumme, die man nennen könnte — sie steht erst
     * fest, wenn gekündigt wird. Also nennt die Zeile den Takt.
     *
     * @param  string  $name  Der Produktname, wie der Katalog ihn heute schreibt.
     * @param  string  $interval  Der Rhythmus, in der Schreibweise des Anbieters („1 month").
     * @param  int|null  $times  Wie viele Zahlungen es insgesamt sind, null bei einem Abo ohne Ende.
     * @param  int  $number  Die wievielte Zahlung diese ist, bei 1 beginnend.
     * @param  int  $amountCent  Was eine einzelne Zahlung kostet.
     */
    public static function lineLabel(
        string $name,
        string $interval,
        ?int $times,
        int $number,
        int $amountCent,
        ?string $currency,
    ): string {
        if ($times === null || $times < 2) {
            return __('statamic-payments::messages.invoice_line_subscription', [
                'name' => $name,
                'interval' => self::intervalLabel($interval),
            ]);
        }

        return __('statamic-payments::messages.invoice_line_installment', [
            'name' => $name,
            'number' => $number,
            'times' => $times,
            'total' => Money::display($times * $amountCent, $currency),
        ]);
    }

    /**
     * Der Rhythmus in Worten, oder unverändert, wenn es dafür keine gibt.
     *
     * Mollie nimmt „1 month", „3 months", „1 year" und einiges dazwischen. Für
     * die geläufigen steht eine Übersetzung bereit; alles andere geht so durch,
     * wie der Anbieter es schreibt. Das ist hässlicher als eine erfundene
     * Formulierung und dafür nie falsch.
     */
    protected static function intervalLabel(string $interval): string
    {
        $key = 'statamic-payments::messages.interval_'.trim($interval);
        $wort = __($key);

        return is_string($wort) && $wort !== $key ? $wort : trim($interval);
    }

    /**
     * Begin one. Hands back the checkout for the first payment.
     *
     * Null when the product is not recurring, the provider cannot do it, or the
     * site has not turned mandate collection on — a subscription without a
     * stored card is not a subscription.
     *
     * **Ein Korb ist erlaubt, ein Plan darin nicht zweimal.** Wer eine Liste
     * übergibt, kauft die ganze Liste in der ersten Zahlung; den Rhythmus gibt
     * allein der **erste** Handle vor, und die Folgeeinzüge belasten nur dessen
     * Betrag (siehe {@see startFromPayment()}, das den Betrag aus dem Katalog
     * dieses einen Produkts nimmt). Damit ist ein Bump neben einer Ratenoption
     * genau das, was er sein soll: einmal bezahlt, nicht jede Rate wieder. Ohne
     * diesen Weg musste eine Strecke mit Korb an `Checkout::start()` vorbei —
     * und die Absicht kann sie dort nicht selbst anheften, `subscription_intent`
     * steht in {@see PaymentDetails::RESERVED_META}.
     *
     * **Gutschein und Testzeitraum können nicht beide gelten.** `Checkout` nimmt
     * einen `Discount`, und der Testzeitraum hat Vorrang: er ist der Preis, den
     * der Käufer auf der Seite gesehen hat.
     *
     * @param  string|list<string>  $products
     * @param  array<string, mixed>  $buyer
     * @param  array<string, mixed>|PaymentDetails  $details  Was die aufrufende
     *                                                        Strecke an die erste Zahlung heften will. Siehe
     *                                                        {@see PaymentDetails}.
     *
     * @throws \InvalidArgumentException wenn $details etwas enthält, das dem Paket gehört
     */
    public function start(string|array $products, array $buyer = [], ?string $returnUrl = null, array|PaymentDetails $details = [], ?Discount $discount = null): ?CheckoutResult
    {
        $details = PaymentDetails::from($details);

        // Der Rhythmus hängt am ersten Handle, nicht am Korb. Alles Weitere
        // darin ist Beiwerk der ersten Zahlung.
        $product = is_array($products)
            ? (string) ($products[0] ?? '')
            : $products;

        $plan = $this->planFor($product);

        if (! $plan) {
            return null;
        }

        // Die Länderregel (statamic-offers O3), vor allem anderen. Die Kasse
        // fragt sie auch, hier ist sie billiger: noch ist nichts vorbereitet.
        $this->refusal = null;

        if (! Checkout::soldIn(array_map('strval', (array) $products), $buyer['country'] ?? null)) {
            $this->refusal = CheckoutGuard::message('country');

            return null;
        }

        // **Eine Regel, nicht zwei.** Was `canStart()` sagt, gilt hier auch:
        // stünde die Prüfung zweimal getippt da, hätte die eine Stelle die
        // Zahlarten irgendwann gelernt und die andere nicht — und die
        // Abweichung wäre genau der stille Kauf, den beide verhindern sollen.
        if (! $this->canStart()) {
            Log::warning('statamic-payments: a subscription was asked for while this site cannot start one; nothing was started.', [
                'product' => $product,
                'provider_can_do_agreements' => $this->available(),
                'collects_mandate' => (bool) config('statamic-payments.follow_up.collect_mandate', false),
                'methods' => PaymentMethods::configured(),
            ]);

            return null;
        }

        // The first payment. An ordinary checkout in every respect except that
        // it carries the intention: what it establishes is the agreement, and
        // the row it leaves behind is what the webhook later turns into one.
        //
        // Die Absicht geht **in** den Checkout hinein und wird nicht danach
        // nachgetragen. Nachgetragen war sie zweimal zu spät: der Anbieter war
        // gerufen, bevor sie in der Datenbank stand, und bei einem Testzeitraum
        // ohne Betrag ist die Zahlung noch innerhalb von `start()` erfüllt —
        // `startFromPayment()` sah dann kein `subscription_intent`, tat nichts,
        // und niemand erfuhr, dass ein bezahltes Abo keines wurde.
        return $this->checkout->start(
            $products,
            $buyer,
            $returnUrl,
            $this->trialDiscount($product, $plan) ?? $discount,
            $details->plus([
                'subscription_intent' => [
                    'product' => $product,
                    'interval' => $plan['interval'],
                    'times' => $plan['times'],
                    'trial_days' => $plan['trial_days'],
                ],
            ]),
        );
    }

    /**
     * The difference between the ordinary price and what a trial charges today.
     *
     * Expressed as a `Discount` rather than a second amount, so the payment row
     * still says what the thing costs and what came off it — a receipt for
     * "1 € instead of 19 €" that only records the 1 € loses the reason.
     *
     * **A trial and a coupon cannot both apply today.** `Checkout::start()` takes
     * one `Discount`, and the trial takes it. That is a real limitation rather
     * than an oversight: two reductions on one line need a rule about which
     * comes off first, and inventing that rule quietly is how a receipt ends up
     * saying something nobody can reproduce.
     *
     * @param  array{interval: string, times: int|null, trial_days: int, trial_amount_cent: int|null}  $plan
     */
    protected function trialDiscount(string $product, array $plan): ?Discount
    {
        if ($plan['trial_days'] === 0 || $plan['trial_amount_cent'] === null) {
            return null;
        }

        $full = $this->catalogue->find($product)['amount_cent'] ?? null;

        if (! is_int($full) || $plan['trial_amount_cent'] >= $full) {
            return null;
        }

        return new Discount(
            code: 'trial',
            amountCent: $full - $plan['trial_amount_cent'],
            label: __('statamic-payments::messages.trial_discount', ['days' => $plan['trial_days']]),
        );
    }

    /**
     * Turn a paid first payment into a running agreement.
     *
     * Called from the fulfilment path, so it happens exactly once per payment
     * and only after the provider confirmed the money. A failure here does not
     * throw: the buyer paid, the row says so, and an agreement that could not
     * be created is a thing to fix rather than a reason to unwind a sale and
     * make the provider redeliver.
     */
    public function startFromPayment(Payment $payment, int $deferDays = 0): ?Subscription
    {
        $intent = $payment->meta['subscription_intent'] ?? null;

        if (! is_array($intent)) {
            return null;
        }

        // The intention outlives the conditions it was made under: a provider
        // swapped, a config switched off between the checkout and the webhook.
        // Money was taken for a subscription either way, so it is said out loud
        // rather than returned as a quiet null.
        // The provider of the payment that started it, not the container's
        // default: this runs inside a webhook, where the first payment's own
        // row is the only thing that knows who took the money.
        //
        // With one exception, and it is not a special case so much as an absence
        // of information: a zero-price first payment was settled by this package
        // without a provider at all, so `free` says nothing about who will
        // charge the cycles after it. That is the site's own provider.
        $gateway = $payment->provider === 'free'
            ? $this->defaultAgreementGateway()
            : $this->agreementGateway($payment);

        if (! $gateway) {
            return $this->startFailed($payment, 'this provider cannot run subscriptions');
        }

        if ($payment->subscription_id) {
            return Subscription::find($payment->subscription_id);
        }

        if (! $payment->customer_reference) {
            Log::error('statamic-payments: a first payment for a subscription left no mandate behind; no agreement was created.', [
                'payment_id' => $payment->getKey(),
                'product' => $intent['product'] ?? null,
            ]);

            return null;
        }

        $product = (string) ($intent['product'] ?? $payment->product);
        $plan = $this->planFor($product);

        if (! $plan) {
            return null;
        }

        $catalogue = $this->catalogue->find($product) ?? [];

        // The first cycle is already paid, so the provider's rhythm starts one
        // interval later — or after the trial, when there is one.
        $startsAt = $plan['trial_days'] > 0
            ? Carbon::now()->addDays($plan['trial_days'])
            : $this->afterOneInterval($plan['interval']);

        // Credit from an agreement this purchase replaces, as later start.
        // See {@see SubscriptionReplacements}.
        if ($deferDays > 0) {
            $startsAt = $startsAt->copy()->addDays($deferDays);
        }

        // A plan of N instalments has already taken one. Asking the provider
        // for N more would charge N+1 in total, which is the kind of arithmetic
        // that ends up in a chargeback.
        $remaining = $plan['times'] === null ? null : max(0, $plan['times'] - 1);

        if ($remaining === 0) {
            // A one-instalment plan is a single payment wearing a costume.
            return null;
        }

        // The row first, committed, *then* the provider. This package says so
        // twice already — `Checkout::start()` and `FollowUp::accept()` both do
        // it — and doing the opposite here would be the one place where the
        // loss repeats: a provider-side agreement with no local row charges
        // somebody every month, forever, and nothing on this site ever hears
        // about it, because a cycle for an unknown agreement is indistinguishable
        // from a stray webhook.
        //
        // So: no transaction around the remote call. A row in `initiated` with
        // no provider id is a thing to notice and repair. A subscription at the
        // provider with no row is not.
        $subscription = Subscription::create([
            // Die Marke des verkauften Angebots, sonst das Erbe der ersten
            // Zahlung. Siehe {@see Brands::forCatalogueEntry()} — dieselbe
            // Regel wie bei einer Folgezahlung, und derselbe Code.
            //
            // **Hier wiegt sie schwerer als dort.** Ein falsch gestempeltes
            // Upsell ist eine Zeile; die Marke einer Vereinbarung steht fuer
            // deren ganze Laufzeit fest und haengt an jedem Zyklus, jeder
            // Rechnung und der Sichtbarkeit im Portal, bis jemand sie von Hand
            // umtraegt. Der Katalogeintrag stand die ganze Zeit schon geladen
            // da; gelesen wurde er nur nicht.
            'brand_id' => Brands::forCatalogueEntry($catalogue, $payment, Brands::FOR_SUBSCRIPTION),
            // The provider that will actually charge the cycles — the same one
            // resolved above, so a free first payment leaves an agreement
            // stamped with the site's provider rather than with `free`.
            'provider' => $gateway->provider(),
            // Unique per payment, so a redelivery cannot make a second.
            'provider_id' => Payment::PLACEHOLDER_PROVIDER_PREFIX.$payment->getKey(),
            'customer_reference' => $payment->customer_reference,
            'product' => $product,
            'amount_cent' => (int) ($catalogue['amount_cent'] ?? $payment->amount_cent),
            'currency' => (string) ($catalogue['currency'] ?? $payment->currency),
            'interval' => $plan['interval'],
            'times' => $remaining,
            'times_charged' => 0,
            'status' => Subscription::STATUS_INITIATED,
            'starts_at' => $startsAt,
            'email' => $payment->email,
            'name' => $payment->name,
            'meta' => $this->couponFor($payment, (int) ($catalogue['amount_cent'] ?? $payment->amount_cent), (string) ($catalogue['currency'] ?? $payment->currency)),
        ]);

        try {
            $remote = $gateway->createSubscription($payment->customer_reference, array_filter([
                // A running coupon (statamic-offers O6) lowers what the
                // provider charges from the start; `recordCycle()` puts the
                // full price back once it runs out.
                'amount' => [
                    'currency' => $subscription->currency,
                    'value' => $subscription->chargedAmount(),
                ],
                'interval' => $subscription->interval,
                'times' => $remaining,
                'startDate' => $startsAt->toDateString(),
                'description' => (string) ($catalogue['name'] ?? $product),
                'webhookUrl' => config('statamic-payments.webhook_url') === false
                    ? null
                    : (config('statamic-payments.webhook_url') ?: route('statamic-payments.webhook')),
                'metadata' => [
                    'product' => $product,
                    'first_payment_id' => $payment->getKey(),
                ],
            ], fn ($v) => $v !== null));
        } catch (Throwable $e) {
            $subscription->delete();

            return $this->startFailed($payment, $e->getMessage());
        }

        $subscription->forceFill([
            'provider_id' => $remote->providerId,
            'status' => $remote->status,
            'next_payment_at' => $remote->nextPaymentAt ? Carbon::parse($remote->nextPaymentAt) : $startsAt,
        ])->save();

        // The first payment belongs to the agreement it created, so a report
        // over one subscription shows what was actually paid for it rather than
        // starting at cycle two.
        $payment->forceFill(['subscription_id' => $subscription->getKey()])->save();

        $subscription = $subscription->fresh() ?? $subscription;

        // Outside everything above. A listener that throws must not be able to
        // undo an agreement the provider has already accepted.
        try {
            SubscriptionStarted::dispatch($subscription, $payment->fresh() ?? $payment);
        } catch (Throwable $e) {
            Log::error('statamic-payments: a listener threw on a subscription that was created anyway.', [
                'subscription_id' => $subscription->getKey(),
                'exception' => $e->getMessage(),
            ]);
        }

        return $subscription;
    }

    /**
     * The coupon of the first payment, as the agreement keeps it (O6).
     *
     * `meta.coupon` on the first payment is what statamic-offers froze at the
     * till (`Basket::paymentMeta()`): code, percent or amount_cent, currency,
     * duration (`once`, `repeating`, `forever`) and cycles. The agreement keeps
     * those terms plus what it takes off the next charge right now. The first
     * charge the provider makes is payment number 2.
     *
     * @return array<string, mixed>|null
     */
    protected function couponFor(Payment $payment, int $amountCent, string $currency): ?array
    {
        $terms = is_array($payment->meta) ? ($payment->meta['coupon'] ?? null) : null;

        if (! is_array($terms) || ! is_string($terms['code'] ?? null)) {
            return null;
        }

        return ['coupon' => $terms + [
            'current_discount_cent' => self::recurringDiscount($terms, 2, $amountCent, $currency),
        ]];
    }

    /**
     * What a coupon takes off payment number `$n` of an agreement.
     *
     * Asked of statamic-offers where it is installed, which owns the rule;
     * nothing without it. `class_exists` on the name and `method_exists` on the
     * class, the family's rule for an optional sibling.
     *
     * @param  array<string, mixed>  $terms
     */
    public static function recurringDiscount(array $terms, int $n, int $amountCent, ?string $currency): int
    {
        $offers = Checkout::OFFERS;

        if (! class_exists($offers) || ! is_callable([$offers, 'recurringDiscountCent'])) {
            return 0;
        }

        try {
            return max(0, (int) $offers::recurringDiscountCent($terms, $n, $amountCent, $currency));
        } catch (Throwable $e) {
            Log::warning('statamic-payments: statamic-offers would not say what a coupon takes off a later charge; the full price applies.', [
                'code' => $terms['code'] ?? null,
                'exception' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * After a cycle: does the next charge cost something else than this one?
     *
     * A repeating coupon runs out; then the provider has to be told the full
     * price. Through `UpdatesSubscriptions` where it can (Stripe, Mollie), and
     * otherwise by ending the agreement and starting it again on the same day.
     * A refusal is logged and tried again after the next cycle; it never
     * undoes the cycle that was just paid.
     */
    protected function followCoupon(Subscription $subscription): void
    {
        $coupon = is_array($subscription->meta) ? ($subscription->meta['coupon'] ?? null) : null;

        if (! is_array($coupon) || isset($coupon['ended']) || ! $subscription->isLive()) {
            return;
        }

        $next = ((int) $subscription->times_charged) + 2;
        $off = self::recurringDiscount($coupon, $next, (int) $subscription->amount_cent, $subscription->currency);

        if ($off === (int) ($coupon['current_discount_cent'] ?? 0)) {
            return;
        }

        $target = max(0, (int) $subscription->amount_cent - $off);

        if (! $this->reprice($subscription, $target)) {
            Log::error('statamic-payments: a coupon ran out and the provider would not take the new amount; it is tried again after the next charge.', [
                'subscription_id' => $subscription->getKey(),
                'amount_cent' => $target,
            ]);

            return;
        }

        $meta = ($subscription->fresh() ?? $subscription)->meta ?? [];
        $meta['coupon']['current_discount_cent'] = $off;
        $subscription->forceFill(['meta' => $meta])->save();
    }

    /**
     * Charge this agreement a different amount from its next cycle on.
     */
    public function reprice(Subscription $subscription, int $cent): bool
    {
        $gateway = $this->agreementGateway($subscription);

        if ($gateway === null) {
            return false;
        }

        $catalogue = $this->catalogue->find($subscription->product) ?? [];
        $amount = ['currency' => $subscription->currency, 'value' => Money::format($cent, $subscription->currency)];

        try {
            if ($gateway instanceof UpdatesSubscriptions) {
                $gateway->updateSubscription($subscription->customer_reference, $subscription->provider_id, [
                    'amount' => $amount,
                    'description' => (string) ($catalogue['name'] ?? $subscription->product),
                ]);

                return true;
            }

            $old = $gateway->cancelSubscription($subscription->customer_reference, $subscription->provider_id);

            if ($old->isLive()) {
                return false;
            }

            $payload = $this->agreementPayload($subscription, $subscription->next_payment_at ?? Carbon::tomorrow());
            $payload['amount'] = $amount;
            $remote = $gateway->createSubscription($subscription->customer_reference, $payload);

            $subscription->forceFill(['provider_id' => $remote->providerId])->save();

            return true;
        } catch (Throwable $e) {
            Log::warning('statamic-payments: the provider would not change the amount of an agreement.', [
                'subscription_id' => $subscription->getKey(),
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Whether a natively paused agreement is running again at the provider,
     * and if so, the row made to say so (`SubscriptionPauses::adoptProviderResume()`).
     */
    protected function resumedByProvider(Subscription $subscription): bool
    {
        if (data_get($subscription->meta, 'pause.mode') !== 'native') {
            return false;
        }

        $gateway = $this->agreementGateway($subscription);

        if ($gateway === null) {
            return false;
        }

        try {
            $remote = $gateway->fetchSubscription($subscription->customer_reference, $subscription->provider_id);
        } catch (Throwable $e) {
            Log::warning('statamic-payments: the provider would not say whether a paused agreement runs again.', [
                'subscription_id' => $subscription->getKey(),
                'exception' => $e->getMessage(),
            ]);

            return false;
        }

        return $remote->isLive() && app(SubscriptionPauses::class)->adoptProviderResume($subscription, $remote);
    }

    /**
     * A paid cycle on a paused row: counted, the paid period carried into the
     * resume date, the access renewed to it.
     */
    protected function countDuringPause(Subscription $subscription, Payment $payment): Subscription
    {
        $meta = $subscription->meta ?? [];
        $pause = is_array($meta['pause'] ?? null) ? $meta['pause'] : [];
        $anchor = $pause['anchor'] ?? $pause['next_payment_at'] ?? null;

        if (is_string($anchor)) {
            $paid = ((int) ($pause['paid_during'] ?? 0)) + 1;
            $pause['anchor'] = $anchor;
            $pause['paid_during'] = $paid;
            $pause['next_payment_at'] = Subscription::addIntervals(Carbon::parse($anchor), (string) $subscription->interval, $paid)->toIso8601String();
            $meta['pause'] = $pause;
        }

        $subscription->forceFill([
            'times_charged' => ((int) $subscription->times_charged) + 1,
            'meta' => $meta,
        ])->save();

        $subscription = $subscription->fresh() ?? $subscription;

        Log::info('statamic-payments: a charge settled during a pause and was counted.', [
            'subscription_id' => $subscription->getKey(),
            'payment_id' => $payment->getKey(),
        ]);

        SubscriptionRenewed::dispatch($subscription, $payment);

        return $subscription;
    }

    /**
     * A first payment that was taken and an agreement that was not created.
     *
     * The customer paid. Not saying so anywhere would leave the only trace in a
     * log file nobody reads, and `subscription_intent` sitting in `meta` looking
     * exactly like one that is still to be processed. So the payment is marked,
     * and an event goes out for anybody who wants to be told.
     */
    protected function startFailed(Payment $payment, string $why): null
    {
        $payment->forceFill([
            'meta' => array_merge($payment->meta ?? [], [
                'subscription_start_failed_at' => now()->toIso8601String(),
                'subscription_start_error' => mb_substr($why, 0, 500),
            ]),
        ])->save();

        Log::error('statamic-payments: the first payment was taken and the agreement was not created.', [
            'payment_id' => $payment->getKey(),
            'reason' => $why,
        ]);

        SubscriptionStartFailed::dispatch($payment->fresh() ?? $payment, $why);

        return null;
    }

    /**
     * Note a cycle that the provider charged on its own.
     *
     * Every cycle after the first arrives as a plain payment with the
     * subscription's id on it. The payment fulfils through the ordinary path —
     * same claim, same event, so an entitlement is granted or extended per cycle
     * without this class knowing anything about entitlements.
     */
    /**
     * @param  array<string, mixed>  $metadata  the payment's metadata at the provider
     */
    public function recordCycle(Payment $payment, string $providerSubscriptionId, array $metadata = []): ?Subscription
    {
        // Scoped to the provider of the payment that carried this cycle, not to
        // whatever the container happens to bind. A cycle id from one provider
        // must not be able to match an agreement row from another.
        //
        // The row's earlier ids count too: a debit started on an agreement a
        // pause or switch ended can settle after it. And an agreement the row
        // never learned the id of is found by the row id in its metadata
        // (Subscription::forCycle()).
        $subscription = Subscription::forCycle((string) $payment->provider, $providerSubscriptionId, $metadata);

        if (! $subscription) {
            Log::warning('statamic-payments: a cycle arrived for an agreement this site does not know.', [
                'provider_subscription_id' => $providerSubscriptionId,
                'payment_id' => $payment->getKey(),
            ]);

            return null;
        }

        // Claimed with a conditional update rather than read-then-write: a
        // redelivered webhook must not count the same cycle twice, and the
        // count is what decides when a payment plan is finished.
        $counted = Payment::query()
            ->whereKey($payment->getKey())
            ->whereNull('subscription_id')
            ->update(['subscription_id' => $subscription->getKey(), 'updated_at' => now()]);

        if ($counted === 0) {
            return $subscription;
        }

        // Money during a pause is money. Two ways it arrives, and neither may
        // be thrown away (Gauntlet 23.09.2026):
        if ($subscription->isPaused()) {
            // Stripe lifted the pause by itself on `resumes_at` and charged.
            // The row follows the provider, then the cycle counts as usual.
            if ($this->resumedByProvider($subscription)) {
                $subscription = $subscription->fresh() ?? $subscription;
            } else {
                // A direct debit on the agreement the pause ended (Mollie)
                // settled late. It pays one more period: counted, and the
                // day the pause will resume on moves one period on.
                return $this->countDuringPause($subscription, $payment);
            }
        }

        // A straggler for an agreement that is already over must not count.
        // Without this a late cycle on a finished plan fires `SubscriptionEnded`
        // a second time and overwrites the date it ended.
        if (! $subscription->isLive() && ! $subscription->isClaimed()) {
            Log::warning('statamic-payments: a cycle arrived for an agreement that is already over.', [
                'subscription_id' => $subscription->getKey(),
                'status' => $subscription->status,
                'payment_id' => $payment->getKey(),
            ]);

            return $subscription;
        }

        // A row in a claim (pausing, resuming, switching, cancelling) is being
        // changed right now, or was by a process that died. The money is real
        // either way: counted and announced, and nothing else is touched, so
        // the claim's own ending writes the status.
        if ($subscription->isClaimed()) {
            // The next date is the provider's, and a charge just moved it on.
            // Only the date: the status belongs to the claim. Written past
            // Eloquent's timestamps on purpose: `updated_at` is the clock that
            // tells the sweep a claim was left behind, and a charge arriving is
            // not the claim making progress.
            $next = $this->nextDateFromProvider($subscription);

            Subscription::query()->toBase()->where('id', $subscription->getKey())->update(array_filter([
                'times_charged' => DB::raw('times_charged + 1'),
                'next_payment_at' => $next?->copy()->setTimezone((string) config('app.timezone', 'UTC'))->format('Y-m-d H:i:s'),
            ], fn ($v) => $v !== null));

            $subscription = $subscription->fresh() ?? $subscription;

            SubscriptionRenewed::dispatch($subscription, $payment);

            return $subscription;
        }

        $subscription->increment('times_charged');
        $subscription = $subscription->fresh() ?? $subscription;

        // What the provider says about the agreement itself, taken while we are
        // already talking to it. Without this a suspension after failed charges
        // never reaches the row, and the screen keeps saying "active" for
        // somebody whose card stopped working.
        $this->refresh($subscription);
        $subscription = $subscription->fresh() ?? $subscription;

        SubscriptionRenewed::dispatch($subscription, $payment);

        // The next charge may cost something else: a coupon that covered this
        // one may not cover the next (statamic-offers O6).
        $this->followCoupon($subscription);
        $subscription = $subscription->fresh() ?? $subscription;

        // A plan that has paid its last instalment is over. The provider stops
        // by itself; this is about the row saying so, so a report does not show
        // a finished plan as still running.
        if ($subscription->times !== null && $subscription->times_charged >= $subscription->times) {
            $subscription->forceFill([
                'status' => Subscription::STATUS_COMPLETED,
                'ended_at' => now(),
                'next_payment_at' => null,
            ])->save();

            SubscriptionEnded::dispatch($subscription->fresh() ?? $subscription);
            SubscriptionPlanCompleted::dispatch($subscription->fresh() ?? $subscription, $payment);
        }

        return $subscription;
    }

    /**
     * Ask the provider what this agreement's state really is, and write it down.
     *
     * The rule the whole package rests on, applied to agreements and not only to
     * payments: the status comes from the provider. Quiet on failure — a
     * provider that will not answer right now is not a reason to change what the
     * row says.
     */
    public function refresh(Subscription $subscription): ?Subscription
    {
        if (Payment::isPlaceholderProviderId($subscription->provider_id)) {
            return null;
        }

        // A pause is this package's state before it is the provider's. On
        // Mollie the agreement the row still names has been ended on purpose,
        // and writing that answer down would turn a pause into a cancellation.
        // The one thing a refresh does follow: Stripe ending a pause by itself.
        if ($subscription->isPaused()) {
            return $this->resumedByProvider($subscription) ? $subscription->fresh() : null;
        }

        // A claim is settled by whoever holds it, or by the sweep
        // (`SubscriptionClaims`). A refresh writing the provider's answer here
        // would turn a pause half done on Mollie into a cancellation.
        if ($subscription->isClaimed()) {
            return null;
        }

        $gateway = $this->agreementGateway($subscription);

        if (! $gateway) {
            return null;
        }

        try {
            $remote = $gateway->fetchSubscription($subscription->customer_reference, $subscription->provider_id);
        } catch (Throwable $e) {
            Log::warning('statamic-payments: the provider would not say how this agreement is doing.', [
                'subscription_id' => $subscription->getKey(),
                'exception' => $e->getMessage(),
            ]);

            return null;
        }

        // A completed or cancelled row is not walked backwards by a provider
        // answer this package cannot read; `normaliseSubscription()` already
        // lands the unknown on `suspended`, and re-opening a finished agreement
        // would be worse than leaving it.
        $update = ['status' => $remote->status];

        if ($remote->nextPaymentAt) {
            $update['next_payment_at'] = Carbon::parse($remote->nextPaymentAt);
        }

        if (! $remote->isLive() && ! $subscription->ended_at) {
            $update['ended_at'] = now();
        }

        // And the other way round: an agreement the provider runs again (a
        // suspension lifted by a new card) has no end date. Left standing, a
        // report that reads `ended_at` counts it as churned while it charges.
        if ($remote->isLive() && $subscription->ended_at !== null) {
            $update['ended_at'] = null;
        }

        $subscription->forceFill($update)->save();

        return $subscription->fresh() ?? $subscription;
    }

    /**
     * Stop one.
     *
     * The provider is told first and its answer is what gets written. Marking
     * the row cancelled and hoping is how somebody keeps being charged for a
     * thing their account says they cancelled.
     */
    public function cancel(Subscription $subscription): bool
    {
        $gateway = $this->agreementGateway($subscription);

        if (! $gateway) {
            return false;
        }

        // Read from the database, not from the object handed in: the question
        // is what the row is now, and a screen that loaded it a minute ago
        // does not know.
        $current = $subscription->fresh() ?? $subscription;
        $from = (string) $current->status;

        // A pause, resume or switch is talking to the provider right now. A
        // cancellation in the middle reported success and was then overwritten
        // by the resume, which went on charging an agreement it had just
        // started (Gauntlet 23.09.2026). So: wait for it. A claim left behind
        // by a dead process is the exception; that one may be ended, and so
        // is whatever agreement it left at the provider.
        if ($current->isClaimed() && ! $current->isStuck()) {
            Log::info('statamic-payments: a cancellation waited for a change to this agreement that is still running.', [
                'subscription_id' => $current->getKey(),
                'status' => $from,
            ]);

            return false;
        }

        $claimed = Subscription::query()
            ->whereKey($current->getKey())
            ->where('status', $from)
            ->update(['status' => Subscription::STATUS_CANCELLING, 'updated_at' => now()]);

        if ($claimed === 0) {
            return false;
        }

        // The object knows the claim too, so the save at the end writes the
        // status even where it was already `cancelled` before the claim.
        $current->setAttribute('status', Subscription::STATUS_CANCELLING);
        $current->syncOriginalAttribute('status');

        // What the row was before this claim, kept on it: a sweep finishing a
        // cancellation a dead process left must know whether the row had
        // already been ended (dunning) and so must not announce it again.
        if ($from !== Subscription::STATUS_CANCELLING) {
            $current->forceFill(['meta' => array_merge($current->meta ?? [], ['cancelling_from' => $from])])->save();
        }

        $from = $from === Subscription::STATUS_CANCELLING
            ? (string) data_get($current->meta, 'cancelling_from', Subscription::STATUS_ACTIVE)
            : $from;

        $giveBack = fn () => Subscription::query()
            ->whereKey($current->getKey())
            ->where('status', Subscription::STATUS_CANCELLING)
            ->update(['status' => $from, 'updated_at' => now()]);

        try {
            $remote = $gateway->cancelSubscription($current->customer_reference, $current->provider_id);
        } catch (Throwable $e) {
            Log::error('statamic-payments: the provider would not cancel this agreement; the row is unchanged.', [
                'subscription_id' => $subscription->getKey(),
                'exception' => $e->getMessage(),
            ]);

            $giveBack();

            return false;
        }

        if ($remote->isLive()) {
            Log::error('statamic-payments: the provider still reports this agreement as running after a cancellation.', [
                'subscription_id' => $subscription->getKey(),
                'status' => $remote->status,
            ]);

            $giveBack();

            return false;
        }

        // Whatever a resume or switch started for this row and never wrote
        // down (its answer lost, its process dead) ends with it, whatever state
        // the row was in: a paused row can have one too. Its own try: the main
        // agreement is ended either way, and a listing the provider will not
        // give now is said loudly rather than undoing that.
        try {
            app(SubscriptionClaims::class)->endOrphans($current, $gateway);
        } catch (Throwable $e) {
            Log::error('statamic-payments: an agreement was cancelled and the provider would not list what else runs for it; check for a second agreement.', [
                'subscription_id' => $current->getKey(),
                'exception' => $e->getMessage(),
            ]);
        }

        $subscription = $current;

        // War die Zeile hier schon beendet, ist der Anbieteraufruf das Einzige
        // gewesen, was noch zu tun war — und dann gibt es auch nichts mehr
        // anzukuendigen.
        //
        // `Dunning::end()` beendet lokal zuerst und laesst den Anbieter danach
        // nachziehen; ohne diese Zeile feuerte jede durch die Mahnstrecke
        // beendete Vereinbarung **zwei** Ereignisse fuer einen Vorgang:
        // `SubscriptionEnded` aus der Mahnstrecke und `SubscriptionCancelled`
        // von hier. Die beiden sind ausdruecklich verschieden gemeint (siehe
        // `FollowSubscriptionWithEntitlement`), und ein Haus, das an
        // `SubscriptionCancelled` eine Kuendigungsmail oder einen
        // Churn-Zaehler haengt, bekam beides doppelt, ohne dass irgendwo eine
        // Zeile davon erzaehlte.
        $schonBeendet = $from === Subscription::STATUS_CANCELLED
            && $subscription->ended_at !== null;

        $meta = $subscription->meta ?? [];
        $requested = is_array($meta['cancel_requested'] ?? null) ? $meta['cancel_requested'] : null;
        unset($meta['cancelling_from'], $meta['cancel_requested']);

        $subscription->forceFill([
            'status' => Subscription::STATUS_CANCELLED,
            'cancelled_at' => $subscription->cancelled_at ?? now(),
            'ended_at' => $subscription->ended_at ?? now(),
            'next_payment_at' => null,
            // A paused agreement can be ended for good; its date to resume
            // must not outlive it.
            'resumes_at' => null,
            'meta' => $meta === [] ? null : $meta,
        ])->save();

        // A statutory cancellation that had to wait: its record says when it
        // was carried out at the provider.
        if (is_numeric($requested['cancellation_id'] ?? null)) {
            Cancellation::query()
                ->whereKey((int) $requested['cancellation_id'])
                ->whereNull('provider_cancelled_at')
                ->update(['provider_cancelled_at' => now()]);
        }

        if (! $schonBeendet) {
            SubscriptionCancelled::dispatch($subscription->fresh() ?? $subscription);
        }

        return true;
    }

    /** The next charge date the provider reports for this row's agreement, or null. */
    protected function nextDateFromProvider(Subscription $subscription): ?Carbon
    {
        $gateway = $this->agreementGateway($subscription);

        if ($gateway === null || Payment::isPlaceholderProviderId($subscription->provider_id)) {
            return null;
        }

        try {
            $remote = $gateway->fetchSubscription($subscription->customer_reference, $subscription->provider_id);
        } catch (Throwable) {
            return null;
        }

        return $remote->nextPaymentAt ? Carbon::parse($remote->nextPaymentAt) : null;
    }

    /**
     * Note a cancellation that could not be carried out because the row is
     * being changed right now. Carried out when the change finishes
     * (`cancelIfRequested()`), or by the sweep. A § 312k cancellation must
     * not be lost to an unlucky moment.
     */
    public function requestCancellation(Subscription $subscription, ?int $cancellationId = null): void
    {
        $fresh = $subscription->fresh() ?? $subscription;
        $meta = $fresh->meta ?? [];
        $meta['cancel_requested'] = array_filter([
            'at' => now()->toIso8601String(),
            'cancellation_id' => $cancellationId,
        ], fn ($v) => $v !== null);

        $fresh->forceFill(['meta' => $meta])->save();
    }

    /** Carry out a noted cancellation, if there is one. True when it was. */
    public function cancelIfRequested(Subscription $subscription): bool
    {
        $fresh = $subscription->fresh() ?? $subscription;

        if (! is_array(data_get($fresh->meta, 'cancel_requested')) || ! $fresh->isRunning()) {
            return false;
        }

        return $this->cancel($fresh);
    }

    /**
     * One interval from now, in the provider's vocabulary.
     *
     * `"1 month"`, `"12 weeks"`, `"2 days"` — the same words the provider takes,
     * which is why they are stored as typed rather than parsed into a unit
     * enum. Anything unrecognised falls back to a month rather than throwing:
     * getting the next date slightly wrong is recoverable, refusing to record a
     * subscription somebody has already paid for is not.
     */
    protected function afterOneInterval(string $interval): Carbon
    {
        // Die Rechnung selbst steht am Modell, weil eine zweite Stelle sie
        // ebenfalls braucht: {@see Subscription::paidThroughAt()} fragt „bis
        // wann ist bezahlt" und muss dabei dieselben Monatsenden treffen wie
        // „wann wird das nächste Mal eingezogen". Zwei Kopien wären zwei Wege,
        // sich darüber zu uneinigen.
        return Subscription::addInterval(Carbon::now(), $interval);
    }
}

<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\BrandContext\ServiceProvider;
use Goldnead\StatamicPayments\Facades\PaymentLog;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\PaymentItem;
use Goldnead\StatamicPayments\Models\Withdrawal;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/**
 * Die Detailseite einer Zahlung.
 *
 * Sie zeigt alles, was zu einer Bestellung gehört — auch Anschrift und
 * USt-IdNr. — also ist zuerst zu belegen, wer sie nicht sieht, und danach,
 * dass jeder Abschnitt aus der Datenbank kommt und nicht aus einer Annahme.
 */
class PaymentDetailScreenTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return array_merge(parent::getPackageProviders($app), array_values(array_filter([
            class_exists(ServiceProvider::class) ? ServiceProvider::class : null,
        ])));
    }

    protected function payment(array $overrides = []): Payment
    {
        $payment = Payment::create(array_merge([
            'provider' => 'fake',
            'provider_id' => 'tr_'.uniqid(),
            'product' => 'noten-paket',
            'amount_cent' => 2400,
            'currency' => 'EUR',
            'status' => Payment::STATUS_PAID,
            'paid_at' => Carbon::parse('2026-08-30 10:00:00'),
            'email' => 'kaeufer@example.com',
            'name' => 'Maria Beispiel',
            'country' => 'DE',
            'country_source' => 'checkout',
            'card_label' => 'Visa',
            'card_last4' => '4242',
            'utm_source' => 'newsletter',
            'utm_campaign' => 'fruehling',
            'landing_page' => 'https://example.test/noten',
            'consent_at' => Carbon::parse('2026-08-30 09:59:00'),
            'consent_text' => 'Ich stimme zu, dass sofort geliefert wird.',
            'refunded_cent' => 500,
            'refunded_at' => Carbon::parse('2026-08-31 12:00:00'),
            'meta' => [
                'address' => "Beispielweg 3\n20095 Hamburg",
                'vat_id' => 'DE123456789',
                'access' => ['starts_at' => '2026-10-01', 'days' => 30],
                'refunds' => ['re_abc'],
                'withdrawal' => ['version' => '2026-06'],
            ],
        ], $overrides));

        PaymentItem::create(['payment_id' => $payment->id, 'product' => 'noten-paket', 'name' => 'Notenpaket', 'amount_cent' => 1900, 'quantity' => 1, 'kind' => PaymentItem::KIND_PRIMARY]);
        PaymentItem::create(['payment_id' => $payment->id, 'product' => 'chorheft', 'name' => 'Chorheft', 'amount_cent' => 500, 'quantity' => 1, 'kind' => PaymentItem::KIND_BUMP, 'offer' => 'fruehling-bump']);

        return $payment;
    }

    protected function user()
    {
        return tap(User::make()->email(uniqid().'@example.com')->makeSuper())->save();
    }

    protected function userWithoutPermission()
    {
        $role = tap(Role::make('nur-cp-'.uniqid())->addPermission('access cp'))->save();

        return tap(User::make()->email(uniqid().'@example.com')->assignRole($role))->save();
    }

    protected function show(Payment $payment, $user = null)
    {
        return $this->actingAs($user ?? $this->user())
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => ''])
            ->getJson('/cp/utilities/payments/'.$payment->id);
    }

    #[Test]
    public function a_user_without_the_permission_is_refused(): void
    {
        $payment = $this->payment();

        $response = $this->show($payment, $this->userWithoutPermission());

        $this->assertContains($response->getStatusCode(), [302, 403]);
        $response->assertDontSee('DE123456789');
        $response->assertDontSee('Beispielweg');
    }

    #[Test]
    public function an_unknown_payment_is_a_404_and_so_is_a_non_numeric_id(): void
    {
        // Ein Nutzer je Test: ohne Pro erlaubt Statamic nur einen.
        $user = $this->user();

        $this->show($this->payment(['id' => 5]), $user)->assertOk();

        $this->actingAs($user)->get('/cp/utilities/payments/999999')->assertNotFound();
        $this->actingAs($user)->get('/cp/utilities/payments/abc')->assertNotFound();
    }

    #[Test]
    public function the_page_carries_every_section(): void
    {
        $payment = $this->payment();

        PaymentLog::mail($payment, 'invoice', 'kaeufer@example.com', 'Ihre Rechnung R-2026-0001');
        PaymentLog::mail($payment, 'purchase_confirmation', 'kaeufer@example.com', 'Danke für den Kauf');

        $response = $this->show($payment);

        $response->assertOk();
        $this->assertSame('statamic-payments::Payments/Show', $response->json('component'));

        $p = $response->json('props.payment');

        // Kopf
        $this->assertSame('24.00', $p['amount']);
        $this->assertSame('paid', $p['status']);
        $this->assertNotNull($p['paid_at']);

        // Positionen, mit Art und Angebot
        $this->assertCount(2, $p['items']);
        $this->assertSame('primary', $p['items'][0]['kind']);
        $this->assertSame('bump', $p['items'][1]['kind']);
        $this->assertSame('fruehling-bump', $p['items'][1]['offer']);
        $this->assertSame('5.00', $p['items'][1]['total']);

        // Käufer aus Spalten und aus meta
        $this->assertSame('Maria Beispiel', $p['buyer']['name']);
        $this->assertSame('DE', $p['buyer']['country']);
        $this->assertStringContainsString('Beispielweg 3', $p['buyer']['address']);
        $this->assertSame('DE123456789', $p['buyer']['vat_id']);

        // Einwilligung
        $this->assertNotNull($p['consent']['at']);
        $this->assertSame('Ich stimme zu, dass sofort geliefert wird.', $p['consent']['text']);
        $this->assertSame('2026-06', $p['consent']['withdrawal_version']);

        // Zugangsfenster, mit gerechnetem Ende
        $this->assertSame('2026-10-01', $p['access']['starts_at']);
        $this->assertSame(30, $p['access']['days']);
        $this->assertStringStartsWith('2026-10-31', $p['access']['expires_at']);

        // Herkunft, Karte, Erstattungen
        $this->assertSame('newsletter', $p['origin']['utm_source']);
        $this->assertSame('https://example.test/noten', $p['origin']['landing_page']);
        $this->assertSame('4242', $p['card']['last4']);
        $this->assertSame('5.00', $p['refunds']['amount']);
        $this->assertSame(['re_abc'], $p['refunds']['references']);

        // Kommunikation, neueste zuerst
        $this->assertCount(2, $p['communications']);
        $this->assertSame('purchase_confirmation', $p['communications'][0]['kind']);
        $this->assertSame('invoice', $p['communications'][1]['kind']);
        $this->assertSame('Ihre Rechnung R-2026-0001', $p['communications'][1]['subject']);
        $this->assertSame('sent', $p['communications'][1]['status']);

        // Kein Webhook-Nachbar im Testlauf: kein Panel, nicht „leer".
        $this->assertNull($p['webhooks']);
    }

    #[Test]
    public function related_payments_and_withdrawals_are_linked(): void
    {
        $parent = $this->payment();
        $child = Payment::create([
            'provider' => 'fake', 'provider_id' => 'tr_child', 'product' => 'chorheft',
            'amount_cent' => 500, 'currency' => 'EUR', 'status' => Payment::STATUS_PAID,
            'email' => 'kaeufer@example.com', 'parent_payment_id' => $parent->id,
        ]);

        Withdrawal::create([
            'public_id' => 'W-TESTTEST', 'payment_id' => $parent->id, 'brand_id' => 0,
            'name' => 'Maria', 'email' => 'kaeufer@example.com', 'order_reference' => (string) $parent->id,
            'contact' => 'kaeufer@example.com', 'declared_at' => Carbon::now(), 'confirmed_at' => Carbon::now(),
        ]);

        $user = $this->user();
        $links = $this->show($parent, $user)->json('props.payment.links');

        $this->assertSame($child->id, $links['children'][0]['id']);
        $this->assertStringEndsWith('/cp/utilities/payments/'.$child->id, $links['children'][0]['url']);
        $this->assertSame('W-TESTTEST', $links['withdrawals'][0]['public_id']);

        $this->assertSame($parent->id, $this->show($child, $user)->json('props.payment.links.parent.id'));
    }

    #[Test]
    public function the_listing_row_links_to_the_detail_page(): void
    {
        $payment = $this->payment();

        $row = $this->actingAs($this->user())->getJson('/cp/utilities/payments')->json('data.0');

        $this->assertStringEndsWith('/cp/utilities/payments/'.$payment->id, $row['url']);
    }

    #[Test]
    public function a_payment_of_another_brand_does_not_exist_for_this_reader(): void
    {
        if (! class_exists(BrandContext::class)) {
            $this->markTestSkipped('goldnead/statamic-brand-context has to be installed for this to mean anything');
        }

        $this->loadMigrationsFrom(__DIR__.'/../../vendor/goldnead/statamic-brand-context/database/migrations');
        config(['brand-context.multi_brand' => true]);

        $shopA = Brand::create(['handle' => 'shop-a', 'name' => 'Shop A']);
        $shopB = Brand::create(['handle' => 'shop-b', 'name' => 'Shop B']);

        $own = $this->payment(['brand_id' => $shopA->getKey(), 'provider_id' => 'tr_own']);
        $unbranded = $this->payment(['brand_id' => 0, 'provider_id' => 'tr_none']);
        $foreign = $this->payment(['brand_id' => $shopB->getKey(), 'provider_id' => 'tr_foreign', 'email' => 'fremd@example.com']);

        BrandContext::setCurrent($shopA);
        $user = $this->user();

        try {
            $this->show($own, $user)->assertOk();
            // Ohne Marke heißt nicht fremd: das Listing zeigt die Zeile auch.
            $this->show($unbranded, $user)->assertOk();

            $response = $this->show($foreign, $user);
            $response->assertNotFound();
            $response->assertDontSee('fremd@example.com');
        } finally {
            BrandContext::forget();
        }
    }
}

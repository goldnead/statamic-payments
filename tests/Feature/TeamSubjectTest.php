<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\Entitlements\Enums\EntitlementState;
use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Models\Entitlement;
use Goldnead\Entitlements\Support\SubjectReference;
use Goldnead\IdentityContracts\ServiceProvider;
use Goldnead\StatamicPayments\Http\Controllers\Cp\PaymentsController;
use Goldnead\StatamicPayments\Http\Resources\Cp\ListedPayment;
use Goldnead\StatamicPayments\Http\Resources\Cp\ListedSubscription;
use Goldnead\StatamicPayments\Http\Resources\Cp\PaymentDetail;
use Goldnead\StatamicPayments\Integrations\EntitlementsBridge;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Support\BuyerSubject;
use Goldnead\StatamicPayments\Support\Chargebacks;
use Goldnead\StatamicPayments\Support\Checkout;
use Goldnead\StatamicPayments\Support\FollowUp;
use Goldnead\StatamicPayments\Support\Refunds;
use Goldnead\StatamicPayments\Support\Subscriptions;
use Goldnead\StatamicPayments\Tests\Support\TeamStandIn;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

/**
 * Ein Team kauft, eine Person bezahlt.
 *
 * `Teams::checkout()` (statamic-teams) legt `meta.entitlement_subject =
 * {type: team, id}` auf die Zahlung. Der Zugang gehört dann dem Team, nicht der
 * Adresse, die an der Kasse stand, und zwar über die ganze Laufzeit: Vergabe,
 * Verlängerung, Kündigung, Erstattung. Ohne das Feld bleibt alles wie vorher.
 *
 * Gegen das echte entitlements, nicht gegen eine Attrappe: ob ein Paar mit dem
 * Typ `team` angenommen und wiedergefunden wird, sagt nur das Geschwister.
 */
class TeamSubjectTest extends TestCase
{
    protected string $secret = 'whsec_team_subject_test_secret_0123456789';

    /** @var array<string, class-string> */
    private array $morphMapBefore = [];

    protected function getPackageProviders($app)
    {
        return array_merge(parent::getPackageProviders($app), array_values(array_filter([
            class_exists(ServiceProvider::class) ? ServiceProvider::class : null,
            class_exists(\Goldnead\BrandContext\ServiceProvider::class) ? \Goldnead\BrandContext\ServiceProvider::class : null,
            class_exists(\Goldnead\Entitlements\ServiceProvider::class) ? \Goldnead\Entitlements\ServiceProvider::class : null,
        ])));
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic-payments.follow_up.collect_mandate', true);
        $app['config']->set('statamic-payments.stripe.key', 'sk_test_notARealKey');
        $app['config']->set('statamic-payments.stripe.webhook_secret', $this->secret);
        $app['config']->set('statamic-payments.products', [
            'chor-lizenz' => ['name' => 'Chorlizenz', 'amount_cent' => 49000, 'grants' => 'chor'],
            'chor-abo' => ['name' => 'Chor-Abo', 'amount_cent' => 4900, 'interval' => '1 month', 'grants' => 'chor'],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(Entitlements::class)) {
            $this->markTestSkipped('the sibling has to be installed for this to mean anything');
        }

        $this->loadMigrationsFrom(__DIR__.'/../../vendor/goldnead/statamic-entitlements/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../../vendor/goldnead/statamic-brand-context/database/migrations');

        config(['statamic-payments.entitlements.enabled' => true]);

        Schema::create('test_teams', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        $this->morphMapBefore = Relation::morphMap();
        Relation::morphMap(['team' => TeamStandIn::class]);

        TeamStandIn::create(['id' => 7, 'name' => 'Kammerchor Nord']);

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Relation::morphMap($this->morphMapBefore, false);

        parent::tearDown();
    }

    /**
     * What `TeamBuyer::details()` in statamic-teams hands the checkout: the
     * team as `for`, its billing data in `meta`.
     */
    private function teamDetails(mixed $for = 'the team'): array
    {
        return ['for' => $for === 'the team' ? TeamStandIn::find(7) : $for, 'meta' => [
            'team_id' => 7,
            'team_uuid' => '0b7c7a8e-team-7',
            'paid_by' => 3,
            'address' => [
                'company' => 'Kammerchor Nord e. V.',
                'line1' => 'Chorweg 1',
                'postal_code' => '20095',
                'city' => 'Hamburg',
                'country' => 'DE',
            ],
            'vat_id' => 'DE123456789',
        ], 'country' => 'DE', 'country_source' => 'billing_address'];
    }

    private function team(): SubjectReference
    {
        return new SubjectReference('team', '7');
    }

    private function email(string $address = 'leitung@example.com'): SubjectReference
    {
        return new SubjectReference('email', $address);
    }

    /** Walk a checkout on the Mollie-shaped fake to paid, as the provider would. */
    private function payOnMollie(Payment $payment): Payment
    {
        $this->gateway->markPaid($payment->provider_id);
        $this->postJson(route('statamic-payments.webhook'), ['id' => $payment->provider_id])->assertOk();

        return $payment->fresh();
    }

    private function startTeamSubscription(): Subscription
    {
        $result = app(Subscriptions::class)->start('chor-abo', ['email' => 'leitung@example.com', 'name' => 'Kammerchor Nord'], null, $this->teamDetails());

        $this->assertNotNull($result, 'the checkout for the first payment was refused');
        $this->payOnMollie($result->payment);

        $subscription = Subscription::first();
        $this->assertNotNull($subscription, 'no agreement was created');

        return $subscription;
    }

    // ------------------------------------------------------------ one-time

    #[Test]
    public function a_team_purchase_grants_the_team_and_not_the_person_who_paid(): void
    {
        $result = app(Checkout::class)->start('chor-lizenz', ['email' => 'leitung@example.com', 'name' => 'Kammerchor Nord'], null, null, $this->teamDetails());

        $this->payOnMollie($result->payment);

        $this->assertTrue(Entitlements::forSubject($this->team())->where('product_slug', 'chor')->exists(), 'the team got nothing');
        $this->assertFalse(Entitlements::forSubject($this->email())->exists(), 'the person who clicked got the team\'s access');
    }

    #[Test]
    public function a_purchase_without_the_field_grants_the_address_as_before(): void
    {
        $result = app(Checkout::class)->start('chor-lizenz', ['email' => 'leitung@example.com']);

        $this->payOnMollie($result->payment);

        $this->assertSame(1, Entitlement::count());
        $this->assertTrue(Entitlements::forSubject($this->email())->where('product_slug', 'chor')->exists());
    }

    #[Test]
    public function a_subject_whose_record_does_not_exist_falls_back_to_the_address_and_says_so(): void
    {
        Log::spy();

        $result = app(Checkout::class)->start('chor-lizenz', ['email' => 'leitung@example.com'], null, null,
            $this->teamDetails(new SubjectReference('team', '99')));

        $this->assertArrayNotHasKey('entitlement_subject', $result->payment->fresh()->meta ?? []);

        $this->payOnMollie($result->payment);

        $this->assertSame(0, Entitlement::query()->where('subject_type', 'team')->count());
        $this->assertTrue(Entitlements::forSubject($this->email())->where('product_slug', 'chor')->exists());
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains((string) $message, 'for'))->atLeast()->once();
    }

    #[Test]
    public function a_type_entitlements_cannot_resolve_falls_back_to_the_address(): void
    {
        Log::spy();

        $result = app(Checkout::class)->start('chor-lizenz', ['email' => 'leitung@example.com'], null, null,
            $this->teamDetails(new SubjectReference('gibtsnicht', '7')));

        $this->assertArrayNotHasKey('entitlement_subject', $result->payment->fresh()->meta ?? []);
        Log::shouldHaveReceived('warning')->atLeast()->once();
    }

    #[Test]
    public function the_subject_cannot_be_smuggled_in_through_free_meta(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(Checkout::class)->start('chor-lizenz', ['email' => 'leitung@example.com'], null, null,
            ['meta' => ['entitlement_subject' => ['type' => 'team', 'id' => '7']]]);
    }

    #[Test]
    public function for_is_a_model_a_user_or_a_reference_and_nothing_else(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(Checkout::class)->start('chor-lizenz', ['email' => 'leitung@example.com'], null, null,
            ['for' => ['type' => 'team', 'id' => '7']]);
    }

    #[Test]
    public function a_reference_to_an_existing_record_is_taken(): void
    {
        $result = app(Checkout::class)->start('chor-lizenz', ['email' => 'leitung@example.com'], null, null,
            ['for' => new SubjectReference('team', '7')]);

        $this->assertSame(['type' => 'team', 'id' => '7'], $result->payment->fresh()->meta['entitlement_subject'] ?? null);
    }

    // ------------------------------------------- only this purchase's access

    /** Two purchases of the same thing by one team, plus a grant somebody made by hand. */
    private function twoPurchasesAndAManualGrant(): array
    {
        $first = app(Checkout::class)->start('chor-lizenz', ['email' => 'leitung@example.com'], null, null, $this->teamDetails());
        $first = $this->payOnMollie($first->payment);
        $second = app(Checkout::class)->start('chor-lizenz', ['email' => 'leitung@example.com'], null, null, $this->teamDetails());
        $second = $this->payOnMollie($second->payment);

        Entitlements::grant($this->team(), 'chor', 'manual', 'cp-1');

        $this->assertSame(3, Entitlements::forSubject($this->team())->count());

        return [$first, $second];
    }

    private function grantOf(string $source, string $ref): Entitlement
    {
        return Entitlements::forSubject($this->team())->where('source', $source)->where('source_ref', $ref)->firstOrFail();
    }

    #[Test]
    public function a_refund_takes_only_the_access_that_payment_bought(): void
    {
        [$first, $second] = $this->twoPurchasesAndAManualGrant();

        app(Refunds::class)->record($first, 49000, 're_first');

        $this->assertSame(EntitlementState::Revoked, $this->grantOf('statamic-payments', $first->provider_id)->state());
        $this->assertSame(EntitlementState::Active, $this->grantOf('statamic-payments', $second->provider_id)->state(), 'the other purchase lost its access');
        $this->assertSame(EntitlementState::Active, $this->grantOf('manual', 'cp-1')->state(), 'the grant made by hand was revoked');
    }

    #[Test]
    public function a_chargeback_takes_only_the_access_that_payment_bought(): void
    {
        [$first, $second] = $this->twoPurchasesAndAManualGrant();

        app(Chargebacks::class)->record($first, 'chb_first', 49000);

        $this->assertSame(EntitlementState::Revoked, $this->grantOf('statamic-payments', $first->provider_id)->state());
        $this->assertSame(EntitlementState::Active, $this->grantOf('statamic-payments', $second->provider_id)->state());
        $this->assertSame(EntitlementState::Active, $this->grantOf('manual', 'cp-1')->state());
    }

    #[Test]
    public function an_ending_agreement_closes_only_its_own_access(): void
    {
        $subscription = $this->startTeamSubscription();
        $first = Payment::query()->where('subscription_id', $subscription->getKey())->first();

        $other = app(Checkout::class)->start('chor-lizenz', ['email' => 'leitung@example.com'], null, null, $this->teamDetails());
        $other = $this->payOnMollie($other->payment);
        Entitlements::grant($this->team(), 'chor', 'manual', 'cp-1');

        $this->assertTrue(app(Subscriptions::class)->cancel($subscription->fresh()));

        $this->assertNotNull($this->grantOf('statamic-payments', $first->provider_id)->expires_at, 'the agreement\'s own access stays open');
        $this->assertNull($this->grantOf('statamic-payments', $other->provider_id)->expires_at, 'the one-off purchase was closed with the agreement');
        $this->assertNull($this->grantOf('manual', 'cp-1')->expires_at, 'the grant made by hand was closed with the agreement');
    }

    // ------------------------------------------------------------ follow-up

    #[Test]
    public function a_follow_up_keeps_the_team_when_the_caller_names_nobody(): void
    {
        config([
            'statamic-payments.follow_up.enabled' => true,
            'statamic-payments.products.noten' => ['name' => 'Noten', 'amount_cent' => 1200, 'grants' => 'noten'],
        ]);

        $original = app(Checkout::class)->start('chor-lizenz', ['email' => 'leitung@example.com'], null, null, $this->teamDetails())->payment;
        $original->forceFill(['status' => Payment::STATUS_PAID, 'paid_at' => now(), 'fulfilled_at' => now(), 'customer_reference' => 'cst_team'])->save();
        $this->gateway->mandates[] = 'cst_team';
        $mandat = trim((string) ($original->fresh()->mandate_id ?? ''));
        if ($mandat !== '') {
            $this->gateway->knownMandates[] = $mandat;
        }

        $follow = app(FollowUp::class)->accept($original->fresh(), 'noten');

        $this->assertNotNull($follow);
        $this->assertSame(['type' => 'team', 'id' => '7'], $follow->fresh()->meta['entitlement_subject'] ?? null);
    }

    #[Test]
    public function a_full_refund_takes_the_access_from_the_team(): void
    {
        $result = app(Checkout::class)->start('chor-lizenz', ['email' => 'leitung@example.com'], null, null, $this->teamDetails());
        $payment = $this->payOnMollie($result->payment);

        app(Refunds::class)->record($payment, 49000, 're_team');

        $grant = Entitlements::forSubject($this->team())->first();
        $this->assertNotNull($grant);
        $this->assertSame(EntitlementState::Revoked, $grant->state());
    }

    // --------------------------------------------------------- subscription

    #[Test]
    public function the_agreement_keeps_the_callers_meta_of_the_first_payment(): void
    {
        $subscription = $this->startTeamSubscription();

        $this->assertSame(['type' => 'team', 'id' => '7'], $subscription->meta['entitlement_subject'] ?? null);
        $this->assertSame(7, $subscription->meta['team_id'] ?? null);
        $this->assertSame('DE123456789', $subscription->meta['vat_id'] ?? null);
        // Nothing the package keeps for itself travels along.
        $this->assertArrayNotHasKey('subscription_intent', $subscription->meta);
        $this->assertSame("Chorweg 1\n20095 Hamburg", $subscription->meta['address'] ?? null);
        $this->assertTrue(Entitlements::forSubject($this->team())->where('product_slug', 'chor')->exists());
    }

    #[Test]
    public function a_renewal_extends_the_teams_access(): void
    {
        $subscription = $this->startTeamSubscription();

        $cycle = $this->gateway->arrive('chor-abo', 4900, $subscription->provider_id);
        $this->postJson(route('statamic-payments.webhook'), ['id' => $cycle])->assertOk();

        $this->assertSame(['type' => 'team', 'id' => '7'], Payment::where('provider_id', $cycle)->first()->meta['entitlement_subject'] ?? null,
            'the cycle did not inherit the subject');
        $this->assertFalse(Entitlements::forSubject($this->email())->exists(), 'the renewal wrote a grant for the address');
        $this->assertSame(1, Entitlement::query()->where('subject_type', 'team')->count());
    }

    #[Test]
    public function a_cancellation_closes_the_teams_open_access_at_the_end_of_the_paid_period(): void
    {
        $subscription = $this->startTeamSubscription();
        $this->assertNull(Entitlements::forSubject($this->team())->first()->expires_at);

        $this->assertTrue(app(Subscriptions::class)->cancel($subscription->fresh()));

        $this->assertNotNull(Entitlements::forSubject($this->team())->first()->expires_at, 'the team\'s access outlives the cancelled agreement');
    }

    #[Test]
    public function an_agreement_from_before_this_change_finds_the_team_on_its_first_payment(): void
    {
        $subscription = $this->startTeamSubscription();
        // What a row written by 1.26 looks like: nothing in its meta.
        $subscription->forceFill(['meta' => null])->save();

        app(EntitlementsBridge::class)->closeFor($subscription->fresh());

        $this->assertNotNull(Entitlements::forSubject($this->team())->first()->expires_at);
    }

    // --------------------------------------------------------------- Stripe

    private function deliverStripe(string $eventId, string $type, array $object): void
    {
        $body = json_encode(['id' => $eventId, 'object' => 'event', 'type' => $type, 'data' => ['object' => $object]], JSON_UNESCAPED_SLASHES);
        $timestamp = Carbon::now()->getTimestamp();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $this->secret);

        $this->call('POST', '/!/statamic-payments/webhook/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ], $body)->assertOk();
    }

    #[Test]
    public function a_team_purchase_on_stripe_grants_the_team(): void
    {
        $payment = Payment::create([
            'provider' => 'stripe', 'provider_id' => 'cs_test_team', 'product' => 'chor-lizenz',
            'amount_cent' => 49000, 'currency' => 'EUR', 'status' => Payment::STATUS_OPEN,
            'email' => 'leitung@example.com',
            'meta' => ['entitlement_subject' => ['type' => 'team', 'id' => '7']],
        ]);

        Http::fake(['api.stripe.com/*' => Http::response([
            'id' => 'cs_test_team', 'object' => 'checkout.session', 'status' => 'complete', 'payment_status' => 'paid',
            'customer_details' => ['email' => 'leitung@example.com', 'address' => ['country' => 'DE']],
            'payment_intent' => ['id' => 'pi_team', 'payment_method' => ['id' => 'pm_team', 'card' => ['brand' => 'visa', 'last4' => '4242']]],
        ])]);

        $this->deliverStripe('evt_team_1', 'checkout.session.completed', ['id' => 'cs_test_team']);

        $this->assertTrue($payment->fresh()->isPaid());
        $this->assertTrue(Entitlements::forSubject($this->team())->where('product_slug', 'chor')->exists());
        $this->assertFalse(Entitlements::forSubject($this->email())->exists());
    }

    #[Test]
    public function a_stripe_cycle_renews_the_teams_access(): void
    {
        $subscription = Subscription::create([
            'provider' => 'stripe', 'provider_id' => 'sub_team', 'customer_reference' => 'cus_team',
            'product' => 'chor-abo', 'amount_cent' => 4900, 'currency' => 'EUR', 'interval' => '1 month',
            'times_charged' => 0, 'status' => Subscription::STATUS_ACTIVE, 'starts_at' => now(),
            'next_payment_at' => now(), 'email' => 'leitung@example.com',
            'meta' => ['entitlement_subject' => ['type' => 'team', 'id' => '7']],
        ]);

        Entitlements::grant($this->team(), 'chor', 'statamic-payments', 'sub_team', expiresAt: Carbon::now()->addDay());

        $periodEnd = Carbon::now()->addMonth()->startOfSecond();

        Http::fake([
            'api.stripe.com/v1/invoices/in_team*' => Http::response([
                'id' => 'in_team', 'object' => 'invoice', 'status' => 'paid',
                'subscription' => 'sub_team', 'customer_email' => 'leitung@example.com',
            ]),
            'api.stripe.com/v1/subscriptions/sub_team*' => Http::response([
                'id' => 'sub_team', 'object' => 'subscription', 'status' => 'active',
                'customer' => 'cus_team', 'current_period_end' => $periodEnd->getTimestamp(),
            ]),
        ]);

        $this->deliverStripe('evt_team_cycle', 'invoice.paid', ['id' => 'in_team', 'object' => 'invoice']);

        $this->assertSame(1, $subscription->fresh()->times_charged);
        $this->assertFalse(Entitlements::forSubject($this->email())->exists(), 'the renewal wrote a grant for the address');
        $this->assertSame(1, Entitlement::query()->where('subject_type', 'team')->count());
        $this->assertTrue(Entitlements::forSubject($this->team())->first()->expires_at->greaterThan(Carbon::now()->addDays(20)),
            'the team\'s access was not extended');
    }

    // -------------------------------------------------------------- invoice

    #[Test]
    public function an_address_in_fields_becomes_the_text_an_invoice_prints(): void
    {
        $result = app(Checkout::class)->start('chor-lizenz', ['email' => 'leitung@example.com', 'name' => 'Kammerchor Nord'], null, null, $this->teamDetails());

        $meta = $result->payment->fresh()->meta;

        $this->assertSame("Chorweg 1\n20095 Hamburg", $meta['address']);
        $this->assertSame('Kammerchor Nord e. V.', $meta['company']);
        $this->assertSame('Chorweg 1', $meta['address_fields']['line1']);
        $this->assertSame('DE123456789', $meta['vat_id']);
    }

    #[Test]
    public function an_address_in_fields_without_a_street_still_becomes_text(): void
    {
        $result = app(Checkout::class)->start('chor-lizenz', ['email' => 'leitung@example.com'], null, null, ['meta' => [
            'address' => ['company' => 'Kammerchor Nord e. V.', 'postal_code' => '20095', 'city' => 'Hamburg', 'country' => 'DE'],
        ]]);

        $meta = $result->payment->fresh()->meta;

        $this->assertSame('20095 Hamburg', $meta['address']);
        $this->assertSame('Kammerchor Nord e. V.', $meta['company']);
        $this->assertSame('Hamburg', $meta['address_fields']['city']);
    }

    // -------------------------------------------- what an agreement inherits

    #[Test]
    public function an_agreement_inherits_only_what_is_on_the_list(): void
    {
        $payment = new Payment(['meta' => [
            'entitlement_subject' => ['type' => 'team', 'id' => '7'],
            'vat_id' => 'DE1', 'thanks_ref' => 'dnk_1', 'pause' => ['mode' => 'keep'],
        ]]);

        $this->assertSame(['entitlement_subject' => ['type' => 'team', 'id' => '7'], 'vat_id' => 'DE1'], Subscriptions::inheritedMeta($payment));

        Subscriptions::inheritMeta('thanks_ref');

        try {
            $this->assertSame('dnk_1', Subscriptions::inheritedMeta($payment)['thanks_ref'] ?? null);
            $this->assertArrayNotHasKey('pause', Subscriptions::inheritedMeta($payment));
        } finally {
            Subscriptions::forgetInheritedMeta();
        }
    }

    #[Test]
    public function an_address_as_text_stays_as_it_is(): void
    {
        $result = app(Checkout::class)->start('chor-lizenz', ['email' => 'k@example.com'], null, null,
            ['meta' => ['address' => "Weg 2\n10115 Berlin"]]);

        $meta = $result->payment->fresh()->meta;

        $this->assertSame("Weg 2\n10115 Berlin", $meta['address']);
        $this->assertArrayNotHasKey('address_fields', $meta);
        $this->assertArrayNotHasKey('company', $meta);
    }

    // ------------------------------------------------------------------- CP

    #[Test]
    public function the_control_panel_names_the_team(): void
    {
        $subscription = $this->startTeamSubscription();
        $payment = Payment::first();

        $this->assertSame('Team Kammerchor Nord', BuyerSubject::describe($payment->meta)['display']);
        $this->assertSame('Team Kammerchor Nord', (new ListedPayment($payment))->toArray(Request::create('/'))['subject_display']);
        $this->assertSame('Team Kammerchor Nord', (new ListedSubscription($subscription->fresh()))->toArray(Request::create('/'))['subject_display']);
    }

    #[Test]
    public function the_control_panel_shows_nothing_extra_for_an_ordinary_order(): void
    {
        $this->assertNull(BuyerSubject::describe(null));
        $this->assertNull(BuyerSubject::describe(['entitlement_subject' => ['type' => 'gibtsnicht', 'id' => '1']]));
        // A team that was deleted keeps its key rather than a guessed name.
        $this->assertSame('Team #99', BuyerSubject::describe(['entitlement_subject' => ['type' => 'team', 'id' => '99']])['display']);
    }

    #[Test]
    public function a_listing_page_loads_the_names_once_and_not_per_row(): void
    {
        TeamStandIn::create(['id' => 8, 'name' => 'Gospelchor Süd']);
        $metas = [
            ['entitlement_subject' => ['type' => 'team', 'id' => '7']],
            ['entitlement_subject' => ['type' => 'team', 'id' => '8']],
            ['entitlement_subject' => ['type' => 'team', 'id' => '7']],
        ];

        BuyerSubject::forget();
        \DB::enableQueryLog();
        BuyerSubject::preload($metas);
        $names = array_map(fn ($meta) => BuyerSubject::describe($meta)['display'], $metas);
        $queries = count(\DB::getQueryLog());
        \DB::disableQueryLog();

        $this->assertSame(['Team Kammerchor Nord', 'Team Gospelchor Süd', 'Team Kammerchor Nord'], $names);
        $this->assertSame(1, $queries);
    }

    #[Test]
    public function the_search_finds_the_team_by_its_name(): void
    {
        $this->withoutMiddleware();
        TeamStandIn::create(['id' => 8, 'name' => 'Gospelchor Süd']);

        $result = app(Checkout::class)->start('chor-lizenz', ['email' => 'kasse@example.com', 'name' => 'Förderverein'], null, null,
            $this->teamDetails(TeamStandIn::find(8)));
        app(Checkout::class)->start('chor-lizenz', ['email' => 'andere@example.com', 'name' => 'Jemand']);

        $query = Payment::query();
        (new class(request()) extends PaymentsController
        {
            public function run(Builder $query, string $term): void
            {
                $this->applySearch($query, $term);
            }
        })->run($query, 'Gospel');

        $this->assertSame([$result->payment->getKey()], $query->pluck('id')->all());
    }

    #[Test]
    public function the_company_is_not_shown_twice_when_it_is_the_name(): void
    {
        $result = app(Checkout::class)->start('chor-lizenz', ['email' => 'leitung@example.com', 'name' => 'Kammerchor Nord e. V.'], null, null, $this->teamDetails());

        $detail = (new PaymentDetail($result->payment->fresh()))->toArray(Request::create('/'));

        $this->assertNull($detail['buyer']['company']);
    }
}

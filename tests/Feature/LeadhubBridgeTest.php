<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\Leadhub\Facades\LeadHub as LeadHubStandIn;
use Goldnead\StatamicPayments\Events\PaymentPaid;
use Goldnead\StatamicPayments\Events\PaymentRefunded;
use Goldnead\StatamicPayments\Integrations\LeadhubBridge;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Tests\Fakes\FakeLeadHub;
use Goldnead\StatamicPayments\Tests\Fakes\FakeOldLeadHub;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;

/**
 * The bridge from a paid payment to a CRM that does not know this addon exists.
 *
 * Tested against a stand-in rather than a real CRM, which is the point of
 * coupling by class name: the sibling is optional, and a test that needed it
 * installed would prove the coupling is not. The stand-in is deliberately
 * stricter than the real thing — see the note in tests/Fakes.
 *
 * What this cannot prove is that the real CRM accepts the array sent here. That
 * lives on the other side, in the sibling's own contract test, which ingests
 * this exact shape.
 */
class LeadhubBridgeTest extends TestCase
{
    protected FakeLeadHub $crm;

    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__.'/../Fakes/leadhub-facade.php';

        $this->crm = new FakeLeadHub;
        LeadHubStandIn::$root = $this->crm;

        config()->set('statamic-payments.leadhub.enabled', true);
    }

    protected function tearDown(): void
    {
        LeadHubStandIn::$root = null;

        parent::tearDown();
    }

    protected function payment(array $overrides = []): Payment
    {
        return Payment::create(array_merge([
            'provider' => 'mollie',
            'provider_id' => 'tr_'.uniqid(),
            'product' => 'noten',
            'amount_cent' => 1900,
            'currency' => 'EUR',
            'status' => Payment::STATUS_PAID,
            'email' => 'kaeufer@example.com',
            'name' => 'Maria Schneider',
            'paid_at' => now(),
            'utm_source' => 'newsletter',
            'utm_campaign' => 'sommer-2026',
        ], $overrides));
    }

    // -- The switch ---------------------------------------------------------

    /**
     * Off unless the site said so, and checked before anything else.
     *
     * Two addons installed for unrelated reasons must not begin exchanging
     * customer data because they share a vendor directory.
     */
    #[Test]
    public function it_does_nothing_until_the_site_turns_it_on(): void
    {
        config()->set('statamic-payments.leadhub.enabled', false);

        $this->assertFalse(app(LeadhubBridge::class)->available());

        PaymentPaid::dispatch($this->payment());

        $this->assertSame([], $this->crm->ingested);
        $this->assertSame([], $this->crm->revenue);
    }

    /**
     * A CRM too old to keep a total is an install without the feature, not a failure.
     */
    #[Test]
    public function an_older_sibling_without_a_revenue_ledger_is_skipped_quietly(): void
    {
        $alt = new FakeOldLeadHub;
        LeadHubStandIn::$root = $alt;

        $this->assertFalse(app(LeadhubBridge::class)->available());

        PaymentPaid::dispatch($this->payment());

        $this->assertSame([], $alt->ingested);
    }

    // -- The purchase -------------------------------------------------------

    #[Test]
    public function a_paid_purchase_reaches_the_timeline_and_the_total(): void
    {
        $zahlung = $this->payment();

        PaymentPaid::dispatch($zahlung);

        $this->assertCount(1, $this->crm->ingested);

        $ereignis = $this->crm->ingested[0];

        $this->assertSame('kaeufer@example.com', $ereignis['email']);
        $this->assertSame('payments.purchase_completed', $ereignis['type']);
        $this->assertSame('payment', $ereignis['source_type']);
        $this->assertSame((string) $zahlung->getKey(), $ereignis['source_id']);
        $this->assertNotEmpty($ereignis['summary']);
        $this->assertSame('Maria Schneider', $ereignis['contact']['full_name']);

        $this->assertArrayHasKey('payments:payment:'.$zahlung->getKey(), $this->crm->revenue);

        $eintrag = $this->crm->revenue['payments:payment:'.$zahlung->getKey()];

        $this->assertSame(1900, $eintrag['amount_cent']);
        $this->assertSame('EUR', $eintrag['currency']);
        $this->assertSame('statamic-payments', $eintrag['source']);
    }

    /**
     * The campaign travels under the key the CRM actually reads.
     *
     * The failure this guards is the quiet one: the CRM ignores a key it does
     * not know, so sending `utm_campaign` at the top level would look identical
     * to success and lose the campaign on every contact it creates.
     */
    #[Test]
    public function the_campaign_travels_as_attribution(): void
    {
        PaymentPaid::dispatch($this->payment());

        $ereignis = $this->crm->ingested[0];

        $this->assertSame('sommer-2026', $ereignis['attribution']['utm_campaign']);
        $this->assertSame('newsletter', $ereignis['attribution']['utm_source']);
        $this->assertArrayNotHasKey('utm_campaign', $ereignis);
    }

    /** A payment without a campaign sends no empty attribution keys. */
    #[Test]
    public function a_sale_without_a_campaign_sends_no_empty_attribution(): void
    {
        PaymentPaid::dispatch($this->payment([
            'utm_source' => null,
            'utm_campaign' => null,
        ]));

        $this->assertSame([], $this->crm->ingested[0]['attribution']);
    }

    /**
     * A redelivered webhook must not buy the same thing twice.
     *
     * The event already fires once per payment, guarded upstream. This is the
     * second net, and the one that would catch a host dispatching by hand.
     */
    #[Test]
    public function a_repeated_event_writes_one_entry_and_one_amount(): void
    {
        $zahlung = $this->payment();

        PaymentPaid::dispatch($zahlung);
        PaymentPaid::dispatch($zahlung);

        $this->assertCount(1, $this->crm->ingested);
        $this->assertCount(1, $this->crm->revenue);
        $this->assertSame(1900, $this->crm->revenue['payments:payment:'.$zahlung->getKey()]['amount_cent']);
    }

    /** No address, nothing to attach it to, and no exception either. */
    #[Test]
    public function a_payment_without_an_email_is_skipped(): void
    {
        PaymentPaid::dispatch($this->payment(['email' => null]));

        $this->assertSame([], $this->crm->ingested);
        $this->assertSame([], $this->crm->revenue);
    }

    // -- The refund ---------------------------------------------------------

    /**
     * The running total goes over, not the movement.
     *
     * A delta would subtract twice on a redelivered webhook and leave the
     * customer's lifetime value quietly too low — wrong in the direction nobody
     * checks.
     */
    #[Test]
    public function a_refund_hands_over_the_running_total(): void
    {
        $zahlung = $this->payment();
        PaymentPaid::dispatch($zahlung);

        $zahlung->forceFill(['refunded_cent' => 500, 'refunded_at' => now()])->save();
        PaymentRefunded::dispatch($zahlung, 500, false);

        $referenz = 'payments:payment:'.$zahlung->getKey();
        $this->assertSame(500, $this->crm->revenue[$referenz]['refunded_cent']);

        // A second delivery of the same fact changes nothing.
        PaymentRefunded::dispatch($zahlung, 500, false);
        $this->assertSame(500, $this->crm->revenue[$referenz]['refunded_cent']);

        // A further refund is a further fact, and it is not swallowed.
        $zahlung->forceFill(['refunded_cent' => 1900])->save();
        PaymentRefunded::dispatch($zahlung, 1400, true);
        $this->assertSame(1900, $this->crm->revenue[$referenz]['refunded_cent']);
    }

    #[Test]
    public function a_refund_leaves_its_own_line_on_the_timeline(): void
    {
        $zahlung = $this->payment();
        PaymentPaid::dispatch($zahlung);

        $zahlung->forceFill(['refunded_cent' => 1900, 'refunded_at' => now()])->save();
        PaymentRefunded::dispatch($zahlung, 1900, true);

        $typen = array_column($this->crm->ingested, 'type');

        $this->assertContains('payments.purchase_refunded', $typen);
    }

    /**
     * The upsell counts towards the campaign that produced the original.
     *
     * Otherwise the very revenue an offer exists to create counts towards
     * nothing, and the funnel report credits it for less than it earned.
     */
    #[Test]
    public function a_follow_up_purchase_carries_the_original_campaign(): void
    {
        $erst = $this->payment();

        $folge = $this->payment([
            'provider_id' => 'tr_folge',
            'parent_payment_id' => $erst->getKey(),
            'utm_source' => $erst->utm_source,
            'utm_campaign' => $erst->utm_campaign,
        ]);

        PaymentPaid::dispatch($folge);

        $this->assertSame('sommer-2026', $this->crm->ingested[0]['attribution']['utm_campaign']);
    }

    // -- Catching up --------------------------------------------------------

    /**
     * Sales taken before the bridge was switched on, or while the CRM was down.
     *
     * Repeatable by construction: both halves are keyed on something unique, so
     * a second pass writes nothing.
     */
    #[Test]
    public function the_backfill_command_sends_what_was_missed_and_repeats_safely(): void
    {
        $eine = $this->payment();
        $andere = $this->payment(['provider_id' => 'tr_zwei', 'email' => 'zweite@example.com']);

        $this->artisan('payments:leadhub-backfill')->assertSuccessful();

        $this->assertCount(2, $this->crm->ingested);
        $this->assertCount(2, $this->crm->revenue);

        $this->artisan('payments:leadhub-backfill')->assertSuccessful();

        $this->assertCount(2, $this->crm->ingested);
        $this->assertCount(2, $this->crm->revenue);
    }

    /** An install without a CRM must not fail a scheduled run. */
    #[Test]
    public function the_backfill_command_says_so_and_stops_when_the_bridge_is_off(): void
    {
        config()->set('statamic-payments.leadhub.enabled', false);

        $this->payment();

        $this->artisan('payments:leadhub-backfill')->assertSuccessful();

        $this->assertSame([], $this->crm->ingested);
    }

    // -- When the CRM is having a bad day -----------------------------------

    /**
     * A CRM that throws must not cost the sale.
     *
     * `Fulfilment` catches an exception out of a `PaymentPaid` listener,
     * **releases the fulfilment claim** and rethrows — so a throw here would
     * mean a redelivered webhook and a second attempt at granting access. The
     * whole consequence a broken CRM may have is a line in the log.
     */
    #[Test]
    public function a_crm_that_throws_does_not_take_the_payment_with_it(): void
    {
        Log::spy();

        LeadHubStandIn::$root = new class
        {
            public function ingest(array $event): ?object
            {
                throw new \RuntimeException('CRM is down');
            }

            public function recordRevenue(...$arguments): ?array
            {
                throw new \RuntimeException('CRM is down');
            }
        };

        PaymentPaid::dispatch($this->payment());

        Log::shouldHaveReceived('error')->atLeast()->once();
    }
}

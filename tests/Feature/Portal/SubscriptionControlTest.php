<?php

namespace Goldnead\StatamicPayments\Tests\Feature\Portal;

use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Portal\Display;
use Goldnead\StatamicPayments\Portal\Mail\PortalLinkMail;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;

/**
 * The buyer's own buttons: pause, resume, switch (P1, P2), and how the portal
 * looks and whether it cancels (P9).
 *
 * Every write is tried twice: once where it is allowed, and once where the
 * button is not on the page and somebody posts anyway.
 */
class SubscriptionControlTest extends TestCase
{
    protected Subscription $subscription;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic-payments.products', [
            'chor' => ['name' => 'Chormitgliedschaft', 'amount_cent' => 1900, 'interval' => '1 month', 'switch_to' => ['chor-plus']],
            'chor-plus' => ['name' => 'Chormitgliedschaft Plus', 'amount_cent' => 2900, 'interval' => '1 month'],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-23 10:00:00');

        $this->subscription = Subscription::create([
            'provider' => 'fake', 'provider_id' => 'sub_1', 'customer_reference' => 'cst_1',
            'product' => 'chor', 'amount_cent' => 1900, 'currency' => 'EUR',
            'interval' => '1 month', 'times_charged' => 3, 'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now()->subMonths(3), 'next_payment_at' => Carbon::parse('2026-10-05'),
            'email' => 'anna@example.de',
        ]);

        $this->gateway->subscriptions['sub_1'] = ['customer' => 'cst_1', 'status' => Subscription::STATUS_ACTIVE];
        $this->gateway->mandates[] = 'cst_1';

        $this->signIn('anna@example.de');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function signIn(string $email): void
    {
        Mail::fake();

        $this->post(route('statamic-payments.portal.request.send'), ['email' => $email]);

        $url = null;

        Mail::assertSent(PortalLinkMail::class, function (PortalLinkMail $mail) use (&$url) {
            $url ??= $mail->url;

            return true;
        });

        $this->get((string) $url)->assertRedirect(route('statamic-payments.portal.show'));
    }

    protected function route(string $name): string
    {
        return route('statamic-payments.portal.'.$name, ['paySubscription' => $this->subscription->getKey()]);
    }

    // ------------------------------------------------------------------ pause

    #[Test]
    public function pausing_is_off_until_the_site_allows_it(): void
    {
        $this->get(route('statamic-payments.portal.show'))
            ->assertOk()
            ->assertDontSee(__('statamic-payments::subscriptions.portal_pause_button'));

        $this->post($this->route('pause.run'))->assertRedirect(route('statamic-payments.portal.show'));
        $this->assertSame(Subscription::STATUS_ACTIVE, $this->subscription->fresh()->status);
    }

    #[Test]
    public function allowed_a_buyer_pauses_with_a_date_and_resumes(): void
    {
        config(['statamic-payments.portal.allow_pause' => true]);

        $this->get(route('statamic-payments.portal.show'))->assertSee(__('statamic-payments::subscriptions.portal_pause_button'));
        $this->get($this->route('pause.confirm'))->assertOk()->assertSee('name="resume_on"', false);

        $this->post($this->route('pause.run'), ['resume_on' => '2026-12-01'])
            ->assertRedirect(route('statamic-payments.portal.show'));

        $abo = $this->subscription->fresh();
        $this->assertSame(Subscription::STATUS_PAUSED, $abo->status);
        $this->assertSame('2026-12-01', $abo->resumes_at->toDateString());
        $this->assertSame('portal', $abo->meta['pause']['by']);

        $this->get(route('statamic-payments.portal.show'))
            ->assertSee(__('statamic-payments::subscriptions.portal_paused_until', ['date' => Carbon::parse('2026-12-01')->translatedFormat(__('statamic-payments::portal.date_format'))]))
            ->assertSee(__('statamic-payments::subscriptions.portal_resume_button'));

        $this->post($this->route('resume.run'))->assertRedirect(route('statamic-payments.portal.show'));
        $this->assertSame(Subscription::STATUS_ACTIVE, $this->subscription->fresh()->status);
    }

    #[Test]
    public function a_date_in_the_past_is_refused_with_a_message(): void
    {
        config(['statamic-payments.portal.allow_pause' => true]);

        $this->post($this->route('pause.run'), ['resume_on' => '2026-09-01'])
            ->assertRedirect($this->route('pause.confirm'))
            ->assertSessionHas('statamic-payments.portal.error');

        $this->assertSame(Subscription::STATUS_ACTIVE, $this->subscription->fresh()->status);
    }

    #[Test]
    public function the_product_can_allow_pausing_on_its_own(): void
    {
        config(['statamic-payments.products.chor.pausable' => true]);

        $this->post($this->route('pause.run'))->assertRedirect(route('statamic-payments.portal.show'));
        $this->assertSame(Subscription::STATUS_PAUSED, $this->subscription->fresh()->status);
    }

    // ----------------------------------------------------------------- switch

    #[Test]
    public function switching_shows_what_it_costs_and_does_it(): void
    {
        config(['statamic-payments.portal.allow_switch' => true]);

        $this->get($this->route('switch.confirm'))
            ->assertOk()
            ->assertSee('Chormitgliedschaft Plus')
            ->assertSee(Display::money(386, 'EUR'));

        $this->post($this->route('switch.run'), ['to' => 'chor-plus'])
            ->assertRedirect(route('statamic-payments.portal.show'));

        $this->assertSame('chor-plus', $this->subscription->fresh()->product);
        $this->assertSame(386, Payment::query()->whereNotNull('meta->subscription_change')->sole()->amount_cent);
    }

    #[Test]
    public function a_product_not_on_the_list_cannot_be_posted_in(): void
    {
        config([
            'statamic-payments.portal.allow_switch' => true,
            'statamic-payments.products.geheim' => ['name' => 'Geheim', 'amount_cent' => 100, 'interval' => '1 month'],
        ]);

        $this->post($this->route('switch.run'), ['to' => 'geheim'])
            ->assertSessionHas('statamic-payments.portal.error');

        $this->assertSame('chor', $this->subscription->fresh()->product);
    }

    // ---------------------------------------------------------------- P9 look

    #[Test]
    public function the_portal_carries_the_shops_logo_and_greeting(): void
    {
        config([
            'statamic-payments.portal.logo_url' => '/assets/logo.svg',
            'statamic-payments.portal.greeting' => "Schön, dass du singst.\n<b>Fragen?</b>",
        ]);

        $this->get(route('statamic-payments.portal.show'))
            ->assertOk()
            ->assertSee('src="/assets/logo.svg"', false)
            ->assertSee('Schön, dass du singst.<br />', false)
            ->assertSee('&lt;b&gt;Fragen?&lt;/b&gt;', false);
    }

    #[Test]
    public function a_logo_that_is_not_a_web_address_is_not_rendered(): void
    {
        config(['statamic-payments.portal.logo_url' => 'javascript:alert(1)']);

        $this->get(route('statamic-payments.portal.show'))->assertDontSee('javascript:alert', false);
    }

    // --------------------------------------------------------- P9 self-cancel

    #[Test]
    public function a_product_can_keep_cancellation_out_of_the_portal_and_the_page_points_to_the_statutory_one(): void
    {
        config(['statamic-payments.products.chor.portal_cancel' => false]);

        $this->get(route('statamic-payments.portal.show'))
            ->assertOk()
            ->assertDontSee($this->route('cancel.confirm'), false)
            ->assertSee(route('statamic-payments.cancellation.form'), false);

        $this->get($this->route('cancel.confirm'))->assertRedirect(route('statamic-payments.portal.show'));
        $this->post($this->route('cancel.run'))->assertRedirect(route('statamic-payments.portal.show'));

        $this->assertSame(Subscription::STATUS_ACTIVE, $this->subscription->fresh()->status);
    }

    #[Test]
    public function by_default_the_portal_still_cancels(): void
    {
        $this->get(route('statamic-payments.portal.show'))->assertSee($this->route('cancel.confirm'), false);
    }
}

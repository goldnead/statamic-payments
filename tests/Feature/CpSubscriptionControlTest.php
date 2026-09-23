<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Actions\CancelSubscription;
use Goldnead\StatamicPayments\Actions\PauseSubscription;
use Goldnead\StatamicPayments\Actions\ReleaseSubscription;
use Goldnead\StatamicPayments\Actions\ResumeSubscription;
use Goldnead\StatamicPayments\Actions\SwitchSubscription;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Support\LocalTime;
use Goldnead\StatamicPayments\Support\SubscriptionSwitches;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/**
 * Pause, resume and switch as row actions on the subscriptions screen (P1, P2).
 *
 * The same endpoint the cancel action uses, so the same two questions: does it
 * do the thing, and does it refuse somebody without the permission.
 */
class CpSubscriptionControlTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic-payments.products', [
            'basis' => ['name' => 'Basis', 'amount_cent' => 1900, 'interval' => '1 month'],
            'plus' => ['name' => 'Plus', 'amount_cent' => 2900, 'interval' => '1 month'],
            'raten' => ['name' => 'Raten', 'amount_cent' => 5000, 'interval' => '1 month', 'times' => 3],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-23 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function abo(array $werte = []): Subscription
    {
        $id = $werte['provider_id'] ?? 'sub_'.uniqid();
        $this->gateway->subscriptions[$id] = ['customer' => 'cst_1', 'status' => 'active'];
        $this->gateway->mandates[] = 'cst_1';

        return Subscription::create(array_merge([
            'provider' => 'fake', 'provider_id' => $id, 'customer_reference' => 'cst_1',
            'product' => 'basis', 'amount_cent' => 1900, 'currency' => 'EUR',
            'interval' => '1 month', 'times_charged' => 2, 'status' => Subscription::STATUS_ACTIVE,
            'next_payment_at' => Carbon::parse('2026-10-05'),
            'email' => 'kaeufer@example.com',
        ], $werte));
    }

    protected $admin = null;

    /** One per test: Statamic without Pro allows a single user. */
    protected function user()
    {
        return $this->admin ??= tap(User::make()->email(uniqid().'@example.com')->makeSuper())->save();
    }

    protected function userWithoutPermission()
    {
        $role = tap(Role::make('nur-cp')->addPermission('access cp'))->save();

        return tap(User::make()->email(uniqid().'@example.com')->assignRole($role))->save();
    }

    protected function runAction(string $action, array $ids, array $values = [], $user = null)
    {
        return $this->actingAs($user ?? $this->user())->postJson('/cp/utilities/subscriptions/actions', [
            'action' => $action,
            'selections' => $ids,
            'context' => [],
            'values' => $values,
        ]);
    }

    #[Test]
    public function the_pause_action_pauses_with_a_date(): void
    {
        $abo = $this->abo();

        $this->runAction(PauseSubscription::handle(), [$abo->getKey()], ['resume_on' => '2026-11-01'])->assertOk();

        $abo->refresh();
        $this->assertSame(Subscription::STATUS_PAUSED, $abo->status);
        $this->assertSame('2026-11-01', $abo->resumes_at->toDateString());
        $this->assertSame('cp', $abo->meta['pause']['by']);
    }

    #[Test]
    public function the_resume_action_resumes(): void
    {
        $abo = $this->abo();
        $this->runAction(PauseSubscription::handle(), [$abo->getKey()])->assertOk();

        $this->runAction(ResumeSubscription::handle(), [$abo->getKey()])->assertOk();

        $this->assertSame(Subscription::STATUS_ACTIVE, $abo->fresh()->status);
    }

    #[Test]
    public function starts_shows_when_the_contract_began_also_after_a_resume(): void
    {
        // Bought on 5 August; the provider's rhythm began a month later.
        $abo = $this->abo(['starts_at' => Carbon::parse('2026-09-05 10:00')]);
        Subscription::query()->whereKey($abo->getKey())->toBase()->update(['created_at' => Carbon::parse('2026-08-05 10:00')]);

        $this->runAction(PauseSubscription::handle(), [$abo->getKey()])->assertOk();
        $this->runAction(ResumeSubscription::handle(), [$abo->getKey()])->assertOk();

        $row = $this->actingAs($this->user())->getJson('/cp/utilities/subscriptions')->json('data.0');

        $this->assertSame(LocalTime::moment(Carbon::parse('2026-08-05 10:00')), $row['starts_at_display']);
    }

    #[Test]
    public function the_switch_action_switches(): void
    {
        $abo = $this->abo();

        $this->runAction(SwitchSubscription::handle(), [$abo->getKey()], ['to' => 'plus'])->assertOk();

        $this->assertSame('plus', $abo->fresh()->product);
    }

    #[Test]
    public function none_of_them_runs_for_somebody_without_the_permission(): void
    {
        $abo = $this->abo();
        $user = $this->userWithoutPermission();

        foreach ([PauseSubscription::handle(), SwitchSubscription::handle()] as $action) {
            $this->runAction($action, [$abo->getKey()], ['to' => 'plus'], $user)->assertForbidden();
        }

        $this->assertSame(Subscription::STATUS_ACTIVE, $abo->fresh()->status);
        $this->assertSame('basis', $abo->fresh()->product);
    }

    /** May look at the screen, may not change what somebody pays. */
    protected function viewer()
    {
        $role = tap(Role::make('abos-lesen')->addPermission('access cp')->addPermission('access subscriptions utility'))->save();

        return tap(User::make()->email(uniqid().'@example.com')->assignRole($role))->save();
    }

    protected function manager()
    {
        $role = tap(Role::make('abos-verwalten')
            ->addPermission('access cp')
            ->addPermission('access subscriptions utility')
            ->addPermission('manage payment subscriptions'))->save();

        return tap(User::make()->email(uniqid().'@example.com')->assignRole($role))->save();
    }

    #[Test]
    public function looking_at_subscriptions_is_not_changing_them(): void
    {
        $abo = $this->abo();
        $viewer = $this->viewer();

        $this->actingAs($viewer)->getJson('/cp/utilities/subscriptions')->assertOk();

        foreach ([PauseSubscription::handle(), SwitchSubscription::handle(), CancelSubscription::handle()] as $action) {
            $this->runAction($action, [$abo->getKey()], ['to' => 'plus'], $viewer)->assertForbidden();
        }

        $this->assertSame(Subscription::STATUS_ACTIVE, $abo->fresh()->status);
        $this->assertFalse((new ReleaseSubscription)->authorize($viewer, $abo));
    }

    #[Test]
    public function the_manage_permission_is_what_lets_somebody_pause(): void
    {
        $abo = $this->abo();

        $this->runAction(PauseSubscription::handle(), [$abo->getKey()], [], $this->manager())->assertOk();

        $this->assertSame(Subscription::STATUS_PAUSED, $abo->fresh()->status);
    }

    #[Test]
    public function switch_targets_stay_within_the_brand_or_the_list(): void
    {
        config(['statamic-payments.products.fremd' => ['name' => 'Fremd', 'amount_cent' => 2500, 'interval' => '1 month', 'brand_id' => 7]]);

        $this->assertSame(['plus' => 'Plus'], app(SubscriptionSwitches::class)->targetsFor($this->abo()));

        config(['statamic-payments.products.basis.switch_to' => ['fremd']]);

        $this->assertSame(['fremd' => 'Fremd'], app(SubscriptionSwitches::class)->targetsFor($this->abo()),
            'a list on the product is the operator saying so, brand or not');
    }

    #[Test]
    public function a_difference_that_later_fails_is_marked_on_the_agreement(): void
    {
        $abo = $this->abo();
        $this->runAction(SwitchSubscription::handle(), [$abo->getKey()], ['to' => 'plus'])->assertOk();

        $differenz = Payment::query()->whereNotNull('meta->subscription_change')->sole();
        $this->gateway->markStatus($differenz->provider_id, Payment::STATUS_FAILED);
        $this->postJson(route('statamic-payments.webhook'), ['id' => $differenz->provider_id])->assertOk();

        $meta = $abo->fresh()->meta;
        $this->assertSame($differenz->getKey(), $meta['switches'][0]['proration_failed_payment_id'] ?? null);

        $row = $this->actingAs($this->user())->getJson('/cp/utilities/subscriptions')->json('data.0');
        $this->assertStringContainsString(__('statamic-payments::subscriptions.history_proration_failed'), json_encode($row['history'], JSON_UNESCAPED_UNICODE));
    }

    #[Test]
    public function each_action_is_offered_only_where_it_can_work(): void
    {
        $laufend = $this->abo();
        $plan = $this->abo(['product' => 'raten', 'times' => 2, 'amount_cent' => 5000]);
        $pausiert = $this->abo(['status' => Subscription::STATUS_PAUSED, 'next_payment_at' => null]);

        $this->assertTrue((new PauseSubscription)->visibleTo($laufend));
        $this->assertFalse((new PauseSubscription)->visibleTo($plan));
        $this->assertFalse((new PauseSubscription)->visibleTo($pausiert));

        $this->assertTrue((new ResumeSubscription)->visibleTo($pausiert));
        $this->assertFalse((new ResumeSubscription)->visibleTo($laufend));

        $this->assertTrue((new SwitchSubscription)->visibleTo($laufend));
        $this->assertFalse((new SwitchSubscription)->visibleTo($plan));
        $this->assertFalse((new SwitchSubscription)->visibleToBulk(collect([$laufend, $this->abo()])));

        // A paused membership can still be ended for good.
        $this->assertTrue((new CancelSubscription)->visibleTo($pausiert));
    }

    #[Test]
    public function the_switch_field_lists_what_fits_this_row(): void
    {
        $field = collect((new SwitchSubscription)->items(collect([$this->abo()]))->toArray()['fields'])
            ->firstWhere('handle', 'to');

        $this->assertNotNull($field);
        $this->assertSame(['plus' => 'Plus'], $field['options']);
    }
}

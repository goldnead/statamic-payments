<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Actions\CancelSubscription;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/**
 * The subscriptions screen in the Control Panel.
 *
 * Two things here are worth more than the rest of the screen put together:
 *
 * 1. **Who may open it, and who may write through it.** The listing says who
 *    pays what every month; the two writing endpoints stop that money. Both are
 *    tested against a signed-in user who simply may not.
 * 2. **A cancellation that failed must arrive as a failure.** `cancel()` returns
 *    false when the provider refuses, and false reported as success is the worst
 *    outcome this package can produce: an account that says stopped while the
 *    money keeps going out.
 */
class CpSubscriptionsScreenTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic-payments.products', [
            'mitgliedschaft' => ['name' => 'Mitgliedschaft', 'amount_cent' => 1900, 'interval' => '1 month'],
            'ratenzahlung' => ['name' => 'Kurs in Raten', 'amount_cent' => 5000, 'interval' => '1 month', 'times' => 3],
        ]);
    }

    protected function subscription(array $overrides = []): Subscription
    {
        return Subscription::create(array_merge([
            'provider' => 'fake',
            'provider_id' => 'sub_'.uniqid(),
            'customer_reference' => 'cst_1',
            'product' => 'mitgliedschaft',
            'amount_cent' => 1900,
            'currency' => 'EUR',
            'interval' => '1 month',
            'times' => null,
            'times_charged' => 2,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now()->subMonths(2),
            'next_payment_at' => now()->addMonth(),
            'email' => 'kaeufer@example.com',
            'name' => 'Maria Beispiel',
        ], $overrides));
    }

    protected function user()
    {
        return tap(User::make()->email(uniqid().'@example.com')->makeSuper())->save();
    }

    /**
     * Signed in, may open the Control Panel, may not open this screen.
     *
     * (`makeSuper()` takes no argument. Passing `false` still makes a superuser,
     * which is how a test like this first proved the opposite of its claim.)
     */
    protected function userWithoutPermission()
    {
        $role = tap(Role::make('nur-cp')->addPermission('access cp'))->save();

        return tap(User::make()->email(uniqid().'@example.com')->assignRole($role))->save();
    }

    /** What both the row menu and the bulk toolbar post. */
    protected function runAction(array $ids, $user = null)
    {
        return $this->actingAs($user ?? $this->user())->postJson('/cp/utilities/subscriptions/actions', [
            'action' => CancelSubscription::handle(),
            'selections' => $ids,
            'context' => [],
            'values' => [],
        ]);
    }

    /** Filters travel as base64-encoded JSON, the way the Listing sends them. */
    protected function filter(array $filters): string
    {
        return base64_encode(json_encode($filters));
    }

    #[Test]
    public function it_is_closed_to_anyone_not_signed_in(): void
    {
        $this->subscription();

        $this->get('/cp/utilities/subscriptions')->assertRedirect();
    }

    #[Test]
    public function a_user_without_the_permission_is_refused(): void
    {
        $this->subscription();

        $user = $this->userWithoutPermission();

        $this->actingAs($user)
            ->get('/cp/utilities/subscriptions')
            ->assertRedirect(cp_route('index'));

        // The data behind the screen, which is the part that would leak: who is
        // subscribed, at what price, and until when.
        $json = $this->actingAs($user)->getJson('/cp/utilities/subscriptions');

        $json->assertForbidden();
        $json->assertDontSee('kaeufer@example.com');
        $json->assertDontSee('Maria Beispiel');
    }

    #[Test]
    public function a_user_without_the_permission_cannot_run_the_bulk_action(): void
    {
        $subscription = $this->subscription();

        $this->runAction([$subscription->id], $this->userWithoutPermission())->assertForbidden();

        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->fresh()->status);
        $this->assertSame([], $this->gateway->cancelled);
    }

    #[Test]
    public function an_inertia_visit_gets_a_page_and_not_a_bare_array(): void
    {
        $this->subscription();

        // An Inertia visit asks for JSON too. Answered with the plain listing
        // array, the Control Panel cannot read it and shows "Something went
        // wrong" on every visit, with nothing in any log.
        $response = $this->actingAs($this->user())
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => ''])
            ->getJson('/cp/utilities/subscriptions');

        $response->assertOk();
        $response->assertHeader('x-inertia', 'true');
        $this->assertSame('statamic-payments::Subscriptions/Index', $response->json('component'));

        // Every label finished server-side. A raw translation key where a label
        // belongs is the loudest possible "third-party addon".
        $this->assertSame('Subscriptions', $response->json('props.t.title'));
        $this->assertStringNotContainsString('statamic-payments::', json_encode($response->json('props.t')));
    }

    #[Test]
    public function every_listing_response_carries_its_columns(): void
    {
        $this->subscription();

        // The Listing reads `meta.columns` from every response. Missing, it
        // throws inside its own promise: red toast, working screen, no failed
        // request to chase.
        $response = $this->actingAs($this->user())->getJson('/cp/utilities/subscriptions');

        $this->assertIsArray($response->json('meta.columns'));
        $this->assertSame('product', $response->json('meta.columns.0.field'));
    }

    #[Test]
    public function the_column_choice_is_honoured(): void
    {
        $this->subscription();

        // Without `setPreferred()` the server answers every request with the
        // same fixed set, so the picker springs back and the saved preference is
        // ignored for ever.
        $visible = collect(
            $this->actingAs($this->user())
                ->getJson('/cp/utilities/subscriptions?columns=status,amount')
                ->json('meta.columns')
        )->where('visible', true)->pluck('field')->all();

        $this->assertSame(['status', 'amount'], $visible);
    }

    #[Test]
    public function the_amount_is_shown_as_money_and_not_as_cents(): void
    {
        $this->subscription(['amount_cent' => 1900]);

        $row = $this->actingAs($this->user())->getJson('/cp/utilities/subscriptions')->json('data.0');

        $this->assertSame('19.00', $row['amount']);
        $this->assertSame('EUR', $row['currency']);
    }

    #[Test]
    public function a_plan_counts_towards_its_end_and_a_subscription_does_not(): void
    {
        $this->subscription(['provider_id' => 'sub_abo', 'times' => null, 'times_charged' => 7]);
        $this->subscription([
            'provider_id' => 'sub_raten',
            'product' => 'ratenzahlung',
            'amount_cent' => 5000,
            'times' => 3,
            'times_charged' => 1,
            'next_payment_at' => now()->addDays(2),
        ]);

        $rows = collect($this->actingAs($this->user())->getJson('/cp/utilities/subscriptions')->json('data'))
            ->keyBy('provider_id');

        // The difference between the two is one column, and the screen has to
        // say which one it is looking at. `7 / null` is what a template that
        // guesses ships.
        $this->assertSame('1 / 3', $rows['sub_raten']['progress']);
        $this->assertTrue($rows['sub_raten']['is_plan']);
        $this->assertSame('Payment plan', $rows['sub_raten']['kind']);
        $this->assertSame('50.00', $rows['sub_raten']['amount']);
        $this->assertSame('150.00', $rows['sub_raten']['total']);

        $this->assertSame('7', $rows['sub_abo']['progress']);
        $this->assertFalse($rows['sub_abo']['is_plan']);
        $this->assertSame('Subscription', $rows['sub_abo']['kind']);
        $this->assertNull($rows['sub_abo']['total']);
    }

    #[Test]
    public function the_rhythm_is_put_into_words_and_an_unknown_one_is_shown_as_written(): void
    {
        $this->subscription(['provider_id' => 'sub_monat', 'interval' => '1 month']);
        $this->subscription(['provider_id' => 'sub_quartal', 'interval' => '3 months']);
        // The provider's vocabulary is the provider's to extend. Something this
        // package has no word for is more use shown raw than guessed at.
        $this->subscription(['provider_id' => 'sub_fremd', 'interval' => '2 fortnights']);

        $rows = collect($this->actingAs($this->user())->getJson('/cp/utilities/subscriptions')->json('data'))
            ->keyBy('provider_id');

        $this->assertSame('monthly', $rows['sub_monat']['rhythm']);
        $this->assertSame('every 3 months', $rows['sub_quartal']['rhythm']);
        $this->assertSame('2 fortnights', $rows['sub_fremd']['rhythm']);
    }

    #[Test]
    public function the_cycles_of_a_subscription_travel_with_its_row(): void
    {
        $subscription = $this->subscription();

        Payment::create([
            'provider' => 'fake',
            'provider_id' => 'tr_zyklus_1',
            'product' => 'mitgliedschaft',
            'amount_cent' => 1900,
            'currency' => 'EUR',
            'status' => Payment::STATUS_PAID,
            'subscription_id' => $subscription->id,
        ]);

        // The slide-over shows them. Fetching them when it opens would mean a
        // second endpoint and a spinner for records that were one eager load
        // away.
        $row = $this->actingAs($this->user())->getJson('/cp/utilities/subscriptions')->json('data.0');

        $this->assertCount(1, $row['payments']);
        $this->assertSame('19.00', $row['payments'][0]['amount']);
        $this->assertSame('Paid', $row['payments'][0]['status_label']);
    }

    #[Test]
    public function it_filters_by_status(): void
    {
        $this->subscription(['provider_id' => 'sub_laeuft']);
        $this->subscription(['provider_id' => 'sub_gekuendigt', 'status' => Subscription::STATUS_CANCELLED]);

        $user = $this->user();

        $cancelled = $this->filter(['subscription_status' => ['status' => 'cancelled']]);
        $response = $this->actingAs($user)->getJson('/cp/utilities/subscriptions?filters='.$cancelled);

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame('sub_gekuendigt', $response->json('data.0.provider_id'));

        // An unrecognised status filters nothing rather than everything: an
        // empty list would read as "there are no subscriptions".
        $erfunden = $this->filter(['subscription_status' => ['status' => 'erfunden']]);
        $this->assertSame(2, $this->actingAs($user)->getJson('/cp/utilities/subscriptions?filters='.$erfunden)->json('meta.total'));
    }

    #[Test]
    public function still_running_is_a_question_for_the_query_and_not_for_the_rows(): void
    {
        $this->subscription(['provider_id' => 'sub_aktiv', 'status' => Subscription::STATUS_ACTIVE]);
        $this->subscription(['provider_id' => 'sub_wartet', 'status' => Subscription::STATUS_PENDING]);
        $this->subscription(['provider_id' => 'sub_gekuendigt', 'status' => Subscription::STATUS_CANCELLED]);
        $this->subscription(['provider_id' => 'sub_fertig', 'status' => Subscription::STATUS_COMPLETED]);
        $this->subscription(['provider_id' => 'sub_begonnen', 'status' => Subscription::STATUS_INITIATED]);

        $user = $this->user();

        $live = $this->filter(['subscription_live' => ['running' => 'yes']]);
        $response = $this->actingAs($user)->getJson('/cp/utilities/subscriptions?filters='.$live);

        // `meta.total` is the assertion that matters. Dropping the finished ones
        // in PHP after the page was fetched leaves the pager counting rows the
        // screen does not show: a total that matches neither the list nor the
        // truth.
        $this->assertSame(2, $response->json('meta.total'));
        $this->assertEqualsCanonicalizing(
            ['sub_aktiv', 'sub_wartet'],
            collect($response->json('data'))->pluck('provider_id')->all()
        );

        $over = $this->filter(['subscription_live' => ['running' => 'no']]);
        $this->assertSame(3, $this->actingAs($user)->getJson('/cp/utilities/subscriptions?filters='.$over)->json('meta.total'));
    }

    #[Test]
    public function a_wildcard_in_the_search_is_not_a_wildcard(): void
    {
        $this->subscription(['name' => 'Maria Beispiel']);
        $this->subscription(['name' => '50% Rabatt', 'email' => 'rabatt@example.com']);

        $user = $this->user();

        // `%` and `_` are LIKE wildcards. Unescaped, a search for "%" returns
        // everything and reads as a filter that does not work.
        $this->assertSame(1, $this->actingAs($user)->getJson('/cp/utilities/subscriptions?search=50%25')->json('meta.total'));
        $this->assertSame(0, $this->actingAs($user)->getJson('/cp/utilities/subscriptions?search=_aria')->json('meta.total'));

        // And no injection either way: the value is bound, never concatenated.
        $this->assertSame(0, $this->actingAs($user)->getJson("/cp/utilities/subscriptions?search=' OR 1=1 --")->json('meta.total'));
        $this->assertSame(2, Subscription::count());
    }

    #[Test]
    public function it_refuses_to_sort_by_a_column_it_does_not_offer(): void
    {
        // Two rows whose order by `name` is the opposite of their order by
        // `next_payment_at`, so the two possible outcomes differ.
        $this->subscription(['provider_id' => 'sub_frueh', 'name' => 'Zacharias', 'next_payment_at' => now()->addDay()]);
        $this->subscription(['provider_id' => 'sub_spaet', 'name' => 'Anna', 'next_payment_at' => now()->addMonths(6)]);

        $response = $this->actingAs($this->user())
            ->getJson('/cp/utilities/subscriptions?sort=name&order=asc');

        $response->assertOk();

        // `name` is not offered for sorting, so the column falls back to
        // `next_payment_at` while the direction the caller asked for stands.
        // Had `name` been used, Anna would lead.
        $this->assertSame('sub_frueh', $response->json('data.0.provider_id'));
    }

    #[Test]
    public function money_sorts_by_the_integer_and_not_by_the_label(): void
    {
        $this->subscription(['amount_cent' => 900, 'provider_id' => 'sub_neun']);
        $this->subscription(['amount_cent' => 1900, 'provider_id' => 'sub_neunzehn']);

        // Ordering the formatted string would put "9.00" above "19.00".
        $response = $this->actingAs($this->user())->getJson('/cp/utilities/subscriptions?sort=amount&order=desc');

        $this->assertSame('sub_neunzehn', $response->json('data.0.provider_id'));
    }

    #[Test]
    public function the_action_cancels_every_selected_row(): void
    {
        $one = $this->subscription(['provider_id' => 'sub_eins']);
        $two = $this->subscription(['provider_id' => 'sub_zwei']);

        $response = $this->runAction([$one->id, $two->id]);

        $response->assertOk();
        $this->assertSame('2 subscriptions were cancelled.', $response->json('message'));
        $this->assertNull($response->json('_toasts'));

        $this->assertSame(Subscription::STATUS_CANCELLED, $one->fresh()->status);
        $this->assertSame(Subscription::STATUS_CANCELLED, $two->fresh()->status);
    }

    #[Test]
    public function one_row_runs_through_exactly_the_same_action(): void
    {
        $one = $this->subscription(['provider_id' => 'sub_allein']);

        // A row's own menu posts the same action with a selection of one. The
        // point of this test is that there is no second cancellation path to
        // get wrong — no route of this addon's own beside it.
        $this->runAction([$one->id])->assertOk();

        $this->assertSame(['sub_allein'], $this->gateway->cancelled);
        $this->assertSame(Subscription::STATUS_CANCELLED, $one->fresh()->status);
    }

    #[Test]
    public function a_cancellation_the_provider_refused_arrives_as_an_error(): void
    {
        $one = $this->subscription(['provider_id' => 'sub_eins']);
        $two = $this->subscription(['provider_id' => 'sub_zwei']);

        // The provider accepts the call and keeps the thing running. Reporting
        // that as done would be telling somebody their money has stopped while
        // it has not.
        $this->gateway->cancelLies = true;

        $response = $this->runAction([$one->id, $two->id]);

        $response->assertOk();

        // A toast pushed from the server, which is the only channel that
        // carries its own severity: core toasts an action's return value green
        // whatever it says, and turns a thrown exception into `success: false`
        // that it then toasts green as well.
        $toasts = collect($response->json('_toasts'));

        $this->assertCount(1, $toasts);
        $this->assertSame('error', $toasts->first()['type']);
        $this->assertStringContainsString('would not cancel 2 of 2', $toasts->first()['message']);

        // And no green one beside it. `false` rather than an empty string:
        // core toasts `message || 'Action completed'`.
        $this->assertFalse($response->json('message'));

        $this->assertSame(Subscription::STATUS_ACTIVE, $one->fresh()->status);
        $this->assertSame(Subscription::STATUS_ACTIVE, $two->fresh()->status);
    }

    #[Test]
    public function the_action_is_not_offered_on_anything_else(): void
    {
        $action = new CancelSubscription;

        // Actions are registered globally and offered on every listing in the
        // Control Panel. Without this, "Cancel subscription" turns up in the
        // bulk toolbar of the Entries screen.
        $this->assertFalse($action->visibleTo(Payment::make()));

        // And not on an agreement the provider has already stopped: that is a
        // call answered with a shrug and a failure nobody caused.
        $this->assertTrue($action->visibleTo($this->subscription()));
        $this->assertFalse($action->visibleTo($this->subscription([
            'provider_id' => 'sub_vorbei',
            'status' => Subscription::STATUS_COMPLETED,
        ])));
    }

    #[Test]
    public function the_screen_creates_nothing(): void
    {
        $this->subscription();
        $user = $this->user();

        // There is no form and no "New" button, and a route that does not exist
        // is the proof. An agreement is what a confirmed first payment leaves
        // behind, never something somebody types.
        $this->actingAs($user)->post('/cp/utilities/subscriptions')->assertNotFound();
        $this->actingAs($user)->patch('/cp/utilities/subscriptions/1')->assertNotFound();
        $this->actingAs($user)->delete('/cp/utilities/subscriptions/1')->assertNotFound();

        // And the one writing endpoint there is, is the action endpoint — not a
        // cancel route of this addon's own.
        $this->actingAs($user)->post('/cp/utilities/subscriptions/1/cancel')->assertNotFound();

        $this->assertSame(1, Subscription::count());
    }
}

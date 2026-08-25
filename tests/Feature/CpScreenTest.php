<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/**
 * The Control Panel screen.
 *
 * This one lists who bought what for how much, so who may look at it is the
 * first thing worth a test.
 */
class CpScreenTest extends TestCase
{
    protected function payment(array $overrides = []): Payment
    {
        return Payment::create(array_merge([
            'provider' => 'fake',
            'provider_id' => 'tr_'.uniqid(),
            'product' => 'noten-paket',
            'amount_cent' => 1900,
            'currency' => 'EUR',
            'status' => Payment::STATUS_PAID,
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
     * (`makeSuper()` takes no argument. Passing `false` still makes a
     * superuser, which is how this test first proved the opposite of what it
     * claimed.)
     */
    protected function userWithoutPermission()
    {
        $role = tap(Role::make('nur-cp')->addPermission('access cp'))->save();

        return tap(User::make()->email(uniqid().'@example.com')->assignRole($role))->save();
    }

    #[Test]
    public function it_is_closed_to_anyone_not_signed_in(): void
    {
        $this->payment();

        $this->get('/cp/utilities/payments')->assertRedirect();
    }

    #[Test]
    public function a_user_without_the_permission_is_refused(): void
    {
        $this->payment();

        $user = $this->userWithoutPermission();

        $this->actingAs($user)
            ->get('/cp/utilities/payments')
            ->assertRedirect(cp_route('index'));

        // The data behind the screen, which is the part that would leak: who
        // bought, their address, and what they paid.
        $json = $this->actingAs($user)->getJson('/cp/utilities/payments');

        $json->assertForbidden();
        $json->assertDontSee('kaeufer@example.com');
        $json->assertDontSee('Maria Beispiel');
    }

    #[Test]
    public function an_inertia_visit_gets_a_page_and_not_a_bare_array(): void
    {
        $this->payment();

        // An Inertia visit asks for JSON too. Answered with the plain listing
        // array, the Control Panel cannot read it and shows "Something went
        // wrong" on every visit, with nothing in any log.
        $response = $this->actingAs($this->user())
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => ''])
            ->getJson('/cp/utilities/payments');

        $response->assertOk();
        $response->assertHeader('x-inertia', 'true');
        $this->assertSame('statamic-payments::Payments/Index', $response->json('component'));
    }

    #[Test]
    public function every_listing_response_carries_its_columns(): void
    {
        $this->payment();

        // The Listing reads `meta.columns` from every response. Missing, it
        // throws inside its own promise: red toast, working screen, no failed
        // request to chase.
        $response = $this->actingAs($this->user())->getJson('/cp/utilities/payments');

        $this->assertIsArray($response->json('meta.columns'));
        $this->assertSame('created_at', $response->json('meta.columns.0.field'));
    }

    #[Test]
    public function the_amount_is_shown_as_money_and_not_as_cents(): void
    {
        $this->payment(['amount_cent' => 1900]);

        // The integer is the truth in the database; the screen must not be the
        // place where somebody divides by a hundred.
        $row = $this->actingAs($this->user())->getJson('/cp/utilities/payments')->json('data.0');

        $this->assertSame('19.00', $row['amount']);
        $this->assertSame('EUR', $row['currency']);
    }

    /** Filters travel as base64-encoded JSON, the way the Listing sends them. */
    protected function filter(array $filters): string
    {
        return base64_encode(json_encode($filters));
    }

    #[Test]
    public function it_can_show_what_was_paid_but_never_fulfilled(): void
    {
        $this->payment(['fulfilled_at' => now(), 'provider_id' => 'tr_erfuellt']);
        $this->payment(['fulfilled_at' => null, 'provider_id' => 'tr_offen']);

        // The one question this screen exists for, and the one the provider
        // cannot answer: money arrived, did the buyer get anything?
        //
        // As a real scope filter, not as a query parameter of my own. The
        // Listing builds its requests from a fixed set of keys and rewrites the
        // address bar from the same set, so `?unfulfilled=1` survived exactly
        // one page load before the component replaced the filtered list with
        // the unfiltered one — while the README advertised the feature.
        $unfulfilled = $this->filter(['payment_fulfilment' => ['fulfilment' => 'unfulfilled']]);

        $response = $this->actingAs($this->user())->getJson('/cp/utilities/payments?filters='.$unfulfilled);

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame('tr_offen', $response->json('data.0.provider_id'));
    }

    #[Test]
    public function the_screen_writes_nothing(): void
    {
        $payment = $this->payment();
        $user = $this->user();

        // Read-only is a claim about a route, and a route that only registers
        // GET is the proof. Refunds belong at Mollie.
        $this->actingAs($user)->post('/cp/utilities/payments')->assertNotFound();
        $this->actingAs($user)->delete('/cp/utilities/payments/'.$payment->id)->assertNotFound();
        $this->actingAs($user)->patch('/cp/utilities/payments/'.$payment->id)->assertNotFound();

        $this->actingAs($user)->getJson('/cp/utilities/payments')->assertOk();

        $this->assertSame(1, Payment::count());
        $this->assertNull(Payment::first()->fulfilled_at);
        $this->assertSame(1900, Payment::first()->amount_cent);
    }

    #[Test]
    public function a_wildcard_in_the_search_is_not_a_wildcard(): void
    {
        $this->payment(['name' => 'Maria Beispiel']);
        $this->payment(['name' => '50% Rabatt', 'email' => 'rabatt@example.com']);

        $user = $this->user();

        // `%` and `_` are LIKE wildcards. Unescaped, a search for "%" returns
        // everything and reads as a filter that does not work.
        $this->assertSame(1, $this->actingAs($user)->getJson('/cp/utilities/payments?search=50%25')->json('meta.total'));
        $this->assertSame(0, $this->actingAs($user)->getJson('/cp/utilities/payments?search=_aria')->json('meta.total'));

        // And no injection either way: the value is bound, never concatenated.
        $this->assertSame(0, $this->actingAs($user)->getJson("/cp/utilities/payments?search=' OR 1=1 --")->json('meta.total'));
        $this->assertSame(2, Payment::count());
    }

    #[Test]
    public function money_sorts_by_the_integer_and_not_by_the_label(): void
    {
        $this->payment(['amount_cent' => 900, 'provider_id' => 'tr_neun']);
        $this->payment(['amount_cent' => 1900, 'provider_id' => 'tr_neunzehn']);

        // Ordering the formatted string would put "9.00" above "19.00".
        $response = $this->actingAs($this->user())->getJson('/cp/utilities/payments?sort=amount&order=desc');

        $this->assertSame('tr_neunzehn', $response->json('data.0.provider_id'));
    }

    #[Test]
    public function the_column_choice_is_honoured(): void
    {
        $this->payment();

        // Before this, the server answered every request with the same fixed
        // set of visible columns, so the picker sprang back and the saved
        // preference was ignored for ever.
        $visible = collect(
            $this->actingAs($this->user())
                ->getJson('/cp/utilities/payments?columns=status,amount')
                ->json('meta.columns')
        )->where('visible', true)->pluck('field')->all();

        $this->assertSame(['status', 'amount'], $visible);
    }

    #[Test]
    public function it_searches_and_filters(): void
    {
        $this->payment(['email' => 'maria@example.com']);
        $this->payment(['email' => 'jonas@example.com', 'status' => Payment::STATUS_FAILED]);

        $user = $this->user();

        $this->assertSame(1, $this->actingAs($user)->getJson('/cp/utilities/payments?search=jonas')->json('meta.total'));

        $failed = $this->filter(['payment_status' => ['status' => 'failed']]);
        $this->assertSame(1, $this->actingAs($user)->getJson('/cp/utilities/payments?filters='.$failed)->json('meta.total'));

        // An unrecognised status filters nothing rather than everything: an
        // empty list would read as "no payments", a different statement.
        $erfunden = $this->filter(['payment_status' => ['status' => 'erfunden']]);
        $this->assertSame(2, $this->actingAs($user)->getJson('/cp/utilities/payments?filters='.$erfunden)->json('meta.total'));
    }

    #[Test]
    public function it_refuses_to_sort_by_a_column_it_does_not_offer(): void
    {
        // Two rows whose order by `name` is the opposite of their order by
        // `created_at`, so the two possible outcomes differ. Asserting only
        // `assertOk()` proved nothing: it would pass just as happily if `sort`
        // went straight into `orderBy`.
        $alt = $this->payment(['provider_id' => 'tr_alt', 'name' => 'Zacharias']);
        $alt->forceFill(['created_at' => now()->subDays(5)])->save();
        $this->payment(['provider_id' => 'tr_neu', 'name' => 'Anna']);

        $response = $this->actingAs($this->user())
            ->getJson('/cp/utilities/payments?sort=name&order=asc');

        $response->assertOk();

        // `name` is not offered for sorting, so the column falls back to
        // `created_at` while the direction the caller asked for stands: oldest
        // first. Had `name` been used, Anna would lead.
        $this->assertSame('tr_alt', $response->json('data.0.provider_id'));
    }
}

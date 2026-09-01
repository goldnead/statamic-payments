<?php

namespace Goldnead\StatamicPayments\Tests\Feature\Legal;

use Goldnead\StatamicPayments\Models\Cancellation;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Withdrawal;
use Goldnead\StatamicPayments\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/**
 * Die Bildschirme „Widerrufe" und „Kündigungen" im Control Panel.
 *
 * Wer sie sehen darf, ist die erste Frage: dahinter stehen Namen und Adressen
 * von Leuten, die einen Vertrag lösen wollen. Die zweite: dass „erledigt" ein
 * eigenes Recht ist und nicht mit dem Lesen mitkommt.
 */
class LegalCpTest extends TestCase
{
    protected function withdrawal(array $overrides = []): Withdrawal
    {
        return Withdrawal::create(array_merge([
            'public_id' => 'W-'.strtoupper(substr(uniqid(), -8)),
            'name' => 'Anna Beispiel',
            'email' => 'anna@example.de',
            'order_reference' => 'tr_abc',
            'contact' => 'anna@example.de',
            'declared_at' => now()->subMinute(),
            'confirmed_at' => now(),
        ], $overrides));
    }

    protected function cancellation(array $overrides = []): Cancellation
    {
        return Cancellation::create(array_merge([
            'public_id' => 'K-'.strtoupper(substr(uniqid(), -8)),
            'name' => 'Boris Beispiel',
            'email' => 'boris@example.de',
            'identification' => 'sub_9',
            'kind' => Cancellation::KIND_ORDINARY,
            'declared_at' => now()->subMinute(),
            'confirmed_at' => now(),
        ], $overrides));
    }

    protected function superuser()
    {
        return tap(User::make()->email(uniqid().'@example.com')->makeSuper())->save();
    }

    protected function userWith(string ...$permissions)
    {
        $role = Role::make('rolle-'.uniqid())->addPermission('access cp');

        foreach ($permissions as $permission) {
            $role->addPermission($permission);
        }

        $role->save();

        return tap(User::make()->email(uniqid().'@example.com')->assignRole($role))->save();
    }

    #[Test]
    public function both_screens_are_closed_to_anyone_not_signed_in(): void
    {
        $this->withdrawal();
        $this->cancellation();

        $this->get('/cp/utilities/withdrawals')->assertRedirect();
        $this->get('/cp/utilities/cancellations')->assertRedirect();
    }

    #[Test]
    public function a_user_without_the_permission_gets_neither_the_page_nor_the_data(): void
    {
        $this->withdrawal();
        $this->cancellation();

        $user = $this->userWith();

        $this->actingAs($user)->get('/cp/utilities/withdrawals')->assertRedirect(cp_route('index'));
        $this->actingAs($user)->getJson('/cp/utilities/withdrawals')->assertForbidden()->assertDontSee('anna@example.de');

        $this->actingAs($user)->get('/cp/utilities/cancellations')->assertRedirect(cp_route('index'));
        $this->actingAs($user)->getJson('/cp/utilities/cancellations')->assertForbidden()->assertDontSee('boris@example.de');
    }

    #[Test]
    public function the_utility_permission_opens_the_listing(): void
    {
        $w = $this->withdrawal();
        $this->cancellation();

        $reader = $this->userWith('access withdrawals utility');

        $response = $this->actingAs($reader)->getJson('/cp/utilities/withdrawals');

        $response->assertOk();
        $this->assertSame($w->public_id, $response->json('data.0.public_id'));
        $this->assertIsArray($response->json('meta.columns'));
        $this->assertSame('public_id', $response->json('meta.columns.0.field'));

        // Das eine Recht öffnet nicht die andere Liste.
        $this->actingAs($reader)->getJson('/cp/utilities/cancellations')->assertForbidden();
    }

    #[Test]
    public function an_inertia_visit_gets_the_page(): void
    {
        $this->withdrawal();

        $response = $this->actingAs($this->superuser())
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => ''])
            ->getJson('/cp/utilities/withdrawals');

        $response->assertOk();
        $this->assertSame('statamic-payments::Withdrawals/Index', $response->json('component'));
        $this->assertNotEmpty($response->json('props.actionUrl'));
    }

    #[Test]
    public function only_confirmed_declarations_are_listed(): void
    {
        $this->withdrawal(['confirmed_at' => null, 'email' => 'abgebrochen@example.de']);
        $this->withdrawal();

        $response = $this->actingAs($this->superuser())->getJson('/cp/utilities/withdrawals');

        $this->assertSame(1, $response->json('meta.total'));
        $response->assertDontSee('abgebrochen@example.de');
    }

    #[Test]
    public function the_matched_payment_and_the_hints_travel_with_the_row(): void
    {
        $payment = Payment::create([
            'provider' => 'fake',
            'provider_id' => 'tr_abc',
            'product' => 'noten-paket',
            'amount_cent' => 1900,
            'currency' => 'EUR',
            'status' => Payment::STATUS_PAID,
            'paid_at' => now()->subDays(20),
            'email' => 'anna@example.de',
        ]);

        $this->withdrawal(['payment_id' => $payment->getKey(), 'right_expired_hint' => true]);

        $row = $this->actingAs($this->superuser())->getJson('/cp/utilities/withdrawals')->json('data.0');

        $this->assertSame($payment->getKey(), $row['payment']['id']);
        $this->assertSame('19.00', $row['payment']['amount']);
        $this->assertTrue($row['right_expired_hint']);
        $this->assertFalse($row['within_period']);
    }

    #[Test]
    public function marking_as_handled_needs_its_own_permission(): void
    {
        $w = $this->withdrawal();

        $reader = $this->userWith('access withdrawals utility');

        // Lesen ja, erledigen nein. Core lehnt eine Action ab, die der
        // Benutzer auf einem der gewählten Elemente nicht ausführen darf.
        $this->actingAs($reader)->postJson('/cp/utilities/withdrawals/actions', [
            'action' => 'statamic_payments_mark_withdrawal_handled',
            'selections' => [$w->getKey()],
            'values' => ['note' => 'erstattet'],
        ])->assertForbidden();

        $this->assertNull($w->fresh()->handled_at);
    }

    #[Test]
    public function marking_as_handled_writes_the_moment_and_the_note_once(): void
    {
        $w = $this->withdrawal();
        $handler = $this->userWith('access withdrawals utility', 'handle payment withdrawals');

        $this->actingAs($handler)->postJson('/cp/utilities/withdrawals/actions', [
            'action' => 'statamic_payments_mark_withdrawal_handled',
            'selections' => [$w->getKey()],
            'values' => ['note' => 'Erstattet am 02.09.'],
        ])->assertOk();

        $fresh = $w->fresh();

        $this->assertNotNull($fresh->handled_at);
        $this->assertSame('Erstattet am 02.09.', $fresh->handled_note);

        // Ein zweites Mal ändert das Datum nicht: die Action ist auf
        // erledigten Zeilen gar nicht sichtbar, und die Schreibung ist
        // bedingt.
        $first = $fresh->handled_at;
        $this->travel(10)->minutes();
        $this->actingAs($handler)->postJson('/cp/utilities/withdrawals/actions', [
            'action' => 'statamic_payments_mark_withdrawal_handled',
            'selections' => [$w->getKey()],
            'values' => ['note' => 'nochmal'],
        ]);

        $this->assertEquals($first, $w->fresh()->handled_at);
        $this->assertSame('Erstattet am 02.09.', $w->fresh()->handled_note);
    }

    #[Test]
    public function the_cancellation_action_works_the_same_way(): void
    {
        $c = $this->cancellation();
        $handler = $this->userWith('access cancellations utility', 'handle payment cancellations');

        $this->actingAs($handler)->postJson('/cp/utilities/cancellations/actions', [
            'action' => 'statamic_payments_mark_cancellation_handled',
            'selections' => [$c->getKey()],
            'values' => ['note' => ''],
        ])->assertOk();

        $this->assertNotNull($c->fresh()->handled_at);
        $this->assertNull($c->fresh()->handled_note);
    }

    #[Test]
    public function the_open_filter_shows_what_is_still_to_do(): void
    {
        $this->withdrawal(['handled_at' => now(), 'email' => 'erledigt@example.de']);
        $this->withdrawal(['email' => 'offen@example.de']);

        $filter = base64_encode(json_encode(['legal_handled' => ['handled' => 'open']]));

        $response = $this->actingAs($this->superuser())->getJson('/cp/utilities/withdrawals?filters='.$filter);

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame('offen@example.de', $response->json('data.0.email'));
    }

    #[Test]
    public function search_finds_by_reference_and_address(): void
    {
        $this->withdrawal(['public_id' => 'W-ABCDEFGH']);
        $this->withdrawal(['email' => 'zoe@example.de', 'order_reference' => 'tr_zoe']);

        $user = $this->superuser();

        $this->assertSame(1, $this->actingAs($user)->getJson('/cp/utilities/withdrawals?search=ABCDEFGH')->json('meta.total'));
        $this->assertSame(1, $this->actingAs($user)->getJson('/cp/utilities/withdrawals?search=tr_zoe')->json('meta.total'));
        $this->assertSame(0, $this->actingAs($user)->getJson('/cp/utilities/withdrawals?search=%25')->json('meta.total'));
    }
}

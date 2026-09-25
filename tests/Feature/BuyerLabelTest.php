<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Http\Resources\Cp\ListedPayment;
use Goldnead\StatamicPayments\Http\Resources\Cp\PaymentDetail;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\BuyerSubject;
use Goldnead\StatamicPayments\Tests\Support\PersonStandIn;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

/**
 * The buyer column in the Control Panel.
 *
 * ChoirLive buys `for` an Eloquent user without a morph alias. The stored
 * subject type is then the class name, and the list read
 * "App\ Models\ User Lifetime Eins Muster" (Gesamtprüfung 25.09.2026,
 * shots/G-23a). A person reads a word, never a class name; and a purchase for
 * the buyer themselves is no "bought for" at all.
 */
class BuyerLabelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('test_people', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email');
            $table->string('password')->nullable();
        });

        BuyerSubject::forget();
    }

    private function paymentFor(PersonStandIn $subject, string $email): Payment
    {
        return Payment::create([
            'provider' => 'mollie',
            'provider_id' => 'tr_'.uniqid(),
            'product' => 'noten-paket',
            'amount_cent' => 1900,
            'currency' => 'EUR',
            'status' => Payment::STATUS_PAID,
            'paid_at' => now(),
            'email' => $email,
            'name' => 'Lara Muster',
            'meta' => ['entitlement_subject' => ['type' => PersonStandIn::class, 'id' => (string) $subject->getKey()]],
        ]);
    }

    #[Test]
    public function a_user_model_without_a_morph_alias_is_named_as_a_user_not_as_a_class(): void
    {
        $lara = PersonStandIn::create(['name' => 'Lara Muster', 'email' => 'lara@example.com']);

        $described = BuyerSubject::describe(['entitlement_subject' => ['type' => PersonStandIn::class, 'id' => (string) $lara->id]]);

        $this->assertSame(__('statamic-payments::messages.subject_type_user'), $described['type_label']);
        $this->assertSame(__('statamic-payments::messages.subject_type_user').' Lara Muster', $described['display']);
        $this->assertStringNotContainsString('\\', $described['display']);
    }

    #[Test]
    public function a_purchase_for_the_buyer_themselves_shows_no_second_name_in_the_list(): void
    {
        $lara = PersonStandIn::create(['name' => 'Lara Muster', 'email' => 'lara@example.com']);
        $payment = $this->paymentFor($lara, 'LARA@example.com');

        $row = (new ListedPayment($payment))->toArray(Request::create('/'));

        $this->assertNull($row['subject_display']);
        $this->assertSame('lara@example.com', strtolower($row['email']));
    }

    #[Test]
    public function a_purchase_for_another_person_names_them_readably(): void
    {
        $kim = PersonStandIn::create(['name' => 'Kim Beispiel', 'email' => 'kim@example.com']);
        $payment = $this->paymentFor($kim, 'lara@example.com');

        $row = (new ListedPayment($payment))->toArray(Request::create('/'));

        $this->assertSame(__('statamic-payments::messages.subject_type_user').' Kim Beispiel', $row['subject_display']);
    }

    #[Test]
    public function the_detail_page_leaves_out_bought_for_when_it_is_the_buyer(): void
    {
        $lara = PersonStandIn::create(['name' => 'Lara Muster', 'email' => 'lara@example.com']);
        $payment = $this->paymentFor($lara, 'lara@example.com');

        $detail = (new PaymentDetail($payment))->toArray(Request::create('/'));

        $this->assertNull(data_get($detail, 'buyer.subject'));
    }
}

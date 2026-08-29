<?php

namespace Goldnead\StatamicPayments\Tests\Support;

use Goldnead\StatamicPayments\Contracts\MandateGateway;
use Goldnead\StatamicPayments\Support\CheckoutSession;
use RuntimeException;

/**
 * A provider that can take a new payment method — and is as unforgiving as
 * Mollie is about how it is asked.
 *
 * The real one refuses a customer it has never heard of, refuses a payment with
 * no amount, and cannot send anybody back without a return address. A double
 * that shrugged at all three would let a broken payload pass a green test and
 * fail on the first real buyer, which is the failure shape this family has paid
 * for before.
 */
class MandateFakeGateway extends FakeGateway implements MandateGateway
{
    public bool $refuseMandateUpdate = false;

    public int $mandateUpdatesStarted = 0;

    /** @var array<string, mixed> */
    public array $lastMandatePayload = [];

    public ?string $lastMandateCustomer = null;

    public function supportsMandateUpdate(): bool
    {
        return ! $this->refuseMandateUpdate;
    }

    public function mandateVerificationCent(): int
    {
        return max(1, (int) config('statamic-payments.portal.mandate_verification_cent', 1));
    }

    public function startMandateUpdate(string $customerReference, array $payload): CheckoutSession
    {
        if ($this->refuseMandateUpdate) {
            throw new RuntimeException('this provider would not start a mandate update');
        }

        // What Mollie does: a customer id it did not issue is a 404.
        if (! in_array($customerReference, $this->mandates, true)) {
            throw new RuntimeException('no such customer: '.$customerReference);
        }

        foreach (['amount', 'description', 'redirectUrl'] as $required) {
            if (empty($payload[$required])) {
                throw new RuntimeException('a mandate update needs a '.$required);
            }
        }

        if (empty($payload['amount']['currency']) || empty($payload['amount']['value'])) {
            throw new RuntimeException('an amount is a currency and a value');
        }

        // The one thing this must *not* have. A webhook here delivers a paid
        // payment with no local row into the fulfilment path, where it is
        // correctly read as a phantom purchase and logged as an alarm.
        if (array_key_exists('webhookUrl', $payload)) {
            throw new RuntimeException('a mandate update must not carry a webhook URL');
        }

        $this->mandateUpdatesStarted++;
        $this->lastMandateCustomer = $customerReference;
        $this->lastMandatePayload = $payload;

        return new CheckoutSession(
            providerId: 'tr_mandat_'.$this->mandateUpdatesStarted,
            checkoutUrl: 'https://checkout.example/mandat/'.$this->mandateUpdatesStarted,
        );
    }
}

<?php

namespace Goldnead\StatamicPayments\Tests\Unit;

use Goldnead\StatamicPayments\Gateways\StripeGateway;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;

/**
 * Stripe's signature scheme, against real Stripe test material.
 *
 * The header below is the shape Stripe actually sends, and the digest is
 * computed the way Stripe's own libraries compute it: HMAC-SHA256 over
 * `<timestamp>.<raw body>` under the endpoint's signing secret. A test that
 * asked the implementation to sign and then verify its own output would only
 * prove it agrees with itself.
 */
class StripeSignatureTest extends TestCase
{
    /**
     * A body Stripe really sends, trimmed to the fields this package reads.
     *
     * Kept as a raw string, not re-encoded from an array: the signature covers
     * the exact bytes, so a test that rebuilt the JSON would be signing
     * something the endpoint never sees.
     */
    protected string $body = '{"id":"evt_1PabcTest","object":"event","type":"checkout.session.completed","data":{"object":{"id":"cs_test_a1b2c3","object":"checkout.session","payment_status":"paid","status":"complete"}}}';

    protected string $secret = 'whsec_kJ8vN2mQ4pR7sT1uV3wX5yZ6aB8cD0eF';

    protected function header(int $timestamp, ?string $secret = null, ?string $body = null): string
    {
        $signature = hash_hmac('sha256', $timestamp.'.'.($body ?? $this->body), $secret ?? $this->secret);

        return "t={$timestamp},v1={$signature}";
    }

    /**
     * A signature computed outside this codebase, frozen as a constant.
     *
     * Every other case here builds its header with `hash_hmac()` — the same
     * call the implementation makes — which proves the parsing and the
     * comparison but not the *signed payload*. If `verifySignature()` joined
     * body and timestamp the other way round, or signed the decoded body
     * instead of the raw bytes, implementation and test would agree and both be
     * wrong.
     *
     * So this digest was produced by `openssl dgst -sha256 -hmac …` over
     * `1720000000.<body>` and written down. Nothing in the suite can regenerate
     * it. A change to how the payload is assembled fails right here.
     *
     * The body is Stripe's own event shape at API version 2024-06-20, kept as
     * raw bytes: the signature covers the exact characters, so re-encoding the
     * JSON would be signing something the endpoint never sees.
     */
    protected string $recordedBody = '{"id":"evt_3PqR7bK8mNvL2xYz","object":"event","api_version":"2024-06-20","created":1720000000,"livemode":false,"type":"checkout.session.completed","data":{"object":{"id":"cs_test_b1TgLmQ9wXeR4uKpZoY7","object":"checkout.session","payment_status":"paid","status":"complete","currency":"eur","amount_total":1900}}}';

    protected int $recordedAt = 1720000000;

    protected string $recordedSignature = '8ce357be9be443e2ac58e405e99b38f19f90a83a4584c1d96341123e06e7184c';

    #[Test]
    public function a_recorded_stripe_signature_verifies_against_a_digest_this_suite_cannot_regenerate(): void
    {
        // Frozen at the moment the delivery was recorded, so the tolerance
        // window is about the recording and not about when the test runs.
        Carbon::setTestNow(Carbon::createFromTimestampUTC($this->recordedAt));

        $this->assertTrue(StripeGateway::verifySignature(
            $this->recordedBody,
            "t={$this->recordedAt},v1={$this->recordedSignature}",
            $this->secret,
        ));

        // And the same recording with one byte changed does not.
        $this->assertFalse(StripeGateway::verifySignature(
            str_replace('"amount_total":1900', '"amount_total":100', $this->recordedBody),
            "t={$this->recordedAt},v1={$this->recordedSignature}",
            $this->secret,
        ));

        Carbon::setTestNow();
    }

    #[Test]
    public function a_signature_stripe_made_is_accepted(): void
    {
        $this->assertTrue(StripeGateway::verifySignature(
            $this->body,
            $this->header(Carbon::now()->getTimestamp()),
            $this->secret,
        ));
    }

    #[Test]
    public function a_signature_made_with_another_secret_is_refused(): void
    {
        $this->assertFalse(StripeGateway::verifySignature(
            $this->body,
            $this->header(Carbon::now()->getTimestamp(), 'whsec_somebody_elses_secret'),
            $this->secret,
        ));
    }

    #[Test]
    public function a_body_changed_after_signing_is_refused(): void
    {
        // The attack the signature exists for: a genuine event, one field
        // rewritten. The amount, the type, the session id — any of them.
        $header = $this->header(Carbon::now()->getTimestamp());
        $tampered = str_replace('"payment_status":"paid"', '"payment_status":"unpaid"', $this->body);

        $this->assertFalse(StripeGateway::verifySignature($tampered, $header, $this->secret));
    }

    #[Test]
    public function a_signature_from_last_week_is_refused(): void
    {
        // Correctly signed, and still not acceptable. Without the timestamp
        // check a body captured once could be replayed for ever, and a
        // signature that never expires only has to leak once.
        $stale = Carbon::now()->subDay()->getTimestamp();

        $this->assertFalse(StripeGateway::verifySignature($this->body, $this->header($stale), $this->secret));
    }

    #[Test]
    public function a_missing_header_or_secret_is_refused(): void
    {
        $header = $this->header(Carbon::now()->getTimestamp());

        $this->assertFalse(StripeGateway::verifySignature($this->body, null, $this->secret));
        $this->assertFalse(StripeGateway::verifySignature($this->body, '', $this->secret));
        // An unconfigured site refuses everything rather than accepting
        // everything. The other way round is a webhook anybody can post to.
        $this->assertFalse(StripeGateway::verifySignature($this->body, $header, ''));
    }

    #[Test]
    public function a_header_without_a_v1_digest_is_refused(): void
    {
        $now = Carbon::now()->getTimestamp();

        $this->assertFalse(StripeGateway::verifySignature($this->body, "t={$now}", $this->secret));
        $this->assertFalse(StripeGateway::verifySignature($this->body, 'nonsense', $this->secret));
        // v0 is Stripe's Connect scheme and is not this one; accepting it
        // because the header parsed would be accepting an unchecked digest.
        $this->assertFalse(StripeGateway::verifySignature($this->body, "t={$now},v0=deadbeef", $this->secret));
    }

    #[Test]
    public function a_second_digest_alongside_the_right_one_still_verifies(): void
    {
        // Stripe sends two during a secret rotation. Refusing the header
        // because one of them does not match would take the endpoint down in
        // the middle of an ordinary key change.
        $now = Carbon::now()->getTimestamp();
        $good = hash_hmac('sha256', $now.'.'.$this->body, $this->secret);

        $this->assertTrue(StripeGateway::verifySignature(
            $this->body,
            "t={$now},v1=0000000000000000000000000000000000000000000000000000000000000000,v1={$good}",
            $this->secret,
        ));
    }
}

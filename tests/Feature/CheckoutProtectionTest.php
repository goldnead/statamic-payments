<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Events\CheckoutBlocked;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\Checkout;
use Goldnead\StatamicPayments\Support\CheckoutGuard;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;

/**
 * The door in front of the checkout (P7).
 *
 * Every refusal is checked for the two things that make it a refusal: no row
 * was written, and the provider was never called.
 */
class CheckoutProtectionTest extends TestCase
{
    protected function start(string $email = 'kim@example.com'): mixed
    {
        return app(Checkout::class)->start('noten-paket', ['email' => $email]);
    }

    protected function fromIp(string $ip): void
    {
        $this->app['request']->server->set('REMOTE_ADDR', $ip);
    }

    protected function assertRefused(mixed $result, string $reason): void
    {
        $this->assertNull($result, 'the checkout went ahead');
        $this->assertSame(0, Payment::count(), 'a refused checkout left a row behind');
        $this->assertSame(0, $this->gateway->created, 'the provider was called for a refused checkout');
        Event::assertDispatched(CheckoutBlocked::class, fn ($e) => $e->reason === $reason);
    }

    #[Test]
    public function nothing_is_in_the_way_by_default(): void
    {
        $this->assertNotNull($this->start());
    }

    #[Test]
    public function a_blocked_address_is_refused_whatever_its_case(): void
    {
        Event::fake([CheckoutBlocked::class]);
        config(['statamic-payments.protection.blocklist.emails' => ['Betrug@Example.com']]);

        $this->assertRefused($this->start('betrug@example.com'), 'blocked_email');
    }

    #[Test]
    public function a_blocked_domain_takes_its_subdomains_with_it(): void
    {
        Event::fake([CheckoutBlocked::class]);
        config(['statamic-payments.protection.blocklist.domains' => ['@wegwerf.example']]);

        $this->assertRefused($this->start('x@mail.wegwerf.example'), 'blocked_domain');
    }

    #[Test]
    public function a_domain_that_only_ends_the_same_is_not_blocked(): void
    {
        config(['statamic-payments.protection.blocklist.domains' => ['werf.example']]);

        $this->assertNotNull($this->start('x@wegwerf.example'));
    }

    #[Test]
    public function a_blocked_range_of_addresses_is_refused(): void
    {
        Event::fake([CheckoutBlocked::class]);
        config(['statamic-payments.protection.blocklist.ips' => "198.51.100.4\n203.0.113.0/24"]);
        $this->fromIp('203.0.113.77');

        $this->assertRefused($this->start(), 'blocked_ip');
    }

    #[Test]
    public function a_run_of_checkouts_from_one_address_is_braked(): void
    {
        Event::fake([CheckoutBlocked::class]);
        config(['statamic-payments.protection.rate_limit.per_email' => 2]);

        $this->assertNotNull($this->start());
        $this->assertNotNull($this->start());

        $vorher = Payment::count();
        $this->assertNull($this->start());
        $this->assertSame($vorher, Payment::count());
        Event::assertDispatched(CheckoutBlocked::class, fn ($e) => $e->reason === 'rate_limited');

        // Somebody else on the same connection is not affected by the address brake.
        $this->assertNotNull($this->start('anderer@example.com'));
    }

    #[Test]
    public function a_run_from_one_ip_is_braked_too(): void
    {
        config(['statamic-payments.protection.rate_limit.per_ip' => 2]);
        // A public address: a private one is not counted, see below.
        $this->fromIp('185.199.108.10');

        $this->start('a@example.com');
        $this->start('b@example.com');

        $this->assertNull($this->start('c@example.com'));
    }

    // ------------------------------------------- Gauntlet 2: behind a proxy

    #[Test]
    public function behind_a_proxy_nobody_trusts_everybody_is_the_same_address_so_it_is_not_counted(): void
    {
        CheckoutGuard::forgetWarnings();
        Log::spy();
        config(['statamic-payments.protection.rate_limit.per_ip' => 2]);
        $this->fromIp('10.0.0.5');
        $this->app['request']->headers->set('X-Forwarded-For', '185.199.108.10');

        $this->assertNotNull($this->start('a@example.com'));
        $this->assertNotNull($this->start('b@example.com'));
        $this->assertNotNull($this->start('c@example.com'), 'a whole choir was braked as one address');

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains((string) $message, 'proxy'))
            ->once();
    }

    #[Test]
    public function the_brake_per_address_is_generous_for_group_orders(): void
    {
        $this->assertSame(100, config('statamic-payments.protection.rate_limit.per_ip'));
    }

    #[Test]
    public function a_refusal_is_not_silent(): void
    {
        Event::fake([CheckoutBlocked::class]);
        config(['statamic-payments.protection.rate_limit.per_email' => 1]);

        $checkout = app(Checkout::class);
        $this->assertNotNull($checkout->start('noten-paket', ['email' => 'kim@example.com']));
        $this->assertNull($checkout->refusal());

        $this->assertNull($checkout->start('noten-paket', ['email' => 'kim@example.com']));
        $this->assertSame(__('statamic-payments::checkout.refused_rate_limited'), $checkout->refusal());
        Event::assertDispatched(CheckoutBlocked::class, fn ($e) => $e->message === __('statamic-payments::checkout.refused_rate_limited'));
    }

    // ---------------------------------------------------------------- captcha

    protected function turnstile(): void
    {
        config([
            'statamic-payments.protection.captcha.provider' => 'turnstile',
            'statamic-payments.protection.captcha.site_key' => '0x4AAAAA',
            'statamic-payments.protection.captcha.secret' => '0x4AAAAA-secret',
        ]);
    }

    #[Test]
    public function with_a_captcha_a_checkout_without_a_token_is_refused(): void
    {
        Event::fake([CheckoutBlocked::class]);
        Http::preventStrayRequests();
        $this->turnstile();

        $this->assertRefused($this->start(), 'captcha');
    }

    #[Test]
    public function a_token_the_service_vouches_for_lets_the_checkout_through(): void
    {
        $this->turnstile();
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);
        $this->app['request']->merge(['cf-turnstile-response' => 'tok_echt']);

        $this->assertNotNull($this->start());

        Http::assertSent(fn (Request $r) => $r['secret'] === '0x4AAAAA-secret' && $r['response'] === 'tok_echt');
    }

    #[Test]
    public function a_token_the_service_rejects_is_refused(): void
    {
        Event::fake([CheckoutBlocked::class]);
        $this->turnstile();
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']])]);
        $this->app['request']->merge(['cf-turnstile-response' => 'tok_falsch']);

        $this->assertRefused($this->start(), 'captcha');
    }

    #[Test]
    public function an_unreachable_captcha_service_refuses_rather_than_waves_through(): void
    {
        Event::fake([CheckoutBlocked::class]);
        $this->turnstile();
        Http::fake(['challenges.cloudflare.com/*' => fn () => throw new ConnectionException('timeout')]);
        $this->app['request']->merge(['cf-turnstile-response' => 'tok']);

        $this->assertRefused($this->start(), 'captcha');
    }

    #[Test]
    public function hcaptcha_reads_its_own_field_and_endpoint(): void
    {
        config([
            'statamic-payments.protection.captcha.provider' => 'hcaptcha',
            'statamic-payments.protection.captcha.site_key' => 'site-hc',
            'statamic-payments.protection.captcha.secret' => 'secret-hc',
        ]);
        Http::fake(['api.hcaptcha.com/*' => Http::response(['success' => true])]);
        $this->app['request']->merge(['h-captcha-response' => 'tok_hc']);

        $this->assertNotNull($this->start());
    }

    #[Test]
    public function the_tag_renders_the_widget_only_when_a_captcha_is_set_up(): void
    {
        $this->assertSame('', app(CheckoutGuard::class)->widget());

        $this->turnstile();

        $html = app(CheckoutGuard::class)->widget();
        $this->assertStringContainsString('class="cf-turnstile"', $html);
        $this->assertStringContainsString('data-sitekey="0x4AAAAA"', $html);
        $this->assertStringNotContainsString('secret', $html);
    }
}

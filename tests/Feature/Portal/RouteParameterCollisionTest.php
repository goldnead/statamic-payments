<?php

namespace Goldnead\StatamicPayments\Tests\Feature\Portal;

use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Portal\Mail\PortalLinkMail;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * A route parameter named `{order}` is not this package's to name.
 *
 * `Route::bind()` is application-wide, not per package. A binding another addon
 * registers for `{order}`, `{link}` or `{subscription}` applies to every route
 * with that name in every installed addon — including these — and resolves it
 * against a repository that has never heard of these values.
 *
 * That is not hypothetical. It is how `goldnead/statamic-leadhub` 1.8.0 shipped
 * a delete button that did nothing: green tests, a working screen in isolation,
 * and a dead endpoint on any site that also had the neighbour installed. The
 * `pay` prefix on every parameter is the fix, and this test is what stops
 * somebody from tidying it away.
 *
 * The binds below are deliberately hostile: they throw. If a portal route ever
 * picks one up, this fails with that exception rather than with a vague 404.
 */
class RouteParameterCollisionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['order', 'link', 'subscription', 'token', 'invoice', 'payment'] as $name) {
            Route::bind($name, function () use ($name): never {
                throw new RuntimeException("a neighbouring addon's binding for {{$name}} was used on a portal route");
            });
        }

        Mail::fake();
    }

    #[Test]
    public function no_portal_route_picks_up_a_neighbours_binding(): void
    {
        $payment = Payment::create([
            'provider' => 'fake',
            'provider_id' => 'tr_1',
            'product' => 'noten-paket',
            'amount_cent' => 1900,
            'currency' => 'EUR',
            'status' => Payment::STATUS_PAID,
            'email' => 'anna@example.de',
            'paid_at' => now(),
        ]);

        $subscription = Subscription::create([
            'provider' => 'fake',
            'provider_id' => 'sub_1',
            'customer_reference' => 'cst_1',
            'product' => 'noten-paket',
            'amount_cent' => 1900,
            'currency' => 'EUR',
            'interval' => '1 month',
            'times_charged' => 0,
            'status' => Subscription::STATUS_ACTIVE,
            'email' => 'anna@example.de',
        ]);

        $this->post(route('statamic-payments.portal.request.send'), ['email' => 'anna@example.de']);

        $url = null;
        Mail::assertSent(PortalLinkMail::class, function (PortalLinkMail $mail) use (&$url) {
            $url ??= $mail->url;

            return true;
        });

        // The one with `{payLink}` in it, which is the parameter most likely to
        // collide: `{link}` is what a mail or a shortener addon would call it.
        $this->get((string) $url)->assertRedirect(route('statamic-payments.portal.show'));

        $this->get(route('statamic-payments.portal.show'))->assertOk();
        $this->get(route('statamic-payments.portal.order', ['payOrder' => $payment->getKey()]))->assertOk();
        $this->get(route('statamic-payments.portal.cancel.confirm', ['paySubscription' => $subscription->getKey()]))->assertOk();
    }

    #[Test]
    public function every_portal_parameter_carries_the_package_prefix(): void
    {
        $offenders = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with((string) $route->getName(), 'statamic-payments.portal.')) {
                continue;
            }

            foreach ($route->parameterNames() as $parameter) {
                if (! str_starts_with($parameter, 'pay')) {
                    $offenders[] = $route->getName().' → {'.$parameter.'}';
                }
            }
        }

        // A list rather than a boolean, so that a failure names the route that
        // has to be renamed instead of only saying that one exists.
        $this->assertSame([], $offenders);
    }
}

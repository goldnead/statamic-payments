<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;

/**
 * The one property a request can never demonstrate in a test.
 *
 * `PreventRequestForgery::handle()` returns early under `runningUnitTests()`,
 * so every request-level test passes whether or not the middleware was
 * excluded. A route that 419s every real delivery therefore looks
 * perfectly healthy in a green suite — which is exactly what happened here
 * before the critic caught it.
 *
 * So this asserts the resolved middleware list instead of a response.
 */
class RouteMiddlewareTest extends TestCase
{
    #[Test]
    public function the_webhook_route_carries_no_csrf_middleware(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($route): bool => $route->getName() === 'statamic-payments.webhook');

        $this->assertNotNull($route, 'The webhook route is not registered.');

        $middleware = app('router')->gatherRouteMiddleware($route);

        foreach ($middleware as $entry) {
            $this->assertStringNotContainsStringIgnoringCase(
                'csrf',
                $entry,
                'A CSRF middleware survived on the webhook route: '.$entry
            );
            $this->assertStringNotContainsString(
                'PreventRequestForgery',
                $entry,
                'PreventRequestForgery survived on the webhook route. Excluding VerifyCsrfToken does '
                .'not remove it — it is the parent class, not a subclass, and Router::resolveMiddleware() '
                .'only removes subclasses of what you exclude.'
            );
        }
    }

    #[Test]
    public function the_webhook_route_is_still_rate_limited(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($route): bool => $route->getName() === 'statamic-payments.webhook');

        $middleware = app('router')->gatherRouteMiddleware($route);

        // The brake must not fall out with the token. An unauthenticated write
        // endpoint without a rate limit is the other half of the problem.
        $this->assertTrue(
            collect($middleware)->contains(fn ($entry): bool => str_contains((string) $entry, 'ThrottleRequests')),
            'The webhook route lost its rate limit.'
        );
    }
}

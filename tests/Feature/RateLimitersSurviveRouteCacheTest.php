<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\ServiceProvider;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;

/**
 * With `php artisan route:cache` the host never loads routes/web.php, so a
 * limiter defined there is missing and `throttle:statamic-payments.…` throws
 * MissingRateLimiterException. The limiters must come from the provider's boot().
 */
class RateLimitersSurviveRouteCacheTest extends TestCase
{
    // Own process: booting the provider a second time queues Statamic boot
    // callbacks that would break the next test's setUp.
    #[Test]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function the_limiters_are_defined_by_the_provider_without_the_route_file(): void
    {
        // A fresh limiter registry stands in for an app whose routes came from
        // the cache: nothing has run routes/web.php on it.
        $this->app->forgetInstance(RateLimiter::class);
        RateLimiterFacade::clearResolvedInstance(RateLimiter::class);

        foreach (['withdrawal', 'cancellation'] as $flow) {
            $this->assertNull(RateLimiterFacade::limiter('statamic-payments.'.$flow), $flow.' defined before boot');
        }

        $this->app->getProvider(ServiceProvider::class)->boot();

        foreach (['withdrawal', 'cancellation'] as $flow) {
            $resolver = RateLimiterFacade::limiter('statamic-payments.'.$flow);
            $this->assertNotNull($resolver, $flow.' limiter is not defined by boot()');

            config(['statamic-payments.'.$flow.'.throttle' => '3,7']);
            $limit = $resolver(Request::create('/', 'POST', server: ['REMOTE_ADDR' => '10.0.0.1']));

            $this->assertInstanceOf(Limit::class, $limit);
            $this->assertSame(3, $limit->maxAttempts);
            $this->assertSame(7 * 60, $limit->decaySeconds);
            $this->assertSame($flow.'|10.0.0.1', $limit->key);
        }
    }
}

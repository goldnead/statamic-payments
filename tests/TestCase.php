<?php

namespace Goldnead\StatamicPayments\Tests;

use Goldnead\StatamicPayments\Contracts\PaymentGateway;
use Goldnead\StatamicPayments\ServiceProvider;
use Goldnead\StatamicPayments\Tests\Support\FakeGateway;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

abstract class TestCase extends AddonTestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected string $addonServiceProvider = ServiceProvider::class;

    protected FakeGateway $gateway;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // SQLite in memory unless something says otherwise, which is what every
        // local run and most of CI wants.
        //
        // The exception is the one property SQLite cannot demonstrate: this
        // package guards two money paths with unique indexes rather than row
        // locks, precisely because `lockForUpdate()` compiles to nothing on
        // SQLite. A claim that is only ever exercised on the engine with the
        // weakest guarantees is a claim nobody has actually seen work. So CI
        // runs the same tests again with `DB_CONNECTION=mysql` and `=pgsql`.
        $app['config']->set('database.default', env('DB_CONNECTION', 'testing'));
        $app['config']->set('statamic.system.multisite', false);
        $app['config']->set('statamic-payments.products', [
            'noten-paket' => ['name' => 'Notenpaket', 'amount_cent' => 1900],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // The fake is the point, not a convenience: without it every test that
        // proves "the caller is not believed" would need the network, and a
        // test that needs the network is a test that gets skipped.
        $this->gateway = new FakeGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);
    }
}

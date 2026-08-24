<?php

namespace Goldnead\StatamicPayments;

use Goldnead\StatamicPayments\Contracts\PaymentGateway;
use Goldnead\StatamicPayments\Gateways\MollieGateway;
use Mollie\Api\MollieApiClient;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $viewNamespace = 'statamic-payments';

    protected $routes = [
        'web' => __DIR__.'/../routes/web.php',
    ];

    /**
     * The parent boots config off the addon directory, which is resolved
     * through the manifest and comes up empty in package test suites. Config is
     * merged explicitly in register() with an absolute path instead.
     */
    protected $config = false;

    public function register()
    {
        parent::register();

        $this->mergeConfigFrom(__DIR__.'/../config/statamic-payments.php', 'statamic-payments');

        // Bound to the interface, not the class. A second provider is then a
        // binding in the host, and the tests bind a fake so no test ever needs
        // the network — which is what makes the security properties testable at
        // all.
        $this->app->bind(PaymentGateway::class, MollieGateway::class);

        // The SDK, built here rather than pulled from Mollie's Laravel wrapper:
        // that wrapper does not support Laravel 13, which Statamic 6 does.
        $this->app->bind(MollieApiClient::class, function () {
            $client = new MollieApiClient;
            $key = (string) config('statamic-payments.key', '');

            if ($key !== '') {
                $client->setApiKey($key);
            }

            return $client;
        });
    }

    public function bootAddon()
    {
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'statamic-payments');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishes([
            __DIR__.'/../config/statamic-payments.php' => config_path('statamic-payments.php'),
        ], 'statamic-payments-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'statamic-payments-migrations');
    }
}

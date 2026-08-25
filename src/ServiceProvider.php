<?php

namespace Goldnead\StatamicPayments;

use Goldnead\StatamicPayments\Contracts\PaymentGateway;
use Goldnead\StatamicPayments\Gateways\MollieGateway;
use Goldnead\StatamicPayments\Http\Controllers\Cp\PaymentsController;
use Mollie\Api\MollieApiClient;
use Statamic\Facades\Utility;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $viewNamespace = 'statamic-payments';

    protected $routes = [
        'web' => __DIR__.'/../routes/web.php',
    ];

    /**
     * The Control Panel bundle. All three values must byte-match `laravel()` in
     * vite.config.js, or the CP loads a manifest that does not describe what is
     * on disk.
     *
     * @var array<string, mixed>
     */
    protected $vite = [
        'hotFile' => __DIR__.'/../dist/hot',
        'publicDirectory' => 'dist',
        'input' => ['resources/js/cp.js', 'resources/css/cp.css'],
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

        $this->bootUtility();
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishes([
            __DIR__.'/../config/statamic-payments.php' => config_path('statamic-payments.php'),
        ], 'statamic-payments-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'statamic-payments-migrations');
    }

    /**
     * One screen, registered as a utility.
     *
     * Registering here earns the nav entry, the `access payments utility`
     * permission and the matching `can:` middleware from core. Hand-rolling a
     * nav entry means writing each of those out, and the permission is the part
     * people forget — on a screen that lists who bought what.
     */
    protected function bootUtility(): self
    {
        // Registered inside `Utility::extend`, not straight in boot. `__()`
        // during boot resolves before core's `Localize` middleware has set the
        // user's language, so the title and description would freeze in the
        // application locale: an English nav entry above an otherwise German
        // screen.
        Utility::extend(fn () => $this->registerUtility());

        return $this;
    }

    protected function registerUtility(): void
    {
        Utility::register('payments')
            ->action([PaymentsController::class, 'index'])
            ->title(__('statamic-payments::messages.utility_title'))
            ->navTitle(__('statamic-payments::messages.utility_nav'))
            ->icon('money-cash-bill')
            ->description(__('statamic-payments::messages.utility_description'))
            ->docsUrl('https://github.com/goldnead/statamic-payments#readme');
    }
}

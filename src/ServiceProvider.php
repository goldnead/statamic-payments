<?php

namespace Goldnead\StatamicPayments;

use Goldnead\StatamicPayments\Actions\CancelSubscription;
use Goldnead\StatamicPayments\Contracts\PaymentGateway;
use Goldnead\StatamicPayments\Gateways\MollieGateway;
use Goldnead\StatamicPayments\Http\Controllers\Cp\PaymentsController;
use Goldnead\StatamicPayments\Http\Controllers\Cp\SubscriptionActionsController;
use Goldnead\StatamicPayments\Http\Controllers\Cp\SubscriptionsController;
use Goldnead\StatamicPayments\Scopes\PaymentFulfilment;
use Goldnead\StatamicPayments\Scopes\PaymentStatus;
use Goldnead\StatamicPayments\Scopes\SubscriptionLive;
use Goldnead\StatamicPayments\Scopes\SubscriptionStatus;
use Mollie\Api\MollieApiClient;
use Statamic\Actions\Action;
use Statamic\Facades\Utility;
use Statamic\Providers\AddonServiceProvider;
use Statamic\Query\Scopes\Scope;

class ServiceProvider extends AddonServiceProvider
{
    protected $viewNamespace = 'statamic-payments';

    protected $routes = [
        'web' => __DIR__.'/../routes/web.php',
    ];

    /**
     * Listed rather than left to the folder scan: autoloading resolves the
     * addon through the manifest, which is exactly what is missing in a package
     * test suite. A filter that is not registered does not fail loudly — it
     * simply never appears on the screen.
     *
     * @var list<class-string<Scope>>
     */
    protected $scopes = [
        PaymentStatus::class,
        PaymentFulfilment::class,
        SubscriptionStatus::class,
        SubscriptionLive::class,
    ];

    /**
     * @var list<class-string<Action>>
     */
    protected $actions = [
        CancelSubscription::class,
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

        $this->bootUtilities();
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishes([
            __DIR__.'/../config/statamic-payments.php' => config_path('statamic-payments.php'),
        ], 'statamic-payments-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'statamic-payments-migrations');
    }

    /**
     * Two screens, each registered as a utility.
     *
     * Registering here earns the nav entry, the `access … utility` permission
     * and the matching `can:` middleware from core. Hand-rolling a nav entry
     * means writing each of those out, and the permission is the part people
     * forget — on screens that list who bought what and let somebody stop the
     * money.
     */
    protected function bootUtilities(): self
    {
        // Registered inside `Utility::extend`, not straight in boot. `__()`
        // during boot resolves before core's `Localize` middleware has set the
        // user's language, so the title and description would freeze in the
        // application locale: an English nav entry above an otherwise German
        // screen.
        Utility::extend(function () {
            $this->registerPaymentsUtility();
            $this->registerSubscriptionsUtility();
        });

        return $this;
    }

    protected function registerPaymentsUtility(): void
    {
        Utility::register('payments')
            ->action([PaymentsController::class, 'index'])
            ->title(__('statamic-payments::messages.utility_title'))
            ->navTitle(__('statamic-payments::messages.utility_nav'))
            ->icon('money-cash-bill')
            ->description(__('statamic-payments::messages.utility_description'))
            ->docsUrl('https://github.com/goldnead/statamic-payments#readme');
    }

    /**
     * Subscriptions get their own utility, which is what buys the second nav
     * entry and the separate `access subscriptions utility` permission. Hanging
     * this screen off the payments utility would have handed everyone who may
     * look at an order a button that stops somebody's monthly charge, and
     * "may read the till" is not the same authority as "may end an agreement".
     */
    protected function registerSubscriptionsUtility(): void
    {
        Utility::register('subscriptions')
            ->action([SubscriptionsController::class, 'index'])
            ->title(__('statamic-payments::messages.subscriptions_utility_title'))
            ->navTitle(__('statamic-payments::messages.subscriptions_utility_nav'))
            ->icon('sync')
            ->description(__('statamic-payments::messages.subscriptions_utility_description'))
            ->docsUrl('https://github.com/goldnead/statamic-payments#readme')
            ->routes(function ($router) {
                // The two endpoints a Listing's actions need: one to run the
                // chosen action, one to ask which actions the selection offers.
                // Both are what the checkboxes and the row menus talk to.
                $router->post('actions', [SubscriptionActionsController::class, 'run'])->name('actions');
                $router->post('actions/list', [SubscriptionActionsController::class, 'bulkActions'])->name('actions.list');
            });
    }
}

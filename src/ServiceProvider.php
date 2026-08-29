<?php

namespace Goldnead\StatamicPayments;

use Goldnead\StatamicPayments\Contracts\PaymentGateway;
use Goldnead\StatamicPayments\Gateways\MollieGateway;
use Goldnead\StatamicPayments\Http\Controllers\Cp\PaymentsController;
use Goldnead\StatamicPayments\Http\Controllers\Cp\SubscriptionActionsController;
use Goldnead\StatamicPayments\Http\Controllers\Cp\SubscriptionsController;
use Goldnead\StatamicPayments\Integrations\Insights\AverageOrder;
use Goldnead\StatamicPayments\Integrations\Insights\Buyers;
use Goldnead\StatamicPayments\Integrations\Insights\Orders;
use Goldnead\StatamicPayments\Integrations\Insights\Refunded;
use Goldnead\StatamicPayments\Integrations\Insights\RefundRate;
use Goldnead\StatamicPayments\Integrations\Insights\RevenueGross;
use Goldnead\StatamicPayments\Integrations\Insights\RevenueNet;
use Illuminate\Support\Facades\Log;
use Mollie\Api\MollieApiClient;
use Statamic\Actions\Action;
use Statamic\Facades\Utility;
use Statamic\Providers\AddonServiceProvider;
use Throwable;

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

        $this->bootUtilities();
        $this->registerInsightsMetrics();
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishes([
            __DIR__.'/../config/statamic-payments.php' => config_path('statamic-payments.php'),
        ], 'statamic-payments-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'statamic-payments-migrations');
    }

    /**
     * The metric handles this addon contributes, and the classes behind them.
     *
     * Handle and class both, so the registry can store the class name without
     * constructing anything to find out what it is called. Naming the handle
     * twice is the price of that laziness, and it is the cheaper half of the
     * trade: an install with twenty addons would otherwise build every metric
     * object of every one of them on a request that renders none.
     *
     * The handles are frozen from the moment they are registered — they end up
     * in saved dashboards and in URLs. Renaming one is a breaking change.
     *
     * @var array<class-string, string>
     */
    protected const INSIGHTS_METRICS = [
        RevenueGross::class => 'payments.revenue_gross',
        RevenueNet::class => 'payments.revenue_net',
        Refunded::class => 'payments.refunded',
        RefundRate::class => 'payments.refund_rate',
        Orders::class => 'payments.orders',
        Buyers::class => 'payments.buyers',
        AverageOrder::class => 'payments.average_order',
    ];

    /**
     * Offer the payment figures to the analytics addon, if it is there.
     *
     * From an `app->booted()` callback rather than from `bootAddon()`: the
     * sibling's container bindings only exist once its own provider has booted,
     * and this one may boot first. Registering earlier registers into nothing,
     * silently — an empty screen with no error anywhere, which is the worst
     * shape this failure could take.
     *
     * **Nothing here throws, ever.** A missing, half-installed or mid-upgrade
     * analytics addon must cost a few tiles on a screen nobody has open, never
     * a checkout. The guards are three, and each one has caught a real
     * variation of "installed but not quite": the class may be absent, the
     * container may refuse to build the manager, and an older release of the
     * sibling may have the facade without this method on it.
     *
     * The metric classes name the sibling's contract in their `implements` and
     * their type hints, which is safe precisely because of the first guard: PHP
     * loads a class when something touches it, and nothing touches these unless
     * the facade exists. Hence `suggest` in composer.json rather than `require`
     * — an install of this addon alone must not drag an analytics package in.
     */
    protected function registerInsightsMetrics(): void
    {
        $this->app->booted(function (): void {
            $facade = '\Goldnead\StatamicInsights\Facades\Insights';

            if (! class_exists($facade)) {
                return;
            }

            try {
                $manager = $facade::getFacadeRoot();

                // Asked of the object, never of the facade: a facade forwards
                // through `__callStatic` and declares none of what it forwards,
                // so the probe on the facade itself is always false.
                if (! is_object($manager) || ! method_exists($manager, 'registerMetric')) {
                    return;
                }

                foreach (self::INSIGHTS_METRICS as $class => $handle) {
                    $manager->registerMetric($class, $handle);
                }
            } catch (Throwable $e) {
                Log::warning('statamic-payments: the insights metrics could not be registered.', [
                    'exception' => $e->getMessage(),
                ]);
            }
        });
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

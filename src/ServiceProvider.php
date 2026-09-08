<?php

namespace Goldnead\StatamicPayments;

use Goldnead\BrandContext\Settings\SettingsRegistry;
use Goldnead\StatamicPayments\Contracts\PaymentGateway;
use Goldnead\StatamicPayments\Cp\SuiteLicence;
use Goldnead\StatamicPayments\Cp\SuiteNav;
use Goldnead\StatamicPayments\Gateways\FreeGateway;
use Goldnead\StatamicPayments\Gateways\MollieGateway;
use Goldnead\StatamicPayments\Gateways\StripeGateway;
use Goldnead\StatamicPayments\Http\Controllers\Cp\CancellationActionsController;
use Goldnead\StatamicPayments\Http\Controllers\Cp\CancellationsController;
use Goldnead\StatamicPayments\Http\Controllers\Cp\PaymentsController;
use Goldnead\StatamicPayments\Http\Controllers\Cp\SubscriptionActionsController;
use Goldnead\StatamicPayments\Http\Controllers\Cp\SubscriptionsController;
use Goldnead\StatamicPayments\Http\Controllers\Cp\WithdrawalActionsController;
use Goldnead\StatamicPayments\Http\Controllers\Cp\WithdrawalsController;
use Goldnead\StatamicPayments\Integrations\Insights\AverageOrder;
use Goldnead\StatamicPayments\Integrations\Insights\Buyers;
use Goldnead\StatamicPayments\Integrations\Insights\Orders;
use Goldnead\StatamicPayments\Integrations\Insights\Refunded;
use Goldnead\StatamicPayments\Integrations\Insights\RefundRate;
use Goldnead\StatamicPayments\Integrations\Insights\RevenueGross;
use Goldnead\StatamicPayments\Integrations\Insights\RevenueNet;
use Goldnead\StatamicPayments\Integrations\InvoiceBridge;
use Goldnead\StatamicPayments\Support\Gateways;
use Goldnead\StatamicPayments\Support\Invoices;
use Goldnead\StatamicPayments\Support\Settings;
use Illuminate\Support\Facades\Log;
use Mollie\Api\MollieApiClient;
use Statamic\Actions\Action;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Facades\Utility;
use Statamic\Providers\AddonServiceProvider;
use Statamic\Statamic;
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

        // Which provider a row belongs to, decided by the row's own `provider`
        // column rather than by whatever the binding above happens to be. A
        // singleton, so a host registering a fifth provider registers it once.
        $this->app->singleton(Gateways::class, function ($app) {
            $gateways = new Gateways;

            // Zero-price orders. No provider was involved and none ever will
            // be, but the handle is in the column like any other and something
            // resolving by handle has to have an answer for it.
            $gateways->register('free', fn () => new FreeGateway);

            // Registered by name as well as being the default binding. The
            // binding is what a `mollie` row resolves through on an ordinary
            // site; this entry is what keeps a site's **existing Mollie rows**
            // answerable after it switches its default to another provider.
            // Without it, moving to Stripe would make every historic order
            // throw in the customer portal.
            $gateways->register('mollie', fn () => $app->make(MollieGateway::class));

            // Ships with the package, costs nothing where it is unused: the
            // class is only built when a row actually says `stripe`, and it
            // refuses to talk to Stripe without a key.
            $gateways->register('stripe', fn () => new StripeGateway(
                (string) config('statamic-payments.stripe.key', ''),
                (string) config('statamic-payments.stripe.api_base', 'https://api.stripe.com'),
            ));

            return $gateways;
        });

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

        // Explicit, and not left to the parent's `$viewNamespace`. That resolves
        // the addon directory through the manifest, which comes up empty in a
        // package test suite — the same reason `$config` is false above. The
        // customer portal is the first thing here that renders a Blade view on
        // a public URL, so a namespace that only works in a host application
        // would fail exactly where nobody looks.
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'statamic-payments');

        $this->bootUtilities()
            ->bootPermissions()
            ->bootSettings()
            ->bootNavigation()
            ->bootSuiteLicenceNotice();
        $this->registerInsightsMetrics();
        $this->registerInvoiceSource();
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishes([
            __DIR__.'/../config/statamic-payments.php' => config_path('statamic-payments.php'),
        ], 'statamic-payments-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'statamic-payments-migrations');

        // The portal's pages and its wording, both publishable. The wording is
        // the one that matters: § 312k BGB prescribes the two button labels, the
        // statute has been amended once already, and a site whose lawyer wants a
        // different phrase must not have to wait for a release of this addon.
        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/statamic-payments'),
        ], 'statamic-payments-views');

        $this->publishes([
            __DIR__.'/../lang' => lang_path('vendor/statamic-payments'),
        ], 'statamic-payments-translations');
    }

    /**
     * Offer the invoice addon a place to answer from, if it is there.
     *
     * From an `app->booted()` callback and behind a `class_exists` on a string,
     * for the same two reasons the insights registration is: the sibling's
     * bindings only exist once its own provider has booted, and a missing,
     * half-installed or mid-upgrade sibling must cost a download button on a page
     * about somebody's orders — never a page, and never a checkout.
     *
     * The bridge itself names no class from that package beyond one facade
     * string; it asks whatever it is handed what it can do. See
     * {@see InvoiceBridge}.
     */
    protected function registerInvoiceSource(): void
    {
        $this->app->booted(function (): void {
            if (! class_exists(InvoiceBridge::FACADE)) {
                return;
            }

            Invoices::extend(new InvoiceBridge);
        });
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
    /**
     * Die vier Bildschirme in die Seitenleiste holen.
     *
     * Sie bleiben Statamic-Utilities — dieselbe Route, dasselbe Recht, dasselbe
     * Lesezeichen. Was fehlte, war der Weg dorthin: unter „Hilfsmittel" stehen
     * sie zwischen Cache, PHP-Info und Suche, und genau das hat Adrian am
     * 03.09.2026 als verwirrend gemeldet. Ein Nav-Eintrag zeigt jetzt auf
     * dieselbe Route, in einem Abschnitt, der nach dem klingt, was man dort tut.
     *
     * `can()` bekommt das Recht, das `Utility::register` ohnehin anlegt — wer
     * den Bildschirm nicht sehen darf, sieht auch den Eintrag nicht, und es gibt
     * keinen zweiten Schalter fuer dieselbe Tuer.
     *
     * Der Abschnittsname kommt aus {@see SuiteNav}, weil Statamic
     * Abschnittsnamen nicht uebersetzt und zwei verschiedene Schreibweisen zwei
     * halb gefuellte Abschnitte ergeben wuerden.
     */
    /**
     * Der Lizenzhinweis der Suite, an den Browser gereicht.
     *
     * Nur eine Zahl, ein Text und ein `true`/`false` — die Entscheidung faellt
     * serverseitig in {@see SuiteLicence}, damit im Client nichts zu entscheiden
     * bleibt. Der Schluessel selbst geht nie mit.
     *
     * Genau hier und nur hier. Bei sechzehn Paketen waere ein Hinweis je Paket
     * der wahrscheinlichste Fehler dieses Tickets; die Geschwister binden
     * `statamic-payments` per `class_exists` ein und bringen nichts Eigenes mit.
     *
     * Kein Netzaufruf, keine Sperre, keine Pruefung des Schluessels. Wer das
     * aendert, aendert eine Produktentscheidung von Adrian (05.09.2026, Weg B).
     */
    protected function bootSuiteLicenceNotice(): self
    {
        Statamic::provideToScript([
            'statamicPaymentsSuiteLicence' => SuiteLicence::forScript(),
        ]);

        return $this;
    }

    protected function bootNavigation(): self
    {
        Nav::extend(function ($nav) {
            $section = SuiteNav::section();

            // Erst aushaengen, dann einhaengen — sonst steht jeder Bildschirm
            // ZWEIMAL in der Seitenleiste. Genau das war der erste Versuch am
            // 04.09.2026: der neue Abschnitt kam dazu, unter „Hilfsmittel"
            // blieb alles stehen, und Adrian sah zu Recht keinen Unterschied.
            //
            // `Utility::register` haengt jeden Bildschirm als Kind an den
            // Hilfsmittel-Punkt (CoreNav::makeUtilitiesItems). Die Registrierung
            // selbst bleibt — sie traegt Route, Recht und Middleware. Nur der
            // Eintrag unter „Hilfsmittel" faellt weg.
            //
            // Die Namen sind so zu lesen, wie der Core sie fuehrt: der
            // Elternpunkt heisst intern `Utilities` (uebersetzt wird erst beim
            // Zeichnen), das Kind traegt den bereits uebersetzten `navTitle`.
            foreach ([
                'statamic-payments::messages.utility_nav',
                'statamic-payments::messages.subscriptions_utility_nav',
                'statamic-payments::messages.withdrawals_utility_nav',
                'statamic-payments::messages.cancellations_utility_nav',
            ] as $schluessel) {
                $nav->remove('Tools', 'Utilities', __($schluessel));
            }

            $nav->create(__('statamic-payments::messages.utility_nav'))
                ->section($section)
                ->icon('credit-card')
                ->route('utilities.payments')
                ->can('access payments utility');

            $nav->create(__('statamic-payments::messages.subscriptions_utility_nav'))
                ->section($section)
                ->icon('money-cash-bill')
                ->route('utilities.subscriptions')
                ->can('access subscriptions utility');

            $nav->create(__('statamic-payments::messages.withdrawals_utility_nav'))
                ->section($section)
                ->icon('return-square')
                ->route('utilities.withdrawals')
                ->can('access withdrawals utility');

            $nav->create(__('statamic-payments::messages.cancellations_utility_nav'))
                ->section($section)
                ->icon('folder-remove')
                ->route('utilities.cancellations')
                ->can('access cancellations utility');
        });

        return $this;
    }

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
            $this->registerWithdrawalsUtility();
            $this->registerCancellationsUtility();
        });

        return $this;
    }

    /**
     * Die zwei Rechte, die über das Lesen hinausgehen.
     *
     * `Utility::register` bringt je Bildschirm ein `access … utility` mit, und
     * das ist das Lese-Recht: wer es hat, sieht Namen und Adressen von Leuten,
     * die einen Vertrag lösen wollen. Ob jemand einen Vorgang **abschließen**
     * darf, ist ein zweites Recht — sonst hätte jeder Leser der Liste den Knopf,
     * der einen Widerruf aus der Arbeitsliste nimmt.
     *
     * Ein eigenes `view payment withdrawals` neben dem Utility-Recht wäre ein
     * zweiter Schalter für dieselbe Tür gewesen; deshalb bleibt core's Recht das
     * Lese-Recht und hier steht nur, was fehlt.
     */
    protected function bootPermissions(): self
    {
        Permission::extend(function () {
            Permission::group('statamic-payments', __('statamic-payments::messages.permission_group'), function () {
                Permission::register('handle payment withdrawals')
                    ->label(__('statamic-payments::messages.permission_handle_withdrawals'));
                Permission::register('handle payment cancellations')
                    ->label(__('statamic-payments::messages.permission_handle_cancellations'));

                // Bewacht den Abschnitt dieses Addons auf der gemeinsamen
                // Einstellungs-Seite. Immer angemeldet, auch ohne
                // `statamic-brand-context`: ein Recht, das nur manchmal
                // existiert, verschwindet aus Rollen, die es tragen. Der Name
                // steht hingeschrieben in Support\Settings, die Registry
                // leitet ihn nicht ab.
                Permission::register('manage payments settings')
                    ->label(__('statamic-payments::settings.permission_manage_settings'));
            });
        });

        return $this;
    }

    /**
     * Die Einstellungs-Seite, angemeldet statt gebaut.
     *
     * Das ist der ganze Umfang: die Registry bekommt die Feldliste, und
     * `statamic-brand-context` stellt Bildschirm, Formular, Validierung,
     * Speicher, Marken und Routen. Ein eigener Controller oder Statamics
     * `settingsBlueprint()` wären zwei Wege, dieselbe Sache ein zweites Mal zu
     * beschreiben — der Blueprint zusätzlich einer, der `config()` gar nicht
     * anfasst und Vollkopien statt Abweichungen speichert.
     *
     * **Die Prüfung ist keine Vorsicht, sie ist Ladeordnung.**
     * `statamic-brand-context` steht in `require-dev`; dieses Addon läuft ohne
     * es. `Support\Settings` implementiert aber eine Schnittstelle aus diesem
     * Paket, und eine Klasse, deren Schnittstelle fehlt, lässt sich nicht
     * laden. Solange `Settings::class` nur als Konstante hier steht, fasst
     * nichts sie an; erst `register()` löst das Autoloading aus, und dahin
     * kommt es nur, wenn das Paket da ist.
     */
    protected function bootSettings(): self
    {
        if (! class_exists(SettingsRegistry::class)) {
            return $this;
        }

        $this->app->make(SettingsRegistry::class)->register(Settings::class);

        return $this;
    }

    /**
     * Widerrufe nach § 356a BGB, und ihre Actions.
     *
     * Eine eigene Utility, nicht ein Reiter der Zahlungen: das Recht, Zahlungen
     * zu sehen, ist nicht das Recht, zu sehen, wer davon zurücktreten will —
     * und umgekehrt kann der Kundendienst Widerrufe bearbeiten, ohne die Kasse
     * zu sehen.
     */
    protected function registerWithdrawalsUtility(): void
    {
        Utility::register('withdrawals')
            ->action([WithdrawalsController::class, 'index'])
            ->title(__('statamic-payments::messages.withdrawals_utility_title'))
            ->navTitle(__('statamic-payments::messages.withdrawals_utility_nav'))
            ->icon('return-square')
            ->description(__('statamic-payments::messages.withdrawals_utility_description'))
            ->docsUrl('https://github.com/goldnead/statamic-payments#readme')
            ->routes(function ($router) {
                $router->post('actions', [WithdrawalActionsController::class, 'run'])->name('actions');
                $router->post('actions/list', [WithdrawalActionsController::class, 'bulkActions'])->name('actions.list');
            });
    }

    /** Kündigungen nach § 312k BGB, ohne Login erklärt. */
    protected function registerCancellationsUtility(): void
    {
        Utility::register('cancellations')
            ->action([CancellationsController::class, 'index'])
            ->title(__('statamic-payments::messages.cancellations_utility_title'))
            ->navTitle(__('statamic-payments::messages.cancellations_utility_nav'))
            ->icon('x-square')
            ->description(__('statamic-payments::messages.cancellations_utility_description'))
            ->docsUrl('https://github.com/goldnead/statamic-payments#readme')
            ->routes(function ($router) {
                $router->post('actions', [CancellationActionsController::class, 'run'])->name('actions');
                $router->post('actions/list', [CancellationActionsController::class, 'bulkActions'])->name('actions.list');
            });
    }

    protected function registerPaymentsUtility(): void
    {
        Utility::register('payments')
            ->action([PaymentsController::class, 'index'])
            ->title(__('statamic-payments::messages.utility_title'))
            ->navTitle(__('statamic-payments::messages.utility_nav'))
            ->icon('money-cash-bill')
            ->description(__('statamic-payments::messages.utility_description'))
            ->docsUrl('https://github.com/goldnead/statamic-payments#readme')
            ->routes(function ($router) {
                // Die Detailseite. `payPayment`, nicht `payment`: ein
                // `Route::bind()` eines anderen Addons gilt für jede Route mit
                // diesem Parameternamen, siehe routes/web.php. Numerisch am
                // Router, damit „abc" eine 404 ist und keine Zahlung 0.
                $router->get('{payPayment}', [PaymentsController::class, 'show'])
                    ->whereNumber('payPayment')
                    ->name('show');
            });
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

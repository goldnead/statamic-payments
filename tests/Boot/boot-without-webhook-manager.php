<?php

/*
 * Boots the addon the way a site without goldnead/statamic-webhook-manager and
 * without brand-context does. Run in its own PHP process by
 * BootWithoutWebhookManagerTest: this repository has both as dev dependencies,
 * so they are hidden from the autoloader here, and none of tests/Fakes is
 * loaded. A provider or bridge that touches one of their classes before
 * checking the name dies with "Interface not found" / "Class not found".
 *
 * Prints "booted" and the bridge state on success; a fatal error ends the
 * process with its message.
 */

$loader = require __DIR__.'/../../vendor/autoload.php';

$hidden = ['Goldnead\\WebhookManager\\', 'Goldnead\\BrandContext\\'];

$loader->unregister();
spl_autoload_register(function (string $class) use ($loader, $hidden): void {
    foreach ($hidden as $prefix) {
        if (str_starts_with($class, $prefix)) {
            return;
        }
    }

    $loader->loadClass($class);
}, true, true);

foreach ([
    'Goldnead\\WebhookManager\\Facades\\WebhookManager',
    'Goldnead\\WebhookManager\\Contracts\\TriggerInterface',
    'Goldnead\\BrandContext\\Facades\\BrandContext',
] as $sibling) {
    if (class_exists($sibling) || interface_exists($sibling)) {
        fwrite(STDERR, "precondition: {$sibling} is reachable, this check proves nothing\n");
        exit(2);
    }
}

use Goldnead\StatamicPayments\Events\PaymentPaid;
use Goldnead\StatamicPayments\Integrations\WebhookManager\WebhookManagerBridge;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\ServiceProvider;
use Orchestra\Testbench\Foundation\Application;

$app = Application::create(options: ['extra' => ['dont-discover' => ['*']]]);
$app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

// register() and boot() of the provider, with the booted callbacks it queues:
// the app is already booted, so Laravel runs them at once.
$app->register(ServiceProvider::class);

// The moment the bridge would hear. Unsaved: no database here, and nothing
// may try to reach one on a site without the manager.
PaymentPaid::dispatch(new Payment(['product' => 'probe', 'amount_cent' => 100, 'currency' => 'EUR']));

echo 'booted, bridge '.($app->make(WebhookManagerBridge::class)->booted() ? 'on' : 'off')
    .', available '.(WebhookManagerBridge::available() ? 'yes' : 'no')."\n";

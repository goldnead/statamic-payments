<?php

namespace Goldnead\StatamicPayments\Integrations\WebhookManager;

use Goldnead\StatamicPayments\Support\Brands;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Offers every payment moment to `goldnead/statamic-webhook-manager` as a
 * trigger, where that addon is installed.
 *
 * The shape follows the leadhub and marketing bridges: register one trigger
 * per moment, listen to the event, re-emit it as the manager's
 * `TriggerDetected`. `registerEventTrigger()` would have been shorter and was
 * not taken, for two reasons. It dispatches in whatever brand is current, and
 * the manager's outbound hooks are brand-scoped: a Mollie webhook or the
 * reminder command has no current brand, so on a multi-brand install every
 * renewal would find no hook, or the default brand's. And it builds a
 * `CustomEventTrigger` whose label is fixed at boot, in one language.
 *
 * **Nothing here loads a class of the webhook manager before its name has
 * been checked as a string.** {@see PaymentsTrigger} implements the manager's
 * interface and is only named after that check; the facade and the event
 * class are strings. A site without the manager boots exactly as before.
 */
class WebhookManagerBridge
{
    public const FACADE = 'Goldnead\\WebhookManager\\Facades\\WebhookManager';

    public const CONTRACT = 'Goldnead\\WebhookManager\\Contracts\\TriggerInterface';

    public const DETECTED = 'Goldnead\\WebhookManager\\Events\\TriggerDetected';

    /**
     * Whether boot() has registered. The provider binds the bridge as a
     * singleton, so this holds across the retry.
     */
    protected bool $booted = false;

    public static function available(): bool
    {
        return (bool) config('statamic-payments.webhook_manager.enabled', true)
            && class_exists(self::FACADE)
            && interface_exists(self::CONTRACT)
            && class_exists(self::DETECTED);
    }

    public function booted(): bool
    {
        return $this->booted;
    }

    public function boot(Dispatcher $events): void
    {
        if ($this->booted || ! static::available()) {
            return;
        }

        // The binding exists once the manager's own provider has booted, and
        // sibling boot order is not guaranteed. Bail without marking booted,
        // so the retry at the end of the booted queue can still register.
        if (! app()->bound('webhook-manager')) {
            return;
        }

        $this->booted = true;

        $manager = app('webhook-manager');

        foreach (WebhookPayload::MOMENTS as $moment => $eventClass) {
            try {
                $manager->registerTrigger(new PaymentsTrigger($moment));
            } catch (Throwable $e) {
                Log::warning('statamic-payments: the webhook manager would not take the trigger ['.WebhookPayload::PREFIX.$moment.'].', [
                    'exception' => $e->getMessage(),
                ]);

                continue;
            }

            $events->listen($eventClass, function (object $event) use ($moment): void {
                $this->dispatch($moment, $event);
            });
        }
    }

    /**
     * Hand one moment to the manager, in the brand of the row it is about.
     *
     * Never throws. A receiver that is down, a hook that is misconfigured or a
     * manager mid-upgrade must cost a webhook, not a payment: these events
     * fire inside the provider's webhook and the checkout.
     */
    protected function dispatch(string $moment, object $event): void
    {
        try {
            $trigger = app('webhook-manager')->triggers()->get(WebhookPayload::PREFIX.$moment);

            if ($trigger === null) {
                return;
            }

            $detected = self::DETECTED;

            Brands::runFor(
                WebhookPayload::brandIdOf($event),
                fn () => event(new $detected($trigger->build($event))),
            );
        } catch (Throwable $e) {
            Log::warning('statamic-payments: a payment moment could not be handed to the webhook manager.', [
                'trigger' => WebhookPayload::PREFIX.$moment,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}

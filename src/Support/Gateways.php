<?php

namespace Goldnead\StatamicPayments\Support;

use Closure;
use Goldnead\StatamicPayments\Contracts\PaymentGateway;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Subscription;
use RuntimeException;

/**
 * Which provider a row belongs to, and the adapter that speaks for it.
 *
 * Every payment and every agreement carries a `provider` handle. It was written
 * from the first version and read by nothing: the container bound
 * `PaymentGateway` once, globally, so whatever arrived was handed to whichever
 * provider happened to be bound. On a site with one provider that is invisible.
 * On a site with two it is the worst failure this package can have — a webhook
 * from the second provider asks the first about an id it has never seen, the
 * lookup finds no row, and the buyer's order is simply never fulfilled. No
 * error, no alarm.
 *
 * So: the handle decides. `resolve('stripe')` gives the Stripe adapter even
 * where the container's default is Mollie, and a handle nobody registered
 * throws instead of falling back — a silent fallback here is the very bug this
 * class exists to remove.
 *
 * **A host extends it.** Same shape as the catalogue seam: register a factory
 * in a service provider and the handle is live.
 *
 * ```php
 * app(Gateways::class)->register('paypal', fn () => new PayPalGateway);
 * ```
 */
class Gateways
{
    /** @var array<string, Closure(): PaymentGateway> */
    protected array $factories = [];

    /**
     * Teach the registry a provider.
     *
     * The factory is called on every resolve rather than once: a gateway may
     * hold a client with per-request state, and a shared instance across two
     * webhook deliveries is a way for one to read the other's answer.
     *
     * @param  Closure(): PaymentGateway  $factory
     */
    public function register(string $handle, Closure $factory): void
    {
        $this->factories[$this->normalise($handle)] = $factory;
    }

    /** The handles this site can resolve, the container's default included. */
    public function handles(): array
    {
        return array_values(array_unique(array_merge(
            array_keys($this->factories),
            [$this->defaultHandle()],
        )));
    }

    /** Whether a handle can be resolved at all, without building anything. */
    public function has(?string $handle): bool
    {
        $handle = $this->normalise((string) $handle);

        return isset($this->factories[$handle])
            || $handle === ''
            || $handle === $this->defaultHandle();
    }

    /**
     * The adapter for a handle.
     *
     * An empty handle is the one benign case: rows written before the column
     * was filled, and legacy rows belong to whatever the site was using then,
     * which is the container's binding. Everything else is either registered or
     * an error.
     */
    public function resolve(?string $handle): PaymentGateway
    {
        $handle = $this->normalise((string) $handle);

        // The container's binding first, and that order matters: a host that
        // bound its own subclass of a shipped adapter means *that* object when
        // it says the handle. A registered factory would otherwise quietly
        // hand back the stock class instead.
        //
        // ponytail: asking the default what it calls itself means building it,
        // on every resolve, even when a factory ends up answering. Cheap for
        // both shipped adapters and correct as written; give the registry a
        // configured default handle if a listing of many rows ever shows up in
        // a profile.
        if ($handle === '' || $handle === $this->defaultHandle()) {
            return $this->default();
        }

        if (isset($this->factories[$handle])) {
            return ($this->factories[$handle])();
        }

        // Loud, and on purpose. The alternative — handing it to the default
        // gateway — is exactly the silent mismatch described at the top: the
        // wrong provider is asked about an id it cannot know, answers "not
        // mine", and an order that was paid for is quietly never delivered.
        throw new RuntimeException(
            "statamic-payments: no gateway is registered for the provider [{$handle}]. "
            .'Register one with Gateways::register() before rows can carry it.'
        );
    }

    /** The adapter that speaks for this row, read off its own `provider` column. */
    public function for(Payment|Subscription $row): PaymentGateway
    {
        return $this->resolve($row->provider);
    }

    /**
     * The container's binding — Mollie unless a host said otherwise.
     *
     * Resolved through the container rather than newed up, which is what keeps
     * a host's own override and the test fake in charge of the default path.
     */
    protected function default(): PaymentGateway
    {
        return app(PaymentGateway::class);
    }

    /** What the default binding calls itself. */
    protected function defaultHandle(): string
    {
        return $this->normalise($this->default()->provider());
    }

    protected function normalise(string $handle): string
    {
        return strtolower(trim($handle));
    }
}

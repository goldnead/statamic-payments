<?php

namespace Goldnead\StatamicPayments\Portal;

use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Support\Brands;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * What a buyer may see, and nothing else.
 *
 * **Every read in the portal goes through this class.** Not as tidiness: the two
 * conditions that make a row this person's — the address and the brand — are
 * applied in one place, together, by methods that cannot be called without both,
 * because {@see PortalAccess} carries them as a pair. A controller that queried
 * `Payment::where('email', …)` itself would compile, pass its test, and on a
 * multi-brand host hand somebody another tenant's orders.
 *
 * **Paid only.** An abandoned checkout is not an order: it is the name and
 * address of somebody with whom no contract was concluded, and `prune-unpaid`
 * exists to delete it. Showing a buyer a list of things they did not buy is
 * confusing at best, and on a shared machine it is a disclosure.
 */
class Orders
{
    /** @return Collection<int, Payment> */
    public function ordersFor(PortalAccess $access): Collection
    {
        return $this->orders($access)
            ->with('items')
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->limit($this->limit())
            ->get();
    }

    public function orderFor(PortalAccess $access, int $id): ?Payment
    {
        return $this->orders($access)->with('items')->whereKey($id)->first();
    }

    /** @return Collection<int, Subscription> */
    public function subscriptionsFor(PortalAccess $access): Collection
    {
        return $this->subscriptions($access)
            ->orderByDesc('id')
            ->limit($this->limit())
            ->get();
    }

    public function subscriptionFor(PortalAccess $access, int $id): ?Subscription
    {
        return $this->subscriptions($access)->whereKey($id)->first();
    }

    /**
     * Whether this address has anything at all to look at, in this brand.
     *
     * Asked before a link is mailed. An address with no purchases gets no link,
     * for the same reason the preference centre will not mail a stranger: an
     * endpoint that sends signed URLs to anything typed into it is an open relay
     * with extra steps.
     *
     * A subscription counts even where its payments do not. A trial that has not
     * charged yet is exactly the agreement somebody most wants to cancel.
     */
    public function anythingFor(string $email, int $brandId): bool
    {
        $access = new PortalAccess($email, $brandId);

        return $this->orders($access)->exists() || $this->subscriptions($access)->exists();
    }

    /** @return Builder<Payment> */
    protected function orders(PortalAccess $access): Builder
    {
        return $this->mine(Payment::query()->where('status', Payment::STATUS_PAID), $access);
    }

    /** @return Builder<Subscription> */
    protected function subscriptions(PortalAccess $access): Builder
    {
        return $this->mine(Subscription::query(), $access);
    }

    /**
     * The two conditions, always both.
     *
     * The address is compared lower-cased on both sides. `Payment.email` holds
     * whatever the checkout form or the provider gave it, capitals and all, and
     * a buyer who types their own address in a different case is still the same
     * buyer — while `WHERE email = ?` on raw input would show them an empty page
     * and no way to understand why.
     *
     * `lower(email)` cannot use an index on `email`. On `payments` there is no
     * index on that column to lose, so today this costs nothing that was not
     * already being paid; on `subscriptions` there is one, and this walks past
     * it. The honest fix is a stored normalised address, and it is a migration
     * plus a backfill plus a second thing every write has to remember — worth
     * doing when a portal query shows up in a slow log, and not before. What
     * bounds it meanwhile is that this route is reachable only with a link that
     * was mailed, and issuing one is throttled twice.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected function mine(Builder $query, PortalAccess $access): Builder
    {
        return Brands::only($query, $access->brandId)
            ->whereRaw('lower(email) = ?', [$access->email]);
    }

    protected function limit(): int
    {
        return max(1, (int) config('statamic-payments.portal.max_rows', 100));
    }
}

<?php

namespace Goldnead\StatamicPayments\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The soft seam to `goldnead/statamic-brand-context`.
 *
 * Never a hard dependency. Most installs of this addon are one shop with one
 * sender and no tenancy at all, and for them every method here answers "zero"
 * and nothing changes. `class_exists` on a string rather than an import, the
 * same shape the insights registration in the service provider uses, so that
 * nothing in this file can trigger an autoload of a package that is not there.
 *
 * **Three states, not two, and that is the whole point of this class.** "No
 * tenants here" and "there are tenants and I could not find out which" look the
 * same to a boolean and must not be treated the same by a query. `multiBrandEnabled()`
 * on the sibling runs a host-configured `license_check` — a closure, or a class
 * the container resolves — which can throw. A `catch (Throwable) { return false; }`
 * around that turns one failing callback into "this install has no tenants",
 * and the next portal query runs unfiltered across every brand on the host.
 * Fail-open, from a defensive catch. So the failure gets its own answer.
 *
 * **`stampId()` and `only()` are not the same question**, either, and mixing
 * those up is the other way a tenant leak gets written. `stampId()` answers
 * "whose row is this about to be" while a row is being created, and it is
 * allowed to land on zero. `only()` answers "whose rows may this reader see",
 * and it never guesses: an unanswerable filter closes.
 */
final class Brands
{
    /** The value on every row of a single-brand install. */
    public const NONE = 0;

    /** No tenancy on this install. Every row is `NONE` and no filter is needed. */
    public const SINGLE = 'single';

    /** Tenants, and the current one is knowable. */
    public const MULTI = 'multi';

    /** The sibling is installed and would not answer. Nothing may be read. */
    public const UNKNOWN = 'unknown';

    /**
     * Whether the sibling is installed and usable at all.
     *
     * The facade is probed by name; the manager is asked of the container,
     * because a half-booted install can have the class and not the binding.
     */
    public static function available(): bool
    {
        if (! class_exists('\Goldnead\BrandContext\Facades\BrandContext')) {
            return false;
        }

        try {
            return app()->bound('brand-context');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Which of the three states this install is in.
     *
     * `UNKNOWN` is reached only where the sibling is present and refused to
     * answer, which is rare and loud — it is logged, because a host whose
     * licence check throws is a host whose customer portal has just stopped
     * showing anybody anything, and that should be findable in a log rather
     * than reported as an empty page.
     */
    public static function mode(): string
    {
        if (! self::available()) {
            return self::SINGLE;
        }

        try {
            return app('brand-context')->multiBrandEnabled() ? self::MULTI : self::SINGLE;
        } catch (Throwable $e) {
            Log::error('statamic-payments: brand-context would not say whether this install is multi-brand; every scoped read is closed until it does.', [
                'exception' => $e->getMessage(),
            ]);

            return self::UNKNOWN;
        }
    }

    /**
     * Whether this install actually separates tenants.
     *
     * A boolean for the callers that only need to know whether to bother —
     * stamping a row, deciding which brands to search. `UNKNOWN` answers false
     * here on purpose: stamping lands on zero, and a zero row is one that
     * {@see self::only()} shows to nobody. The closing happens at the read.
     */
    public static function multiBrand(): bool
    {
        return self::mode() === self::MULTI;
    }

    /**
     * The brand to write onto a row being created now.
     *
     * `currentId()` is not usable here on its own: it falls back to the default
     * brand when nothing is set, and nothing is set in a provider's webhook or a
     * console command. `hasCurrent()` is the difference between "this brand" and
     * "nobody said", and only the first may be stamped.
     *
     * Landing on zero in multi-brand mode is a real outcome, not a bug to hide:
     * it means the row was created where no brand was current. The portal then
     * shows it to nobody, which is the fail-closed half of the same decision.
     * Rows created *for* another row — a subscription cycle, a follow-up charge
     * — inherit their parent's brand explicitly instead of asking this.
     */
    public static function stampId(): int
    {
        if (! self::multiBrand()) {
            return self::NONE;
        }

        try {
            $manager = app('brand-context');

            return $manager->hasCurrent() ? (int) $manager->currentId() : self::NONE;
        } catch (Throwable) {
            return self::NONE;
        }
    }

    /**
     * The brand that `brand-context` itself calls default.
     *
     * Asked, never assumed. `DB::table('brands')->orderBy('id')->value('id')`
     * answers a different question — "which brand was created first" — and the
     * two only coincide by accident. The sibling decides its default by handle
     * and by an `is_default` flag, and a host may move it.
     *
     * Nothing in this package ever *writes* this id onto a row. It exists so a
     * report can name the brand that a guessing backfill would have written,
     * which is the difference between "seven rows could not be resolved" and
     * "seven rows could not be resolved and were **not** silently made
     * nordlicht's". Zero where the sibling is absent or has no default.
     */
    public static function defaultId(): int
    {
        if (! self::available()) {
            return self::NONE;
        }

        try {
            return (int) app('brand-context')->defaultId();
        } catch (Throwable) {
            return self::NONE;
        }
    }

    /**
     * Narrow a query to one brand's rows, fail-closed.
     *
     * Four cases and only four:
     *
     * 1. Single-brand install — no filter. There is one tenant; filtering on a
     *    column that is zero everywhere would be theatre.
     * 2. Multi-brand, a brand named — that brand's rows.
     * 3. Multi-brand, no brand named — **no rows at all**. Not "the default
     *    brand's", not "all of them". A reader who cannot say which tenant they
     *    belong to is not a reader of any tenant.
     * 4. The sibling would not answer — **no rows at all**, for the same reason.
     *    This is the case a boolean would have collapsed into case 1, and case 1
     *    returns everything.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function only(Builder $query, ?int $brandId): Builder
    {
        $mode = self::mode();

        if ($mode === self::SINGLE) {
            return $query;
        }

        if ($mode === self::UNKNOWN || $brandId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('brand_id', $brandId);
    }
}

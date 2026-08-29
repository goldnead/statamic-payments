<?php

namespace Goldnead\StatamicPayments\Http\Middleware;

use Closure;
use Goldnead\StatamicPayments\Portal\PortalSession;
use Goldnead\StatamicPayments\Support\Brands;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * A magic link opens the brand it was issued in.
 *
 * The brand was sealed into the link next to the address, and it has to survive
 * the redirect that follows it — otherwise everything downstream renders in
 * whatever brand the browser's session was already sitting in, which for a buyer
 * who has never seen a control panel is the default brand.
 *
 * **This is not what keeps one brand's orders away from another's.** That is
 * `Brands::only()`, applied to every portal query with the brand id out of the
 * session note, and it would hold even if this middleware were never registered.
 * What this buys is that the *siblings* — an invoice source, a sender identity —
 * see the same tenant the portal is answering for. Two mechanisms, because a
 * tenant boundary that depends on a middleware being in the right group is a
 * boundary that one route definition can lose.
 *
 * Never aborts. A stale or unknown brand id simply finds no brand and leaves the
 * scope closed, which is what fail-closed means one layer down.
 */
class SetBrandFromPortalSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Brands::multiBrand() || ! $request->hasSession()) {
            return $next($request);
        }

        $brandId = $request->session()->get(PortalSession::BRAND);

        if (! is_int($brandId) && ! ctype_digit((string) $brandId)) {
            return $next($request);
        }

        try {
            $brand = app('\Goldnead\BrandContext\Models\Brand')->newQuery()->find((int) $brandId);

            if ($brand !== null) {
                app('brand-context')->setCurrent($brand);
            }
        } catch (Throwable) {
            // The sibling is optional and this is a convenience for it. Failing
            // here would take down a page whose own isolation does not depend on
            // this line having run.
        }

        return $next($request);
    }
}

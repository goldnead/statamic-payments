<?php

namespace Goldnead\StatamicPayments\Http\Controllers\Portal;

use Goldnead\StatamicPayments\Portal\Orders;
use Goldnead\StatamicPayments\Portal\PortalAccess;
use Goldnead\StatamicPayments\Portal\PortalSession;
use Goldnead\StatamicPayments\Support\Catalogue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * What every portal screen shares.
 *
 * One place that turns a request into a {@see PortalAccess} or into a redirect,
 * so that no screen can be written that forgets to ask. The alternative — each
 * controller reading the session itself — is how one of five screens ends up
 * without the check, and it is never the screen anybody tests.
 */
abstract class PortalController extends Controller
{
    public function __construct(
        protected PortalSession $session,
        protected Orders $orders,
    ) {}

    /**
     * Who is asking, or `null` when nobody is.
     *
     * Null is not an error case to be logged; it is a link that has expired,
     * which is the ordinary end of every visit. The caller redirects to the
     * request page and the buyer asks for another one.
     */
    protected function access(Request $request): ?PortalAccess
    {
        // Checked here rather than only on the two form routes. A session note
        // lives an hour; switching the portal off while one is open would
        // otherwise close the entrance and leave every screen behind it working
        // — including the cancellation POST — until the note expired by itself.
        if (! config('statamic-payments.portal.enabled', true)) {
            return null;
        }

        return $this->session->access($request);
    }

    protected function askForALink(): RedirectResponse
    {
        return redirect()
            ->route('statamic-payments.portal.request')
            ->with('statamic-payments.portal.status', __('statamic-payments::portal.session_over'));
    }

    /**
     * What to call a product on a page the buyer reads.
     *
     * The catalogue where it can answer, the stored handle where it cannot. A
     * handle is ugly and it is honest; inventing a friendly name for a product
     * that has been removed from the catalogue would put a word on somebody's
     * order that was never on their receipt.
     */
    protected function nameOf(string $handle): string
    {
        $product = app(Catalogue::class)->find($handle);

        $name = $product['name'] ?? null;

        return is_string($name) && $name !== '' ? $name : $handle;
    }
}

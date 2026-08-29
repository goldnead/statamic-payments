<?php

namespace Goldnead\StatamicPayments\Http\Controllers\Portal;

use Goldnead\StatamicPayments\Portal\LinkRequests;
use Goldnead\StatamicPayments\Portal\LinkTokenizer;
use Goldnead\StatamicPayments\Portal\PortalSession;
use Goldnead\StatamicPayments\Support\Brands;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Throwable;

/**
 * Asking for a link, and following one.
 *
 * The response to a request is the same page with the same sentence whatever
 * happened, and it is held open to a floor so that the outcome cannot be read
 * off a stopwatch either. Both halves are needed: identical wording with a 12 ms
 * "never bought anything" and a 340 ms "mail sent" is a customer-list oracle
 * with good manners.
 */
class MagicLinkController extends Controller
{
    public function __construct(
        protected LinkRequests $requests,
        protected LinkTokenizer $tokenizer,
        protected PortalSession $session,
    ) {}

    /** The ordinary entrance: "show me my orders". */
    public function form(Request $request)
    {
        abort_unless(config('statamic-payments.portal.enabled', true), 404);

        return response()->view('statamic-payments::portal.request', [
            'brand' => $this->namedBrand($request),
            'intent' => 'orders',
        ]);
    }

    /**
     * The § 312k entrance: the page the „Verträge hier kündigen" button leads to.
     *
     * A separate route rather than a query parameter on the one above, because
     * the statute wants a button that leads *directly* to the cancellation, and
     * a URL a site can link from its footer is what makes that possible to
     * satisfy. It is the same form: whoever wants to cancel still has to prove
     * the contract is theirs, and a link to their own mailbox is how.
     *
     * Every word on it comes from `portal.cancel_*`, so the wording that goes in
     * front of a lawyer is a translation file and not a controller.
     */
    public function cancellationEntry(Request $request)
    {
        abort_unless(config('statamic-payments.portal.enabled', true), 404);

        return response()->view('statamic-payments::portal.request', [
            'brand' => $this->namedBrand($request),
            'intent' => 'cancel',
        ]);
    }

    public function send(Request $request)
    {
        abort_unless(config('statamic-payments.portal.enabled', true), 404);

        $started = microtime(true);

        // Not `email` validation on the request: a rejected field would answer
        // faster and differently than an accepted one, and the shape of an
        // address is the one thing about it we are willing to reveal. Malformed
        // input takes the same path and gets the same page.
        $this->requests->request(
            is_string($request->input('email')) ? $request->input('email') : null,
            (string) $request->ip(),
            $this->namedBrandId($request),
        );

        $this->holdOpen($started);

        // Back to the page they came from, so that somebody who pressed
        // „Verträge hier kündigen" is not silently moved onto a page about
        // order history. The intent is a label on a redirect and never anything
        // more: what a link opens is decided by what is sealed inside it.
        $route = $request->input('intent') === 'cancel'
            ? 'statamic-payments.portal.cancel.entry'
            : 'statamic-payments.portal.request';

        return redirect()
            ->route($route, array_filter(['payBrand' => $this->namedBrand($request)]))
            ->with('statamic-payments.portal.status', __('statamic-payments::portal.link_sent'));
    }

    /**
     * Following the link.
     *
     * The signature has already been checked by middleware. What is left is to
     * open the sealed blob — a 404 for anything this package did not write, not
     * a 403 with an explanation — and spend it on a session note.
     */
    public function open(Request $request, string $payLink)
    {
        abort_unless(config('statamic-payments.portal.enabled', true), 404);

        $payload = $this->tokenizer->open($payLink);

        abort_if($payload === null, 404);

        // A link sealed with no tenant cannot be honoured on a host that has
        // tenants. It is not forgeable — the blob is encrypted and the URL
        // signed — but it can be *stale*: issued before multi-brand was switched
        // on, when zero was the right answer. Answering it now would show rows
        // that belong to nobody.
        abort_if(Brands::mode() !== Brands::SINGLE && $payload['brand'] < 1, 404);

        $this->session->open($request, $payload['email'], $payload['brand']);

        return redirect()->route('statamic-payments.portal.show');
    }

    /** Leave, on purpose. A shared machine is the whole reason this exists. */
    public function close(Request $request)
    {
        $this->session->close($request);

        return redirect()
            ->route('statamic-payments.portal.request')
            ->with('statamic-payments.portal.status', __('statamic-payments::portal.signed_out'));
    }

    /**
     * The brand handle this page was linked with, if it was told one.
     *
     * `payBrand` is a hint and nothing more. A site that runs one shop of a
     * multi-brand host links here with its own handle, and the request then
     * searches that audience only. Left out — which is what the form itself
     * does, because it has no brand field — the address decides which brands are
     * searched.
     *
     * It is safe to let a visitor name a brand for the same reason the rest of
     * this endpoint is safe: the answer is the same sentence whichever brand is
     * named, and whether it exists at all. Naming one changes which audience is
     * searched and which brand a link opens — never whether anything is revealed.
     */
    protected function namedBrand(Request $request): ?string
    {
        $handle = $request->input('payBrand');

        return is_string($handle) && $handle !== '' ? $handle : null;
    }

    protected function namedBrandId(Request $request): ?int
    {
        $handle = $this->namedBrand($request);

        if ($handle === null || ! Brands::multiBrand()) {
            return null;
        }

        try {
            $brand = app('\Goldnead\BrandContext\Models\Brand')->newQuery()->where('handle', $handle)->first();

            return $brand === null ? null : (int) $brand->getKey();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Pad the response to the configured floor.
     *
     * A floor rather than a fixed duration: fixing it would make a slow mailer
     * visible as an overrun, and would also make this endpoint a convenient way
     * to hold a worker open.
     */
    protected function holdOpen(float $started): void
    {
        $floor = (int) config('statamic-payments.portal.min_response_ms', 350);
        $elapsedMs = (microtime(true) - $started) * 1000;

        if ($elapsedMs < $floor) {
            usleep((int) (($floor - $elapsedMs) * 1000));
        }
    }
}

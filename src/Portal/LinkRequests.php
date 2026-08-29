<?php

namespace Goldnead\StatamicPayments\Portal;

use Goldnead\StatamicPayments\Portal\Mail\PortalLinkMail;
use Goldnead\StatamicPayments\Support\Brands;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * "Send me a link to my orders."
 *
 * Four properties, all of them security rather than polish. They are the same
 * four `statamic-preference-center` arrived at, and they are copied on purpose
 * rather than reinvented — this endpoint has the same shape and therefore the
 * same holes.
 *
 * **It does not say whether the address bought anything.** Every outcome — sent,
 * no such buyer, throttled, misconfigured — returns the same page with the same
 * sentence, and the caller holds the response open to a floor so the fast paths
 * cannot be told from the slow one by a stopwatch. An endpoint that answered
 * "we have no orders for that address" politely is a customer-list oracle for
 * whoever asks it hardest, and here the list is a list of people who have spent
 * money.
 *
 * **It is throttled twice.** By address, so one mailbox cannot be flooded; by
 * origin, so the endpoint cannot be pointed at a list of addresses somebody else
 * owns. One limiter without the other is not a limit: per-address alone lets one
 * client mail ten thousand different people, per-origin alone lets ten thousand
 * clients mail one person. The address key is the address and nothing else — not
 * `brand|address`, which would read like tidy namespacing and hand every brand
 * its own budget into the same inbox.
 *
 * **A link is only issued where there is something to see.** `Orders::anythingFor()`
 * is asked per brand, and a brand with nothing gets no link. Without that the
 * endpoint would mail a signed URL to anything typed into it.
 *
 * **The address answers which brand, not the visitor.** See `brandsToSearch()`.
 */
class LinkRequests
{
    public function __construct(
        protected LinkTokenizer $tokenizer,
        protected Orders $orders,
    ) {}

    /**
     * @param  int|null  $brandId  the brand the visitor's page named, or null for
     *                             "none was named", which is the ordinary case
     * @return string one of `sent`, `unknown`, `throttled`, `disabled`,
     *                `misconfigured` — for logs and tests. It never reaches the
     *                visitor.
     */
    public function request(?string $rawEmail, string $origin, ?int $brandId = null): string
    {
        if (! config('statamic-payments.portal.enabled', true)) {
            return 'disabled';
        }

        $email = EmailAddress::normalise($rawEmail);

        if ($email === null || ! EmailAddress::looksDeliverable($email)) {
            return 'unknown';
        }

        if (! $this->withinLimits($email, $origin)) {
            return 'throttled';
        }

        $links = [];

        foreach ($this->brandsToSearch($brandId) as $brand) {
            if ($this->orders->anythingFor($email, $brand)) {
                $links[] = ['url' => $this->tokenizer->issue($email, $brand), 'brand' => $brand];
            }
        }

        if ($links === []) {
            return 'unknown';
        }

        return $this->mail($email, $links);
    }

    /**
     * One mail per brand.
     *
     * Not one mail carrying several brands' links: on a multi-brand host that is
     * a mail about brand B's orders arriving under brand A's name, and where the
     * transport verifies sending domains per account (Scaleway TEM, Postmark,
     * SES) it is a mail that gets refused outright or rewritten to whichever
     * identity the shared account owns. `statamic-preference-center` learned that
     * the expensive way and split its sends; this one is split from the start.
     *
     * In the ordinary case nothing about it is visible: one brand knows the
     * address, so one link, so one mail.
     *
     * @param  list<array{url: string, brand: int}>  $links
     */
    protected function mail(string $email, array $links): string
    {
        $sent = 0;

        foreach ($links as $link) {
            try {
                Mail::to($email)->send(new PortalLinkMail($link['url']));
                $sent++;
            } catch (Throwable $e) {
                Log::error('statamic-payments: the portal link could not be sent.', [
                    'brand_id' => $link['brand'],
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        return $sent > 0 ? 'sent' : 'misconfigured';
    }

    /**
     * Both limiters are hit for every request that gets this far, including the
     * ones for addresses that have never bought anything. Counting only real
     * buyers would turn the limiter itself into the oracle the rest of this
     * class is built to avoid.
     */
    protected function withinLimits(string $email, string $origin): bool
    {
        $limits = (array) config('statamic-payments.portal.throttle', []);

        $keys = [
            'address' => [
                'key' => 'statamic-payments:portal:address:'.hash('sha256', $email),
                'max' => (int) ($limits['per_address']['max'] ?? 3),
                'decay' => (int) ($limits['per_address']['decay_minutes'] ?? 60) * 60,
            ],
            'origin' => [
                'key' => 'statamic-payments:portal:origin:'.hash('sha256', $origin),
                'max' => (int) ($limits['per_origin']['max'] ?? 10),
                'decay' => (int) ($limits['per_origin']['decay_minutes'] ?? 60) * 60,
            ],
        ];

        $blocked = null;

        foreach ($keys as $name => $limit) {
            if (RateLimiter::tooManyAttempts($limit['key'], $limit['max'])) {
                $blocked ??= $name;

                continue;
            }

            RateLimiter::hit($limit['key'], $limit['decay']);
        }

        if ($blocked !== null) {
            Log::warning('statamic-payments: a portal link request was throttled.', ['limiter' => $blocked]);

            return false;
        }

        return true;
    }

    /**
     * Which brands a request searches — and why the form has no brand field.
     *
     * Every other entrance derives its brand from something the visitor could
     * not have chosen: a signature, a sealed blob. This one has nothing to derive
     * from, because an address is not yet known to belong anywhere and that is
     * precisely the question being asked. Three answers were available and two
     * are wrong.
     *
     * A **silent default brand** is a bet: it searches one audience and tells
     * everybody else the same reassuring sentence, which is then untrue for
     * every buyer of the other brands. A **visible brand field** publishes the
     * brand list to anyone who loads the page and asks somebody who bought from
     * one of several sister shops to remember which company that was.
     *
     * So the address answers it. The lookup runs in every brand and the buyer
     * receives one link per brand they have bought from — normally exactly one.
     * This reveals nothing: the page says the same sentence either way, and the
     * only person who learns which shops know the address is whoever reads that
     * mailbox, who already has the receipts.
     *
     * @return list<int> brand ids; `[Brands::NONE]` on a single-brand install
     */
    protected function brandsToSearch(?int $brandId): array
    {
        $mode = Brands::mode();

        if ($mode === Brands::SINGLE) {
            return [Brands::NONE];
        }

        // The sibling is there and would not say whether this host has tenants.
        // Issuing a link for "no brand" would hand out a key to the rows that
        // belong to nobody; issuing one per brand means guessing at a list this
        // package could not read. Neither, then.
        if ($mode === Brands::UNKNOWN) {
            return [];
        }

        try {
            if ($brandId !== null) {
                $named = app('\Goldnead\BrandContext\Models\Brand')->newQuery()->find($brandId);

                return $named === null ? [] : [(int) $named->getKey()];
            }

            return app('\Goldnead\BrandContext\Models\Brand')->newQuery()
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        } catch (Throwable $e) {
            // Fail-closed: a brand list that cannot be read is not a reason to
            // fall back to "all brands" or to the default one. No link is issued
            // and the visitor sees the same sentence as everybody else.
            Log::error('statamic-payments: the brand list could not be read; no portal link was issued.', [
                'exception' => $e->getMessage(),
            ]);

            return [];
        }
    }
}

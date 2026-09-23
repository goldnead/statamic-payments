<?php

namespace Goldnead\StatamicPayments\Support;

use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * A thank-you page that stops working after a while (P5).
 *
 * **Why.** A thank-you page often carries what was bought: a download, a join
 * link, the first lesson. Its address is plain (`/danke?payment=42`) and gets
 * forwarded, bookmarked, posted in a group chat.
 *
 * **How.** With `thanks.expires_minutes` set, the provider no longer sends the
 * buyer to the page itself but to a signed, expiring link of this addon. That
 * link notes in the buyer's session until when the page is theirs and then
 * forwards to the configured page. The page itself asks with
 * `{{ payments:thanks }}` whether the visit is still valid:
 *
 *     {{ payments:thanks }}
 *         {{ if valid }} … the download … {{ else }} … link expired … {{ /if }}
 *     {{ /payments:thanks }}
 *
 * A link opened after its time lands on a short page of this addon, or on
 * `thanks.expired_url`. Off by default: without the setting nothing changes.
 */
class ThanksLink
{
    public const SESSION_KEY = 'statamic-payments.thanks';

    public function enabled(): bool
    {
        return $this->minutes() > 0;
    }

    public function minutes(): int
    {
        return max(0, (int) config('statamic-payments.thanks.expires_minutes', 0));
    }

    /** The signed link the provider sends the buyer back to, around `$target`. */
    public function wrap(string $target, Payment $payment): string
    {
        return URL::temporarySignedRoute(
            'statamic-payments.thanks',
            Carbon::now()->addMinutes($this->minutes()),
            ['payPayment' => $payment->getKey(), 'to' => $target],
        );
    }

    /** Note in the session that this visit may see the page, until when. */
    public function admit(Request $request, Payment $payment): void
    {
        $request->session()->put(self::SESSION_KEY, [
            'payment_id' => (int) $payment->getKey(),
            'until' => Carbon::now()->addMinutes($this->minutes())->toIso8601String(),
        ]);
    }

    /**
     * What the page may know about this visit.
     *
     * @return array{valid: bool, payment_id: int|null, expires_at: string|null}
     */
    public function state(?Request $request = null): array
    {
        $request ??= request();

        if (! $this->enabled()) {
            // Nothing expires where nothing was set up. The page stays what it
            // was, and the tag says so rather than hiding everything.
            return ['valid' => true, 'payment_id' => null, 'expires_at' => null];
        }

        $note = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;

        if (! is_array($note) || ! is_string($note['until'] ?? null)) {
            return ['valid' => false, 'payment_id' => null, 'expires_at' => null];
        }

        $until = Carbon::parse($note['until']);

        return [
            'valid' => $until->isFuture(),
            'payment_id' => isset($note['payment_id']) ? (int) $note['payment_id'] : null,
            'expires_at' => $until->toIso8601String(),
        ];
    }
}

<?php

namespace Goldnead\StatamicPayments\Support;

use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;

/**
 * A thank-you page that stops working after a while (P5).
 *
 * **Why.** A thank-you page often carries what was bought: a download, a join
 * link, the first lesson. Its address is plain (`/danke?payment=42`) and gets
 * forwarded, bookmarked, posted in a group chat.
 *
 * **How.** With `thanks.expires_minutes` set, the provider no longer sends the
 * buyer to the page itself but to a signed link of this addon. The signature
 * runs `thanks.link_hours` (24): a buyer paying by bank transfer comes back
 * much later than one paying by card. The window of `expires_minutes` starts
 * at the **first** visit through the link, is noted in the session, and the
 * page asks `{{ payments:thanks }}` about it:
 *
 *     {{ payments:thanks }}
 *         {{ if valid }} … the download …
 *         {{ elseif pending }} … payment is being confirmed …
 *         {{ else }} … link expired … {{ /if }}
 *     {{ /payments:thanks }}
 *
 * **`valid` means paid.** The window alone would show the download to somebody
 * whose direct debit has not arrived yet, or never will. `paid` and `pending`
 * say which of the two a page is looking at.
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

    /** How long the signed link itself works. */
    public function linkHours(): int
    {
        return max(1, (int) config('statamic-payments.thanks.link_hours', 24));
    }

    /** The signed link the provider sends the buyer back to, around `$target`. */
    public function wrap(string $target, Payment $payment): string
    {
        return URL::temporarySignedRoute(
            'statamic-payments.thanks',
            Carbon::now()->addHours($this->linkHours()),
            ['payPayment' => $payment->getKey(), 'to' => $target],
        );
    }

    /**
     * Until when this payment's page may be seen: `expires_minutes` from the
     * first visit through the link, remembered per payment. Null once over.
     */
    public function windowFor(Payment $payment): ?Carbon
    {
        $key = 'statamic-payments.thanks.first.'.$payment->getKey();
        Cache::add($key, Carbon::now()->toIso8601String(), Carbon::now()->addHours($this->linkHours() + 1));

        $first = Cache::get($key);
        $until = Carbon::parse(is_string($first) ? $first : Carbon::now()->toIso8601String())->addMinutes($this->minutes());

        return $until->isFuture() ? $until : null;
    }

    /** Note in the session that this visit may see the page, until when. */
    public function admit(Request $request, Payment $payment, Carbon $until): void
    {
        $request->session()->put(self::SESSION_KEY, [
            'payment_id' => (int) $payment->getKey(),
            'until' => $until->toIso8601String(),
        ]);
    }

    /**
     * What the page may know about this visit.
     *
     * @return array{valid: bool, paid: bool|null, pending: bool, payment_id: int|null, expires_at: string|null}
     */
    public function state(?Request $request = null): array
    {
        $request ??= request();

        if (! $this->enabled()) {
            // Nothing expires where nothing was set up. The page stays what it
            // was, and the tag says so rather than hiding everything. Whether
            // it was paid is not known here: no link carried the payment.
            return ['valid' => true, 'paid' => null, 'pending' => false, 'payment_id' => null, 'expires_at' => null];
        }

        $note = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;

        if (! is_array($note) || ! is_string($note['until'] ?? null)) {
            return ['valid' => false, 'paid' => false, 'pending' => false, 'payment_id' => null, 'expires_at' => null];
        }

        $until = Carbon::parse($note['until']);
        $id = isset($note['payment_id']) ? (int) $note['payment_id'] : null;
        $payment = $id !== null ? Payment::find($id) : null;
        $paid = $payment !== null && $payment->isPaid();
        $within = $until->isFuture();

        return [
            'valid' => $within && $paid,
            'paid' => $paid,
            'pending' => $within && ! $paid,
            'payment_id' => $id,
            'expires_at' => $until->toIso8601String(),
        ];
    }
}

<?php

namespace Goldnead\StatamicPayments\Portal;

use Goldnead\StatamicPayments\Support\Brands;
use Illuminate\Http\Request;

/**
 * What a followed magic link leaves behind.
 *
 * The link is signed and expiring, so it cannot be posted to — a form submission
 * would have to carry the signature back, and every redirect after it would
 * carry it further, into logs and referrers. Instead the link is spent once on
 * arrival and leaves a short-lived note in the session, with its own expiry,
 * independent of whatever session lifetime the host has configured for people
 * who log in.
 *
 * The note carries the brand as well as the address. Not so that it can be
 * changed later — nothing in this package writes it except `open()` — but so
 * that every read after the redirect asks the same question the link answered.
 */
class PortalSession
{
    public const EMAIL = 'statamic-payments.portal.email';

    public const BRAND = 'statamic-payments.portal.brand';

    public const EXPIRES = 'statamic-payments.portal.expires';

    /** The three keys the note is made of. Everything that ends it uses this. */
    public const KEYS = [self::EMAIL, self::BRAND, self::EXPIRES];

    /**
     * Spend the link and leave the note.
     *
     * **The session id is regenerated first, and the old record destroyed with
     * it.** Without that, whoever handed over the session id gets the note: a
     * link followed in a session somebody else fixed — a shared machine, a
     * `?PHPSESSID` in a forwarded URL, a cookie written by a neighbouring
     * subdomain — writes the address into *their* session, and the id they
     * already hold now opens a stranger's order history. This is the same move
     * `Auth::login()` makes, for the same reason: anything that grants access to
     * a session has to give that session a new name.
     */
    public function open(Request $request, string $email, int $brandId): void
    {
        $request->session()->regenerate(true);

        $request->session()->put(self::EMAIL, $email);
        $request->session()->put(self::BRAND, $brandId);
        $request->session()->put(self::EXPIRES, now()
            ->addMinutes($this->minutes())
            ->getTimestamp());
    }

    public function access(Request $request): ?PortalAccess
    {
        if (! $request->hasSession()) {
            return null;
        }

        $email = EmailAddress::normalise($request->session()->get(self::EMAIL));
        $expires = (int) $request->session()->get(self::EXPIRES, 0);

        if ($email === null) {
            return null;
        }

        if ($expires < now()->getTimestamp()) {
            $this->close($request);

            return null;
        }

        $brand = $request->session()->get(self::BRAND);

        // Not a fallback to the default brand, and not to zero-as-"any". A note
        // without a readable brand is a note this package did not write, and the
        // access it would grant is the one thing that must never be guessed. Ask
        // for a new link.
        if (! is_int($brand) && ! ctype_digit((string) $brand)) {
            $this->close($request);

            return null;
        }

        // Zero means "no tenant", which is right on the single-brand installs
        // that are the great majority and wrong on every multi-brand one: rows
        // land on zero when they were created where no brand was current — a
        // webhook whose parent could not be found, a console command. Those rows
        // belong to nobody, and neither does a note that claims them.
        if (Brands::mode() !== Brands::SINGLE && (int) $brand < 1) {
            $this->close($request);

            return null;
        }

        return new PortalAccess($email, (int) $brand);
    }

    public function close(Request $request): void
    {
        $request->session()->forget(self::KEYS);
    }

    protected function minutes(): int
    {
        return max(1, (int) config('statamic-payments.portal.session_minutes', 60));
    }
}

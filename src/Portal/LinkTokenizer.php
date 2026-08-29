<?php

namespace Goldnead\StatamicPayments\Portal;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\URL;

/**
 * The way into the portal: a signed, expiring URL and nothing else.
 *
 * No token table, and that is a decision rather than an omission — the same one
 * `statamic-preference-center` made, for the same reasons. A table would make
 * this package the owner of a fourth one, with its own migration, its own index
 * and its own pruning job, to hold a fact Laravel can carry in the URL and
 * verify without a query.
 *
 * What the URL carries is **encrypted, not merely signed**. The signature already
 * makes it unforgeable; the encryption keeps the buyer's address out of access
 * logs, `Referer` headers and browser history, which a signed-but-plain link
 * would scatter it across.
 *
 * **The brand is sealed in next to the address.** That is the whole of constraint
 * five: on a multi-brand host the tenant travels with the link and never with
 * the session, so a link issued for brand A cannot be made to open brand B by
 * anything the holder does to their cookies. It is inside the encrypted blob,
 * inside the path, inside the signature — changing it means forging all three.
 *
 * What this design does not give you is single use and revocation. The trade is
 * the lifetime, which is minutes. Shorten it rather than reaching for a table.
 */
class LinkTokenizer
{
    /** @return string absolute, signed, expiring URL */
    public function issue(string $email, int $brandId): string
    {
        return URL::temporarySignedRoute(
            'statamic-payments.portal.link',
            now()->addMinutes($this->ttlMinutes()),
            ['payLink' => $this->seal($email, $brandId)],
        );
    }

    /**
     * @return array{email: string, brand: int}|null null when the blob is not ours
     */
    public function open(string $blob): ?array
    {
        try {
            $decoded = json_decode(Crypt::decryptString($this->fromUrlSafe($blob)), true);
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($decoded) || ! isset($decoded['e'], $decoded['b'])) {
            return null;
        }

        $email = EmailAddress::normalise((string) $decoded['e']);

        return $email === null ? null : ['email' => $email, 'brand' => (int) $decoded['b']];
    }

    public function ttlMinutes(): int
    {
        return max(1, (int) config('statamic-payments.portal.link_ttl_minutes', 30));
    }

    protected function seal(string $email, int $brandId): string
    {
        return $this->toUrlSafe(Crypt::encryptString((string) json_encode([
            'e' => EmailAddress::normalise($email),
            'b' => $brandId,
            'i' => time(),
        ])));
    }

    protected function toUrlSafe(string $value): string
    {
        return rtrim(strtr($value, '+/', '-_'), '=');
    }

    protected function fromUrlSafe(string $value): string
    {
        $value = strtr($value, '-_', '+/');

        return str_pad($value, (int) (ceil(strlen($value) / 4) * 4), '=', STR_PAD_RIGHT);
    }
}

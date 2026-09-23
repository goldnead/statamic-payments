<?php

namespace Goldnead\StatamicPayments\Support;

use Goldnead\StatamicPayments\Events\CheckoutBlocked;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;

/**
 * The door in front of the checkout (P7): a block list, a rate limit, a captcha.
 *
 * **What it is against.** Card testing: a script that tries stolen card numbers
 * one small checkout at a time. Each attempt costs the site a provider fee and,
 * at enough of them, the provider account. And the few addresses a shop has
 * decided not to sell to.
 *
 * **Where it stands.** In `Checkout::start()`, before a row is written or a
 * provider is called. Every way into a new payment goes through there (the
 * funnels checkout, a resumed abandoned checkout, a site's own form), so none
 * of them can be the way round it. Follow-up charges on a stored card do not
 * start a checkout and are not affected.
 *
 * **What a refusal looks like.** `start()` answers null, which every caller
 * already handles as "this checkout cannot be started", and
 * `Checkout::refusal()` then holds a sentence for the page: "too many attempts,
 * try again in a few minutes", "please confirm you are not a robot", or, for
 * the block list, only that the order cannot be taken. The block list's rule
 * itself is never named: telling a card tester which rule caught him is
 * telling him which to avoid. The code goes to the log and to `CheckoutBlocked`.
 *
 * **The captcha is off by default**, and turning it on is a promise the site
 * makes: every checkout form renders `{{ payments:captcha }}`. A form without it
 * is refused, which is the point of it.
 */
class CheckoutGuard
{
    public const TURNSTILE = 'turnstile';

    public const HCAPTCHA = 'hcaptcha';

    /**
     * Null when the checkout may go ahead, otherwise why not.
     *
     * @param  array<string, mixed>  $buyer
     * @return 'blocked_email'|'blocked_domain'|'blocked_ip'|'rate_limited'|'captcha'|null
     */
    public function check(array $buyer, ?Request $request = null): ?string
    {
        $request ??= request();
        $email = is_string($buyer['email'] ?? null) ? mb_strtolower(trim($buyer['email'])) : '';
        $ip = (string) $request->ip();

        $reason = $this->blocked($email, $ip)
            ?? ($this->rateLimited($email, $this->countableIp($request)) ? 'rate_limited' : null)
            ?? ($this->captchaPasses($request, $ip) ? null : 'captcha');

        if ($reason !== null) {
            Log::warning('statamic-payments: a checkout was refused at the door.', [
                'reason' => $reason,
                'ip' => $ip,
                'email_domain' => str_contains($email, '@') ? substr($email, strrpos($email, '@') + 1) : null,
            ]);

            try {
                CheckoutBlocked::dispatch($reason, $email !== '' ? $email : null, $ip !== '' ? $ip : null, self::message($reason));
            } catch (Throwable $e) {
                Log::error('statamic-payments: a listener threw on a refused checkout.', ['exception' => $e->getMessage()]);
            }
        }

        return $reason;
    }

    /** @return 'blocked_email'|'blocked_domain'|'blocked_ip'|null */
    public function blocked(string $email, string $ip): ?string
    {
        $list = (array) config('statamic-payments.protection.blocklist', []);

        if ($email !== '') {
            foreach ($this->entries($list['emails'] ?? []) as $blocked) {
                if ($email === mb_strtolower($blocked)) {
                    return 'blocked_email';
                }
            }

            $domain = str_contains($email, '@') ? substr($email, strrpos($email, '@') + 1) : '';

            foreach ($this->entries($list['domains'] ?? []) as $blocked) {
                $blocked = ltrim(mb_strtolower($blocked), '@.');

                // The domain and every subdomain of it: `mail.wegwerf.example`
                // is as disposable as `wegwerf.example`.
                if ($domain !== '' && ($domain === $blocked || str_ends_with($domain, '.'.$blocked))) {
                    return 'blocked_domain';
                }
            }
        }

        if ($ip !== '') {
            $ranges = $this->entries($list['ips'] ?? []);

            try {
                if ($ranges !== [] && IpUtils::checkIp($ip, $ranges)) {
                    return 'blocked_ip';
                }
            } catch (\InvalidArgumentException|\ValueError) {
                // A mistyped range must not stop every checkout. Narrow on
                // purpose: a broad catch here once hid a missing class and
                // turned the whole IP list into a no-op.
                Log::warning('statamic-payments: protection.blocklist.ips holds an entry that is not an address or range.');
            }
        }

        return null;
    }

    /**
     * Counted per address and per origin, like the portal's own brake.
     *
     * Every checkout that got this far counts, paid or not: a card tester's
     * attempts fail at the provider, after this point, and waiting for the
     * failure to count them would let the whole batch through first.
     */
    public function rateLimited(string $email, string $ip): bool
    {
        $limit = (array) config('statamic-payments.protection.rate_limit', []);

        if (! ($limit['enabled'] ?? true)) {
            return false;
        }

        $decay = max(1, (int) ($limit['decay_minutes'] ?? 10)) * 60;
        $keys = array_filter([
            $ip !== '' ? ['statamic-payments.checkout.ip|'.$ip, max(1, (int) ($limit['per_ip'] ?? 100))] : null,
            $email !== '' ? ['statamic-payments.checkout.email|'.sha1($email), max(1, (int) ($limit['per_email'] ?? 10))] : null,
        ]);

        foreach ($keys as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                return true;
            }
        }

        foreach ($keys as [$key]) {
            RateLimiter::hit($key, $decay);
        }

        return false;
    }

    /** Said once per process, not once per checkout. */
    protected static bool $warnedAboutProxy = false;

    /**
     * The address worth counting, or '' when there is none.
     *
     * A private or loopback address is not a visitor's: it is a proxy this
     * application does not trust (no TrustProxies), and every buyer arrives
     * from it. Counting it would brake a whole shop as one person. Then only
     * the email address is counted. Where the proxy even forwarded the real
     * address and nobody trusts it, that is a setup fault worth one line.
     */
    public function countableIp(Request $request): string
    {
        $ip = (string) $request->ip();

        if ($ip === '' || ! IpUtils::isPrivateIp($ip)) {
            return $ip;
        }

        if ($request->headers->has('X-Forwarded-For') && ! self::$warnedAboutProxy) {
            self::$warnedAboutProxy = true;

            Log::warning('statamic-payments: checkouts arrive from a private address with X-Forwarded-For set; the proxy is not trusted (TrustProxies), so the checkout brake counts email addresses only.', [
                'ip' => $ip,
            ]);
        }

        return '';
    }

    /** For tests: the proxy warning may be said again. */
    public static function forgetWarnings(): void
    {
        self::$warnedAboutProxy = false;
    }

    /** The reason, in words a buyer can read. */
    public static function message(string $reason): string
    {
        return (string) __('statamic-payments::checkout.refused_'.match ($reason) {
            'blocked_email', 'blocked_domain', 'blocked_ip' => 'blocked',
            'rate_limited' => 'rate_limited',
            'captcha' => 'captcha',
            'country' => 'country',
            default => 'blocked',
        });
    }

    public function captchaProvider(): ?string
    {
        $provider = config('statamic-payments.protection.captcha.provider');

        return in_array($provider, [self::TURNSTILE, self::HCAPTCHA], true) ? $provider : null;
    }

    /**
     * Whether the token in this request is one the captcha service vouches for.
     *
     * Asked of the service, never decided here. A service that cannot be
     * reached is a refusal: failing open would switch the protection off for
     * exactly as long as somebody manages to make it unreachable.
     */
    public function captchaPasses(Request $request, string $ip): bool
    {
        $provider = $this->captchaProvider();

        if ($provider === null) {
            return true;
        }

        $secret = (string) config('statamic-payments.protection.captcha.secret', '');
        $field = $provider === self::TURNSTILE ? 'cf-turnstile-response' : 'h-captcha-response';
        $token = trim((string) $request->input($field, ''));

        if ($secret === '' || $token === '') {
            return false;
        }

        $endpoint = $provider === self::TURNSTILE
            ? 'https://challenges.cloudflare.com/turnstile/v0/siteverify'
            : 'https://api.hcaptcha.com/siteverify';

        try {
            $answer = Http::asForm()->timeout(5)->post($endpoint, array_filter([
                'secret' => $secret,
                'response' => $token,
                'remoteip' => $ip !== '' ? $ip : null,
            ]))->json();
        } catch (Throwable $e) {
            Log::warning('statamic-payments: the captcha service could not be reached; the checkout was refused.', [
                'provider' => $provider,
                'exception' => $e->getMessage(),
            ]);

            return false;
        }

        return is_array($answer) && ($answer['success'] ?? false) === true;
    }

    /** The widget markup for `{{ payments:captcha }}`, or nothing. */
    public function widget(): string
    {
        $provider = $this->captchaProvider();
        $key = e((string) config('statamic-payments.protection.captcha.site_key', ''));

        if ($provider === null || $key === '') {
            return '';
        }

        return $provider === self::TURNSTILE
            ? '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script><div class="cf-turnstile" data-sitekey="'.$key.'"></div>'
            : '<script src="https://js.hcaptcha.com/1/api.js" async defer></script><div class="h-captcha" data-sitekey="'.$key.'"></div>';
    }

    /**
     * One list, from config or from the settings screen: an array, or one entry
     * per line where somebody pasted a block of text.
     *
     * @return list<string>
     */
    protected function entries(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\r\n,]+/', $value) ?: [];
        }

        return array_values(array_filter(array_map(
            fn ($v) => is_string($v) ? trim($v) : '',
            (array) $value,
        ), fn (string $v) => $v !== ''));
    }
}

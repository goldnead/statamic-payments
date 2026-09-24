<?php

namespace Goldnead\StatamicPayments\Integrations\WebhookManager;

use Goldnead\StatamicPayments\Events\CheckoutAbandoned;
use Goldnead\StatamicPayments\Events\CheckoutBlocked;
use Goldnead\StatamicPayments\Events\PaymentChargedBack;
use Goldnead\StatamicPayments\Events\PaymentFailed;
use Goldnead\StatamicPayments\Events\PaymentPaid;
use Goldnead\StatamicPayments\Events\PaymentRefunded;
use Goldnead\StatamicPayments\Events\SubscriptionAttemptFailed;
use Goldnead\StatamicPayments\Events\SubscriptionCancelled;
use Goldnead\StatamicPayments\Events\SubscriptionCardExpired;
use Goldnead\StatamicPayments\Events\SubscriptionCardExpiring;
use Goldnead\StatamicPayments\Events\SubscriptionChanged;
use Goldnead\StatamicPayments\Events\SubscriptionEnded;
use Goldnead\StatamicPayments\Events\SubscriptionPaused;
use Goldnead\StatamicPayments\Events\SubscriptionPaymentUpcoming;
use Goldnead\StatamicPayments\Events\SubscriptionPlanCompleted;
use Goldnead\StatamicPayments\Events\SubscriptionRenewed;
use Goldnead\StatamicPayments\Events\SubscriptionReplaced;
use Goldnead\StatamicPayments\Events\SubscriptionResumed;
use Goldnead\StatamicPayments\Events\SubscriptionStarted;
use Goldnead\StatamicPayments\Events\SubscriptionStartFailed;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\PaymentItem;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Support\Brands;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What a webhook receiver is told about a payment moment, and nothing more.
 *
 * Deliberately a list, never a model's `toArray()`. A payment row carries the
 * card's last four digits, the mandate, the provider's customer reference,
 * the consent text, the landing page with whatever query string the visitor
 * arrived on, and `meta` with the thank-you token. A Zapier zap or an n8n flow
 * needs none of that, and anything handed to a webhook is handed to a third
 * party for good. A column added to `payments` next year must not appear in
 * every receiver's body without somebody deciding it should.
 *
 * The shape is shared with the other addons of the suite:
 *
 *     {
 *       "event": "payments.subscription_paused",
 *       "occurred_at": "2026-09-24T10:12:03+02:00",
 *       "brand": {"id": 2, "handle": "nordlicht"} | null,
 *       "<object>": { ... }, ...
 *     }
 *
 * Money is always `*_cent` (integer, minor units) next to `currency`. Every
 * point in time is ISO 8601 (`DATE_ATOM`) or null. A person is `email` and
 * `name` on the object that names them.
 *
 * This class names no class of the webhook manager, so it loads, and is
 * tested, on every install.
 */
final class WebhookPayload
{
    /**
     * Payment moment => event class. The handle is `payments.<moment>`, the
     * same one the automations addon uses for the same moment.
     *
     * Two public events are left out on purpose, as they are in automations:
     * `SubscriptionCycleFailed` is the raw provider status behind
     * `subscription_attempt_failed` and `failed`, and would fire twice for one
     * failure; `PaymentCommunicationLogged` is a line in this addon's own
     * mail log, not something that happened to a buyer.
     *
     * @var array<string, class-string>
     */
    public const MOMENTS = [
        'paid' => PaymentPaid::class,
        'failed' => PaymentFailed::class,
        'refunded' => PaymentRefunded::class,
        'charged_back' => PaymentChargedBack::class,
        'checkout_abandoned' => CheckoutAbandoned::class,
        'checkout_blocked' => CheckoutBlocked::class,
        'subscription_started' => SubscriptionStarted::class,
        'subscription_start_failed' => SubscriptionStartFailed::class,
        'subscription_renewed' => SubscriptionRenewed::class,
        'subscription_attempt_failed' => SubscriptionAttemptFailed::class,
        'subscription_payment_upcoming' => SubscriptionPaymentUpcoming::class,
        'subscription_card_expiring' => SubscriptionCardExpiring::class,
        'subscription_card_expired' => SubscriptionCardExpired::class,
        'subscription_paused' => SubscriptionPaused::class,
        'subscription_resumed' => SubscriptionResumed::class,
        'subscription_changed' => SubscriptionChanged::class,
        'subscription_replaced' => SubscriptionReplaced::class,
        'subscription_plan_completed' => SubscriptionPlanCompleted::class,
        'subscription_cancelled' => SubscriptionCancelled::class,
        'subscription_ended' => SubscriptionEnded::class,
    ];

    public const PREFIX = 'payments.';

    /** @var array<int, array{id: int, handle: string}|null> */
    private static array $brands = [];

    /**
     * The body for one moment.
     *
     * @return array<string, mixed>
     */
    public static function build(string $moment, object $event, ?\DateTimeInterface $at = null): array
    {
        [$type, $id] = self::subjectOf($event);

        return array_merge([
            'event' => self::PREFIX.$moment,
            'event_id' => self::eventId($moment, $event),
            'occurred_at' => self::date($at ?? self::occurredAt($event)),
            'brand' => self::brand(self::brandIdOf($event)),
            // Named outright, because the manager's own guess reads every
            // `payments.*` reference as a payment id, and a paused
            // subscription would then be filed under the payment that happens
            // to share its number in "Webhook deliveries for this object".
            'subject_type' => $type,
            'subject_id' => $id,
        ], self::body($event));
    }

    /**
     * The same id for the same moment, however often it is dispatched.
     *
     * A provider redelivers its webhook, a command runs twice, a listener
     * retries: the event fires again and the receiver hears it again. This id
     * lets it throw the second one away. It is built from what makes the moment
     * this moment and not another one (the object, and the time or reference
     * that separates it from the next moment of the same kind on that object),
     * never from the clock at dispatch.
     *
     * `sha1(handle|part|part…)`, 40 hex characters, the same recipe in every
     * addon of the suite.
     */
    public static function eventId(string $moment, object $event): string
    {
        return sha1(implode('|', array_map(
            fn ($part) => $part instanceof \DateTimeInterface ? $part->format(\DATE_ATOM) : (string) $part,
            [self::PREFIX.$moment, ...self::momentParts($event)],
        )));
    }

    /**
     * When the moment happened, as the rows record it. Null only where no row
     * records it; the caller then falls back to the clock.
     */
    public static function occurredAt(object $event): \DateTimeInterface
    {
        foreach (self::momentParts($event) as $part) {
            if ($part instanceof \DateTimeInterface) {
                return $part;
            }
        }

        return now();
    }

    /**
     * What separates this moment from every other moment of the same kind.
     *
     * The first date in the list is also the moment's time. A blocked checkout
     * has no row and no date of its own: reason, address and network within
     * the same minute count as one moment.
     *
     * @return list<mixed>
     */
    private static function momentParts(object $event): array
    {
        $sub = fn (Subscription $s): string => 'subscription:'.$s->getKey();
        $pay = fn (Payment $p): string => 'payment:'.$p->getKey();

        return match (true) {
            $event instanceof PaymentPaid => [$pay($event->payment), $event->payment->paid_at ?? 'paid'],
            $event instanceof PaymentFailed => [$pay($event->payment), $event->payment->updated_at ?? $event->payment->status],
            // Cumulative after this refund: two partial refunds of the same
            // size are two moments, the same refund told twice is one.
            $event instanceof PaymentRefunded => [$pay($event->payment), $event->payment->refunded_at ?? '', 'refunded:'.(int) $event->payment->refunded_cent, 'amount:'.$event->amountCent],
            $event instanceof PaymentChargedBack => [$pay($event->payment), $event->payment->charged_back_at ?? '', 'chargeback:'.$event->reference],
            $event instanceof CheckoutAbandoned => [$pay($event->payment), $event->payment->abandoned_notified_at ?? $event->payment->created_at ?? ''],
            $event instanceof CheckoutBlocked => [now()->startOfMinute(), $event->reason, (string) $event->email, (string) self::ipPrefix($event->ip)],
            $event instanceof SubscriptionStartFailed => [$pay($event->payment), $event->payment->updated_at ?? '', 'reason:'.$event->reason],
            $event instanceof SubscriptionStarted,
            $event instanceof SubscriptionRenewed,
            $event instanceof SubscriptionPlanCompleted => [$sub($event->subscription), $event->payment->paid_at ?? '', $pay($event->payment)],
            $event instanceof SubscriptionAttemptFailed => [$sub($event->subscription), $event->payment->updated_at ?? '', $pay($event->payment), 'attempt:'.$event->attempt],
            $event instanceof SubscriptionPaymentUpcoming => [$sub($event->subscription), $event->dueAt, 'days:'.$event->daysBefore],
            $event instanceof SubscriptionCardExpiring => [$sub($event->subscription), $event->expiresAt],
            $event instanceof SubscriptionCardExpired => [$sub($event->subscription), $event->expiredAt],
            $event instanceof SubscriptionPaused => [$sub($event->subscription), $event->subscription->paused_at ?? $event->subscription->updated_at ?? ''],
            $event instanceof SubscriptionResumed => [$sub($event->subscription), $event->subscription->updated_at ?? '', 'by:'.$event->by],
            $event instanceof SubscriptionChanged => [$sub($event->subscription), $event->subscription->updated_at ?? '', $event->fromProduct.'>'.$event->toProduct],
            $event instanceof SubscriptionReplaced => [$sub($event->replaced), $event->replaced->ended_at ?? $event->purchase->paid_at ?? '', $pay($event->purchase)],
            $event instanceof SubscriptionCancelled => [$sub($event->subscription), $event->subscription->cancelled_at ?? $event->subscription->updated_at ?? ''],
            $event instanceof SubscriptionEnded => [$sub($event->subscription), $event->subscription->ended_at ?? $event->subscription->updated_at ?? ''],
            default => [$event::class],
        };
    }

    /**
     * Run the hand-over as the brand the row names, or not at all.
     *
     * Stricter than {@see Brands::runFor()} on purpose. That one lets money
     * work run on when the brand cannot be set; a webhook must not, because
     * the only brand left is whatever is current, and its hooks belong to
     * another tenant. A row naming a brand that cannot be set (deleted, a
     * typo in a backfill) is logged and not delivered.
     *
     * No brand named, or no brand-context installed: runs as it is.
     *
     * @param  \Closure(): void  $callback
     */
    public static function runForBrand(?int $brand, \Closure $callback, string $handle): bool
    {
        if (! $brand || ! Brands::available()) {
            $callback();

            return true;
        }

        $ran = false;

        try {
            app('brand-context')->runFor($brand, function () use ($callback, &$ran): void {
                $ran = true;
                $callback();
            });

            return true;
        } catch (Throwable $e) {
            if ($ran) {
                throw $e;
            }

            Log::warning('statamic-payments: the row names a brand that cannot be set; the webhook was not delivered rather than sent through another brand\'s hooks.', [
                'trigger' => $handle,
                'brand_id' => $brand,
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * The object a moment is about: the agreement for a subscription moment,
     * the payment for a payment moment, nothing for a blocked checkout.
     *
     * @return array{0: string|null, 1: int|null}
     */
    public static function subjectOf(object $event): array
    {
        foreach (['subscription', 'replaced'] as $key) {
            $model = $event->{$key} ?? null;

            if ($model instanceof Subscription && $model->getKey() !== null) {
                return ['subscription', (int) $model->getKey()];
            }
        }

        $payment = $event->payment ?? null;

        if ($payment instanceof Payment && $payment->getKey() !== null) {
            return ['payment', (int) $payment->getKey()];
        }

        return [null, null];
    }

    /**
     * The id of the object a receiver would look the moment up by.
     */
    public static function referenceOf(object $event): ?string
    {
        $id = self::subjectOf($event)[1];

        return $id === null ? null : (string) $id;
    }

    /**
     * Whose moment this is: the brand stamped on the row it is about.
     *
     * The rows know; the request often does not. A provider webhook and the
     * reminder command run with no brand at all, so the current brand would
     * send a nordlicht renewal through the default brand's endpoints. The one
     * event without a row, `CheckoutBlocked`, happens inside the visitor's
     * request, and there the current brand is the right answer.
     */
    public static function brandIdOf(object $event): ?int
    {
        foreach (['subscription', 'replaced', 'payment', 'purchase'] as $key) {
            $model = $event->{$key} ?? null;

            if (is_object($model) && is_numeric($model->brand_id ?? null) && (int) $model->brand_id > 0) {
                return (int) $model->brand_id;
            }
        }

        return self::currentBrandId();
    }

    /**
     * `{id, handle}` of a brand, or null where brand-context is not installed
     * or cannot name one.
     *
     * @return array{id: int, handle: string}|null
     */
    public static function brand(?int $id): ?array
    {
        $model = 'Goldnead\\BrandContext\\Models\\Brand';

        if ($id === null || $id < 1 || ! class_exists($model)) {
            return null;
        }

        if (array_key_exists($id, self::$brands)) {
            return self::$brands[$id];
        }

        try {
            $brand = $model::query()->find($id);
            $answer = $brand === null ? null : ['id' => (int) $brand->getKey(), 'handle' => (string) $brand->getAttribute('handle')];
        } catch (Throwable) {
            $answer = null;
        }

        return self::$brands[$id] = $answer;
    }

    /** For tests: a brand renamed between two cases must not answer twice. */
    public static function forgetBrands(): void
    {
        self::$brands = [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function body(object $event): array
    {
        return match (true) {
            $event instanceof PaymentRefunded => [
                'payment' => self::payment($event->payment),
                'refund' => [
                    'amount_cent' => $event->amountCent,
                    'currency' => $event->payment->currency,
                    'full' => $event->isFull,
                ],
            ],
            $event instanceof PaymentChargedBack => [
                'payment' => self::payment($event->payment),
                'chargeback' => [
                    'reference' => $event->reference,
                    'amount_cent' => $event->amountCent,
                    'currency' => $event->payment->currency,
                    'reason' => $event->reason,
                ],
            ],
            $event instanceof CheckoutBlocked => [
                // The address and the network the visitor typed and came from,
                // never the address itself: a full IP in a third party's log
                // names one person for longer than the block needed it.
                'blocked' => [
                    'reason' => $event->reason,
                    'email' => $event->email,
                    'ip_prefix' => self::ipPrefix($event->ip),
                ],
            ],
            $event instanceof SubscriptionStartFailed => [
                'payment' => self::payment($event->payment),
                'reason' => $event->reason,
            ],
            $event instanceof SubscriptionAttemptFailed => [
                'subscription' => self::subscription($event->subscription),
                'payment' => self::payment($event->payment),
                'attempt' => $event->attempt,
            ],
            $event instanceof SubscriptionPaymentUpcoming => [
                'subscription' => self::subscription($event->subscription),
                'due_at' => self::date($event->dueAt),
                'days_before' => $event->daysBefore,
            ],
            $event instanceof SubscriptionCardExpiring => [
                'subscription' => self::subscription($event->subscription),
                'expires_at' => self::date($event->expiresAt),
            ],
            $event instanceof SubscriptionCardExpired => [
                'subscription' => self::subscription($event->subscription),
                'expired_at' => self::date($event->expiredAt),
            ],
            $event instanceof SubscriptionPaused => [
                'subscription' => self::subscription($event->subscription),
                'resumes_at' => self::date($event->resumesAt),
                'by' => $event->by,
            ],
            $event instanceof SubscriptionResumed => [
                'subscription' => self::subscription($event->subscription),
                'by' => $event->by,
            ],
            $event instanceof SubscriptionChanged => [
                'subscription' => self::subscription($event->subscription),
                'change' => [
                    'from_product' => $event->fromProduct,
                    'to_product' => $event->toProduct,
                    'from_amount_cent' => $event->fromAmountCent,
                    'to_amount_cent' => $event->toAmountCent,
                    'currency' => $event->subscription->currency,
                    'direction' => match (true) {
                        $event->toAmountCent > $event->fromAmountCent => 'up',
                        $event->toAmountCent < $event->fromAmountCent => 'down',
                        default => 'same',
                    },
                    'proration_cent' => $event->prorationCent,
                    'immediate' => $event->immediate,
                    'by' => $event->by,
                ],
                'proration_payment' => $event->prorationPayment === null ? null : self::payment($event->prorationPayment),
            ],
            $event instanceof SubscriptionReplaced => [
                'subscription' => self::subscription($event->replaced),
                'purchase' => self::payment($event->purchase),
                'replacement' => $event->replacement === null ? null : self::subscription($event->replacement),
                'credit' => [
                    'amount_cent' => $event->creditCent,
                    'currency' => $event->replaced->currency,
                    'days' => $event->creditDays,
                ],
            ],
            $event instanceof SubscriptionStarted,
            $event instanceof SubscriptionRenewed,
            $event instanceof SubscriptionPlanCompleted => [
                'subscription' => self::subscription($event->subscription),
                'payment' => self::payment($event->payment),
            ],
            $event instanceof SubscriptionCancelled,
            $event instanceof SubscriptionEnded => [
                'subscription' => self::subscription($event->subscription),
            ],
            $event instanceof PaymentPaid,
            $event instanceof PaymentFailed,
            $event instanceof CheckoutAbandoned => [
                'payment' => self::payment($event->payment),
            ],
            default => [],
        };
    }

    /**
     * A payment, as a receiver needs it.
     *
     * Not here, and on purpose: card digits and label, the mandate, the
     * provider's customer reference, consent text, referrer and landing page
     * (a URL can carry a token), `meta` (holds the thank-you token).
     *
     * @return array<string, mixed>
     */
    public static function payment(Payment $payment): array
    {
        $providerId = (string) $payment->provider_id;

        return [
            'id' => $payment->getKey(),
            'provider' => $payment->provider,
            // Null while the provider has not answered yet: the placeholder is
            // this addon's bookkeeping, and nobody can look it up anywhere.
            'provider_id' => $providerId === '' || Payment::isPlaceholderProviderId($providerId) ? null : $providerId,
            'status' => $payment->status,
            'product' => $payment->product,
            'amount_cent' => (int) $payment->amount_cent,
            'currency' => $payment->currency,
            'discount_code' => $payment->discount_code,
            'discount_cent' => $payment->discount_cent === null ? null : (int) $payment->discount_cent,
            'refunded_cent' => (int) ($payment->refunded_cent ?? 0),
            'email' => $payment->email,
            'name' => $payment->name,
            'country' => $payment->country,
            'subscription_id' => $payment->subscription_id,
            'parent_payment_id' => $payment->parent_payment_id,
            'items' => self::items($payment),
            'attribution' => [
                'utm_source' => $payment->utm_source,
                'utm_medium' => $payment->utm_medium,
                'utm_campaign' => $payment->utm_campaign,
                'utm_term' => $payment->utm_term,
                'utm_content' => $payment->utm_content,
            ],
            'created_at' => self::date($payment->created_at),
            'paid_at' => self::date($payment->paid_at),
            'refunded_at' => self::date($payment->refunded_at),
            'charged_back_at' => self::date($payment->charged_back_at),
        ];
    }

    /**
     * An agreement, as a receiver needs it.
     *
     * Not here: the provider's customer reference, the card's expiry and the
     * dunning bookkeeping, `meta`.
     *
     * @return array<string, mixed>
     */
    public static function subscription(Subscription $subscription): array
    {
        return [
            'id' => $subscription->getKey(),
            'provider' => $subscription->provider,
            'provider_id' => $subscription->provider_id ?: null,
            'status' => $subscription->status,
            'product' => $subscription->product,
            'amount_cent' => (int) $subscription->amount_cent,
            'currency' => $subscription->currency,
            'interval' => $subscription->interval,
            'times' => $subscription->times === null ? null : (int) $subscription->times,
            'times_charged' => (int) $subscription->times_charged,
            'email' => $subscription->email,
            'name' => $subscription->name,
            'starts_at' => self::date($subscription->starts_at),
            'next_payment_at' => self::date($subscription->next_payment_at),
            'paused_at' => self::date($subscription->paused_at),
            'resumes_at' => self::date($subscription->resumes_at),
            'cancelled_at' => self::date($subscription->cancelled_at),
            'ended_at' => self::date($subscription->ended_at),
            'created_at' => self::date($subscription->created_at),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function items(Payment $payment): array
    {
        if (! $payment->exists) {
            return [];
        }

        try {
            return $payment->items()->orderBy('id')->get()
                ->map(fn (PaymentItem $item): array => [
                    'product' => $item->product,
                    'offer' => $item->offer,
                    'name' => $item->name,
                    'kind' => $item->kind,
                    'quantity' => (int) $item->quantity,
                    'amount_cent' => (int) $item->amount_cent,
                    'discount_cent' => (int) ($item->discount_cent ?? 0),
                ])
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    public static function date(mixed $value): ?string
    {
        return $value instanceof \DateTimeInterface ? $value->format(\DATE_ATOM) : null;
    }

    /**
     * The network, not the address: /24 for IPv4, /48 for IPv6. The same cut
     * the automations addon makes for `blocked.ip_prefix`.
     */
    public static function ipPrefix(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);

            return $parts[0].'.'.$parts[1].'.'.$parts[2].'.0/24';
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = inet_pton($ip);
            $network = $packed === false ? false : inet_ntop(substr($packed, 0, 6).str_repeat("\0", 10));

            return $network === false ? null : $network.'/48';
        }

        return null;
    }

    private static function currentBrandId(): ?int
    {
        if (! class_exists('Goldnead\\BrandContext\\Facades\\BrandContext')) {
            return null;
        }

        try {
            if (! app()->bound('brand-context')) {
                return null;
            }

            $manager = app('brand-context');

            return $manager->hasCurrent() ? (int) $manager->currentId() : null;
        } catch (Throwable) {
            return null;
        }
    }
}

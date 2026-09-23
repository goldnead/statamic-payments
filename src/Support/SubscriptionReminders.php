<?php

namespace Goldnead\StatamicPayments\Support;

use Goldnead\StatamicPayments\Contracts\ReadsCardExpiry;
use Goldnead\StatamicPayments\Events\SubscriptionCardExpired;
use Goldnead\StatamicPayments\Events\SubscriptionCardExpiring;
use Goldnead\StatamicPayments\Events\SubscriptionPaymentUpcoming;
use Goldnead\StatamicPayments\Facades\PaymentLog;
use Goldnead\StatamicPayments\Mail\SubscriptionReminderMail;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\PaymentCommunication;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Models\SubscriptionNotice;
use Goldnead\StatamicPayments\Portal\Display;
use Goldnead\StatamicPayments\Portal\LinkTokenizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Telling a buyer before a charge, and before a card stops working.
 *
 * Dunning starts after a charge failed. This runs before: a mail some days
 * ahead of every charge, one when the card on file is about to expire, one when
 * it has. Preventing a failed charge is cheaper than chasing one, and on a
 * monthly membership an expired card is the most common silent loss.
 *
 * **Once, and only once.** Every notice is claimed in
 * `payment_subscription_notices` under agreement, kind and a reference (the
 * charge date, the expiry date) before anything is sent. A second run, an
 * overlapping scheduler, a second worker: all hit the unique index. A mail that
 * could not be delivered gives its claim back, so tomorrow's run tries again.
 *
 * **Off by default**, each kind on its own switch, like everything here that
 * writes to a customer. A product can refuse all of them (`reminders: false`
 * in its catalogue entry).
 *
 * **Where the card's expiry comes from.** Stripe for cards, Mollie for credit
 * card mandates. It is copied onto the agreement (`card_expires_at`) and asked
 * again every `reminders.card_check_days`; SEPA and PayPal have none, and no
 * reminder goes out for them.
 */
class SubscriptionReminders
{
    public const KINDS = [
        SubscriptionNotice::KIND_UPCOMING,
        SubscriptionNotice::KIND_CARD_EXPIRING,
        SubscriptionNotice::KIND_CARD_EXPIRED,
    ];

    public function __construct(
        protected Subscriptions $subscriptions,
        protected Catalogue $catalogue,
        protected DunningNotice $notice,
    ) {}

    public function enabled(string $kind): bool
    {
        return (bool) config("statamic-payments.reminders.{$kind}.enabled", false);
    }

    /**
     * One pass over every running agreement.
     *
     * @return array<string, int> what happened, by outcome
     */
    public function run(?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $report = ['seen' => 0, 'sent' => 0, 'announced' => 0, 'skipped' => 0, 'failed' => 0];

        if (! array_filter(self::KINDS, fn (string $kind) => $this->enabled($kind))) {
            return $report;
        }

        Subscription::query()
            ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_PENDING])
            ->lazyById(200)
            ->each(function (Subscription $subscription) use ($now, &$report) {
                $report['seen']++;

                try {
                    foreach ($this->due($subscription, $now) as [$kind, $reference, $date]) {
                        $outcome = $this->notify($subscription, $kind, $reference, $date, $now);
                        $report[$outcome]++;
                    }
                } catch (Throwable $e) {
                    $report['failed']++;
                    Log::error('statamic-payments: a reminder for this agreement could not be worked out.', [
                        'subscription_id' => $subscription->getKey(),
                        'exception' => $e->getMessage(),
                    ]);
                }
            });

        return $report;
    }

    /**
     * What is due for one agreement right now.
     *
     * @return list<array{0: string, 1: string, 2: Carbon}>
     */
    public function due(Subscription $subscription, Carbon $now): array
    {
        if (! $this->productAllows($subscription)) {
            return [];
        }

        // Days are the shop's days: counted in the display time zone, so a
        // German shop at 01:30 on the 23rd is on the 23rd, not the 22nd.
        $today = $now->copy()->setTimezone(LocalTime::zone())->startOfDay();
        $due = [];

        if ($this->enabled(SubscriptionNotice::KIND_UPCOMING) && $subscription->next_payment_at !== null) {
            $date = $subscription->next_payment_at->copy()->setTimezone(LocalTime::zone())->startOfDay();
            $days = (int) $today->diffInDays($date, false);
            $window = max(1, (int) config('statamic-payments.reminders.upcoming.days', 7));

            if ($days > 0 && $days <= $window) {
                $due[] = [SubscriptionNotice::KIND_UPCOMING, $date->toDateString(), $date];
            }
        }

        if ($this->enabled(SubscriptionNotice::KIND_CARD_EXPIRING) || $this->enabled(SubscriptionNotice::KIND_CARD_EXPIRED)) {
            $expiry = $this->cardExpiry($subscription, $now);

            if ($expiry !== null) {
                // A card's last day is a calendar day, in the shop's zone.
                $expiry = Carbon::parse($expiry->toDateString(), LocalTime::zone());
                $window = max(1, (int) config('statamic-payments.reminders.card_expiring.days', 30));

                if ($expiry->lt($today)) {
                    if ($this->enabled(SubscriptionNotice::KIND_CARD_EXPIRED)) {
                        $due[] = [SubscriptionNotice::KIND_CARD_EXPIRED, $expiry->toDateString(), $expiry];
                    }
                } elseif ((int) $today->diffInDays($expiry, false) <= $window && $this->enabled(SubscriptionNotice::KIND_CARD_EXPIRING)) {
                    $due[] = [SubscriptionNotice::KIND_CARD_EXPIRING, $expiry->toDateString(), $expiry];
                }
            }
        }

        return $due;
    }

    /**
     * The card's expiry, from the row, asked of the provider when it is stale.
     */
    public function cardExpiry(Subscription $subscription, Carbon $now): ?Carbon
    {
        $every = max(1, (int) config('statamic-payments.reminders.card_check_days', 7));
        $fresh = $subscription->card_checked_at !== null
            && $subscription->card_checked_at->gt($now->copy()->subDays($every));

        if ($fresh) {
            return $subscription->card_expires_at?->copy()->startOfDay();
        }

        $gateway = $this->subscriptions->gatewayFor($subscription);

        if (! $gateway instanceof ReadsCardExpiry || trim((string) $subscription->customer_reference) === '') {
            return null;
        }

        try {
            $expiry = $gateway->cardExpiry($subscription->customer_reference);
        } catch (Throwable $e) {
            // Not written as checked: tomorrow's run asks again.
            Log::warning('statamic-payments: the provider would not say when the card expires.', [
                'subscription_id' => $subscription->getKey(),
                'exception' => $e->getMessage(),
            ]);

            return $subscription->card_expires_at?->copy()->startOfDay();
        }

        $subscription->forceFill([
            'card_expires_at' => $expiry?->toDateString(),
            'card_checked_at' => $now,
        ])->save();

        return $expiry?->copy()->startOfDay();
    }

    /**
     * Claim, send, announce. Says which of the outcomes it was.
     *
     * @return 'sent'|'announced'|'skipped'|'failed'
     */
    protected function notify(Subscription $subscription, string $kind, string $reference, Carbon $date, Carbon $now): string
    {
        if (! SubscriptionNotice::claim($subscription, $kind, $reference)) {
            return 'skipped';
        }

        $outcome = 'announced';

        if ((bool) config("statamic-payments.reminders.{$kind}.mail", true)) {
            $sent = $this->send($subscription, $kind, $date);

            if ($sent === DunningNotice::FAILED) {
                SubscriptionNotice::release($subscription, $kind, $reference);

                return 'failed';
            }

            $outcome = $sent === DunningNotice::SENT ? 'sent' : 'skipped';
        }

        try {
            match ($kind) {
                SubscriptionNotice::KIND_UPCOMING => SubscriptionPaymentUpcoming::dispatch(
                    $subscription,
                    $date,
                    (int) $now->copy()->startOfDay()->diffInDays($date, false),
                ),
                SubscriptionNotice::KIND_CARD_EXPIRING => SubscriptionCardExpiring::dispatch($subscription, $date),
                SubscriptionNotice::KIND_CARD_EXPIRED => SubscriptionCardExpired::dispatch($subscription, $date),
                default => null,
            };
        } catch (Throwable $e) {
            Log::error('statamic-payments: a listener threw on a reminder that went out anyway.', [
                'subscription_id' => $subscription->getKey(),
                'kind' => $kind,
                'exception' => $e->getMessage(),
            ]);
        }

        return $outcome;
    }

    /**
     * One mail. The same gatekeepers as a dunning letter: an address, the
     * suppression list, the brand's own sender.
     *
     * @return DunningNotice::SENT|DunningNotice::SKIPPED|DunningNotice::FAILED
     */
    public function send(Subscription $subscription, string $kind, Carbon $date): string
    {
        $email = is_string($subscription->email) ? trim($subscription->email) : '';

        if ($email === '') {
            return DunningNotice::SKIPPED;
        }

        $brand = (int) $subscription->brand_id;
        $suppressed = $this->notice->suppressed($email, $brand);

        if ($suppressed === null) {
            return DunningNotice::FAILED;
        }

        $payment = $this->latestPayment($subscription);

        if ($suppressed) {
            if ($payment) {
                PaymentLog::note($payment, 'reminder_suppressed', __('statamic-payments::reminders.log_suppressed', ['email' => $email]));
            }

            return DunningNotice::SKIPPED;
        }

        try {
            $rendered = $this->render($subscription, $kind, $date);
            $mailable = new SubscriptionReminderMail($subscription, $kind, $rendered['subject'], $rendered['html'], $rendered['variables']);

            if (! $this->notice->deliver($subscription, $email, $mailable)) {
                if ($payment) {
                    PaymentLog::mail($payment, 'reminder_'.$kind, $email, null, PaymentCommunication::STATUS_FAILED, ['error' => 'brand refused to send']);
                }

                return DunningNotice::FAILED;
            }

            if ($payment) {
                PaymentLog::mail($payment, 'reminder_'.$kind, $email, $rendered['subject']);
            }

            return DunningNotice::SENT;
        } catch (Throwable $e) {
            Log::error('statamic-payments: a reminder could not be sent.', [
                'subscription_id' => $subscription->getKey(),
                'kind' => $kind,
                'exception' => $e->getMessage(),
            ]);

            return DunningNotice::FAILED;
        }
    }

    /**
     * Subject, body and variables for one reminder.
     *
     * @return array{subject: string, html: string|null, variables: array<string, mixed>}
     */
    public function render(Subscription $subscription, string $kind, Carbon $date): array
    {
        $variables = $this->variables($subscription, $kind, $date);

        $subject = config("statamic-payments.reminders.{$kind}.subject");
        $subject = is_string($subject) && trim($subject) !== ''
            ? $subject
            : (string) __("statamic-payments::reminders.{$kind}_subject", ['plan' => $variables['plan']['name']]);

        return [
            'subject' => $subject,
            'html' => $this->template($subscription, $kind, $variables),
            'variables' => $variables,
        ];
    }

    /** @return array<string, mixed> */
    public function variables(Subscription $subscription, string $kind, Carbon $date): array
    {
        $entry = $this->catalogue->find($subscription->product) ?? [];

        return [
            'kind' => $kind,
            'buyer' => [
                'name' => is_string($subscription->name) ? trim($subscription->name) : '',
                'email' => (string) $subscription->email,
            ],
            'plan' => [
                'handle' => (string) $subscription->product,
                'name' => (string) ($entry['name'] ?? $subscription->product),
                // What is actually charged: a running coupon comes off
                // (statamic-offers O6). The price itself is `full`.
                'amount' => $subscription->chargedAmount(),
                'currency' => (string) $subscription->currency,
                'display' => Display::money($subscription->chargedCent(), $subscription->currency),
                'full' => Display::money((int) $subscription->amount_cent, $subscription->currency),
                'coupon' => Display::coupon($subscription),
                'rhythm' => Display::rhythm((string) $subscription->interval),
            ],
            'date' => $date->toDateString(),
            'date_display' => $date->translatedFormat(__('statamic-payments::portal.date_format')),
            // Valid until the day it is about: a mail five days before a charge
            // whose link died after thirty minutes is a mail with a dead button.
            'portal_url' => $this->portalUrl($subscription, $date),
        ];
    }

    /**
     * The way into the portal, valid until the end of the day the mail is
     * about in the shop's zone (a charge, a card expiry). For an expiry that
     * has passed, until the next charge, or a week.
     */
    protected function portalUrl(Subscription $subscription, Carbon $date): string
    {
        $email = is_string($subscription->email) ? trim($subscription->email) : '';

        if ($email === '') {
            return (string) config('app.url');
        }

        $bis = Carbon::parse($date->toDateString(), LocalTime::zone())->endOfDay();

        if ($bis->isPast()) {
            $bis = $subscription->next_payment_at
                ? LocalTime::of($subscription->next_payment_at)?->endOfDay() ?? LocalTime::now()->addWeek()
                : LocalTime::now()->addWeek();
        }

        return app(LinkTokenizer::class)->issueUntil($email, (int) $subscription->brand_id, $bis);
    }

    protected function productAllows(Subscription $subscription): bool
    {
        $entry = $this->catalogue->find($subscription->product) ?? [];

        return ($entry['reminders'] ?? true) !== false;
    }

    protected function latestPayment(Subscription $subscription): ?Payment
    {
        return Payment::query()
            ->where('subscription_id', $subscription->getKey())
            ->orderByDesc('id')
            ->first();
    }

    /**
     * An email-templates slug for this kind, when the sibling is there.
     *
     * @param  array<string, mixed>  $variables
     */
    protected function template(Subscription $subscription, string $kind, array $variables): ?string
    {
        $slug = config("statamic-payments.reminders.{$kind}.template");

        if (! is_string($slug) || trim($slug) === '') {
            return null;
        }

        $facade = DunningNotice::TEMPLATES_FACADE;

        if (! class_exists($facade)) {
            Log::warning('statamic-payments: a reminder template is set but statamic-email-templates is not installed; the built-in mail was sent.', ['template' => $slug]);

            return null;
        }

        try {
            $html = $facade::render($slug, $variables, (int) $subscription->brand_id ?: null);
        } catch (Throwable $e) {
            Log::warning('statamic-payments: the reminder template could not be resolved; the built-in mail was sent.', [
                'template' => $slug,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }

        return is_string($html) && trim($html) !== '' ? $html : null;
    }
}

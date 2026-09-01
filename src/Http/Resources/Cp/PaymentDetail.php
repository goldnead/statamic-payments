<?php

namespace Goldnead\StatamicPayments\Http\Resources\Cp;

use Goldnead\StatamicPayments\Facades\PaymentLog;
use Goldnead\StatamicPayments\Http\Resources\Cp\Concerns\DescribesProducts;
use Goldnead\StatamicPayments\Models\Cancellation;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\PaymentCommunication;
use Goldnead\StatamicPayments\Models\PaymentItem;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Models\Withdrawal;
use Goldnead\StatamicPayments\Support\Invoices;
use Goldnead\StatamicPayments\Support\Money;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Eine Zahlung, ganz.
 *
 * Alles, was die Detailseite zeigt, wird hier zu Text: Beträge formatiert,
 * Zeitpunkte als ISO-8601, Kennungen als Kennungen. Die Vue-Seite rechnet
 * nichts und schließt nichts, sie zeigt.
 *
 * Die Nachbarn (Rechnung, Webhook-Zustellungen) werden nach Form gefragt,
 * nie nach Typ — mit denselben drei Wachen wie überall in diesem Paket. Fehlt
 * einer oder antwortet er nicht, fehlt ein Abschnitt, nie die Seite.
 *
 * @mixin Payment
 */
class PaymentDetail extends JsonResource
{
    use DescribesProducts;

    public function toArray($request)
    {
        /** @var Payment $payment */
        $payment = $this->resource;
        $meta = (array) ($payment->meta ?? []);

        return [
            'id' => $payment->id,
            'title' => __('statamic-payments::messages.detail_title', ['id' => $payment->id]),
            'product' => $payment->product,
            'product_name' => $this->productName($payment->product),
            'amount' => $payment->amount(),
            'currency' => $payment->currency,
            'status' => $payment->status,
            'status_label' => $this->translatedOrRaw('status_'.$payment->status, (string) $payment->status),
            'provider' => $payment->provider,
            'provider_id' => $payment->provider_id,
            'created_at' => $payment->created_at?->toIso8601String(),
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'fulfilled_at' => $payment->fulfilled_at?->toIso8601String(),
            'discount_code' => $payment->discount_code,
            'discount' => $payment->discount_cent ? Money::format((int) $payment->discount_cent, $payment->currency) : null,

            // In der Reihenfolge, in der sie geschrieben wurden: das Hauptprodukt
            // zuerst, dann die Zusätze. Die Relation selbst sortiert nicht.
            'items' => $payment->items->sortBy('id')->values()->map(fn (PaymentItem $item) => [
                'id' => $item->id,
                'product' => $item->product,
                'name' => $item->name,
                'kind' => $item->kind,
                'kind_label' => $this->translatedOrRaw('item_kind_'.$item->kind, (string) $item->kind),
                'offer' => $item->getAttribute('offer'),
                'quantity' => $item->quantity,
                'unit' => Money::format($item->amount_cent, $payment->currency),
                'discount' => $item->discount_cent ? Money::format((int) $item->discount_cent, $payment->currency) : null,
                'total' => Money::format($item->lineTotalCent(), $payment->currency),
            ])->values()->all(),

            'buyer' => [
                'email' => $payment->email,
                'name' => $payment->name,
                'country' => $payment->country,
                'country_source' => $payment->country_source,
                'address' => self::string($meta['address'] ?? null),
                'vat_id' => self::string($meta['vat_id'] ?? null),
                'customer_reference' => $payment->customer_reference,
            ],

            'consent' => [
                'at' => $payment->consent_at?->toIso8601String(),
                'text' => $payment->consent_text,
                'withdrawal_version' => self::string(data_get($meta, 'withdrawal.version') ?? data_get($meta, 'withdrawal')),
            ],

            'access' => self::access($meta['access'] ?? null),

            'origin' => [
                'utm_source' => $payment->utm_source,
                'utm_medium' => $payment->utm_medium,
                'utm_campaign' => $payment->utm_campaign,
                'utm_term' => $payment->utm_term,
                'utm_content' => $payment->utm_content,
                'referrer' => $payment->referrer,
                'landing_page' => $payment->landing_page,
            ],

            'card' => [
                'label' => $payment->card_label,
                'last4' => $payment->card_last4,
            ],

            'refunds' => [
                'amount' => $payment->refunded_cent ? Money::format((int) $payment->refunded_cent, $payment->currency) : null,
                'at' => $payment->refunded_at?->toIso8601String(),
                'references' => array_values(array_filter((array) ($meta['refunds'] ?? []), 'is_string')),
            ],

            'links' => [
                'parent' => $payment->parent ? self::related($payment->parent) : null,
                'children' => $payment->children->map(fn (Payment $child) => self::related($child))->values()->all(),
                'subscription' => $payment->subscription ? $this->subscription($payment->subscription) : null,
                'invoice' => $this->invoice($payment),
                'withdrawals' => $payment->withdrawals->map(fn (Withdrawal $w) => [
                    'id' => $w->id,
                    'public_id' => $w->public_id,
                    'confirmed_at' => $w->confirmed_at?->toIso8601String(),
                    'handled_at' => $w->handled_at?->toIso8601String(),
                    'url' => cp_route('utilities.withdrawals'),
                ])->values()->all(),
                'cancellations' => $this->cancellations($payment),
            ],

            'communications' => PaymentLog::for($payment)->map(fn (PaymentCommunication $c) => [
                'id' => $c->id,
                'channel' => $c->channel,
                'channel_label' => $this->translatedOrRaw('channel_'.$c->channel, $c->channel),
                'kind' => $c->kind,
                'kind_label' => $this->translatedOrRaw('kind_'.$c->kind, $c->kind),
                'recipient' => $c->recipient,
                'subject' => $c->subject,
                'status' => $c->status,
                'status_label' => $this->translatedOrRaw('communication_'.$c->status, $c->status),
                'reference' => $c->reference,
                'happened_at' => $c->happened_at->toIso8601String(),
            ])->values()->all(),

            'webhooks' => $this->webhookDeliveries($payment),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function related(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'product' => $payment->product,
            'amount' => $payment->amount(),
            'currency' => $payment->currency,
            'status' => $payment->status,
            'created_at' => $payment->created_at?->toIso8601String(),
            'url' => cp_route('utilities.payments.show', ['payPayment' => $payment->id]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function subscription(Subscription $subscription): array
    {
        return [
            'id' => $subscription->id,
            'provider_id' => $subscription->provider_id,
            'status' => $subscription->status,
            'status_label' => $this->translatedOrRaw('subscription_status_'.$subscription->status, (string) $subscription->status),
            'next_payment_at' => $subscription->next_payment_at?->toIso8601String(),
            'url' => cp_route('utilities.subscriptions'),
        ];
    }

    /**
     * Kündigungen laufen über das Abo, nicht über die Zahlung. Die zu dieser
     * Zahlung gehörenden sind die des Abos, zu dem sie zählt.
     *
     * @return list<array<string, mixed>>
     */
    protected function cancellations(Payment $payment): array
    {
        if ($payment->subscription_id === null) {
            return [];
        }

        return Cancellation::query()
            ->where('subscription_id', $payment->subscription_id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (Cancellation $c) => [
                'id' => $c->id,
                'public_id' => $c->public_id,
                'confirmed_at' => $c->confirmed_at?->toIso8601String(),
                'handled_at' => $c->handled_at?->toIso8601String(),
                'url' => cp_route('utilities.cancellations'),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function invoice(Payment $payment): ?array
    {
        try {
            $document = Invoices::forPayment($payment);
        } catch (Throwable $e) {
            Log::warning('statamic-payments: the invoice could not be looked up for the detail screen.', [
                'payment_id' => $payment->getKey(),
                'exception' => $e->getMessage(),
            ]);

            return null;
        }

        if ($document === null) {
            return null;
        }

        return [
            'number' => $document->number,
            'issued_at' => $document->issuedAt?->toIso8601String(),
        ];
    }

    /**
     * Zustellungen aus statamic-webhook-manager, wenn er da ist und die Frage
     * kennt. `class_exists` auf die Fassade, `method_exists` auf das Objekt
     * dahinter — nie auf die Fassade, die leitet nur weiter.
     *
     * @return list<array<string, mixed>>|null null heißt: kein Nachbar, kein Panel
     */
    protected function webhookDeliveries(Payment $payment): ?array
    {
        $facade = '\Goldnead\WebhookManager\Facades\WebhookLog';

        if (! class_exists($facade)) {
            return null;
        }

        try {
            $log = $facade::getFacadeRoot();

            if (! is_object($log) || ! method_exists($log, 'forSubject')) {
                return null;
            }

            $deliveries = $log->forSubject('payment', (int) $payment->getKey(), 50);
        } catch (Throwable $e) {
            Log::warning('statamic-payments: webhook deliveries could not be read for the detail screen.', [
                'payment_id' => $payment->getKey(),
                'exception' => $e->getMessage(),
            ]);

            return null;
        }

        $rows = [];

        foreach ($deliveries as $delivery) {
            if (! is_object($delivery)) {
                continue;
            }

            $at = $delivery->last_attempted_at ?? $delivery->first_attempted_at ?? $delivery->created_at ?? null;

            $rows[] = [
                'uuid' => self::string($delivery->uuid ?? null),
                'trigger' => self::string($delivery->trigger_type ?? null),
                'url' => self::string($delivery->request_url ?? null),
                'status' => self::string($delivery->status ?? null),
                'response_status' => isset($delivery->response_status) ? (int) $delivery->response_status : null,
                'attempts' => isset($delivery->attempts) ? (int) $delivery->attempts : null,
                'at' => $at instanceof Carbon ? $at->toIso8601String() : self::string($at),
            ];
        }

        return $rows;
    }

    /**
     * @return array{starts_at: string|null, days: int|null, expires_at: string|null}|null
     */
    protected static function access(mixed $access): ?array
    {
        if (! is_array($access)) {
            return null;
        }

        $startsAt = self::string($access['starts_at'] ?? null);
        $days = isset($access['days']) && is_numeric($access['days']) ? (int) $access['days'] : null;

        if ($startsAt === null && $days === null) {
            return null;
        }

        $expiresAt = null;

        if ($days !== null && $days > 0) {
            try {
                $expiresAt = ($startsAt !== null ? Carbon::parse($startsAt)->startOfDay() : Carbon::now())->addDays($days)->toIso8601String();
            } catch (Throwable) {
                $expiresAt = null;
            }
        }

        return ['starts_at' => $startsAt, 'days' => $days, 'expires_at' => $expiresAt];
    }

    private static function string(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}

<?php

namespace Goldnead\StatamicPayments\Integrations\WebhookManager;

use Goldnead\WebhookManager\Contracts\TriggerInterface;
use Goldnead\WebhookManager\ValueObjects\TriggerEvent;
use Illuminate\Support\Carbon;

/**
 * One payment moment as a trigger of the webhook manager.
 *
 * **Loaded only after the bridge has checked that interface by name.** This
 * class `implements` it, so touching it on an install without the webhook
 * manager, even statically, is a fatal "Interface not found" at boot. Nothing
 * outside {@see WebhookManagerBridge::boot()} may name it.
 */
class PaymentsTrigger implements TriggerInterface
{
    public function __construct(
        private readonly string $moment,
    ) {}

    public function handle(): string
    {
        return WebhookPayload::PREFIX.$this->moment;
    }

    /**
     * Asked every time, not stored: the CP lists triggers in the language of
     * the person looking, and the registry is built once per process.
     */
    public function label(): string
    {
        return (string) __('statamic-payments::webhooks.triggers.'.$this->moment);
    }

    public function description(): ?string
    {
        $key = 'statamic-payments::webhooks.descriptions.'.$this->moment;
        $text = __($key);

        return is_string($text) && $text !== $key ? $text : null;
    }

    public function sourceType(): string
    {
        return 'payments';
    }

    public function build(mixed $source, array $context = []): TriggerEvent
    {
        // The moment's own time, not the clock at dispatch: a redelivered
        // provider webhook must not look like a later moment.
        $at = is_object($source)
            ? Carbon::instance(WebhookPayload::occurredAt($source))->toImmutable()
            : Carbon::now()->toImmutable();
        $payload = is_object($source) ? WebhookPayload::build($this->moment, $source, $at) : [
            'event' => $this->handle(),
            'event_id' => sha1($this->handle().'|'.$at->format(\DATE_ATOM)),
            'occurred_at' => $at->format(\DATE_ATOM),
            'brand' => null,
        ];

        return new TriggerEvent(
            triggerHandle: $this->handle(),
            sourceType: $this->sourceType(),
            sourceReference: is_object($source) ? WebhookPayload::referenceOf($source) : null,
            payload: $payload,
            site: null,
            locale: null,
            isReplay: (bool) ($context['replay'] ?? false),
            eventAt: $at,
        );
    }
}

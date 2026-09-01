<?php

namespace Goldnead\StatamicPayments\Support;

use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\PaymentItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Die Erinnerung an einen offenen Kauf: was sie sagt und wohin sie führt.
 *
 * Drei Fragen, getrennt gehalten: darf die Mail raus (Konfiguration,
 * Sperrliste), wohin führt sie (`resumeUrl`), und wie sieht sie aus
 * (`render`: eine email-templates-Vorlage, wenn der Nachbar da ist und den
 * Slug kennt, sonst die eingebaute Blade-Mail). Der Listener stellt die
 * Fragen in dieser Reihenfolge und verschickt.
 */
class AbandonedReminder
{
    /** Der Slug-Fassade des Nachbarn, als String — kein Import, siehe Provider. */
    public const TEMPLATES_FACADE = '\Goldnead\EmailTemplates\Facades\EmailTemplates';

    public const SUPPRESSION_FACADE = '\Goldnead\Suppression\Facades\SuppressionGate';

    public function enabled(): bool
    {
        return (bool) config('statamic-payments.abandoned.mail.enabled', false);
    }

    /**
     * Ob die Adresse auf einer Sperrliste steht.
     *
     * Nur mit statamic-suppression. Ohne den Nachbarn gibt es keine Liste,
     * gegen die zu prüfen wäre; das README sagt dem Betreiber, dass er dann
     * selbst eine vor den Versand stellen muss. Antwortet der Nachbar nicht,
     * gilt „gesperrt": eine Mail zu viel an jemanden, der nicht will, ist der
     * teurere Fehler.
     */
    public function suppressed(string $email, int $brandId): bool
    {
        $facade = self::SUPPRESSION_FACADE;

        if (! class_exists($facade)) {
            return false;
        }

        try {
            return (bool) $facade::isSuppressed($email, $brandId === 0 ? null : $brandId);
        } catch (Throwable $e) {
            Log::warning('statamic-payments: the suppression list did not answer; the reminder was not sent.', [
                'exception' => $e->getMessage(),
            ]);

            return true;
        }
    }

    /**
     * Wohin die Schaltfläche führt.
     *
     * Eine eigene Adresse des Betreibers gewinnt (`{payment}` wird ersetzt).
     * Sonst ein signierter Link, der den Checkout mit denselben Positionen neu
     * startet — wenn die Zahlung Positionen hat. Sonst die Einstiegsseite,
     * sonst die konfigurierte Rückkehrseite. Es gibt immer ein Ziel.
     */
    public function resumeUrl(Payment $payment): string
    {
        $configured = config('statamic-payments.abandoned.mail.resume_url');

        if (is_string($configured) && trim($configured) !== '') {
            return url(str_replace('{payment}', (string) $payment->getKey(), trim($configured)));
        }

        if ($payment->items->isNotEmpty()) {
            $days = max(1, (int) config('statamic-payments.abandoned.mail.resume_days', 14));

            return URL::temporarySignedRoute(
                'statamic-payments.resume',
                Carbon::now()->addDays($days),
                ['payPayment' => $payment->getKey()],
            );
        }

        if (is_string($payment->landing_page) && $payment->landing_page !== '') {
            return $payment->landing_page;
        }

        return url((string) config('statamic-payments.return_url', '/'));
    }

    /**
     * Die Variablen, die eine Vorlage bekommt. Flach benannt, wie
     * email-templates sie in `{{ … }}` erwartet.
     *
     * @return array<string, mixed>
     */
    public function variables(Payment $payment): array
    {
        $lines = $payment->items->map(fn (PaymentItem $item) => [
            'name' => $item->name,
            'quantity' => $item->quantity,
            'amount' => Money::format($item->lineTotalCent(), $payment->currency),
        ])->values()->all();

        $listHtml = $lines === []
            ? ''
            : '<ul>'.implode('', array_map(
                fn (array $l) => '<li>'.e($l['quantity'] > 1 ? $l['quantity'].' × ' : '').e($l['name']).' – '.e($l['amount']).' '.e($payment->currency).'</li>',
                $lines,
            )).'</ul>';

        $listText = implode("\n", array_map(
            fn (array $l) => ($l['quantity'] > 1 ? $l['quantity'].' × ' : '').$l['name'].' – '.$l['amount'].' '.$payment->currency,
            $lines,
        ));

        return [
            'buyer' => [
                'email' => (string) $payment->email,
                'name' => (string) ($payment->name ?? ''),
            ],
            'order' => [
                'id' => (int) $payment->getKey(),
                'lines' => $listHtml,
                'lines_text' => $listText,
                'total' => $payment->amount(),
                'currency' => $payment->currency,
                'product' => $payment->product,
            ],
            'resume_url' => $this->resumeUrl($payment),
        ];
    }

    /**
     * Betreff und Körper, gerendert.
     *
     * `html` ist null, wenn keine Vorlage greift — dann nimmt die Mailable die
     * eingebaute Blade-Fassung mit denselben Variablen.
     *
     * @return array{subject: string, html: string|null, variables: array<string, mixed>}
     */
    public function render(Payment $payment): array
    {
        $variables = $this->variables($payment);
        $subject = config('statamic-payments.abandoned.mail.subject');
        $subject = is_string($subject) && trim($subject) !== '' ? $subject : (string) __('statamic-payments::abandoned.mail_subject');
        $html = null;

        $slug = config('statamic-payments.abandoned.mail.template');

        if (is_string($slug) && trim($slug) !== '') {
            $resolved = $this->template(trim($slug));

            if ($resolved !== null) {
                $html = self::apply($resolved['body'], $variables);

                if ($resolved['subject'] !== '') {
                    $subject = $resolved['subject'];
                }
            }
        }

        return [
            'subject' => self::apply($subject, $variables),
            'html' => $html,
            'variables' => $variables,
        ];
    }

    /**
     * @return array{subject: string, body: string}|null
     */
    protected function template(string $slug): ?array
    {
        $facade = self::TEMPLATES_FACADE;

        if (! class_exists($facade)) {
            Log::warning('statamic-payments: abandoned.mail.template is set but statamic-email-templates is not installed; the built-in mail was sent.', ['template' => $slug]);

            return null;
        }

        try {
            $data = $facade::resolve($slug);
        } catch (Throwable $e) {
            Log::warning('statamic-payments: the email template for the reminder could not be resolved; the built-in mail was sent.', [
                'template' => $slug,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }

        if (! is_object($data) || ! is_string($data->body ?? null) || trim($data->body) === '') {
            Log::warning('statamic-payments: the email template for the reminder resolved to nothing; the built-in mail was sent.', ['template' => $slug]);

            return null;
        }

        return [
            'subject' => is_string($data->subject ?? null) ? trim($data->subject) : '',
            'body' => $data->body,
        ];
    }

    /**
     * `{{ buyer.name }}`-Ersetzung, dieselbe Grammatik wie email-templates.
     * Eigene Kopie von zwölf Zeilen statt Abhängigkeit: Unbekanntes bleibt
     * stehen, damit man es in der Vorschau sieht.
     *
     * @param  array<string, mixed>  $data
     */
    public static function apply(string $text, array $data): string
    {
        if ($text === '') {
            return '';
        }

        $flat = [];
        $walk = function (array $values, string $prefix) use (&$flat, &$walk): void {
            foreach ($values as $key => $value) {
                $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

                if (is_array($value)) {
                    $walk($value, $path);
                } elseif (is_scalar($value) || $value === null) {
                    $flat[$path] = (string) $value;
                }
            }
        };
        $walk($data, '');

        return (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/',
            fn (array $m) => array_key_exists($m[1], $flat) ? $flat[$m[1]] : $m[0],
            $text,
        );
    }
}

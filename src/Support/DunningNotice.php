<?php

namespace Goldnead\StatamicPayments\Support;

use Goldnead\StatamicPayments\Facades\PaymentLog;
use Goldnead\StatamicPayments\Mail\DunningMail;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\PaymentCommunication;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Portal\LinkTokenizer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Building and sending one letter of the sequence.
 *
 * Split from {@see Dunning} the way {@see AbandonedReminder} is split from
 * {@see Abandonment}: one class decides *whether and when*, this one decides
 * *what it says and whether it may go out*. The three questions kept apart are
 * the switch, the suppression list, and the rendering.
 *
 * **Through the BrandMailer where there is one.** A relay that verifies sending
 * domains per account replaces a From it does not own, so on a multi-brand
 * install the second brand's letter arrives under the first brand's name. That
 * is not cosmetic when the letter is about somebody's money. Where
 * `statamic-brand-context` is not installed, the ordinary mailer is right and
 * there is only one brand to get wrong.
 */
class DunningNotice
{
    /** Die Slug-Fassaden der Nachbarn, als Strings — kein Import, siehe Provider. */
    public const TEMPLATES_FACADE = '\Goldnead\EmailTemplates\Facades\EmailTemplates';

    public const SUPPRESSION_FACADE = '\Goldnead\Suppression\Facades\SuppressionGate';

    /** Die Klasse des markenbewussten Versands, wenn der Nachbar da ist. */
    public const BRAND_MAILER = '\Goldnead\BrandContext\Sending\BrandMailer';

    /** Der Frequenz-Deckel und seine Klassifizierung, beide optional. */
    public const CAP_CONTRACT = '\Goldnead\Marketing\Contracts\FrequencyCap';

    public const MAIL_CLASS = '\Goldnead\Marketing\Contracts\MailClass';

    public function __construct(protected LinkTokenizer $tokenizer) {}

    /**
     * Send one stage. Returns whether it actually went out.
     *
     * A refusal is a return value, not an exception: the caller has already
     * claimed the stage and needs to know whether to give the claim back.
     */
    public function send(Subscription $subscription, int $stage): bool
    {
        $email = is_string($subscription->email) ? trim($subscription->email) : '';

        if ($email === '') {
            // Nothing to do and nothing to retry. An agreement without an
            // address cannot be dunned, and the sequence still has to reach its
            // end — so this counts as sent.
            //
            // Said out loud, though: the stage counter moves on as if a letter
            // had gone out, and without this line somebody asking later why a
            // customer was never written to finds an advanced counter and no
            // explanation anywhere.
            Log::warning('statamic-payments: a dunning stage was skipped because the agreement carries no email address.', [
                'subscription_id' => $subscription->getKey(),
                'stage' => $stage,
            ]);

            return true;
        }

        // The communication log hangs off a payment, not an agreement, so the
        // cycle that opened the sequence carries the record. Without it there
        // is no trail for "we did write, three times".
        $payment = $subscription->dunning_payment_id
            ? Payment::find($subscription->dunning_payment_id)
            : null;

        $brand = (int) $subscription->brand_id;

        if ($this->suppressed($email, $brand)) {
            // The gatekeeper stays the gatekeeper. A dunning letter is
            // transactional, and the frequency cap therefore lets it through —
            // but somebody who asked never to be written to meant it, and the
            // suppression list knows no such exemption. The sequence still ends
            // on its own schedule.
            if ($payment) {
                PaymentLog::note($payment, 'dunning_suppressed', __('statamic-payments::dunning.log_suppressed', ['email' => $email]));
            }

            return true;
        }

        try {
            $rendered = $this->render($subscription, $stage);
            $mailable = new DunningMail($subscription, $stage, $rendered['subject'], $rendered['html'], $rendered['variables']);

            if (! $this->deliver($subscription, $email, $mailable)) {
                // The brand refused to send — no verified sender, usually. It
                // is logged there, and it is a configuration fault rather than
                // a transient one, so the stage goes back and the next run says
                // so again instead of silently skipping a letter.
                return false;
            }

            if ($payment) {
                PaymentLog::mail($payment, 'dunning_'.$stage, $email, $rendered['subject']);
            }

            // After delivery, never before it. A dunning letter is exempt from
            // the cap but still counted, so the Control Panel can say "three
            // marketing mails and one payment notice" rather than three.
            $this->countAgainstCap($email, $brand);

            return true;
        } catch (Throwable $e) {
            Log::error('statamic-payments: a dunning letter could not be sent.', [
                'subscription_id' => $subscription->getKey(),
                'stage' => $stage,
                'exception' => $e->getMessage(),
            ]);

            if ($payment) {
                PaymentLog::mail($payment, 'dunning_'.$stage, $email, null, PaymentCommunication::STATUS_FAILED, ['error' => $e->getMessage()]);
            }

            // Not sent. The caller gives the stage back, and the next run tries
            // again — a letter lost to a broken relay must not cost the
            // customer a stage of their own sequence.
            return false;
        }
    }

    /**
     * Zaehlen, nicht fragen.
     *
     * Der Deckel gilt fuer Werbung; eine Mahnung ist `transactional` und laeuft
     * ausdruecklich daran vorbei — jemanden nicht zu warnen, weil er diese
     * Woche schon einen Newsletter bekam, waere die falsche Sparsamkeit. Sie
     * wird trotzdem verbucht, damit im Control Panel steht, was wirklich
     * rausging.
     */
    protected function countAgainstCap(string $email, int $brandId): void
    {
        $cap = self::CAP_CONTRACT;
        $class = self::MAIL_CLASS;

        if (! interface_exists($cap) || ! enum_exists($class)) {
            return;
        }

        try {
            app($cap)->record($email, $class::Transactional, $brandId === 0 ? null : $brandId);
        } catch (Throwable $e) {
            // Ein Zaehler, der nicht zaehlt, haelt keine Mahnung auf.
            Log::warning('statamic-payments: the dunning letter went out but could not be counted.', [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Through the brand's own sender where there is one.
     *
     * Installed is not the same as configured. `BrandMailer` needs a
     * `SenderIdentityResolver` bound by the host, and on a single-brand site
     * there may be none — the class is simply on disk because another addon
     * pulled it in. Treating "cannot be built" as "cannot send" would silently
     * stop every dunning letter on exactly those sites.
     *
     * So: use it if it can be built, and fall back to the ordinary mailer if it
     * cannot. A **refusal** from a mailer that *was* built is different, and is
     * passed on as one — that means a brand with no verified sender, which is
     * a configuration fault somebody has to fix rather than one to paper over.
     */
    protected function deliver(Subscription $subscription, string $email, DunningMail $mailable): bool
    {
        $class = self::BRAND_MAILER;
        $mailer = null;

        if (class_exists($class)) {
            try {
                $mailer = app($class);
            } catch (Throwable $e) {
                Log::debug('statamic-payments: no brand-aware mailer is configured; the dunning letter went out through the ordinary one.', [
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        if ($mailer === null) {
            Mail::to($email)->send($mailable);

            return true;
        }

        $brand = (int) $subscription->brand_id;

        return (bool) $mailer->send(
            $brand === 0 ? null : $brand,
            $email,
            is_string($subscription->name) && $subscription->name !== '' ? $subscription->name : null,
            $mailable,
        );
    }

    /**
     * Ob diese Adresse ueberhaupt angeschrieben werden darf.
     *
     * Kein Nachbar, keine Sperrliste — dann gilt „nicht gesperrt". Wo einer da
     * ist, gilt seine Antwort, und im Zweifel gilt „gesperrt": eine Mail zu
     * viel an jemanden, der nicht will, ist der teurere Fehler.
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
            Log::warning('statamic-payments: the suppression list could not be read; the dunning letter was withheld.', [
                'exception' => $e->getMessage(),
            ]);

            return true;
        }
    }

    /**
     * Subject, body and variables for one stage.
     *
     * The link is the point of the whole letter: a signed, short-lived way into
     * the customer portal, where the payment method can be changed. It is the
     * same tokeniser the magic-link mail uses — minutes, not days, and no
     * separate table to revoke.
     *
     * @return array{subject: string, html: string|null, variables: array<string, mixed>}
     */
    public function render(Subscription $subscription, int $stage): array
    {
        $variables = $this->variables($subscription, $stage);

        $subject = config('statamic-payments.dunning.mail.subject');
        $subject = is_string($subject) && trim($subject) !== ''
            ? $subject
            : (string) __('statamic-payments::dunning.mail_subject');

        return [
            'subject' => $subject,
            'html' => $this->template($subscription, $variables),
            'variables' => $variables,
        ];
    }

    /** @return array<string, mixed> */
    protected function variables(Subscription $subscription, int $stage): array
    {
        $stages = app(Dunning::class)->stages();

        return [
            'stage' => $stage,
            'stages' => count($stages),
            // The last letter says so. Somebody deciding whether to bother
            // deserves to know this is the last chance rather than the first.
            'final' => $stage >= count($stages),
            'buyer' => [
                'name' => is_string($subscription->name) ? trim($subscription->name) : '',
                'email' => (string) $subscription->email,
            ],
            'plan' => [
                'name' => (string) $subscription->product,
                'amount' => $subscription->amount(),
                'currency' => (string) $subscription->currency,
                'display' => Money::display((int) $subscription->amount_cent, $subscription->currency),
            ],
            'portal_url' => $this->portalUrl($subscription),
        ];
    }

    /**
     * The way back in.
     *
     * Signed and short-lived, straight to the customer portal where the card
     * can be replaced. Not a link to a form of our own: the portal already has
     * the screen, the permission check and the provider's mandate flow behind
     * it.
     */
    public function portalUrl(Subscription $subscription): string
    {
        $email = is_string($subscription->email) ? trim($subscription->email) : '';

        if ($email === '') {
            return (string) config('app.url');
        }

        return $this->tokenizer->issue($email, (int) $subscription->brand_id);
    }

    /**
     * Eine email-templates-Vorlage, wenn der Nachbar da ist und den Slug kennt.
     *
     * Null heisst „nimm die eingebaute Blade-Fassung". Ein gesetzter Slug ohne
     * Nachbarn ist eine Fehlkonfiguration und wird laut, nicht still.
     *
     * @param  array<string, mixed>  $variables
     */
    protected function template(Subscription $subscription, array $variables): ?string
    {
        $slug = config('statamic-payments.dunning.mail.template');

        if (! is_string($slug) || trim($slug) === '') {
            return null;
        }

        $facade = self::TEMPLATES_FACADE;

        if (! class_exists($facade)) {
            Log::warning('statamic-payments: dunning.mail.template is set but statamic-email-templates is not installed; the built-in mail was sent.', ['template' => $slug]);

            return null;
        }

        try {
            $html = $facade::render($slug, $variables, (int) $subscription->brand_id ?: null);
        } catch (Throwable $e) {
            Log::warning('statamic-payments: the email template for the dunning letter could not be resolved; the built-in mail was sent.', [
                'template' => $slug,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }

        return is_string($html) && trim($html) !== '' ? $html : null;
    }
}

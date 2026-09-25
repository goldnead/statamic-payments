<?php

namespace Goldnead\StatamicPayments\Tests\Feature\Portal;

use Goldnead\StatamicPayments\Mail\SubscriptionReminderMail;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Portal\Mail\CancellationConfirmed;
use Goldnead\StatamicPayments\Portal\Mail\PortalLinkMail;
use Goldnead\StatamicPayments\Support\Anrede;
use Goldnead\StatamicPayments\Support\Settings;
use Goldnead\StatamicPayments\Support\SubscriptionReminders;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;

/**
 * Du oder Sie, eine Einstellung für alles, was ein Käufer liest.
 *
 * Befund aus ChoirLive (25.09.2026): Konto, Team und Registrierung duzen, das
 * Kundenkonto von payments siezte („Ihr Vertrag … wurde gekündigt"). Eine
 * Seite, die in der Mitte die Anrede wechselt, liest sich wie zwei Läden.
 *
 * `statamic-payments.anrede` wählt. Ohne Angabe bleibt es bei „Sie", dem
 * Wortlaut, den die Installationen heute ausliefern (auch Widerruf und
 * Kündigung): eine Anrede, die sich mit einem Update still ändert, ist eine
 * Textänderung, die niemand freigegeben hat.
 */
class AnredeTest extends TestCase
{
    /** Wörter, die in einem geduzten Text nicht vorkommen dürfen. */
    protected const SIE = '/\b(Sie|Ihr|Ihre|Ihnen|Ihrem|Ihren|Ihres|Ihrer)\b|Guten Tag/u';

    /** Die Gruppen, die ein Käufer liest. `messages`, `settings`, `webhooks` sind CP. */
    protected const KAEUFER_GRUPPEN = ['abandoned', 'cancellation', 'checkout', 'dunning', 'portal', 'reminders', 'subscriptions', 'withdrawal'];

    protected Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('de');
        Mail::fake();

        $this->subscription = Subscription::create([
            'provider' => 'fake',
            'provider_id' => 'sub_1',
            'customer_reference' => 'cst_1',
            'product' => 'noten-paket',
            'amount_cent' => 1900,
            'currency' => 'EUR',
            'interval' => '1 month',
            'times_charged' => 3,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now()->subMonths(3),
            'next_payment_at' => now()->addMonth(),
            'email' => 'anna@example.de',
            'name' => 'Anna',
        ]);

        $this->gateway->subscriptions['sub_1'] = [
            'customer' => 'cst_1',
            'status' => Subscription::STATUS_ACTIVE,
        ];
    }

    #[Test]
    public function without_a_setting_the_portal_keeps_saying_sie(): void
    {
        $this->assertSame('sie', Anrede::current());

        $this->signIn('anna@example.de');

        $this->post(route('statamic-payments.portal.cancel.run', ['paySubscription' => $this->subscription->getKey()]))
            ->assertOk()
            ->assertSee('Ihr Vertrag', false);
    }

    #[Test]
    public function with_du_the_page_after_cancelling_says_du(): void
    {
        config(['statamic-payments.anrede' => 'du']);

        $this->signIn('anna@example.de');

        $page = $this->post(route('statamic-payments.portal.cancel.run', ['paySubscription' => $this->subscription->getKey()]))
            ->assertOk()
            ->assertSee('Dein Vertrag', false);

        $this->assertDoesNotMatchRegularExpression(self::SIE, strip_tags((string) $page->getContent()));
    }

    #[Test]
    public function with_du_the_mails_say_du(): void
    {
        config(['statamic-payments.anrede' => 'du']);

        $this->signIn('anna@example.de', function (PortalLinkMail $mail) {
            $html = $mail->render();
            $this->assertStringContainsString('Hallo,', $html);
            $this->assertDoesNotMatchRegularExpression(self::SIE, strip_tags($html).' '.$mail->envelope()->subject);
        });

        $this->post(route('statamic-payments.portal.cancel.run', ['paySubscription' => $this->subscription->getKey()]));

        Mail::assertSent(CancellationConfirmed::class, function (CancellationConfirmed $mail) {
            $html = $mail->render();
            $this->assertStringContainsString('deines Vertrags', $html);
            $this->assertDoesNotMatchRegularExpression(self::SIE, strip_tags($html).' '.$mail->envelope()->subject);

            return true;
        });

        $rendered = app(SubscriptionReminders::class)->render($this->subscription, 'card_expiring', Carbon::parse('2026-10-31'));
        $html = (new SubscriptionReminderMail($this->subscription, 'card_expiring', $rendered['subject'], null, $rendered['variables']))->render();

        $this->assertStringContainsString('Hallo Anna,', $html);
        $this->assertDoesNotMatchRegularExpression(self::SIE, strip_tags($html).' '.$rendered['subject']);
    }

    #[Test]
    public function every_line_that_says_sie_has_a_du_line_and_no_du_line_says_sie(): void
    {
        foreach (self::KAEUFER_GRUPPEN as $group) {
            $sie = require __DIR__."/../../../lang/de/{$group}.php";
            $duPfad = __DIR__."/../../../lang/de/du/{$group}.php";
            $du = is_file($duPfad) ? require $duPfad : [];

            foreach ($this->flatten($sie) as $key => $text) {
                if (preg_match(self::SIE, $text)) {
                    $this->assertArrayHasKey($key, $this->flatten($du), "{$group}.{$key} siezt und hat keine du-Fassung");
                }
            }

            foreach ($this->flatten($du) as $key => $text) {
                $this->assertArrayHasKey($key, $this->flatten($sie), "du/{$group}.{$key} hat keine Vorlage");
                $this->assertDoesNotMatchRegularExpression(self::SIE, $text, "du/{$group}.{$key}");
                $this->assertStringNotContainsString('—', $text, "du/{$group}.{$key}: kein Gedankenstrich");
            }
        }
    }

    #[Test]
    public function english_is_not_touched(): void
    {
        config(['statamic-payments.anrede' => 'du']);
        app()->setLocale('en');

        $this->assertSame(__('statamic-payments::portal.cancelled_title'), Anrede::trans('statamic-payments::portal.cancelled_title'));
        $this->assertSame(__('statamic-payments::portal.mail_link_subject'), Anrede::trans('statamic-payments::portal.mail_link_subject'));
    }

    #[Test]
    public function the_setting_is_offered_on_the_settings_screen(): void
    {
        $fields = collect(Settings::settingsGroups())->flatMap(fn ($group) => $group['fields']);
        $anrede = $fields->firstWhere('key', 'anrede');

        $this->assertNotNull($anrede);
        $this->assertSame('select', $anrede['type']);
        $this->assertSame(['sie', 'du'], array_keys($anrede['options']));
    }

    /**
     * @param  array<string, mixed>  $lines
     * @return array<string, string>
     */
    protected function flatten(array $lines, string $prefix = ''): array
    {
        $flat = [];

        foreach ($lines as $key => $value) {
            if (is_array($value)) {
                $flat += $this->flatten($value, $prefix.$key.'.');
            } else {
                $flat[$prefix.$key] = (string) $value;
            }
        }

        return $flat;
    }

    protected function signIn(string $email, ?callable $inspect = null): void
    {
        $this->post(route('statamic-payments.portal.request.send'), ['email' => $email]);

        $url = null;

        Mail::assertSent(PortalLinkMail::class, function (PortalLinkMail $mail) use (&$url, $inspect) {
            $url ??= $mail->url;

            if ($inspect) {
                $inspect($mail);
            }

            return true;
        });

        $this->get((string) $url)->assertRedirect(route('statamic-payments.portal.show'));

        Mail::fake();
    }
}

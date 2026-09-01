<?php

namespace Goldnead\StatamicPayments\Http\Controllers\Legal;

use Goldnead\StatamicPayments\Legal\Moment;
use Goldnead\StatamicPayments\Legal\Withdrawals;
use Goldnead\StatamicPayments\Models\Withdrawal;
use Goldnead\StatamicPayments\Portal\EmailAddress;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Der Widerrufsbutton nach § 356a BGB — öffentlich, ohne Login, zwei Schritte.
 *
 * 1. `form()` / `declare()`: Name, E-Mail, Bestellkennung, Kontaktmittel,
 *    Nachricht. Die Zeile entsteht mit `declared_at`.
 * 2. `show()` (unbestätigt): die Zusammenfassung mit der Schaltfläche
 *    „Widerruf bestätigen". `confirm()`: der Widerruf, `confirmed_at`, Mail
 *    an den Verbraucher, Meldung an den Händler.
 * 3. `show()` (bestätigt): Kennung und Zeitpunkt. Sonst nichts.
 *
 * **Was eine GET-Route verraten darf, ist die Grenze dieses Controllers.** Die
 * Zusammenfassung in Schritt 2 zeigt Name und Adresse — also nur dem Browser,
 * der sie eben eingegeben hat, erkennbar an einer Notiz in dessen Session. Wer
 * mit einer Kennung ohne diese Notiz kommt, sieht nach der Bestätigung Kennung
 * und Zeit (dasselbe, was in der Mail steht) und vorher eine 404. Kein Weg
 * durch diesen Controller beantwortet die Frage, ob eine Adresse hier gekauft
 * hat.
 *
 * POST → Redirect → GET, nicht POST → View: ein Reload auf Schritt 2 darf
 * keine zweite Erklärung anlegen, und ein Reload auf Schritt 3 keinen zweiten
 * Widerruf versuchen.
 */
class WithdrawalController extends Controller
{
    protected const SESSION = 'statamic-payments.withdrawal.declared';

    public function __construct(protected Withdrawals $withdrawals) {}

    /** Schritt 1: das Formular. */
    public function form()
    {
        $this->enabledOr404();

        return response()->view('statamic-payments::withdrawal.form', [
            'policyUrl' => $this->policyUrl(),
        ]);
    }

    public function declare(Request $request)
    {
        $this->enabledOr404();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            // Nicht `email:filter`: das lehnt jede Adresse mit Umlaut ab, und
            // wer mit `bärbel@…` gekauft hat, muss damit auch widerrufen
            // können. Dieselbe Prüfung wie im Portal.
            'email' => ['required', 'string', 'max:191', EmailAddress::rule()],
            'order_reference' => ['required', 'string', 'max:191'],
            'contact' => ['nullable', 'string', 'max:191'],
            'message' => ['nullable', 'string', 'max:4000'],
        ]);

        $withdrawal = $this->withdrawals->declare($data, $request->ip());

        $request->session()->push(self::SESSION, $withdrawal->public_id);

        return redirect()->route('statamic-payments.withdrawal.show', ['payWithdrawal' => $withdrawal->public_id]);
    }

    /** Schritt 2 für den, der erklärt hat; Schritt 3 für jeden mit der Kennung. */
    public function show(Request $request, string $payWithdrawal)
    {
        $this->enabledOr404();

        $withdrawal = $this->find($payWithdrawal);

        if ($withdrawal->isConfirmed()) {
            return $this->done($withdrawal);
        }

        // Kein Blick in eine fremde Zusammenfassung — aber auch keine nackte
        // 404 für den, dessen Session abgelaufen ist: zurück zum Formular, mit
        // einem Satz, der sagt, was zu tun ist.
        if (! $this->declaredHere($request, $withdrawal)) {
            return redirect()
                ->route('statamic-payments.withdrawal.form')
                ->with('statamic-payments.portal.status', __('statamic-payments::withdrawal.restart'));
        }

        return response()->view('statamic-payments::withdrawal.confirm', [
            'withdrawal' => $withdrawal,
            'policyUrl' => $this->policyUrl(),
        ]);
    }

    /** Schritt 2 → 3: „Widerruf bestätigen". */
    public function confirm(Request $request, string $payWithdrawal)
    {
        $this->enabledOr404();

        $withdrawal = $this->find($payWithdrawal);

        // Schon bestätigt: kein zweiter Widerruf, keine zweite Mail, nur die
        // Seite mit dem ersten Zeitpunkt. Wer die Notiz nicht hat, darf eine
        // fremde Erklärung nicht bestätigen — auch wenn der Schaden klein
        // wäre, ist es nicht seine.
        if (! $withdrawal->isConfirmed()) {
            abort_unless($this->declaredHere($request, $withdrawal), 404);

            $this->withdrawals->confirm($withdrawal);
        }

        return redirect()->route('statamic-payments.withdrawal.show', ['payWithdrawal' => $withdrawal->public_id]);
    }

    protected function done(Withdrawal $withdrawal)
    {
        $moment = Moment::parts($withdrawal->confirmed_at ?? $withdrawal->declared_at);

        return response()->view('statamic-payments::withdrawal.done', [
            'id' => $withdrawal->public_id,
            'date' => $moment['date'],
            'time' => $moment['time'],
            'zone' => $moment['zone'],
            // Ob die Mail rausging — nicht, wohin. Die Seite bleibt lesbar für
            // jeden mit der Kennung und darf die Adresse deshalb nicht nennen.
            'delivered' => $withdrawal->receipt_sent_at !== null,
        ]);
    }

    protected function find(string $publicId): Withdrawal
    {
        $withdrawal = Withdrawal::query()->where('public_id', strtoupper(trim($publicId)))->first();

        abort_if($withdrawal === null, 404);

        return $withdrawal;
    }

    protected function declaredHere(Request $request, Withdrawal $withdrawal): bool
    {
        return in_array($withdrawal->public_id, (array) $request->session()->get(self::SESSION, []), true);
    }

    protected function enabledOr404(): void
    {
        abort_unless(config('statamic-payments.withdrawal.enabled', true), 404);
    }

    protected function policyUrl(): ?string
    {
        $url = config('statamic-payments.withdrawal.policy_url');

        return is_string($url) && trim($url) !== '' ? trim($url) : null;
    }
}

<?php

namespace Goldnead\StatamicPayments\Http\Controllers\Legal;

use Goldnead\StatamicPayments\Legal\Cancellations;
use Goldnead\StatamicPayments\Legal\Moment;
use Goldnead\StatamicPayments\Models\Cancellation;
use Goldnead\StatamicPayments\Portal\EmailAddress;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * Der Kündigungsbutton nach § 312k BGB — ohne Login.
 *
 * Dieselbe Form wie {@see WithdrawalController}, mit den Angaben, die § 312k
 * Abs. 2 Nr. 1 verlangt: Art der Kündigung, bei der außerordentlichen der
 * Grund, Identifikation des Vertrags, gewünschter Zeitpunkt. Die
 * Bestätigungsseite trägt die Schaltfläche „jetzt kündigen".
 *
 * Der Weg über das Portal (`Portal\CancellationController`) bleibt bestehen.
 * Er verlangt einen Magic-Link, und nach herrschender Lesart darf die
 * Kündigungserklärung nicht hinter einem Identifikationsschritt liegen — also
 * ist dieser hier der Pflichtweg, jener der Komfortweg für jemanden, der seinen
 * Vertrag ohnehin vor sich hat. (Entscheidung 01.09.2026, von Adrian zu prüfen.
 * Keine Rechtsberatung.)
 */
class CancellationController extends Controller
{
    protected const SESSION = 'statamic-payments.cancellation.declared';

    public function __construct(protected Cancellations $cancellations) {}

    public function form()
    {
        $this->enabledOr404();

        return response()->view('statamic-payments::cancellation.form', [
            'policyUrl' => $this->policyUrl(),
            'kinds' => Cancellation::kinds(),
        ]);
    }

    public function declare(Request $request)
    {
        $this->enabledOr404();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'email' => ['required', 'string', 'max:191', EmailAddress::rule()],
            'identification' => ['required', 'string', 'max:191'],
            'kind' => ['required', Rule::in(Cancellation::kinds())],
            // § 312k Abs. 2 Nr. 1 a): bei der außerordentlichen Kündigung
            // der Kündigungsgrund. Bei der ordentlichen ist er frei.
            'reason' => ['nullable', 'string', 'max:4000', 'required_if:kind,'.Cancellation::KIND_EXTRAORDINARY],
            'effective_at' => ['nullable', 'date'],
        ]);

        $cancellation = $this->cancellations->declare($data, $request->ip());

        $request->session()->push(self::SESSION, $cancellation->public_id);

        return redirect()->route('statamic-payments.cancellation.show', ['payCancellation' => $cancellation->public_id]);
    }

    public function show(Request $request, string $payCancellation)
    {
        $this->enabledOr404();

        $cancellation = $this->find($payCancellation);

        if ($cancellation->isConfirmed()) {
            return $this->done($cancellation);
        }

        abort_unless($this->declaredHere($request, $cancellation), 404);

        return response()->view('statamic-payments::cancellation.confirm', [
            'cancellation' => $cancellation,
            'policyUrl' => $this->policyUrl(),
        ]);
    }

    /** „jetzt kündigen". */
    public function confirm(Request $request, string $payCancellation)
    {
        $this->enabledOr404();

        $cancellation = $this->find($payCancellation);

        if (! $cancellation->isConfirmed()) {
            abort_unless($this->declaredHere($request, $cancellation), 404);

            $this->cancellations->confirm($cancellation);
        }

        return redirect()->route('statamic-payments.cancellation.show', ['payCancellation' => $cancellation->public_id]);
    }

    protected function done(Cancellation $cancellation)
    {
        $moment = Moment::parts($cancellation->confirmed_at ?? $cancellation->declared_at);

        return response()->view('statamic-payments::cancellation.done', [
            'id' => $cancellation->public_id,
            'date' => $moment['date'],
            'time' => $moment['time'],
            'zone' => $moment['zone'],
            'effective' => $cancellation->effective_at?->translatedFormat((string) __('statamic-payments::portal.date_format')),
            'delivered' => $cancellation->receipt_sent_at !== null,
        ]);
    }

    protected function find(string $publicId): Cancellation
    {
        $cancellation = Cancellation::query()->where('public_id', strtoupper(trim($publicId)))->first();

        abort_if($cancellation === null, 404);

        return $cancellation;
    }

    protected function declaredHere(Request $request, Cancellation $cancellation): bool
    {
        return in_array($cancellation->public_id, (array) $request->session()->get(self::SESSION, []), true);
    }

    protected function enabledOr404(): void
    {
        abort_unless(config('statamic-payments.cancellation.enabled', true), 404);
    }

    protected function policyUrl(): ?string
    {
        $url = config('statamic-payments.cancellation.policy_url');

        return is_string($url) && trim($url) !== '' ? trim($url) : null;
    }
}

@extends('statamic-payments::portal.layout')

@section('title', \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::abandoned.resume_title'))

@section('content')
    {{--
        § 312j Abs. 2 und 3 BGB: die wesentlichen Angaben unmittelbar vor der
        Schaltfläche, und die Schaltfläche mit dem gesetzlichen Wortlaut. Der
        GET zeigt; erst der POST bestellt. Der Haken ist die Zustimmung nach
        § 356 Abs. 5 — ohne ihn wird trotzdem bestellt, nur bleibt das
        Widerrufsrecht bestehen.
    --}}
    <h1>{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::abandoned.resume_title') }}</h1>
    <p class="lede">{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::abandoned.resume_intro') }}</p>

    <div class="block">
        <table class="lines">
            <tbody>
                @foreach ($lines as $line)
                    <tr>
                        <td>{{ $line['quantity'] > 1 ? $line['quantity'].' × ' : '' }}{{ $line['name'] }}</td>
                        <td class="num">{{ $line['amount'] }} {{ $currency }}</td>
                    </tr>
                @endforeach
                @if ($discount)
                    <tr>
                        <td>{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::abandoned.resume_discount') }}</td>
                        <td class="num">− {{ $discount }} {{ $currency }}</td>
                    </tr>
                @endif
                <tr>
                    <td><strong>{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::abandoned.resume_total') }}</strong></td>
                    <td class="num"><strong>{{ $total }} {{ $currency }}</strong></td>
                </tr>
            </tbody>
        </table>
        <p class="hint">{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::abandoned.resume_price_hint') }}</p>
    </div>

    <form method="POST" action="{{ $action }}" class="block">
        @csrf

        <h2>{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::abandoned.resume_withdrawal_heading') }}</h2>
        <p class="muted">{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::abandoned.resume_withdrawal_text') }}
            @if ($policyUrl)
                <a href="{{ $policyUrl }}">{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::abandoned.resume_policy_link') }}</a>
            @endif
        </p>

        <div class="field">
            <label for="resume-consent" style="display:flex; gap:10px; align-items:flex-start;">
                <input id="resume-consent" type="checkbox" name="consent" value="1" style="margin-top:4px;">
                <span>{{ $consentText }}</span>
            </label>
        </div>

        {{-- The captcha, where the site has one (P7). This order starts a checkout like any other. --}}
        {!! app(\Goldnead\StatamicPayments\Support\CheckoutGuard::class)->widget() !!}

        <button type="submit" class="btn">{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::abandoned.resume_button') }}</button>
        <p class="muted" style="margin-top:10px; text-align:center;">{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::abandoned.resume_button_hint') }}</p>
    </form>

    <div class="foot">
        {{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::abandoned.resume_foot', ['email' => $payment->email]) }}
    </div>
@endsection

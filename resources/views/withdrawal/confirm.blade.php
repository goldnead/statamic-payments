@extends('statamic-payments::portal.layout')

@section('title', \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::withdrawal.confirm_title'))

@section('content')
    {{--
        § 356a Abs. 3 BGB: die Bestätigungsschaltfläche. Die Eingaben stehen
        noch einmal da, damit klar ist, was gleich erklärt wird, und darunter
        genau eine Schaltfläche mit dem gesetzlichen Wortlaut.
    --}}
    <h1>{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::withdrawal.confirm_title') }}</h1>
    <p class="lede">{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::withdrawal.confirm_intro') }}</p>

    <div class="block">
        <table class="lines">
            <tbody>
                <tr><td>{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::withdrawal.field_name') }}</td><td class="num">{{ $withdrawal->name }}</td></tr>
                <tr><td>{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::withdrawal.field_email') }}</td><td class="num">{{ $withdrawal->email }}</td></tr>
                <tr><td>{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::withdrawal.field_reference') }}</td><td class="num">{{ $withdrawal->order_reference }}</td></tr>
                <tr><td>{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::withdrawal.field_contact') }}</td><td class="num">{{ $withdrawal->contact }}</td></tr>
                @if ($withdrawal->message)
                    <tr><td>{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::withdrawal.field_message') }}</td><td class="num">{{ $withdrawal->message }}</td></tr>
                @endif
            </tbody>
        </table>
    </div>

    <p class="notice">{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::withdrawal.confirm_effect') }}</p>

    <form method="POST" action="{{ route('statamic-payments.withdrawal.confirm', ['payWithdrawal' => $withdrawal->public_id]) }}">
        @csrf
        <button type="submit" class="btn">{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::withdrawal.confirm_button') }}</button>
    </form>

    <div class="foot">
        <a href="{{ route('statamic-payments.withdrawal.form') }}">{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::withdrawal.confirm_back') }}</a>
    </div>
@endsection

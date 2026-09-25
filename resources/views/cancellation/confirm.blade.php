@extends('statamic-payments::portal.layout')

@section('title', \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.confirm_title'))

@section('content')
    {{--
        § 312k Abs. 2 S. 3 BGB: die Bestätigungsseite. Die Angaben noch einmal,
        darunter eine Schaltfläche mit dem gesetzlichen Wortlaut „jetzt
        kündigen".
    --}}
    <h1>{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.confirm_title') }}</h1>
    <p class="lede">{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.confirm_intro') }}</p>

    <div class="block">
        <table class="lines">
            <tbody>
                <tr><td>{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.field_name') }}</td><td class="num">{{ $cancellation->name }}</td></tr>
                <tr><td>{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.field_email') }}</td><td class="num">{{ $cancellation->email }}</td></tr>
                <tr><td>{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.field_identification') }}</td><td class="num">{{ $cancellation->identification }}</td></tr>
                <tr><td>{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.field_kind') }}</td><td class="num">{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.kind_'.$cancellation->kind) }}</td></tr>
                @if ($cancellation->reason)
                    <tr><td>{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.field_reason') }}</td><td class="num">{{ $cancellation->reason }}</td></tr>
                @endif
                <tr>
                    <td>{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.field_effective') }}</td>
                    <td class="num">{{ $cancellation->effective_at ? \Goldnead\StatamicPayments\Support\LocalTime::portalDate($cancellation->effective_at) : \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.effective_earliest') }}</td>
                </tr>
            </tbody>
        </table>
    </div>

    <p class="notice">{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.confirm_effect') }}</p>

    <form method="POST" action="{{ route('statamic-payments.cancellation.confirm', ['payCancellation' => $cancellation->public_id]) }}">
        @csrf
        <button type="submit" class="btn">{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.confirm_button') }}</button>
    </form>

    <div class="foot">
        <a href="{{ route('statamic-payments.cancellation.form') }}">{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.confirm_back') }}</a>
    </div>
@endsection

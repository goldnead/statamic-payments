@extends('statamic-payments::portal.layout')

@section('title', __('statamic-payments::cancellation.confirm_title'))

@section('content')
    {{--
        § 312k Abs. 2 S. 3 BGB: die Bestätigungsseite. Die Angaben noch einmal,
        darunter eine Schaltfläche mit dem gesetzlichen Wortlaut „jetzt
        kündigen".
    --}}
    <h1>{{ __('statamic-payments::cancellation.confirm_title') }}</h1>
    <p class="lede">{{ __('statamic-payments::cancellation.confirm_intro') }}</p>

    <div class="block">
        <table class="lines">
            <tbody>
                <tr><td>{{ __('statamic-payments::cancellation.field_name') }}</td><td class="num">{{ $cancellation->name }}</td></tr>
                <tr><td>{{ __('statamic-payments::cancellation.field_email') }}</td><td class="num">{{ $cancellation->email }}</td></tr>
                <tr><td>{{ __('statamic-payments::cancellation.field_identification') }}</td><td class="num">{{ $cancellation->identification }}</td></tr>
                <tr><td>{{ __('statamic-payments::cancellation.field_kind') }}</td><td class="num">{{ __('statamic-payments::cancellation.kind_'.$cancellation->kind) }}</td></tr>
                @if ($cancellation->reason)
                    <tr><td>{{ __('statamic-payments::cancellation.field_reason') }}</td><td class="num">{{ $cancellation->reason }}</td></tr>
                @endif
                <tr>
                    <td>{{ __('statamic-payments::cancellation.field_effective') }}</td>
                    <td class="num">{{ $cancellation->effective_at?->translatedFormat(__('statamic-payments::portal.date_format')) ?? __('statamic-payments::cancellation.effective_earliest') }}</td>
                </tr>
            </tbody>
        </table>
    </div>

    <p class="notice">{{ __('statamic-payments::cancellation.confirm_effect') }}</p>

    <form method="POST" action="{{ route('statamic-payments.cancellation.confirm', ['payCancellation' => $cancellation->public_id]) }}">
        @csrf
        <button type="submit" class="btn">{{ __('statamic-payments::cancellation.confirm_button') }}</button>
    </form>

    <div class="foot">
        <a href="{{ route('statamic-payments.cancellation.form') }}">{{ __('statamic-payments::cancellation.confirm_back') }}</a>
    </div>
@endsection

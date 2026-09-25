@extends('statamic-payments::portal.layout')

@section('title', \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.done_title'))

@section('content')
    {{--
        § 312k Abs. 2 S. 4 BGB: die Bestätigung auf der Seite, mit Datum und
        Uhrzeit des Eingangs und dem genannten Zeitpunkt. Die Mail ist die
        Bestätigung in Textform; diese Seite bleibt für jeden mit der Kennung
        lesbar und nennt deshalb weder Name noch Adresse.
    --}}
    <h1>{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.done_title') }}</h1>
    <p class="lede">{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.done_received', ['date' => $date, 'time' => $time, 'zone' => $zone]) }}</p>
    <p class="lede">{{ $effective
        ? \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.done_effective', ['date' => $effective])
        : \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.done_effective_earliest') }}</p>

    <div class="ticket">
        <div class="id">{{ $id }}</div>
        <p class="when">{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.done_id_label') }}</p>
    </div>

    @if ($delivered)
        <p class="notice" style="margin-top:16px;">{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.done_mailed') }}</p>
    @else
        <p class="errors" role="alert" style="margin-top:16px;">{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.done_not_mailed') }}</p>
    @endif

    <p class="foot">{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.done_keep') }}</p>
@endsection

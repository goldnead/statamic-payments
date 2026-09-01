@extends('statamic-payments::portal.layout')

@section('title', __('statamic-payments::withdrawal.done_title'))

@section('content')
    {{--
        Schritt 3. Kennung und Zeitpunkt, dasselbe wie in der Mail — und sonst
        nichts, weil diese Seite für jeden lesbar bleibt, der die Kennung hat.
        Kein Name, keine Adresse, kein Wort dazu, ob eine Bestellung gefunden
        wurde.
    --}}
    <h1>{{ __('statamic-payments::withdrawal.done_title') }}</h1>
    <p class="lede">{{ __('statamic-payments::withdrawal.done_received', ['date' => $date, 'time' => $time, 'zone' => $zone]) }}</p>

    <div class="ticket">
        <div class="id">{{ $id }}</div>
        <p class="when">{{ __('statamic-payments::withdrawal.done_id_label') }}</p>
    </div>

    @if ($delivered)
        <p class="notice" style="margin-top:16px;">{{ __('statamic-payments::withdrawal.done_mailed') }}</p>
    @else
        <p class="errors" role="alert" style="margin-top:16px;">{{ __('statamic-payments::withdrawal.done_not_mailed') }}</p>
    @endif

    <p class="foot">{{ __('statamic-payments::withdrawal.done_keep') }}</p>
@endsection

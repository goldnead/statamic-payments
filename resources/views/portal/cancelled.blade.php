@extends('statamic-payments::portal.layout')

@section('title', __('statamic-payments::portal.cancelled_title'))

@section('content')
    <h1>{{ __('statamic-payments::portal.cancelled_title') }}</h1>

    {{--
        The date and the time, on the screen as well as in the mail. The mail is
        the confirmation the statute asks for — this page is gone on reload and
        proves nothing — but somebody standing here has just ended a contract and
        should be able to read when, without waiting for a mail server.
    --}}
    <p class="lede">{{ __('statamic-payments::portal.cancelled_confirmation', [
        'name' => $name,
        'date' => $moment->translatedFormat(__('statamic-payments::portal.date_format')),
        'time' => $moment->translatedFormat(__('statamic-payments::portal.time_format')),
    ]) }}</p>

    @if ($delivered)
        <p class="notice">{{ __('statamic-payments::portal.cancelled_mailed', ['email' => $email]) }}</p>
    @else
        {{--
            The cancellation stands; the confirmation in Textform did not go out.
            Said on the screen rather than swallowed into a log, because the
            person it concerns is the one reading this — and because the page
            they are looking at is, for the moment, the only record they have.
        --}}
        <p class="errors" role="alert">{{ __('statamic-payments::portal.cancelled_not_mailed', ['email' => $email]) }}</p>
    @endif

    <div class="foot">
        <a href="{{ route('statamic-payments.portal.show') }}">{{ __('statamic-payments::portal.cancelled_back') }}</a>
    </div>
@endsection

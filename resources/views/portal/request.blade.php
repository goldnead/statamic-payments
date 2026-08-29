@extends('statamic-payments::portal.layout')

@php
    // One form, two entrances. The cancellation entrance is what § 312k's
    // „Verträge hier kündigen" button leads to, and whoever arrives there still
    // has to show that the contract is theirs — so it is the same field, with
    // the words the situation calls for.
    $cancelling = ($intent ?? 'orders') === 'cancel';
    $title = $cancelling ? 'cancel_entry_title' : 'request_title';
    $intro = $cancelling ? 'cancel_entry_intro' : 'request_intro';
    $button = $cancelling ? 'cancel_entry_button' : 'request_button';
@endphp

@section('title', __('statamic-payments::portal.'.$title))

@section('content')
    <h1>{{ __('statamic-payments::portal.'.$title) }}</h1>
    <p class="lede">{{ __('statamic-payments::portal.'.$intro) }}</p>

    <form method="POST" action="{{ route('statamic-payments.portal.request.send') }}" class="block">
        @csrf
        <input type="hidden" name="intent" value="{{ $cancelling ? 'cancel' : 'orders' }}">
        @if (! empty($brand))
            {{-- Carried across the redirect so the second page searches the same
                 audience the first one did. Naming a brand changes which orders a
                 link opens, never whether anything is revealed. --}}
            <input type="hidden" name="payBrand" value="{{ $brand }}">
        @endif
        <label class="muted" for="pay-email">{{ __('statamic-payments::portal.request_label') }}</label>
        <input id="pay-email" type="email" name="email" autocomplete="email" required
               placeholder="{{ __('statamic-payments::portal.request_placeholder') }}">
        <button type="submit" class="btn">{{ __('statamic-payments::portal.'.$button) }}</button>
    </form>

    <p class="foot">{{ __('statamic-payments::portal.request_foot', ['minutes' => max(1, (int) config('statamic-payments.portal.link_ttl_minutes', 30))]) }}</p>
@endsection

@extends('statamic-payments::portal.layout')

@section('title', __('statamic-payments::withdrawal.title'))

@section('content')
    {{--
        § 356a Abs. 2 BGB: Schritt 1. Name, Vertragsidentifikation, Kontaktmittel.
        Nichts hier prüft, ob es die Bestellung gibt — das Formular nimmt jede
        Erklärung an und verrät niemandem, welche Adresse hier gekauft hat.
    --}}
    <h1>{{ __('statamic-payments::withdrawal.title') }}</h1>
    <p class="lede">{{ __('statamic-payments::withdrawal.intro') }}</p>

    @if ($errors->any())
        <p class="errors" role="alert">{{ __('statamic-payments::withdrawal.errors_intro') }}</p>
    @endif

    <form method="POST" action="{{ route('statamic-payments.withdrawal.declare') }}" class="block" novalidate>
        @csrf

        <div class="field">
            <label class="muted" for="wd-name">{{ __('statamic-payments::withdrawal.field_name') }}</label>
            <input id="wd-name" type="text" name="name" autocomplete="name" required value="{{ old('name') }}">
            @error('name')<p class="error">{{ $message }}</p>@enderror
        </div>

        <div class="field">
            <label class="muted" for="wd-email">{{ __('statamic-payments::withdrawal.field_email') }}</label>
            <input id="wd-email" type="email" name="email" autocomplete="email" required value="{{ old('email') }}">
            <p class="help">{{ __('statamic-payments::withdrawal.field_email_help') }}</p>
            @error('email')<p class="error">{{ $message }}</p>@enderror
        </div>

        <div class="field">
            <label class="muted" for="wd-reference">{{ __('statamic-payments::withdrawal.field_reference') }}</label>
            <input id="wd-reference" type="text" name="order_reference" required value="{{ old('order_reference') }}">
            <p class="help">{{ __('statamic-payments::withdrawal.field_reference_help') }}</p>
            @error('order_reference')<p class="error">{{ $message }}</p>@enderror
        </div>

        <div class="field">
            <label class="muted" for="wd-contact">{{ __('statamic-payments::withdrawal.field_contact') }}</label>
            <input id="wd-contact" type="text" name="contact" value="{{ old('contact') }}" placeholder="{{ __('statamic-payments::withdrawal.field_contact_placeholder') }}">
            <p class="help">{{ __('statamic-payments::withdrawal.field_contact_help') }}</p>
            @error('contact')<p class="error">{{ $message }}</p>@enderror
        </div>

        <div class="field">
            <label class="muted" for="wd-message">{{ __('statamic-payments::withdrawal.field_message') }}</label>
            <textarea id="wd-message" name="message">{{ old('message') }}</textarea>
            @error('message')<p class="error">{{ $message }}</p>@enderror
        </div>

        <button type="submit" class="btn">{{ __('statamic-payments::withdrawal.continue') }}</button>
        <p class="muted" style="margin-top:10px; text-align:center;">{{ __('statamic-payments::withdrawal.continue_hint') }}</p>
    </form>

    <div class="foot">
        @if ($policyUrl)
            <a href="{{ $policyUrl }}">{{ __('statamic-payments::withdrawal.policy_link') }}</a> ·
        @endif
        {{ __('statamic-payments::withdrawal.foot') }}
    </div>
@endsection

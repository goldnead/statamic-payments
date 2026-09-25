@extends('statamic-payments::portal.layout')

@section('title', \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.title'))

@section('content')
    {{--
        § 312k Abs. 2 BGB, ohne Login. Die Angaben aus Nr. 1: Art der Kündigung,
        bei der außerordentlichen der Grund, Identifikation des Vertrags, der
        gewünschte Zeitpunkt. Nichts hier prüft, ob es den Vertrag gibt.
    --}}
    <h1>{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.title') }}</h1>
    <p class="lede">{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.intro') }}</p>

    @if ($errors->any())
        <p class="errors" role="alert">{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.errors_intro') }}</p>
    @endif

    <form method="POST" action="{{ route('statamic-payments.cancellation.declare') }}" class="block" novalidate>
        @csrf

        <div class="field">
            <label class="muted" for="cn-name">{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.field_name') }}</label>
            <input id="cn-name" type="text" name="name" autocomplete="name" required value="{{ old('name') }}">
            @error('name')<p class="error">{{ $message }}</p>@enderror
        </div>

        <div class="field">
            <label class="muted" for="cn-email">{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.field_email') }}</label>
            <input id="cn-email" type="email" name="email" autocomplete="email" required value="{{ old('email') }}">
            <p class="help">{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.field_email_help') }}</p>
            @error('email')<p class="error">{{ $message }}</p>@enderror
        </div>

        <div class="field">
            <label class="muted" for="cn-identification">{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.field_identification') }}</label>
            <input id="cn-identification" type="text" name="identification" required value="{{ old('identification') }}">
            <p class="help">{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.field_identification_help') }}</p>
            @error('identification')<p class="error">{{ $message }}</p>@enderror
        </div>

        <fieldset class="field" style="border:0; padding:0; margin-top:16px;">
            <legend class="muted" style="padding:0; margin:0 0 4px;">{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.field_kind') }}</legend>
            @foreach ($kinds as $kind)
                <label class="choice">
                    <input type="radio" name="kind" value="{{ $kind }}" @checked(old('kind', 'ordinary') === $kind)>
                    <span>
                        {{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.kind_'.$kind) }}
                        <span class="desc">{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.kind_'.$kind.'_help') }}</span>
                    </span>
                </label>
            @endforeach
            @error('kind')<p class="error">{{ $message }}</p>@enderror
        </fieldset>

        <div class="field">
            <label class="muted" for="cn-reason">{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.field_reason') }}</label>
            <textarea id="cn-reason" name="reason">{{ old('reason') }}</textarea>
            <p class="help">{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.field_reason_help') }}</p>
            @error('reason')<p class="error">{{ $message }}</p>@enderror
        </div>

        <div class="field">
            <label class="muted" for="cn-effective">{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.field_effective') }}</label>
            <input id="cn-effective" type="date" name="effective_at" value="{{ old('effective_at') }}">
            <p class="help">{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.field_effective_help') }}</p>
            @error('effective_at')<p class="error">{{ $message }}</p>@enderror
        </div>

        <button type="submit" class="btn">{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.continue') }}</button>
        <p class="muted" style="margin-top:10px; text-align:center;">{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.continue_hint') }}</p>
    </form>

    <div class="foot">
        @if ($policyUrl)
            <a href="{{ $policyUrl }}">{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.policy_link') }}</a> ·
        @endif
        {{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.foot') }}
    </div>
@endsection

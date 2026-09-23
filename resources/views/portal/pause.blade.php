@extends('statamic-payments::portal.layout')

@section('title', __('statamic-payments::subscriptions.portal_pause_title'))

@section('content')
    <h1>{{ __('statamic-payments::subscriptions.portal_pause_title') }}</h1>
    <p class="lede">{{ __('statamic-payments::subscriptions.portal_pause_intro', ['name' => $name]) }}</p>

    <div class="block">
        <table class="lines">
            <tbody>
                <tr>
                    <td>{{ __('statamic-payments::portal.cancel_contract') }}</td>
                    <td class="num">{{ $name }}</td>
                </tr>
                <tr>
                    <td>{{ __('statamic-payments::portal.cancel_price') }}</td>
                    <td class="num">{{ \Goldnead\StatamicPayments\Portal\Display::money((int) $subscription->amount_cent, $subscription->currency) }} · {{ \Goldnead\StatamicPayments\Portal\Display::rhythm($subscription->interval) }}</td>
                </tr>
                @if ($subscription->next_payment_at)
                    <tr>
                        <td>{{ __('statamic-payments::subscriptions.portal_paid_until') }}</td>
                        <td class="num">{{ $subscription->next_payment_at->translatedFormat(__('statamic-payments::portal.date_format')) }}</td>
                    </tr>
                @endif
            </tbody>
        </table>
    </div>

    <p class="notice">{{ __('statamic-payments::subscriptions.portal_pause_effect') }}</p>

    <form method="POST" action="{{ route('statamic-payments.portal.pause.run', ['paySubscription' => $subscription->getKey()]) }}">
        @csrf
        <div class="field">
            <label class="muted" for="resume_on">{{ __('statamic-payments::subscriptions.pause_resume_on') }}</label>
            <input type="date" id="resume_on" name="resume_on" min="{{ $min }}">
            <p class="help">{{ __('statamic-payments::subscriptions.portal_pause_date_help') }}</p>
        </div>

        <button type="submit" class="btn">{{ __('statamic-payments::subscriptions.portal_pause_now') }}</button>
    </form>

    <div class="foot">
        <a href="{{ route('statamic-payments.portal.show') }}">{{ __('statamic-payments::subscriptions.portal_back') }}</a>
    </div>
@endsection

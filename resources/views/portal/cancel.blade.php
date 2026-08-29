@extends('statamic-payments::portal.layout')

@section('title', __('statamic-payments::portal.cancel_title'))

@section('content')
    {{--
        § 312k Abs. 2 S. 3 BGB: the Bestätigungsseite. It names the contract, so
        that what is about to end is unambiguous, and carries one button whose
        wording the statute prescribes. Nothing else is on it that could be
        pressed by mistake.
    --}}
    <h1>{{ __('statamic-payments::portal.cancel_title') }}</h1>
    <p class="lede">{{ __('statamic-payments::portal.cancel_intro') }}</p>

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
                @if ($subscription->starts_at)
                    <tr>
                        <td>{{ __('statamic-payments::portal.cancel_started') }}</td>
                        <td class="num">{{ $subscription->starts_at->translatedFormat(__('statamic-payments::portal.date_format')) }}</td>
                    </tr>
                @endif
                @if ($subscription->next_payment_at)
                    <tr>
                        <td>{{ __('statamic-payments::portal.cancel_next') }}</td>
                        <td class="num">{{ $subscription->next_payment_at->translatedFormat(__('statamic-payments::portal.date_format')) }}</td>
                    </tr>
                @endif
            </tbody>
        </table>
    </div>

    @if ($subscription->isLive())
        <p class="notice">{{ __('statamic-payments::portal.cancel_effect') }}</p>

        <form method="POST" action="{{ route('statamic-payments.portal.cancel.run', ['paySubscription' => $subscription->getKey()]) }}">
            @csrf
            <button type="submit" class="btn">{{ __('statamic-payments::portal.cancel_now') }}</button>
        </form>
    @else
        <p class="notice">{{ __('statamic-payments::portal.cancel_not_live') }}</p>
    @endif

    <div class="foot">
        <a href="{{ route('statamic-payments.portal.show') }}">{{ __('statamic-payments::portal.cancel_abort') }}</a>
    </div>
@endsection

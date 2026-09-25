@extends('statamic-payments::portal.layout')

@section('title', \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::subscriptions.portal_switch_title'))

@section('content')
    <h1>{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::subscriptions.portal_switch_title') }}</h1>
    <p class="lede">{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::subscriptions.portal_switch_intro', [
        'name' => $name,
        'amount' => \Goldnead\StatamicPayments\Portal\Display::money((int) $subscription->amount_cent, $subscription->currency),
    ]) }}</p>

    {{--
        Every choice says what it costs and when: an upgrade today with the
        difference for the rest of the period, a downgrade from the next charge.
        The amount on the page is the one `SubscriptionSwitches::preview()`
        worked out, the same call the button runs.
    --}}
    <form method="POST" action="{{ route('statamic-payments.portal.switch.run', ['paySubscription' => $subscription->getKey()]) }}">
        @csrf
        <div class="block">
            <ul class="list">
                @foreach ($choices as $index => $choice)
                    <li class="entry">
                        <label class="choice">
                            <input type="radio" name="to" value="{{ $choice['handle'] }}" @checked($index === 0) required>
                            <span class="what">
                                <span class="name">{{ $choice['name'] }}</span>
                                <span class="desc">{{ $choice['amount'] }} · {{ $choice['rhythm'] }}</span>
                                <span class="desc">{{ $choice['effect'] }}</span>
                            </span>
                        </label>
                    </li>
                @endforeach
            </ul>
        </div>

        <button type="submit" class="btn">{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::subscriptions.portal_switch_now') }}</button>
    </form>

    <div class="foot">
        <a href="{{ route('statamic-payments.portal.show') }}">{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::subscriptions.portal_back') }}</a>
    </div>
@endsection

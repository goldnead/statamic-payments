@extends('statamic-payments::portal.layout')

@section('title', __('statamic-payments::portal.orders_title'))

@section('content')
    <h1>{{ __('statamic-payments::portal.orders_title') }}</h1>
    <p class="muted">{{ __('statamic-payments::portal.orders_for', ['email' => $email]) }}</p>

    <div class="block">
        <h2>{{ __('statamic-payments::portal.subscriptions_heading') }}</h2>

        @if (empty($subscriptions))
            <p class="hint">{{ __('statamic-payments::portal.subscriptions_none') }}</p>
        @else
            <ul class="list">
                @foreach ($subscriptions as $subscription)
                    {{--
                        Two parts in one item: the contract on one line, what can
                        be done about it under it. The line itself stays a flex
                        row so the amount lands on the right edge like every
                        other amount on these pages; the actions sit outside that
                        row, or they would be squeezed into a column with it.
                    --}}
                    <li class="entry">
                        <div class="row">
                            <span class="what">
                                <span class="name">{{ $subscription['name'] }}</span>
                                <span class="desc">
                                    {{ __('statamic-payments::portal.status_'.$subscription['status']) }}
                                    @if ($subscription['live'] && $subscription['next_payment_at'])
                                        · {{ __('statamic-payments::portal.subscription_next', ['date' => $subscription['next_payment_at']->translatedFormat(__('statamic-payments::portal.date_format'))]) }}
                                    @elseif (! $subscription['live'] && $subscription['cancelled_at'])
                                        · {{ __('statamic-payments::portal.subscription_ended', ['date' => $subscription['cancelled_at']->translatedFormat(__('statamic-payments::portal.date_format'))]) }}
                                    @endif
                                    @if ($subscription['remaining'] !== null)
                                        · {{ __('statamic-payments::portal.subscription_remaining', ['count' => $subscription['remaining']]) }}
                                    @endif
                                </span>
                            </span>
                            <span class="amount">
                                {{ $subscription['amount'] }}
                                <span class="desc quiet">{{ $subscription['rhythm'] }}</span>
                            </span>
                        </div>

                        @if ($subscription['live'])
                            <div class="actions">
                                {{--
                                    § 312k BGB, step one as it appears to somebody
                                    already inside: the button with the wording the
                                    statute prescribes, leading to the confirmation
                                    page and nowhere else. A link, not a form —
                                    pressing it cancels nothing, which is the point
                                    of there being a second page.
                                --}}
                                <a class="btn btn-quiet" href="{{ route('statamic-payments.portal.cancel.confirm', ['paySubscription' => $subscription['id']]) }}">{{ __('statamic-payments::portal.cancel_button') }}</a>

                                @if ($subscription['can_change_method'])
                                    <form method="POST" action="{{ route('statamic-payments.portal.method.start', ['paySubscription' => $subscription['id']]) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-plain">{{ __('statamic-payments::portal.method_button') }}</button>
                                        {{--
                                            The charge is named under the button
                                            that causes it. On Mollie there is no
                                            way to store a card without taking
                                            money, and a buyer should read that
                                            before pressing rather than on their
                                            statement afterwards.
                                        --}}
                                        <p class="hint">{{ __('statamic-payments::portal.method_note', ['amount' => $subscription['verification']]) }}</p>
                                    </form>
                                @endif
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="block">
        <h2>{{ __('statamic-payments::portal.orders_heading') }}</h2>

        @if (empty($orders))
            <p class="hint">{{ __('statamic-payments::portal.orders_none') }}</p>
        @else
            <ul class="list">
                @foreach ($orders as $order)
                    <li class="entry">
                        <div class="row">
                            <span class="what">
                                <span class="name">{{ $order['name'] }}</span>
                                <span class="desc">
                                    @if ($order['paid_at'])
                                        {{ $order['paid_at']->translatedFormat(__('statamic-payments::portal.date_format')) }}
                                    @endif
                                    @if ($order['refunded'])
                                        · {{ __('statamic-payments::portal.order_refunded') }}
                                    @endif
                                    · <a href="{{ route('statamic-payments.portal.order', ['payOrder' => $order['id']]) }}">{{ __('statamic-payments::portal.order_view') }}</a>
                                </span>
                            </span>
                            <span class="amount">{{ $order['amount'] }}</span>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="foot">
        <form method="POST" action="{{ route('statamic-payments.portal.close') }}">
            @csrf
            <button type="submit">{{ __('statamic-payments::portal.sign_out') }}</button>
        </form>
    </div>
@endsection

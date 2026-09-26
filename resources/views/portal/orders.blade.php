@extends('statamic-payments::portal.layout')

@section('title', \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::portal.orders_title'))

@section('content')
    <h1>{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::portal.orders_title') }}</h1>
    <p class="muted">{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::portal.orders_for', ['email' => $email]) }}</p>

    @if ($greeting !== '')
        {{-- The shop's own words (portal.greeting). Plain text: escaped, line breaks kept. --}}
        <p class="lede greeting">{!! nl2br(e($greeting)) !!}</p>
    @endif

    <div class="block">
        <h2>{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::portal.subscriptions_heading') }}</h2>

        @if (empty($subscriptions))
            <p class="hint">{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::portal.subscriptions_none') }}</p>
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
                                    @if ($subscription['paused'])
                                        {{-- One phrase for a pause: "Pausiert bis …" already says the status. --}}
                                        {{ $subscription['resumes_at']
                                            ? \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::subscriptions.portal_paused_until', ['date' => \Goldnead\StatamicPayments\Support\LocalTime::portalDate($subscription['resumes_at'])])
                                            : \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::subscriptions.portal_paused_open') }}
                                    @else
                                        {{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::portal.status_'.$subscription['status']) }}
                                    @endif
                                    @if ($subscription['live'] && $subscription['next_payment_at'])
                                        · {{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::portal.subscription_next', ['date' => \Goldnead\StatamicPayments\Support\LocalTime::portalDate($subscription['next_payment_at'])]) }}
                                    @elseif (($subscription['ending'] ?? null) !== null)
                                        · {{ $subscription['ending'] }}
                                    @endif
                                    @if ($subscription['remaining'] !== null)
                                        · {{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::portal.subscription_remaining', ['count' => $subscription['remaining']]) }}
                                    @endif
                                    @if (($subscription['coupon'] ?? '') !== '')
                                        <span class="desc">{{ $subscription['coupon'] }}</span>
                                    @endif
                                </span>
                            </span>
                            <span class="amount">
                                {{ $subscription['amount'] }}
                                <span class="desc quiet">{{ $subscription['rhythm'] }}</span>
                            </span>
                        </div>

                        @if ($subscription['running'])
                            <div class="actions">
                                {{-- Pausing, resuming and switching come first: they are
                                     the ways to stay, and the cancel button below them
                                     stays exactly where the statute wants it. --}}
                                @if ($subscription['can_resume'])
                                    <form method="POST" action="{{ route('statamic-payments.portal.resume.run', ['paySubscription' => $subscription['id']]) }}">
                                        @csrf
                                        <button type="submit" class="btn">{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::subscriptions.portal_resume_button') }}</button>
                                        <p class="hint">{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::subscriptions.portal_resume_note') }}</p>
                                    </form>
                                @endif

                                @if ($subscription['can_switch'])
                                    <a class="btn btn-plain" href="{{ route('statamic-payments.portal.switch.confirm', ['paySubscription' => $subscription['id']]) }}">{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::subscriptions.portal_switch_button') }}</a>
                                @endif

                                @if ($subscription['can_pause'])
                                    <a class="btn btn-plain" href="{{ route('statamic-payments.portal.pause.confirm', ['paySubscription' => $subscription['id']]) }}">{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::subscriptions.portal_pause_button') }}</a>
                                @endif

                                {{--
                                    § 312k BGB, step one as it appears to somebody
                                    already inside: the button with the wording the
                                    statute prescribes, leading to the confirmation
                                    page and nowhere else. A link, not a form —
                                    pressing it cancels nothing, which is the point
                                    of there being a second page.

                                    Where the product keeps cancellation out of the
                                    portal (P9), the statutory flow without login is
                                    named instead. It is never switched off here.
                                --}}
                                @if ($subscription['can_cancel'])
                                    <a class="btn btn-quiet" href="{{ route('statamic-payments.portal.cancel.confirm', ['paySubscription' => $subscription['id']]) }}">{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::portal.cancel_button') }}</a>
                                @elseif ($subscription['cancel_elsewhere_url'])
                                    <p class="hint">
                                        {{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::subscriptions.portal_cancel_hint') }}
                                        <a href="{{ $subscription['cancel_elsewhere_url'] }}">{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::cancellation.button') }}</a>
                                    </p>
                                @endif

                                @if ($subscription['can_change_method'])
                                    <form method="POST" action="{{ route('statamic-payments.portal.method.start', ['paySubscription' => $subscription['id']]) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-plain">{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::portal.method_button') }}</button>
                                        {{--
                                            The charge is named under the button
                                            that causes it. On Mollie there is no
                                            way to store a card without taking
                                            money, and a buyer should read that
                                            before pressing rather than on their
                                            statement afterwards.
                                        --}}
                                        <p class="hint">{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::portal.method_note', ['amount' => $subscription['verification']]) }}</p>
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
        <h2>{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::portal.orders_heading') }}</h2>

        @if (empty($orders))
            <p class="hint">{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::portal.orders_none') }}</p>
        @else
            <ul class="list">
                @foreach ($orders as $order)
                    <li class="entry">
                        <div class="row">
                            <span class="what">
                                <span class="name">{{ $order['name'] }}</span>
                                <span class="desc">
                                    @if ($order['paid_at'])
                                        {{ \Goldnead\StatamicPayments\Support\LocalTime::portalDate($order['paid_at']) }}
                                    @endif
                                    @if ($order['refunded'])
                                        · {{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::portal.order_refunded') }}
                                    @endif
                                    · <a href="{{ route('statamic-payments.portal.order', ['payOrder' => $order['id']]) }}">{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::portal.order_view') }}</a>
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
            <button type="submit">{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::portal.sign_out') }}</button>
        </form>
    </div>
@endsection

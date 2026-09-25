@extends('statamic-payments::portal.layout')

@section('title', \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::checkout.thanks_expired_title'))

@section('content')
    {{--
        A thank-you link opened after its time (thanks.expires_minutes). Nothing
        here says whether an order exists: the link could be anybody's. What was
        bought is in the buyer's own account, behind the address they bought with.
    --}}
    <h1>{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::checkout.thanks_expired_title') }}</h1>
    <p class="lede">{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::checkout.thanks_expired_body') }}</p>

    @if ($portal)
        <a class="btn" href="{{ $portal }}">{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::checkout.thanks_expired_portal') }}</a>
    @endif
@endsection

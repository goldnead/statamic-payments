@extends('statamic-payments::portal.layout')

@section('title', \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::portal.order_title', ['date' => \Goldnead\StatamicPayments\Support\LocalTime::portalDate($payment->paid_at)]))

@section('content')
    <h1>{{ $name }}</h1>
    <p class="muted">{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::portal.order_title', ['date' => \Goldnead\StatamicPayments\Support\LocalTime::portalDate($payment->paid_at)]) }}</p>

    <div class="block">
        <h2>{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::portal.order_lines') }}</h2>

        {{--
            No header row. The block heading above already says what these are,
            and a `<th>` repeating it produced „POSITIONEN" twice under each
            other — visible only once the page was actually rendered.
        --}}
        <table class="lines">
            <tbody>
                @foreach ($lines as $line)
                    <tr>
                        <td>{{ $line->name ?: $line->product }}@if ($line->quantity > 1) · {{ $line->quantity }}×@endif</td>
                        <td class="num">{{ \Goldnead\StatamicPayments\Portal\Display::money($line->lineTotalCent(), $payment->currency) }}</td>
                    </tr>
                @endforeach
                <tr class="total">
                    <td>{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::portal.order_total') }}</td>
                    <td class="num">{{ \Goldnead\StatamicPayments\Portal\Display::money((int) $payment->amount_cent, $payment->currency) }}</td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="block">
        <h2>{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::portal.order_invoice') }}</h2>

        {{--
            No invoice is a normal state, not a failure: the site may have no
            invoicing addon installed, or the document may not be written yet.
            The page says so plainly rather than showing a button that 404s.
        --}}
        @if ($invoice)
            <a class="btn btn-plain" href="{{ route('statamic-payments.portal.invoice', ['payOrder' => $payment->getKey()]) }}">{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::portal.order_invoice_download', ['number' => $invoice->number]) }}</a>
        @else
            <p class="hint">{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::portal.order_invoice_none') }}</p>
        @endif
    </div>

    <div class="foot">
        <a href="{{ route('statamic-payments.portal.show') }}">{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::portal.order_back') }}</a>
    </div>
@endsection

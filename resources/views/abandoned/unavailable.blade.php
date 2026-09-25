@extends('statamic-payments::portal.layout')

@section('title', \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::abandoned.mail_subject'))

@section('content')
    <p>{{ \Goldnead\StatamicPayments\Support\Anrede::trans('statamic-payments::abandoned.resume_unavailable') }}</p>
@endsection

@extends('statamic-payments::portal.layout')

@section('title', __('statamic-payments::abandoned.mail_subject'))

@section('content')
    <p>{{ __('statamic-payments::abandoned.resume_unavailable') }}</p>
@endsection

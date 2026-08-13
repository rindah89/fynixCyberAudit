@extends('layouts.public')

@section('title', 'Fynix Cyber Audit - Error 402 (Payment Required)')
@section('heading', 'Payment required')

@section('content')
    <div class="ppm-auth__alert ppm-auth__alert--amber" role="alert">
        {{ __('Payment Required') }}
    </div>
    <a href="{{ url('/') }}" class="ppm-btn ppm-btn--secondary">Back home</a>
@endsection

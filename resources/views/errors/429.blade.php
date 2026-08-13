@extends('layouts.public')

@section('title', 'Fynix Cyber Audit - Error 429 (Too Many Requests)')
@section('heading', 'Too many requests')

@section('content')
    <div class="ppm-auth__alert ppm-auth__alert--amber" role="alert">
        {{ __('Too Many Requests') }}
    </div>
    <a href="{{ url('/') }}" class="ppm-btn ppm-btn--secondary">Back home</a>
@endsection

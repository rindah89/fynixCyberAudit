@extends('layouts.public')

@section('title', 'Fynix Cyber Audit - Error 404 (Not Found)')
@section('heading', 'Not found')

@section('content')
    <div class="ppm-auth__alert ppm-auth__alert--info" role="alert">
        {{ __('Not Found') }}
    </div>
    <a href="{{ url('/') }}" class="ppm-btn ppm-btn--primary">Back home</a>
@endsection

@extends('layouts.public')

@section('title', 'Fynix Cyber Audit - Error 403 (Forbidden)')
@section('heading', 'Forbidden')

@section('content')
    <div class="ppm-auth__alert" role="alert">
        {{ config('app.debug') ? $exception->getMessage() : __('Forbidden') }}
    </div>
    <a href="{{ url('/app') }}" class="ppm-btn ppm-btn--primary">Sign in</a>
@endsection

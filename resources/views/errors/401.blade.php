@extends('layouts.public')

@section('title', 'Fynix Cyber Audit - Error 401 (Access Denied)')
@section('heading', 'Access denied')

@section('content')
    <div class="ppm-auth__alert" role="alert">
        {{ config('app.debug') ? $exception->getMessage() : 'Access denied. Please log in to continue.' }}
    </div>
    <a href="{{ url('/app') }}" class="ppm-btn ppm-btn--primary">Sign in</a>
@endsection

@extends('layouts.public')

@section('title', 'Fynix Cyber Audit - Error 500')
@section('heading', 'Something went wrong')

@section('content')
    <div class="ppm-auth__alert" role="alert">
        {{ config('app.debug') ? $exception->getMessage() : 'An unexpected error occurred. Please try again later.' }}
    </div>
    <a href="{{ url('/') }}" class="ppm-btn ppm-btn--secondary">Back home</a>
@endsection

@extends('layouts.public')

@section('title', config('app.name'))
@section('heading', 'Offline for maintenance')

@section('content')
    <div class="ppm-auth__alert ppm-auth__alert--amber" role="alert">
        Fynix Cyber Audit is currently offline for maintenance.
    </div>
@endsection

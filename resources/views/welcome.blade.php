@extends('layouts.public')

@section('title', config('app.name'))
@section('card_class', 'ppm-auth__card--wide')
@section('heading', 'Fynix Cyber Audit')
@section('lede', 'Governance, risk, and compliance for teams that need a quiet control room — not an enterprise tax.')

@section('content')
    <ul class="ps-5 mt-2 space-y-2 list-disc" style="color: var(--gray-700); font-size: var(--text-body); line-height: var(--text-body-lh);">
        <li>Simple interface designed to get up and running with very little training</li>
        <li>Quick imports of common security frameworks</li>
        <li>Ability to connect Standards, Controls, and your actual Implementations</li>
        <li>Ability to perform audits for internal and external assessments</li>
        <li>Report generation capability to create deliverables for auditors</li>
        <li>Intuitive dashboard to display your progress</li>
    </ul>
    <p style="color: var(--gray-700); font-size: var(--text-body); line-height: var(--text-body-lh);">
        Above all, Fynix Cyber Audit is written to solve cyber compliance headaches that tend to be
        caused by complex enterprise solutions. It doesn't have to be that hard!
    </p>
    <div style="display: flex; justify-content: center; margin-top: 8px;">
        <a href="{{ url('/app') }}" class="ppm-btn ppm-btn--primary" id="login-button">Sign in</a>
    </div>
@endsection

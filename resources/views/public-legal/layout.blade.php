@extends('layouts.public-cars')
@section('main-class', 'amd-legal-page')
@section('content')
<header class="amd-legal-heading">
    <div class="amd-section-inner">
        <h1>@yield('legal-title')</h1>
        <p>@yield('legal-intro')</p>
        <nav class="amd-legal-tabs" aria-label="Informative del sito">
            <a href="{{ route('public-site.privacy') }}" @if(request()->routeIs('public-site.privacy')) aria-current="page" @endif>Privacy</a>
            <a href="{{ route('public-site.cookies') }}" @if(request()->routeIs('public-site.cookies')) aria-current="page" @endif>Cookie</a>
        </nav>
    </div>
</header>
<div class="amd-section-inner amd-legal-layout">
    <aside class="amd-legal-index">
        <nav aria-label="In questa pagina"><h2>In questa pagina</h2>@yield('legal-index')</nav>
        <p>Aggiornamento: <time datetime="{{ $privacy['updated_at'] }}">{{ \Carbon\CarbonImmutable::parse($privacy['updated_at'])->format('d/m/Y') }}</time></p>
    </aside>
    <article class="amd-legal-copy" aria-label="@yield('legal-title')">
        @if($draft)
            <div class="amd-legal-draft" role="note">
                <strong>Informativa in preparazione</strong>
                <p>Questa versione descrive le funzioni del sito. I dati indicati come “da completare” e la verifica del titolare sono necessari prima della pubblicazione definitiva.</p>
            </div>
        @endif
        @yield('legal-copy')
    </article>
</div>
@endsection

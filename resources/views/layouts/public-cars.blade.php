<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Cerca auto') | AMD Rent</title>
    <meta name="description" content="Cerca un’auto a noleggio con AMD Rent. Scegli luogo e date, confronta le auto disponibili e prenota con il 20% online. Scopri anche il noleggio a lungo termine.">
    <meta name="robots" content="noindex, follow">
    @vite(['resources/css/public-cars.css', 'resources/css/public-interface.css', 'resources/js/public-cars.js'])
</head>
<body>
    <a class="amd-skip" href="#ricerca-contenuto">Vai al contenuto</a>
    @php
        $routePrefix = $routePrefix ?? 'public-cars';
        $links = [
            ['Cerca auto', route($routePrefix.'.index'), request()->routeIs('public-cars.*')],
            ['Lungo termine', route('public-site.long-term'), request()->routeIs('public-site.long-term*')],
            ['Come funziona', route('public-site.how-it-works'), request()->routeIs('public-site.how-it-works')],
            ['Assistenza', route('public-site.support'), request()->routeIs('public-site.support')],
            [auth('public_customer')->check() ? 'La mia area' : 'Area cliente', route(auth('public_customer')->check() ? 'public-account.bookings' : 'public-account.login'), request()->routeIs('public-account.*')],
        ];
    @endphp
    <header class="amd-header">
        <div class="amd-header-inner">
            <a href="{{ route($routePrefix.'.index') }}" class="amd-logo" aria-label="AMD Rent, cerca auto"><img src="{{ asset('images/amd-rent-logo-v2.png') }}" width="1937" height="812" alt="AMD Rent" fetchpriority="high"></a>
            <nav class="amd-desktop-nav" aria-label="Menu principale">
                @include('public-cars.partials.navigation')
            </nav>
            <div class="amd-mobile-nav">
                <button class="amd-mobile-toggle" type="button" aria-expanded="false" aria-controls="amd-mobile-menu" hidden><span class="amd-menu-icon" aria-hidden="true"></span><span>Menu</span></button>
                <nav id="amd-mobile-menu" aria-label="Menu principale mobile" hidden>
                    @include('public-cars.partials.navigation')
                </nav>
            </div>
        </div>
        <noscript><nav class="amd-nojs-nav" aria-label="Menu senza JavaScript">@include('public-cars.partials.navigation')</nav></noscript>
    </header>
    <p class="amd-live-status" data-search-status role="status" aria-live="polite" aria-atomic="true"></p>
    <main id="ricerca-contenuto" class="@yield('main-class', 'amd-main')" tabindex="-1">
        @yield('content')
    </main>
    <footer class="amd-footer">
        <div class="amd-footer-inner">
            <div class="amd-footer-about">
                <a class="amd-footer-brand" href="{{ route($routePrefix.'.index') }}">AMD Rent</a>
                <p>Il tuo viaggio, la tua auto.<br>Scegli il luogo, confronta le condizioni e parti con le idee chiare.</p>
                <p class="amd-footer-payment">Noleggio auto: 20% online, saldo al ritiro.</p>
            </div>
            <nav class="amd-footer-column" aria-label="Esplora AMD Rent">
                <h2>Esplora</h2>
                <ul>
                    <li><a href="{{ route($routePrefix.'.index') }}">Cerca auto</a></li>
                    <li><a href="{{ route($routePrefix.'.index') }}#luoghi-di-ritiro">Luoghi di ritiro</a></li>
                    <li><a href="{{ route('public-site.long-term') }}">Noleggio a lungo termine</a></li>
                    <li><a href="{{ route('public-site.how-it-works') }}">Come funziona</a></li>
                    <li><a href="{{ route('public-account.bookings') }}">Area cliente</a></li>
                </ul>
            </nav>
            <nav class="amd-footer-column" aria-label="Informazioni per prenotare">
                <h2>Prima di partire</h2>
                <ul>
                    <li><a href="{{ route('public-site.how-it-works') }}#prezzi-e-condizioni">Prezzi e condizioni</a></li>
                    <li><a href="{{ route('public-site.how-it-works') }}#conferma-e-ritiro">Conferma e ritiro</a></li>
                    <li><a href="{{ route($routePrefix.'.index') }}#domande-frequenti">Domande frequenti</a></li>
                </ul>
            </nav>
            <div class="amd-footer-column amd-footer-help">
                <h2>Serve una mano?</h2>
                <p>Hai già prenotato? Tieni a portata di mano il riferimento della prenotazione.</p>
                <a href="{{ route('public-site.support') }}">Informazioni e assistenza</a>
                @if(config('public_cars.contact_email') || config('public_cars.contact_phone'))
                    <address>
                        @if(config('public_cars.contact_email'))<a href="mailto:{{ config('public_cars.contact_email') }}">{{ config('public_cars.contact_email') }}</a>@endif
                        @if(config('public_cars.contact_phone'))<a href="tel:{{ preg_replace('/[^+0-9]/', '', config('public_cars.contact_phone')) }}">{{ config('public_cars.contact_phone') }}</a>@endif
                    </address>
                @endif
            </div>
        </div>
        <div class="amd-footer-bottom">
            <p>{{ config('public_cars.legal_notice') ?: '© '.now()->year.' AMD Rent' }}</p>
            @if(config('public_cars.privacy_url'))<a href="{{ config('public_cars.privacy_url') }}">Informativa privacy</a>@endif
            <a href="{{ route($routePrefix.'.index') }}#car-search">Torna alla ricerca</a>
        </div>
    </footer>
</body>
</html>

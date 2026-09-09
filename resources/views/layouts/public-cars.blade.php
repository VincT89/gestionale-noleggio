<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Cerca auto') | AMD Mobility</title>
    <meta name="description" content="Cerca un’auto disponibile per il tuo periodo, la località e il budget. Scopri le offerte di noleggio AMD Mobility.">
    <meta name="robots" content="noindex, follow">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:ital,wght@0,300;0,400;0,500;0,600;0,700;1,500&display=swap" rel="stylesheet">
    @vite(['resources/css/public-cars.css', 'resources/js/public-cars.js'])
</head>
<body>
    <a class="amd-skip" href="#ricerca-contenuto">Vai al contenuto</a>
    @php
        $website = config('public_cars.website_url');
        $links = [
            ['Home', $website.'/'], ['Catalogo auto', $website.'/auto-economy/'],
            ['Cerca auto', route($routePrefix.'.index')], ['Veicoli commerciali', $website.'/auto-medium/'],
            ['Chi Siamo', $website.'/our-story/'], ['Partners', $website.'/#partners'],
            ['Diventa Segnalatore', $website.'/diventa-segnalatore/'],
            ['Richiedi Preventivo', $website.'/contact-us/'], ['Login', route('login')],
        ];
    @endphp
    <header class="amd-header">
        <div class="amd-header-inner">
            <a href="{{ $website }}/" class="amd-logo"><img src="{{ asset('images/amd-site-logo.jpg') }}" width="1366" height="573" alt="AMD Mobility" fetchpriority="high"></a>
            <nav class="amd-desktop-nav" aria-label="Menu principale">
                @include('public-cars.partials.navigation', ['navigationId' => 'desktop'])
            </nav>
            <div class="amd-mobile-nav">
                <button class="amd-mobile-toggle" type="button" aria-expanded="false" aria-controls="amd-mobile-menu"><span class="amd-menu-icon" aria-hidden="true"></span><span class="amd-visually-hidden">Menu</span></button>
                <nav id="amd-mobile-menu" aria-label="Menu principale mobile" hidden>
                    @include('public-cars.partials.navigation', ['navigationId' => 'mobile'])
                </nav>
            </div>
        </div>
    </header>
    <main id="ricerca-contenuto" class="@yield('main-class', 'amd-main')">
        @yield('content')
    </main>
    <footer class="amd-footer">
        <div class="amd-footer-inner">
            <a class="amd-footer-brand" href="{{ $website }}/">AMD Mobility</a>
            <nav aria-label="Menu del footer"><a href="{{ $website }}/politiche-del-siito/">Politiche del sito</a></nav>
            <p class="amd-copyright">AMD MOBILITY SRLS - P.IVA 08952480724 - © 2022 All Rights Reserved</p>
        </div>
    </footer>
</body>
</html>

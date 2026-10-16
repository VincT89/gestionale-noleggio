<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'AMD Mobility') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />
        <script src="https://kit.fontawesome.com/a9ab42b9cf.js" crossorigin="anonymous"></script>

        <!-- Scripts -->
        <link rel="stylesheet" href="{{ asset('era-classic/app-6If3uo48.css') }}">
        <link rel="stylesheet" href="{{ asset('era-classic/compatibility.css') }}">
        @vite('resources/js/app.js')

        <!-- Styles -->
        @livewireStyles
    </head>
    <body
        data-era-interface="classic"
        x-data="{ isOpen: false, openSection: null }"
        @keydown.escape.window="if (isOpen) { isOpen = false; $refs.sidebarToggle.focus() }"
        class="font-sans antialiased flex h-screen overflow-hidden"
    >
        {{-- Toast globali (Livewire + Alpine) --}}
        <x-ui.toast />

        {{-- Portal target per dropdown filtri/ordinamento tabelle --}}
        <div id="portal-target"></div>

        <button type="button" x-cloak x-show="isOpen"
            @click="isOpen = false; $refs.sidebarToggle.focus()"
            class="era-sidebar-backdrop" aria-label="Chiudi menu laterale" tabindex="-1"></button>

        {{-- Su smartphone il menu si sovrappone senza comprimere il contenuto. --}}
        <div class="era-sidebar-shell relative flex-shrink-0 flex flex-col">
            {{-- Sidebar component --}}
            <x-sidebar />

            {{-- Toggle button “agganciato” alla sidebar --}}
            <button
                type="button"
                x-ref="sidebarToggle"
                x-cloak
                @click="isOpen = !isOpen"
                class="absolute top-1/2 transform -translate-y-1/2 p-2 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-full shadow z-20 transition-all duration-150"
                :style="{ left: isOpen ? '100%' : '0' }"
                :aria-label="isOpen ? 'Chiudi sidebar' : 'Apri sidebar'"
                :aria-expanded="isOpen.toString()"
                aria-controls="era-classic-sidebar"
            >
                <i :class="isOpen ? 'fas fa-angle-left' : 'fas fa-angle-right'"></i>
            </button>

        </div>

        {{-- Contenuto principale --}}
        <div id="main-content" class="flex-1 flex flex-col bg-gray-100 dark:bg-gray-900 overflow-auto">
            <x-banner />
            @livewire('navigation-menu')

            @if (isset($header))
                <header class="nav-color dark:bg-gray-800 shadow">
                    <div class="max-w-7xl mx-auto py-4 px-2 sm:px-4 lg:px-6">
                        {{ $header }}
                    </div>
                </header>
            @endif

            <main class="flex-1 p-6">
                {{ $slot }}
            </main>
        </div>

        @stack('modals')
        @stack('scripts')
        @livewireScripts
    </body>
</html>

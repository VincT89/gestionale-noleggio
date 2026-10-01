{{-- resources/views/components/sidebar.blade.php --}}

<?php
/**
 * Navigazione del gestionale AMD Mobility.
 *
 * - Accordion “una sola sezione aperta” usando `openSection` dal layout
 * - Nessun `x-data` proprio: eredita `isOpen` e `openSection` dal genitore
 * - Su mobile il contenitore del layout si sovrappone al contenuto
 */
?>
@php
    $sidebarSections = config('menu.sidebar');
    $isSidebarItemActive = fn (array $item) => request()->routeIs(...(array) ($item['active'] ?? $item['route']));
    $activeSection = null;
    foreach ($sidebarSections as $sectionIndex => $section) {
        foreach ($section['items'] as $item) {
            if ((empty($item['permission']) || auth()->user()->can($item['permission'])) && $isSidebarItemActive($item)) {
                $activeSection = $sectionIndex;
                break 2;
            }
        }
    }
@endphp
<aside
    id="app-sidebar"
    aria-label="Navigazione principale"
    x-cloak
    x-show="isOpen"
    x-init="openSection = @js($activeSection)"
    x-transition:enter="transition-all duration-200"
    x-transition:leave="transition-all duration-200"
    :class="isOpen ? 'w-64' : 'w-0'"
    class="h-full overflow-y-auto overscroll-contain bg-white dark:bg-gray-800 border-r dark:border-gray-700 flex flex-col"
>
    {{-- Spazio in testa (logo, padding) --}}
    <div class="h-16 shrink-0 flex items-center justify-center">
        
    </div>

    {{-- Link rapido alla Dashboard --}}
    <div class="border-b border-gray-200 dark:border-gray-700">
        @php $dashboardActive = request()->routeIs('dashboard'); @endphp
        <a
            href="{{ route('dashboard') }}"
            @if($dashboardActive) aria-current="page" @endif
            class="block px-8 py-2 text-sm transition-colors font-semibold
                   {{ $dashboardActive
                        ? 'bg-gray-200 dark:bg-gray-700 text-gray-900 dark:text-gray-100'
                        : 'text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700' }}"
        >
            <div class="flex items-center">
                <span class="font-semibold">{{ __('Dashboard') }}</span>
            </div>
        </a>
    </div>

    {{-- Menu a fisarmonica: itero solo le sezioni con almeno una voce accessibile --}}
    @foreach($sidebarSections as $i => $section)
        @php
            // filtro gli items cui l'utente ha effettivo accesso
            $accessible = collect($section['items'])
                ->filter(fn($item) => empty($item['permission']) || auth()->user()->can($item['permission']));
        @endphp

        @if($accessible->isEmpty())
            @continue
        @endif

        <div class="border-b border-gray-200 dark:border-gray-700">
            {{-- Intestazione sezione --}}
            <button
                type="button"
                @click="openSection = (openSection === {{ $i }} ? null : {{ $i }})"
                :aria-expanded="(openSection === {{ $i }}).toString()"
                aria-controls="sidebar-section-{{ $i }}"
                class="w-full flex justify-between items-center px-6 py-3 hover:bg-gray-100 dark:hover:bg-gray-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600"
            >
                <span class="font-semibold text-gray-700 dark:text-gray-200">
                    {{ __($section['section']) }}
                </span>
                <svg class="w-4 h-4 shrink-0 text-gray-500 dark:text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"
                     :style="openSection === {{ $i }} ? 'transform: rotate(180deg)' : ''"><path d="m6 9 6 6 6-6" stroke-linecap="round" stroke-linejoin="round" /></svg>
            </button>

            {{-- Voci accessibili della sezione --}}
            <ul
                id="sidebar-section-{{ $i }}"
                x-show="openSection === {{ $i }}"
                x-transition
                class="space-y-1 bg-gray-50 dark:bg-gray-900"
            >
                @foreach($accessible as $item)
                    @php
                        $isActive = $isSidebarItemActive($item);
                    @endphp
                    <li>
                        <a
                            href="{{ route($item['route']) }}"
                            @if($isActive) aria-current="page" @endif
                            @if(!empty($item['new_tab'])) target="_blank" rel="noopener noreferrer" @endif
                            class="block px-8 py-2 text-sm transition-colors
                                   {{ $isActive
                                        ? 'bg-gray-200 dark:bg-gray-700 font-medium text-gray-900 dark:text-gray-100'
                                        : 'text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700' }}"
                        >
                            {{ __($item['label']) }}
                            @if(!empty($item['new_tab']))<span class="sr-only"> (si apre in una nuova scheda)</span>@endif
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    @endforeach

</aside>

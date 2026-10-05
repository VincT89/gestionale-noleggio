@extends('layouts.public-cars')
@section('title', 'Cerca e prenota un’auto')
@section('main-class', 'amd-search-page')
@section('content')
@php
    $value = function ($name, $default = '') use ($filters) {
        $input = old($name, $filters[$name] ?? $default);
        return is_scalar($input) ? (string) $input : '';
    };
    $money = fn ($cents) => number_format($cents / 100, 2, ',', '.').' €';
    $baseFilters = array_filter(\Illuminate\Support\Arr::only($filters, ['pickup_at', 'return_at', 'place_id', 'city', 'request_delivery', 'delivery_address', 'delivery_place']), fn ($v) => $v !== null && $v !== '');
    $customPickup = $value('request_delivery') === '1';
    $destinationValue = $value('destination', $value('place_id') ?: ($value('city') ? 'city:'.$value('city') : ''));
    $knownDestination = $destinations->contains(fn ($destination) => mb_strtolower($destination['value']) === mb_strtolower($destinationValue));
@endphp
@if($searched)
<section class="amd-search-band amd-search-band--results" aria-labelledby="search-title">
    <div class="amd-search-band-inner">
        <div class="amd-page-heading"><h1 id="search-title">La tua ricerca</h1><p>Il tuo viaggio prende forma. Confronta le proposte e scegli come partire.</p></div>
            <div class="amd-search-recap" data-search-recap hidden>
                <div id="public-search-recap">
                    <strong>{{ $customPickup ? $value('delivery_address') : ($selectedPlace?->label ?? $value('city')) }}</strong>
                    <span>{{ \Carbon\CarbonImmutable::parse($filters['pickup_at'])->format('d/m/Y H:i') }} – {{ \Carbon\CarbonImmutable::parse($filters['return_at'])->format('d/m/Y H:i') }}</span>
                    @if($customPickup)<span>Riconsegna: {{ $selectedPlace?->label ?? ($value('city') ?: 'da scegliere') }}</span>@endif
                </div>
                <button class="amd-button amd-button-secondary" type="button" data-search-toggle aria-controls="car-search" aria-expanded="true">Modifica ricerca</button>
            </div>

        @include('public-cars.partials.search-form')
    </div>
</section>
@else
<section class="amd-home-hero" aria-labelledby="search-title">
    <div class="amd-home-hero-layout">
        <div class="amd-home-intro">
            <h1 id="search-title">Il viaggio è tuo.<br><span>Parti come vuoi.</span></h1>
            <div class="amd-home-intro-copy"><p>Dall’aeroporto al tuo hotel.<br> Trova l’auto, scegli la tua partenza.</p><a class="amd-home-search-link" href="#car-search">Trova la tua auto</a></div>
        </div>
        <div class="amd-scene-track" data-scene-track>
            <div class="amd-scene-sticky">
                @include('public-cars.partials.home-scene')
                <div class="amd-home-search-panel">@include('public-cars.partials.search-form')</div>
            </div>
        </div>
    </div>
</section>
@endif
<div class="{{ $searched ? 'amd-main amd-results-main' : 'amd-home-sections' }}" @if($searched) id="public-search-results" data-search-url="{{ route($routePrefix.'.index') }}" @endif>
@if($searched)
    @if($deliveryLookupNeeded)
        @include('public-cars.partials.delivery-place-choices')
    @elseif($showDeliverySuppliers)
        @include('public-cars.partials.delivery-suppliers')
    @else
    @if($deliveryPoint)<a class="amd-back" href="{{ route($routePrefix.'.index', $baseFilters) }}">Torna ai noleggiatori vicini</a>@endif
    <div class="amd-results-layout">
        <aside class="amd-filters" aria-label="Filtri delle auto">
            <details class="amd-filter-disclosure" open data-filter-disclosure>
                <summary>Filtra le auto</summary>
                <form method="get" action="{{ route($routePrefix.'.index') }}" class="amd-filter-form" data-live-search>
                    @foreach($baseFilters as $key => $filterValue)<input type="hidden" name="{{ $key }}" value="{{ $filterValue }}">@endforeach
                    <input type="hidden" name="sort" value="{{ $value('sort', 'price_asc') }}">
                    <div class="amd-field"><label for="budget">Budget massimo totale</label><div class="amd-money-input"><input id="budget" name="budget" type="number" min="0" max="1000000" step="0.01" inputmode="decimal" value="{{ $value('budget') }}" placeholder="Nessun limite" aria-describedby="budget-help"><span aria-hidden="true">€</span></div><small id="budget-help">Per tutto il periodo, cauzione esclusa.</small></div>
                    <div class="amd-field"><label for="supplier">Noleggiatore</label><select id="supplier" name="supplier"><option value="">Tutti i noleggiatori</option>@foreach($suppliers as $supplier)<option value="{{ $supplier->id }}" @selected($value('supplier') === (string) $supplier->id)>{{ $supplier->name }}</option>@endforeach</select></div>
                    <div class="amd-field"><label for="segment">Categoria</label><select id="segment" name="segment"><option value="">Tutte le categorie</option>@foreach($segments as $segment)<option value="{{ $segment }}" @selected(mb_strtolower($value('segment')) === mb_strtolower($segment))>{{ $segment }}</option>@endforeach</select></div>
                    <div class="amd-field"><label for="transmission">Cambio</label><select id="transmission" name="transmission"><option value="">Qualsiasi</option>@foreach(\App\Models\Vehicle::TRANSMISSION_LABELS_IT as $key => $label)<option value="{{ $key }}" @selected($value('transmission') === $key)>{{ $label }}</option>@endforeach</select></div>
                    <div class="amd-field"><label for="fuel-type">Alimentazione</label><select id="fuel-type" name="fuel_type"><option value="">Qualsiasi</option>@foreach(\App\Models\Vehicle::FUEL_TYPE_LABELS_IT as $key => $label)<option value="{{ $key }}" @selected($value('fuel_type') === $key)>{{ $label }}</option>@endforeach</select></div>
                    <div class="amd-field"><label for="seats">Posti minimi</label><input id="seats" name="seats" type="number" min="1" max="20" step="1" value="{{ $value('seats') }}" placeholder="Qualsiasi"></div>
                    <div class="amd-field"><label for="car-query">Marca o modello</label><input id="car-query" name="q" type="search" maxlength="100" value="{{ $value('q') }}" placeholder="Cerca marca o modello"></div>
                    <button class="amd-button" type="submit">Applica filtri</button><a class="amd-reset" href="{{ route($routePrefix.'.index', $baseFilters) }}">Rimuovi filtri</a>
                </form>
            </details>
        </aside>
        <section class="amd-results" aria-labelledby="results-title">
            <div class="amd-results-heading"><div><h2 id="results-title">{{ $results->total() === 1 ? '1 auto disponibile' : $results->total().' auto disponibili' }}</h2>@if($selectedPlace)<p class="amd-results-place">{{ $selectedPlace->name }}@if($selectedPlace->city), {{ $selectedPlace->city }}@endif</p>@elseif($value('city'))<p class="amd-results-place">{{ $value('city') }} — Tutti i punti di ritiro</p>@endif</div>
                <form method="get" action="{{ route($routePrefix.'.index') }}" class="amd-sort" data-live-search>
                    @foreach($filters as $key => $filterValue) @if(!in_array($key, ['sort','page']) && $filterValue !== null)<input type="hidden" name="{{ $key }}" value="{{ $filterValue }}">@endif @endforeach
                    <label for="sort">Ordina per</label><select id="sort" name="sort"><option value="price_asc" @selected(($filters['sort'] ?? '') !== 'price_desc')>Prezzo crescente</option><option value="price_desc" @selected(($filters['sort'] ?? '') === 'price_desc')>Prezzo decrescente</option></select><button class="amd-button amd-button-secondary" type="submit">Ordina</button>
                </form>
            </div>
            <p class="amd-result-period">Dal {{ \Carbon\CarbonImmutable::parse($filters['pickup_at'])->format('d/m/Y H:i') }} al {{ \Carbon\CarbonImmutable::parse($filters['return_at'])->format('d/m/Y H:i') }}. Prezzi per l’intero periodo.</p>
            @if($customPickup)<p class="amd-result-period">Ritiro richiesto: <strong>{{ $value('delivery_address') }}</strong>. Consegna e supplemento devono essere confermati. I prezzi indicati comprendono il noleggio; l’eventuale supplemento sarà nella proposta.</p>@endif
            <div class="amd-car-grid">
            @forelse($results as $car)
                <article class="amd-car" aria-labelledby="car-{{ $car['id'] }}">
                    <figure class="amd-car-photo">@if($car['has_photo'])<img src="{{ route($routePrefix.'.photo', $car['id']) }}" alt="{{ $car['photo_is_reference'] ? 'Immagine indicativa: '.$car['title'] : $car['title'] }}" loading="lazy" width="360" height="240">@if($car['photo_is_reference'])<figcaption>Immagine indicativa del modello</figcaption>@endif @else<span>Foto non disponibile</span>@endif</figure>
                    <div class="amd-car-info"><h3 id="car-{{ $car['id'] }}">{{ $car['product_name'] ?? $car['title'] }}</h3>
                        @if($car['product_name'] !== null && $car['product_name'] !== $car['title'])<p>{{ $car['title'] }}</p>@endif
                        <p class="amd-car-provider">Noleggiatore: <strong>{{ $car['organization'] }}</strong></p>
                        <dl class="amd-specs">@if($car['seats'])<div><dt>Posti</dt><dd>{{ $car['seats'] }}</dd></div>@endif @if($car['transmission'])<div><dt>Cambio</dt><dd>{{ $car['transmission'] }}</dd></div>@endif @if($car['fuel'])<div><dt>Alimentazione</dt><dd>{{ $car['fuel'] }}</dd></div>@endif</dl>
                        <div class="amd-car-pickup"><span>{{ $customPickup ? 'Riconsegna' : 'Punto di ritiro' }}</span><p>@if($customPickup && empty($filters['place_id']))Da scegliere tra i punti serviti@else{{ $car['location'] }}@if($car['city']), {{ $car['city'] }}@endif @endif</p></div>
                        <p class="amd-car-mileage">{{ $car['km_per_day'] === null ? 'Chilometraggio illimitato' : $car['km_per_day'].' km inclusi al giorno' }}</p>
                    </div>
                    <div class="amd-car-price"><span>Totale per {{ $car['days'] }} {{ $car['days'] === 1 ? 'giorno' : 'giorni' }}</span><strong>{{ $money($car['total_cents']) }}</strong><small>{{ $car['prices_include_vat'] ? 'IVA inclusa' : 'Importo di listino, IVA da verificare' }}</small><small>Cauzione separata: {{ $money($car['deposit_cents']) }}</small><small>20% online con Stripe, saldo al ritiro</small><a class="amd-button" href="{{ route($routePrefix.'.show', ['pricelist' => $car['id']] + ($customPickup ? $filters : array_replace($filters, ['place_id' => $car['place_id']]))) }}">Vedi auto e condizioni</a></div>
                </article>
            @empty
                <div class="amd-empty"><h3>Nessuna auto disponibile con questi criteri</h3><p>Prova altre date o rimuovi i filtri. Puoi anche scegliere un altro luogo di consegna.</p><a class="amd-button amd-button-secondary" href="{{ route($routePrefix.'.index', $baseFilters) }}">Rimuovi filtri</a><a class="amd-empty-edit" href="#car-search">Modifica luogo o date</a></div>
            @endforelse
            </div>
            @if($results->hasPages())<nav class="amd-pagination" aria-label="Pagine dei risultati">@if(!$results->onFirstPage())<a href="{{ $results->previousPageUrl() }}">Pagina precedente</a>@endif<span>Pagina {{ $results->currentPage() }} di {{ $results->lastPage() }}</span>@if($results->hasMorePages())<a href="{{ $results->nextPageUrl() }}">Pagina successiva</a>@endif</nav>@endif
        </section>
    </div>
    @endif
@else
    @include('public-cars.partials.home-guide')
@endif
</div>
@endsection

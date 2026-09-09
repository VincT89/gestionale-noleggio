@extends('layouts.public-cars')
@section('title', 'Cerca auto disponibili')
@section('main-class', 'amd-search-page')
@section('content')
@php
    $startDefault = now()->addDay()->setTime(10, 0)->format('Y-m-d\TH:i');
    $endDefault = now()->addDays(4)->setTime(10, 0)->format('Y-m-d\TH:i');
    $value = function ($name, $default = '') use ($filters) {
        $input = old($name, $filters[$name] ?? $default);
        return is_scalar($input) ? (string) $input : '';
    };
    $money = fn($cents) => number_format($cents / 100, 2, ',', '.').' €';
    $hasCharacteristics = collect(['q','seats','segment','transmission','fuel_type'])->contains(fn($key) => filled($value($key)));
@endphp
<section class="amd-search-band" aria-label="Trova un’auto disponibile">
<div class="amd-search-band-inner">
<div class="amd-page-heading">
    <h1>Cerca un’auto</h1>
    <p>Scegli dove e quando partire. Trova le auto disponibili per il tuo periodo e il tuo budget.</p>
</div>
<form class="amd-search" method="get" action="{{ route($routePrefix.'.index') }}" aria-label="Ricerca auto disponibili">
    @if($errors->any())
        <div class="amd-errors" role="alert"><strong>Controlla i dati della ricerca.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif
    <div class="amd-search-primary">
        <div class="amd-field"><label for="pickup-at">Ritiro</label><input id="pickup-at" name="pickup_at" type="datetime-local" required value="{{ $value('pickup_at', $startDefault) }}" min="{{ now()->format('Y-m-d\TH:i') }}" @if($errors->has('pickup_at')) aria-invalid="true" @endif></div>
        <div class="amd-field"><label for="return-at">Riconsegna</label><input id="return-at" name="return_at" type="datetime-local" required value="{{ $value('return_at', $endDefault) }}" min="{{ now()->format('Y-m-d\TH:i') }}" @if($errors->has('return_at')) aria-invalid="true" @endif></div>
        <div class="amd-field"><label for="city">Località di ritiro</label><select id="city" name="city"><option value="">Tutte le località</option>@foreach($cities as $city)<option value="{{ $city }}" @selected(mb_strtolower($value('city')) === mb_strtolower($city))>{{ $city }}</option>@endforeach</select></div>
        <div class="amd-field"><label for="budget">Budget massimo totale</label><div class="amd-money-input"><input id="budget" name="budget" type="number" min="0" max="1000000" step="0.01" inputmode="decimal" value="{{ $value('budget') }}" placeholder="Nessun limite" aria-describedby="budget-help"><span aria-hidden="true">€</span></div><small id="budget-help">Per tutto il periodo, cauzione esclusa.</small></div>
    </div>
    <details class="amd-characteristics" @if($hasCharacteristics) open @endif>
        <summary>Caratteristiche dell’auto</summary>
        <div class="amd-search-secondary">
            <div class="amd-field"><label for="segment">Categoria</label><select id="segment" name="segment"><option value="">Tutte le categorie</option>@foreach($segments as $segment)<option value="{{ $segment }}" @selected(mb_strtolower($value('segment')) === mb_strtolower($segment))>{{ $segment }}</option>@endforeach</select></div>
            <div class="amd-field"><label for="seats">Posti minimi</label><input id="seats" name="seats" type="number" min="1" max="20" step="1" value="{{ $value('seats') }}" placeholder="Qualsiasi"></div>
            <div class="amd-field"><label for="transmission">Cambio</label><select id="transmission" name="transmission"><option value="">Qualsiasi</option>@foreach(\App\Models\Vehicle::TRANSMISSION_LABELS_IT as $key => $label)<option value="{{ $key }}" @selected($value('transmission') === $key)>{{ $label }}</option>@endforeach</select></div>
            <div class="amd-field"><label for="fuel-type">Alimentazione</label><select id="fuel-type" name="fuel_type"><option value="">Qualsiasi</option>@foreach(\App\Models\Vehicle::FUEL_TYPE_LABELS_IT as $key => $label)<option value="{{ $key }}" @selected($value('fuel_type') === $key)>{{ $label }}</option>@endforeach</select></div>
            <div class="amd-field amd-model-field"><label for="car-query">Marca o modello</label><input id="car-query" name="q" type="search" maxlength="100" value="{{ $value('q') }}" placeholder="Cerca una marca o un modello"></div>
        </div>
    </details>
    <div class="amd-search-actions"><p>Ritiro e riconsegna nella stessa sede. Orari italiani.</p><div><a class="amd-reset" href="{{ route($routePrefix.'.index') }}">Azzera filtri</a><button class="amd-button" type="submit">Cerca auto disponibili</button></div></div>
    <input type="hidden" name="sort" value="{{ $value('sort', 'price_asc') }}">
</form>
</div>
</section>
<div class="amd-main amd-results-main">
@if($searched)
    <section class="amd-results" aria-labelledby="results-title">
        <div class="amd-results-heading"><h2 id="results-title">{{ $results->total() === 1 ? '1 auto disponibile' : $results->total().' auto disponibili' }}</h2>
            @if($results->total() > 1)
            <form method="get" action="{{ route($routePrefix.'.index') }}" class="amd-sort">
                @foreach($filters as $key => $filterValue) @if(!in_array($key, ['sort','page']) && $filterValue !== null)<input type="hidden" name="{{ $key }}" value="{{ $filterValue }}">@endif @endforeach
                <label for="sort">Ordina per</label><select id="sort" name="sort"><option value="price_asc" @selected(($filters['sort'] ?? '') !== 'price_desc')>Prezzo crescente</option><option value="price_desc" @selected(($filters['sort'] ?? '') === 'price_desc')>Prezzo decrescente</option></select><button class="amd-button amd-button-secondary" type="submit">Ordina</button>
            </form>
            @endif
        </div>
        <p class="amd-result-period">Dal {{ \Carbon\CarbonImmutable::parse($filters['pickup_at'])->format('d/m/Y H:i') }} al {{ \Carbon\CarbonImmutable::parse($filters['return_at'])->format('d/m/Y H:i') }}. Importi per l’intero periodo.</p>
        @forelse($results as $car)
            <article class="amd-car" aria-labelledby="car-{{ $car['id'] }}">
                <figure class="amd-car-photo">
                    @if($car['has_photo'])
                        <img src="{{ route($routePrefix.'.photo', $car['id']) }}" alt="{{ $car['photo_is_reference'] ? 'Immagine indicativa: '.$car['title'] : $car['title'] }}" loading="lazy" width="360" height="240">
                        @if($car['photo_is_reference'])<figcaption>Immagine indicativa del modello</figcaption>@endif
                    @else<span>Foto non disponibile</span>@endif
                </figure>
                <div class="amd-car-info"><h3 id="car-{{ $car['id'] }}">{{ $car['title'] }}</h3><p class="amd-car-location">{{ $car['city'] }} — {{ $car['location'] }}</p><p class="amd-car-provider">Offerta da {{ $car['organization'] }}</p>
                    <dl class="amd-specs">@if($car['seats'])<div><dt>Posti</dt><dd>{{ $car['seats'] }}</dd></div>@endif @if($car['transmission'])<div><dt>Cambio</dt><dd>{{ $car['transmission'] }}</dd></div>@endif @if($car['fuel'])<div><dt>Alimentazione</dt><dd>{{ $car['fuel'] }}</dd></div>@endif</dl>
                </div>
                <div class="amd-car-price"><span>Totale {{ $car['days'] }} {{ $car['days'] === 1 ? 'giorno' : 'giorni' }}</span><strong>{{ $money($car['total_cents']) }}</strong><small>{{ $car['prices_include_vat'] ? 'IVA inclusa' : 'Importo di listino, IVA da verificare' }}</small><small>Cauzione separata: {{ $money($car['deposit_cents']) }}</small><a class="amd-button" href="{{ route($routePrefix.'.show', ['offer' => $car['id']] + $filters) }}">Vedi auto e condizioni</a></div>
            </article>
        @empty
            <div class="amd-empty"><h3>Nessuna auto disponibile con questi criteri</h3><p>Prova a cambiare periodo, località, budget o caratteristiche dell’auto.</p><a href="{{ config('public_cars.website_url') }}/contact-us/">Contattaci per verificare altre soluzioni</a></div>
        @endforelse
        @if($results->hasPages())<nav class="amd-pagination" aria-label="Pagine dei risultati">@if(!$results->onFirstPage())<a href="{{ $results->previousPageUrl() }}">Pagina precedente</a>@endif<span>Pagina {{ $results->currentPage() }} di {{ $results->lastPage() }}</span>@if($results->hasMorePages())<a href="{{ $results->nextPageUrl() }}">Pagina successiva</a>@endif</nav>@endif
    </section>
@else
    <div class="amd-search-hint"><h2>Un’auto per il periodo che scegli</h2><p>Indica le date per verificare la disponibilità. Nei risultati troverai il prezzo totale, la sede e le caratteristiche di ogni auto.</p></div>
@endif
</div>
@endsection

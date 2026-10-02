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
    $baseFilters = array_filter(\Illuminate\Support\Arr::only($filters, ['pickup_at', 'return_at', 'place_id', 'city', 'request_delivery', 'delivery_address']), fn ($v) => $v !== null && $v !== '');
    $customPickup = $value('request_delivery') === '1';
    $destinationValue = $value('destination', $value('place_id') ?: ($value('city') ? 'city:'.$value('city') : ''));
    $knownDestination = $destinations->contains(fn ($destination) => mb_strtolower($destination['value']) === mb_strtolower($destinationValue));
@endphp
<section class="amd-search-band {{ $searched ? 'amd-search-band--results' : 'amd-search-band--home' }}" aria-labelledby="search-title">
    @unless($searched)
        <figure class="amd-home-visual" data-home-scene aria-hidden="true">
            <div class="amd-home-scene-plane">
                <picture>
                    <source media="(prefers-reduced-motion: reduce)"
                        srcset="{{ asset('images/amd-rent-coastal-drive-branded-640.webp') }} 640w, {{ asset('images/amd-rent-coastal-drive-branded-1200.webp') }} 1200w, {{ asset('images/amd-rent-coastal-drive-branded-1942.webp') }} 1942w">
                    <img class="amd-home-scene-background" src="{{ asset('images/amd-rent-coastal-scene-v2-1200.webp') }}"
                        srcset="{{ asset('images/amd-rent-coastal-scene-v2-640.webp') }} 640w, {{ asset('images/amd-rent-coastal-scene-v2-1200.webp') }} 1200w, {{ asset('images/amd-rent-coastal-scene-v2-1942.webp') }} 1942w"
                        data-scene-fallback="{{ asset('images/amd-rent-coastal-drive-branded-1200.webp') }}"
                        sizes="(max-width: 600px) 514px, (max-width: 900px) 900px, (max-width: 2300px) 1150px, 50vw"
                        width="1942" height="809" alt="" fetchpriority="high" decoding="async">
                </picture>
                <div class="amd-home-scene-vehicle" data-scene-vehicle>
                    <img class="amd-home-scene-car" src="{{ asset('images/amd-rent-car-motion-v1-640.webp') }}"
                        srcset="{{ asset('images/amd-rent-car-motion-v1-640.webp') }} 640w, {{ asset('images/amd-rent-car-motion-v1-1200.webp') }} 1200w"
                        sizes="(max-width: 600px) 165px, (max-width: 900px) 288px, (max-width: 2300px) 368px, 16vw"
                        width="1774" height="887" alt="" decoding="async" data-scene-car>
                    <span class="amd-car-glint" data-car-glint></span>
                </div>
            </div>
        </figure>
    @endunless
    <div class="amd-search-band-inner">
        <div class="amd-page-heading">
            @if($searched)
                <h1 id="search-title">La tua ricerca</h1>
                <p>Scegli il luogo e le date. Confronta le auto dei noleggiatori che consegnano lì.</p>
            @else
                <h1 id="search-title">Il viaggio inizia<br>con l’auto giusta.</h1>
                <p>Scegli dove ritirarla e quando partire. Trova le auto dei noleggiatori che consegnano lì, disponibili per le tue date.</p>
                <a class="amd-hero-link" href="#luoghi-di-ritiro">Scopri i luoghi di ritiro</a>
            @endif
        </div>
        <form id="car-search" class="amd-search" method="get" action="{{ route($routePrefix.'.index') }}" aria-label="Ricerca auto disponibili">
            @if($errors->any())<div class="amd-errors" role="alert"><strong>Controlla i dati della ricerca.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
            <div class="amd-search-primary">
                <div class="amd-field amd-place-field"><label for="pickup-place" data-search-place-label>{{ $customPickup ? 'Luogo di riconsegna' : 'Luogo di ritiro' }}</label>
                    <select id="pickup-place" name="destination" required data-place-select aria-describedby="place-help">
                        <option value="">Cerca città, aeroporto, stazione o zona</option>
                        @foreach($destinations as $destination)<option value="{{ $destination['value'] }}" @selected(mb_strtolower($destinationValue) === mb_strtolower($destination['value']))>{{ $destination['label'] }}</option>@endforeach
                        @if(!$knownDestination && $selectedPlace && $destinationValue === (string) $selectedPlace->id)<option value="{{ $selectedPlace->id }}" selected>Punto di ritiro{{ $selectedPlace->city ? ' — '.$selectedPlace->city : ' selezionato' }}</option>@endif
                    </select>
                    <small id="place-help">{{ $customPickup ? 'Scegli dove riconsegnare l’auto, per esempio un aeroporto servito.' : 'Scegli un luogo dall’elenco. Per il ritiro personalizzato, qui scegli dove riconsegnare l’auto.' }}</small>
                    <label class="amd-search-delivery-choice" for="search-request-delivery"><input id="search-request-delivery" type="checkbox" name="request_delivery" value="1" data-search-delivery @checked($customPickup)><span>Voglio ritirare l’auto ad un indirizzo personalizzato</span></label>
                    <div class="amd-field amd-search-delivery-address" data-search-delivery-field>
                        <label for="search-delivery-address">Hotel o indirizzo completo di ritiro</label>
                        <input id="search-delivery-address" name="delivery_address" type="text" minlength="8" maxlength="500" value="{{ $value('delivery_address') }}" aria-describedby="search-delivery-help" data-search-delivery-address @if($customPickup) required @endif>
                        <small id="search-delivery-help">Indica anche la città. Il noleggiatore deve confermare l’indirizzo e l’eventuale supplemento prima del pagamento.</small>
                    </div>
                </div>
                <div class="amd-field"><label for="pickup-at">Ritiro</label><input id="pickup-at" name="pickup_at" type="datetime-local" required value="{{ $value('pickup_at', now()->addDay()->setTime(10, 0)->format('Y-m-d\TH:i')) }}" min="{{ now()->format('Y-m-d\TH:i') }}" @if($errors->has('pickup_at')) aria-invalid="true" @endif></div>
                <div class="amd-field"><label for="return-at">Riconsegna</label><input id="return-at" name="return_at" type="datetime-local" required value="{{ $value('return_at', now()->addDays(4)->setTime(10, 0)->format('Y-m-d\TH:i')) }}" min="{{ now()->format('Y-m-d\TH:i') }}" @if($errors->has('return_at')) aria-invalid="true" @endif></div>
                <button class="amd-button amd-search-submit" type="submit">Cerca auto</button>
            </div>
            <div class="amd-search-foot">
                <p class="amd-search-note">Date e orari italiani.</p>
                @unless($searched)<p class="amd-search-note"><strong>20% online con Stripe.</strong> Saldo al ritiro.</p>@endunless
            </div>
        </form>
    </div>
</section>
<div class="{{ $searched ? 'amd-main amd-results-main' : 'amd-home-sections' }}">
@if($searched)
    <div class="amd-results-layout">
        <aside class="amd-filters" aria-label="Filtri delle auto">
            <details class="amd-filter-disclosure" open data-filter-disclosure>
                <summary>Filtra le auto</summary>
                <form method="get" action="{{ route($routePrefix.'.index') }}" class="amd-filter-form">
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
                <form method="get" action="{{ route($routePrefix.'.index') }}" class="amd-sort">
                    @foreach($filters as $key => $filterValue) @if(!in_array($key, ['sort','page']) && $filterValue !== null)<input type="hidden" name="{{ $key }}" value="{{ $filterValue }}">@endif @endforeach
                    <label for="sort">Ordina per</label><select id="sort" name="sort"><option value="price_asc" @selected(($filters['sort'] ?? '') !== 'price_desc')>Prezzo crescente</option><option value="price_desc" @selected(($filters['sort'] ?? '') === 'price_desc')>Prezzo decrescente</option></select><button class="amd-button amd-button-secondary" type="submit">Ordina</button>
                </form>
            </div>
            <p class="amd-result-period">Dal {{ \Carbon\CarbonImmutable::parse($filters['pickup_at'])->format('d/m/Y H:i') }} al {{ \Carbon\CarbonImmutable::parse($filters['return_at'])->format('d/m/Y H:i') }}. Prezzi per l’intero periodo.</p>
            @if($customPickup)<p class="amd-result-period">Ritiro richiesto: <strong>{{ $value('delivery_address') }}</strong>. Mostriamo i noleggiatori che accettano richieste personalizzate: indirizzo e supplemento devono essere confermati. I prezzi indicati comprendono il noleggio; l’eventuale supplemento sarà nella proposta.</p>@endif
            @if($results->contains(fn ($car) => $car['product_id'] !== null))<p class="amd-result-period">Per ogni prodotto mostriamo l’auto disponibile con il prezzo totale più basso, nel rispetto dei filtri selezionati.</p>@endif
            @forelse($results as $car)
                <article class="amd-car" aria-labelledby="car-{{ $car['id'] }}">
                    <figure class="amd-car-photo">@if($car['has_photo'])<img src="{{ route($routePrefix.'.photo', $car['id']) }}" alt="{{ $car['photo_is_reference'] ? 'Immagine indicativa: '.$car['title'] : $car['title'] }}" loading="lazy" width="360" height="240">@if($car['photo_is_reference'])<figcaption>Immagine indicativa del modello</figcaption>@endif @else<span>Foto non disponibile</span>@endif</figure>
                    <div class="amd-car-info"><h3 id="car-{{ $car['id'] }}">{{ $car['product_name'] ?? $car['title'] }}</h3>
                        @if($car['product_name'] !== null && $car['product_name'] !== $car['title'])<p>{{ $car['title'] }}</p>@endif
                        <p class="amd-car-provider">Noleggiatore: <strong>{{ $car['organization'] }}</strong></p>
                        <dl class="amd-specs">@if($car['seats'])<div><dt>Posti</dt><dd>{{ $car['seats'] }}</dd></div>@endif @if($car['transmission'])<div><dt>Cambio</dt><dd>{{ $car['transmission'] }}</dd></div>@endif @if($car['fuel'])<div><dt>Alimentazione</dt><dd>{{ $car['fuel'] }}</dd></div>@endif</dl>
                        <div class="amd-car-pickup"><span>{{ $customPickup ? 'Luogo di riconsegna' : 'Punto di ritiro' }}</span><p>{{ $car['location'] }}@if($car['city']), {{ $car['city'] }}@endif</p></div>
                        <p class="amd-car-mileage">{{ $car['km_per_day'] === null ? 'Chilometraggio illimitato' : $car['km_per_day'].' km inclusi al giorno' }}</p>
                    </div>
                    <div class="amd-car-price"><span>Totale per {{ $car['days'] }} {{ $car['days'] === 1 ? 'giorno' : 'giorni' }}</span><strong>{{ $money($car['total_cents']) }}</strong><small>{{ $car['prices_include_vat'] ? 'IVA inclusa' : 'Importo di listino, IVA da verificare' }}</small><small>Cauzione separata: {{ $money($car['deposit_cents']) }}</small><small>20% online con Stripe, saldo al ritiro</small><a class="amd-button" href="{{ route($routePrefix.'.show', ['pricelist' => $car['id']] + $filters) }}">Vedi auto e condizioni</a></div>
                </article>
            @empty
                <div class="amd-empty"><h3>Nessuna auto disponibile con questi criteri</h3><p>Prova altre date o rimuovi i filtri. Puoi anche scegliere un altro luogo di consegna.</p><a class="amd-button amd-button-secondary" href="{{ route($routePrefix.'.index', $baseFilters) }}">Rimuovi filtri</a><a class="amd-empty-edit" href="#car-search">Modifica luogo o date</a></div>
            @endforelse
            @if($results->hasPages())<nav class="amd-pagination" aria-label="Pagine dei risultati">@if(!$results->onFirstPage())<a href="{{ $results->previousPageUrl() }}">Pagina precedente</a>@endif<span>Pagina {{ $results->currentPage() }} di {{ $results->lastPage() }}</span>@if($results->hasMorePages())<a href="{{ $results->nextPageUrl() }}">Pagina successiva</a>@endif</nav>@endif
        </section>
    </div>
@else
    @include('public-cars.partials.home-guide')
@endif
</div>
@endsection

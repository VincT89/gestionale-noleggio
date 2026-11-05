<section class="amd-delivery-suppliers amd-delivery-layout" aria-labelledby="delivery-suppliers-title">
    <div class="amd-delivery-intro">
        <h2 id="delivery-suppliers-title">Noleggiatori per questo ritiro</h2>
        <p class="amd-delivery-lead">L’auto giusta, dove comincia il tuo viaggio.</p>
        <p>Scegli la proposta per te e richiedi la consegna al tuo indirizzo.</p>
        <div class="amd-delivery-guidance">
            <h3>Prima di partire</h3>
            <p>Consegna ed eventuale supplemento ti saranno confermati prima di pagare.</p>
            @if(($deliveryPoint['source'] ?? null) === 'map')
                @include('public-cars.partials.selected-map-point', ['point' => $deliveryPoint])
            @endif
        </div>
        @if(($deliveryPoint['source'] ?? null) === 'map')
            <a class="amd-text-link" href="{{ route($routePrefix.'.index', \Illuminate\Support\Arr::except($filters, ['delivery_place', 'supplier', 'page'])) }}#pickup-map">Modifica il punto sulla mappa</a>
        @endif
    </div>
    <div class="amd-delivery-offers">
        @forelse($supplierResults as $provider)
            <article class="amd-delivery-provider" aria-labelledby="provider-{{ $provider['id'] }}">
                <div class="amd-delivery-provider-info">
                    <h3 id="provider-{{ $provider['id'] }}">{{ $provider['name'] }}</h3>
                    <p>{{ $provider['count'] === 1 ? '1 auto disponibile' : $provider['count'].' auto disponibili' }} per il tuo viaggio</p>
                    <dl class="amd-provider-location"><div><dt>Sede di partenza</dt><dd>{{ $provider['origin'] }}</dd></div><div><dt>Distanza dal ritiro</dt><dd>{{ number_format($provider['distance_km'], 1, ',', '.') }} <small>km in linea d’aria</small></dd></div></dl>
                    @if($provider['area'])<p class="amd-provider-area">{{ $provider['area'] }}</p>@endif
                </div>
                <div class="amd-delivery-provider-offer">
                    <p class="amd-provider-price"><span>Noleggio da</span><strong>{{ $money($provider['from_cents']) }}</strong><span>per tutto il periodo</span></p>
                    <p class="amd-delivery-price-note">IVA inclusa. Supplemento consegna e cauzione separati.</p>
                    <a class="amd-button" href="{{ route($routePrefix.'.index', array_replace(\Illuminate\Support\Arr::except($filters, ['page']), ['supplier' => $provider['id']])) }}" aria-describedby="provider-{{ $provider['id'] }}">Vedi auto e prezzi</a>
                </div>
            </article>
        @empty
            <div class="amd-delivery-empty">
                <h3>Nessun noleggiatore disponibile con questi criteri</h3>
                <p>Al momento non ci sono auto disponibili con consegna a questo indirizzo{{ $selectedPlace || $value('city') ? ' e riconsegna nel luogo scelto' : '' }}.</p>
                <div class="amd-delivery-empty-action">
                    <p>Il viaggio può cominciare da un’altra scelta. Prova altre date o un punto di ritiro diverso.</p>
                    <a class="amd-button" href="#car-search">Modifica luogo o date</a>
                </div>
            </div>
        @endforelse
        @if($supplierResults->hasPages())<nav class="amd-pagination" aria-label="Pagine dei noleggiatori">@if(!$supplierResults->onFirstPage())<a href="{{ $supplierResults->previousPageUrl() }}">Pagina precedente</a>@endif<span>Pagina {{ $supplierResults->currentPage() }} di {{ $supplierResults->lastPage() }}</span>@if($supplierResults->hasMorePages())<a href="{{ $supplierResults->nextPageUrl() }}">Pagina successiva</a>@endif</nav>@endif
    </div>
</section>

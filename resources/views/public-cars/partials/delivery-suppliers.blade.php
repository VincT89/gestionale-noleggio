<section class="amd-delivery-suppliers" aria-labelledby="delivery-suppliers-title">
    <h2 id="delivery-suppliers-title">Noleggiatori per questo ritiro</h2>
    <p class="amd-selected-address">{{ $deliveryPoint['label'] }}</p>
    <p>Dal {{ \Carbon\CarbonImmutable::parse($filters['pickup_at'])->format('d/m/Y H:i') }} al {{ \Carbon\CarbonImmutable::parse($filters['return_at'])->format('d/m/Y H:i') }}.
        Riconsegna: {{ $selectedPlace?->label ?? ($value('city') ?: 'da scegliere dopo l’auto') }}.</p>
    <p>Parti dai noleggiatori più vicini che offrono la consegna in questa zona. Confronta le auto e richiedi il ritiro dove preferisci: consegna ed eventuale supplemento ti saranno confermati prima di pagare.</p>
    @forelse($supplierResults as $provider)
        <article class="amd-delivery-provider" aria-labelledby="provider-{{ $provider['id'] }}">
            <div><h3 id="provider-{{ $provider['id'] }}">{{ $provider['name'] }}</h3>
                <p>{{ number_format($provider['distance_km'], 1, ',', '.') }} km in linea d’aria dalla sede {{ $provider['origin'] }}.</p>
                @if($provider['area'])<p>{{ $provider['area'] }}</p>@endif
            </div>
            <div class="amd-delivery-provider-offer"><p>{{ $provider['count'] === 1 ? '1 auto disponibile' : $provider['count'].' auto disponibili' }}<br>Noleggio da <strong>{{ $money($provider['from_cents']) }}</strong> per tutto il periodo.</p>
                <p class="amd-delivery-price-note">IVA inclusa. Supplemento consegna e cauzione separati.</p>
                <a class="amd-button" href="{{ route($routePrefix.'.index', array_replace(\Illuminate\Support\Arr::except($filters, ['page']), ['supplier' => $provider['id']])) }}" aria-describedby="provider-{{ $provider['id'] }}">Vedi auto e prezzi</a>
            </div>
        </article>
    @empty
        <div class="amd-empty"><h3>Nessun noleggiatore disponibile con questi criteri</h3><p>Al momento non ci sono auto disponibili con consegna a questo indirizzo{{ $selectedPlace || $value('city') ? ' e riconsegna nel luogo scelto' : '' }}. Prova altre date o modifica il luogo.</p><a href="#car-search">Modifica luogo o date</a></div>
    @endforelse
    @if($supplierResults->hasPages())<nav class="amd-pagination" aria-label="Pagine dei noleggiatori">@if(!$supplierResults->onFirstPage())<a href="{{ $supplierResults->previousPageUrl() }}">Pagina precedente</a>@endif<span>Pagina {{ $supplierResults->currentPage() }} di {{ $supplierResults->lastPage() }}</span>@if($supplierResults->hasMorePages())<a href="{{ $supplierResults->nextPageUrl() }}">Pagina successiva</a>@endif</nav>@endif
    <p class="amd-map-attribution">Dati del luogo: <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap e collaboratori, licenza ODbL</a>.</p>
</section>

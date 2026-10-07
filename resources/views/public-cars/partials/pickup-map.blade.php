@php
    $isReturnMap = ($mapPurpose ?? 'pickup') === 'return';
    $mapId = $isReturnMap ? 'return-map' : 'pickup-map';
    $mapLocation = $isReturnMap ? 'riconsegna' : 'ritiro';
    $mapAddress = $isReturnMap ? ($returnPoint['label'] ?? '') : $value('delivery_address');
@endphp
<details id="{{ $mapId }}" class="amd-pickup-map" data-pickup-map data-map-purpose="{{ $isReturnMap ? 'return' : 'pickup' }}" data-address-url="{{ route($routePrefix.'.map.address') }}" data-tiles-url="{{ config('geocoding.map_tiles_url') }}" data-initial-lat="{{ $mapCenter['lat'] ?? 42.5 }}" data-initial-lng="{{ $mapCenter['lng'] ?? 12.5 }}" data-initial-zoom="{{ $mapCenter['zoom'] ?? (isset($mapCenter) ? 13 : 5) }}" open hidden>
    <summary><span>{{ $isReturnMap ? 'Indica il punto di riconsegna sulla mappa' : 'Indica il punto sulla mappa' }}<small>Cerca la zona, poi scegli il punto esatto.</small></span></summary>
    <div class="amd-pickup-map-content">
        <p class="amd-map-privacy">Mappa e ricerca dei luoghi con OpenStreetMap. <a href="{{ route('public-site.privacy') }}#servizi-esterni" target="_blank" rel="noopener noreferrer">Informazioni sui dati (nuova scheda)</a>.</p>
        <div class="amd-map-canvas">
            <div class="amd-map-search-panel">
                <form method="post" action="{{ route($routePrefix.'.map.search') }}" class="amd-map-area-search" data-map-area-search>
                    @csrf
                    <div class="amd-field"><label for="{{ $mapId }}-area">Città, aeroporto o luogo vicino</label><input id="{{ $mapId }}-area" name="query" type="search" value="{{ $isReturnMap ? '' : mb_substr($mapAddress, 0, 200) }}" minlength="3" maxlength="200" required autocomplete="off" placeholder="{{ $isReturnMap ? 'Dove vuoi riconsegnare l’auto?' : 'Da dove vuoi partire?' }}"></div>
                    <button class="amd-button" type="submit" aria-label="Cerca sulla mappa">Cerca</button>
                </form>
                <p data-map-search-status role="status" aria-live="polite"></p>
                <ul class="amd-map-area-results" data-map-area-results aria-label="Luoghi trovati sulla mappa"></ul>
            </div>
            <div class="amd-map-frame">
                <div class="amd-map-toolbar" role="group" aria-label="Zoom della mappa">
                    <button type="button" data-map-zoom-in aria-label="Ingrandisci la mappa" title="Ingrandisci la mappa"><span aria-hidden="true">+</span></button>
                    <button type="button" data-map-zoom-out aria-label="Riduci la mappa" title="Riduci la mappa"><span aria-hidden="true">−</span></button>
                </div>
                <div class="amd-map-viewport" data-map-viewport tabindex="0" role="group" aria-label="Mappa del luogo di {{ $mapLocation }}" aria-describedby="{{ $mapId }}-help {{ $mapId }}-keyboard">
                    <div class="amd-map-tiles" data-map-tiles aria-hidden="true"></div>
                    <span class="amd-map-pin" data-map-pin aria-hidden="true" hidden></span>
                </div>
                <a class="amd-map-credit" href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">© OpenStreetMap</a>
            </div>
        </div>
        <div class="amd-map-selection">
            <p class="amd-map-status" data-map-status role="status" aria-live="polite"></p>
            <form method="post" action="{{ route($routePrefix.($isReturnMap ? '.return-map.store' : '.map.store')) }}" data-map-confirm>
                @csrf
                @unless($isReturnMap)
                @foreach(\Illuminate\Support\Arr::only($filters, ['pickup_at', 'return_at', 'place_id', 'city', 'request_delivery', 'request_custom_return', 'return_address', 'return_place']) as $key => $fieldValue)
                    @if($fieldValue !== null)<input type="hidden" name="{{ $key }}" value="{{ $fieldValue }}">@endif
                @endforeach
                @endunless
                <input type="hidden" name="map_lat"><input type="hidden" name="map_lng"><input type="hidden" name="map_zoom">
                <div class="amd-field"><label for="{{ $mapId }}-address">Indirizzo di {{ $mapLocation }}</label><input id="{{ $mapId }}-address" name="{{ $isReturnMap ? 'return_address' : 'delivery_address' }}" value="{{ $mapAddress }}" data-map-address minlength="8" maxlength="500" required aria-describedby="{{ $mapId }}-address-help {{ $mapId }}-address-status"><small id="{{ $mapId }}-address-help">Si aggiorna quando scegli un punto. Puoi precisare ingresso, terminal o civico.</small><small id="{{ $mapId }}-address-status" data-map-address-status role="status" aria-live="polite"></small></div>
                <div class="amd-map-confirm-action"><p>Il noleggiatore confermerà {{ $isReturnMap ? 'la riconsegna' : 'la consegna' }} e l’eventuale supplemento.</p><button type="submit" class="amd-button" name="map_confirmed" value="1" disabled>{{ $isReturnMap ? 'Usa questo punto di riconsegna' : 'Conferma il punto e continua' }}</button></div>
            </form>
        </div>
        <details class="amd-map-instructions"><summary>Come usare la mappa</summary><p id="{{ $mapId }}-help">Ingrandisci fino a vedere la strada, poi tocca il punto di {{ $mapLocation }}. Trascina la mappa per spostarti.</p><p id="{{ $mapId }}-keyboard">Da tastiera: frecce per spostarti, tasti + e - per lo zoom, Invio per scegliere il punto al centro.</p></details>
    </div>
</details>
<noscript><p>Per indicare il punto sulla mappa, attiva JavaScript nel browser. Puoi comunque modificare l’indirizzo e ripetere la ricerca.</p></noscript>

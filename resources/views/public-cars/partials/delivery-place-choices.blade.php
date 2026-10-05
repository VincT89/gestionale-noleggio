<section class="amd-place-confirmation" aria-labelledby="place-confirmation-title">
    <h2 id="place-confirmation-title">Conferma il luogo di ritiro</h2>
    <p>Hai cercato: <strong>{{ $value('delivery_address') }}</strong>. Controlla il comune e l’indirizzo prima di continuare.</p>
    @if($placeSearchError)
        <p class="amd-errors" role="alert">{{ $placeSearchError }}</p>
    @elseif(!$placeChoices)
        <div class="amd-empty"><h3>Non abbiamo trovato un indirizzo preciso</h3><p>Prova con il nome completo dell’hotel o del B&B e il comune. Se la struttura non compare, inserisci via, numero civico e comune.</p></div>
    @else
        <ul class="amd-location-choices">
            @foreach($placeChoices as $choice)
                <li><form method="get" action="{{ route($routePrefix.'.index') }}" data-live-search>
                    @foreach(\Illuminate\Support\Arr::except($filters, ['delivery_place', 'delivery_address', 'supplier', 'page']) as $key => $fieldValue)
                        @if($fieldValue !== null)<input type="hidden" name="{{ $key }}" value="{{ $fieldValue }}">@endif
                    @endforeach
                    <input type="hidden" name="delivery_place" value="{{ $choice['token'] }}">
                    <input type="hidden" name="delivery_address" value="{{ $choice['label'] }}">
                    <p id="place-choice-{{ $loop->index }}">{{ $choice['label'] }}</p>
                    <button class="amd-button amd-button-secondary" type="submit" aria-describedby="place-choice-{{ $loop->index }}">Scegli questo luogo</button>
                </form></li>
            @endforeach
        </ul>
        <p>Se manca il numero civico, la posizione può essere indicativa. Il noleggiatore confermerà il punto esatto della consegna.</p>
    @endif
    <p><a href="#car-search">Modifica la ricerca</a></p>
    <p class="amd-map-attribution">Dati <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap e collaboratori, licenza ODbL</a>.</p>
</section>

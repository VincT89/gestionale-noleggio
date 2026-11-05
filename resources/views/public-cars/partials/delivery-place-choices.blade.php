<section class="amd-place-confirmation amd-place-workspace" aria-labelledby="place-confirmation-title">
    <div class="amd-place-guide">
        <h2 id="place-confirmation-title">Conferma il luogo di ritiro</h2>
        <p class="amd-place-lead">Scegli dove ricevere l’auto.</p>
        @if($placeSearchError)
            <p class="amd-errors" role="alert">{{ $placeSearchError }}</p>
        @elseif(!$placeChoices)
            <div class="amd-place-notice"><h3>Non abbiamo trovato un indirizzo preciso</h3><p>Indica il punto sulla mappa. Ti aiutiamo a completare l’indirizzo prima di cercare le auto.</p></div>
        @else
            <p>Controlla il comune e l’indirizzo, poi scegli il luogo giusto.</p>
            <ul class="amd-location-choices">
                @foreach($placeChoices as $choice)
                    <li><form method="get" action="{{ route($routePrefix.'.index') }}" data-live-search>
                        @foreach(\Illuminate\Support\Arr::except($filters, ['delivery_place', 'delivery_address', 'supplier', 'page']) as $key => $fieldValue)
                            @if($fieldValue !== null)<input type="hidden" name="{{ $key }}" value="{{ $fieldValue }}">@endif
                        @endforeach
                        <input type="hidden" name="delivery_place" value="{{ $choice['token'] }}">
                        <input type="hidden" name="delivery_address" value="{{ $choice['label'] }}">
                        <p id="place-choice-{{ $loop->index }}">{{ $choice['label'] }}</p>
                        <button class="amd-button" type="submit" aria-describedby="place-choice-{{ $loop->index }}">Scegli questo luogo</button>
                    </form></li>
                @endforeach
            </ul>
            <small class="amd-place-credit"><a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">© OpenStreetMap</a></small>
            <p class="amd-place-note">Se manca il civico, la posizione può essere indicativa. Il noleggiatore confermerà il punto esatto.</p>
        @endif
        <a class="amd-text-link" href="#car-search">Modifica la ricerca</a>
    </div>
    @include('public-cars.partials.pickup-map')
</section>

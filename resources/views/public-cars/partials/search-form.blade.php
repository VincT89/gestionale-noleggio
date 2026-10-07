<form id="car-search" class="amd-search" method="get" action="{{ route($routePrefix.'.index') }}" aria-label="Ricerca auto disponibili">
    @if(!empty($filters['request_custom_return']))
        <input type="hidden" name="request_custom_return" value="1">
        <input type="hidden" name="return_address" value="{{ $filters['return_address'] }}">
        <input type="hidden" name="return_place" value="{{ $filters['return_place'] ?? '' }}">
    @endif
    @if($errors->any())<div class="amd-errors" role="alert"><strong>Controlla i dati della ricerca.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @unless($searched)<div class="amd-search-intro"><h2>La tua prossima partenza</h2></div>@endunless
    <div class="amd-search-primary">
        <div class="amd-field amd-place-field"><label for="pickup-place" data-search-place-label>{{ $customPickup ? 'Luogo di riconsegna (facoltativo)' : 'Luogo di ritiro' }}</label>
            <select id="pickup-place" name="destination" data-place-select aria-describedby="place-help">
                <option value="">Cerca città, aeroporto, stazione o zona</option>
                @foreach($destinations as $destination)<option value="{{ $destination['value'] }}" @selected(mb_strtolower($destinationValue) === mb_strtolower($destination['value']))>{{ $destination['label'] }}</option>@endforeach
                @if(!$knownDestination && $selectedPlace && $destinationValue === (string) $selectedPlace->id)<option value="{{ $selectedPlace->id }}" selected>Punto di ritiro{{ $selectedPlace->city ? ' — '.$selectedPlace->city : ' selezionato' }}</option>@endif
            </select>
            <small id="place-help">{{ $customPickup ? 'Puoi scegliere ora oppure dopo aver trovato l’auto.' : 'Scegli un punto di ritiro o richiedi la consegna al tuo indirizzo.' }}</small>
            <label class="amd-search-delivery-choice" for="search-request-delivery"><input id="search-request-delivery" type="checkbox" name="request_delivery" value="1" data-search-delivery @checked($customPickup)><span>Voglio ritirare l’auto ad un indirizzo personalizzato</span></label>
            <div class="amd-field amd-search-delivery-address" data-search-delivery-field>
                <label for="search-delivery-address">Indirizzo, hotel o B&B</label>
                <input id="search-delivery-address" name="delivery_address" type="text" minlength="3" maxlength="500" value="{{ $value('delivery_address') }}" aria-describedby="search-delivery-help" data-search-delivery-address @if($customPickup) required @endif>
                <small id="search-delivery-help">Il nome dell’hotel o del B&B e la città, oppure l’indirizzo completo.</small>
                <small>Per trovare il luogo usiamo OpenStreetMap. <a href="{{ route('public-site.privacy') }}#servizi-esterni" target="_blank" rel="noopener noreferrer">Come usiamo l’indirizzo (nuova scheda)</a>.</small>
            </div>
        </div>
        <div class="amd-field"><label for="pickup-at">Ritiro</label><input id="pickup-at" name="pickup_at" type="datetime-local" required value="{{ $value('pickup_at', now()->addDay()->setTime(10, 0)->format('Y-m-d\TH:i')) }}" min="{{ now()->format('Y-m-d\TH:i') }}" @if($errors->has('pickup_at')) aria-invalid="true" @endif></div>
        <div class="amd-field"><label for="return-at">Riconsegna</label><input id="return-at" name="return_at" type="datetime-local" required value="{{ $value('return_at', now()->addDays(4)->setTime(10, 0)->format('Y-m-d\TH:i')) }}" min="{{ now()->format('Y-m-d\TH:i') }}" @if($errors->has('return_at')) aria-invalid="true" @endif></div>
        <button class="amd-button amd-search-submit" type="submit" data-search-submit>{{ $customPickup ? 'Cerca noleggiatori' : 'Cerca auto' }}</button>
    </div>
    <div class="amd-search-foot">
        <p class="amd-search-note">Date e orari italiani.</p>
        @unless($searched)<p class="amd-search-note"><strong>Prenoti con il 20% online.</strong> Saldo al ritiro.</p>@endunless
    </div>
</form>

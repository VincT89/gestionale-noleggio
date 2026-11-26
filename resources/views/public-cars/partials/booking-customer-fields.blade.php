<div class="amd-field">
    <label for="booking-birthdate">Data di nascita del conducente</label>
    <input id="booking-birthdate" name="birthdate" type="date" autocomplete="bday" max="{{ now()->toDateString() }}" value="{{ $inputValue('birthdate') }}" required data-driver-birthdate aria-describedby="booking-birthdate-help booking-driver-age-status{{ $errors->has('birthdate') ? ' error-birthdate' : '' }}" @if($errors->has('birthdate')) aria-invalid="true" @endif>
    <small id="booking-birthdate-help">Calcoliamo l’età alla data del ritiro e controlliamo che corrisponda alla fascia scelta. Il noleggiatore verificherà i dati sul documento prima della consegna.</small>
    <small id="booking-driver-age-status" data-driver-age-status role="status"></small>
    @error('birthdate')<small id="error-birthdate" class="amd-field-error">{{ $message }}</small>@enderror
</div>
<div class="amd-field">
    @if(!empty($filters['driver_age_band']))
        <input type="hidden" name="driver_age_band" value="{{ $filters['driver_age_band'] }}">
        <p><strong>Età del conducente alla data del ritiro:</strong> {{ \App\Services\AmdRent\DriverAgePricing::BANDS[$filters['driver_age_band']] }}.</p>
        @if(empty($deliveryRequestUrl))<p>Per cambiare fascia, <a href="{{ route($routePrefix.'.show', ['pricelist' => $car['id']] + \Illuminate\Support\Arr::except($browseFilters, ['driver_age_band'])) }}">aggiorna la fascia e il prezzo</a>.</p>
        @else<p>Se la fascia non corrisponde alla data di nascita, <a href="{{ $deliveryRequestUrl }}">torna alla proposta</a> e richiedi l’aggiornamento prima di prenotare.</p>@endif
    @else
        <label for="booking-driver-age">Età del conducente alla data del ritiro</label>
        <select id="booking-driver-age" name="driver_age_band" required><option value="">Scegli la fascia di età</option>@foreach(\App\Services\AmdRent\DriverAgePricing::BANDS as $band => $label)<option value="{{ $band }}" @selected($inputValue('driver_age_band') === $band)>{{ $label }}</option>@endforeach</select>
    @endif
    @error('driver_age_band')<small class="amd-field-error">{{ $message }}</small>@enderror
</div>
<h2>Dati di fatturazione</h2>
<div class="amd-field"><label for="booking-customer-type">Prenoto come</label><select id="booking-customer-type" name="booking_customer_type" data-booking-customer-type required><option value="private" @selected($inputValue('booking_customer_type') !== 'company')>Privato</option><option value="company" @selected($inputValue('booking_customer_type') === 'company')>Azienda</option></select></div>
<fieldset class="amd-billing-company" data-booking-company>
    <legend>Dati dell’azienda</legend>
    <p>Compila questi campi se prenoti come azienda. Nome e cognome indicati sopra restano quelli del conducente.</p>
    <div class="amd-booking-fields">
        @foreach(['company_name' => ['Ragione sociale', 191], 'vat_number' => ['Partita IVA / identificativo fiscale aziendale', 32], 'sdi_code' => ['Codice destinatario SDI (facoltativo)', 7], 'pec' => ['PEC (facoltativa)', 191]] as $field => [$label, $max])
            <div class="amd-field"><label for="booking-{{ $field }}">{{ $label }}</label><input id="booking-{{ $field }}" type="{{ $field === 'pec' ? 'email' : 'text' }}" name="{{ $field }}" value="{{ $inputValue($field) }}" maxlength="{{ $max }}" @if(in_array($field, ['company_name', 'vat_number'])) data-company-required @endif @if($inputValue('booking_customer_type') === 'company' && in_array($field, ['company_name', 'vat_number'])) required @endif>@error($field)<small class="amd-field-error">{{ $message }}</small>@enderror</div>
        @endforeach
    </div>
</fieldset>
<div class="amd-booking-fields">
    @foreach(['billing_address' => ['Indirizzo di fatturazione', 'street-address', 191], 'billing_postal_code' => ['CAP / codice postale', 'postal-code', 20], 'billing_city' => ['Città', 'address-level2', 128], 'billing_country' => ['Paese (codice di due lettere, es. IT)', 'country', 2], 'tax_code' => ['Codice fiscale', 'off', 32]] as $field => [$label, $autocomplete, $max])
        <div class="amd-field"><label for="booking-{{ $field }}">{{ $label }}</label><input id="booking-{{ $field }}" name="{{ $field }}" type="text" autocomplete="{{ $autocomplete }}" maxlength="{{ $max }}" value="{{ $field === 'billing_country' ? ($inputValue($field) ?: 'IT') : $inputValue($field) }}" @if($field !== 'tax_code') required @endif>@error($field)<small class="amd-field-error">{{ $message }}</small>@enderror</div>
    @endforeach
</div>
<p class="amd-booking-help">Per i privati con fatturazione in Italia è richiesto il codice fiscale. Per le aziende SDI e PEC sono facoltativi.</p>

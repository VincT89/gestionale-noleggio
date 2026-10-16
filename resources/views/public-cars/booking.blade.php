@extends('layouts.public-cars')
@section('title', 'Prenota la tua auto')
@section('content')
@php
    $accountContact = auth('public_customer')->user();
    $contact = array_replace($accountContact?->hasVerifiedEmail() ? $accountContact->only(['first_name', 'last_name', 'email', 'phone']) : [], $contact ?? []);
    $inputValue = function ($field) use ($contact, $filters) { $v = old($field, $contact[$field] ?? ($field === 'delivery_address' ? ($filters[$field] ?? '') : '')); return is_scalar($v) ? (string) $v : ''; };
    $fixedDelivery = !empty($car['delivery_destination']) && empty($car['delivery_request_id']);
    $requestDelivery = $fixedDelivery;
    $browseFilters = $browseFilters ?? $filters;
    $canChoosePickup = !$fixedDelivery && empty($car['delivery_request_id']) && ($car['custom_delivery_enabled'] ?? false);
    $customReturn = !empty($car['return_address']);
    $needsOnlinePayment = config('amd_rent.payment_mode') === 'stripe' && \App\Services\AmdRent\DeliveryQuotes::onlineDue($car) > 0;
@endphp
@if(!empty($deliveryRequestUrl))
    <a class="amd-back" href="{{ $deliveryRequestUrl }}">Torna alla proposta</a>
@else
    <a class="amd-back" href="{{ route($routePrefix.'.show', ['pricelist' => $car['id']] + $browseFilters) }}">Torna all’auto</a>
@endif
<div class="amd-page-heading"><h1>{{ $fixedDelivery ? 'Richiedi disponibilità' : 'Prenota la tua auto' }}</h1><p>{{ $fixedDelivery ? 'Controlla auto, luoghi e date e inserisci i tuoi recapiti. Il noleggiatore confermerà auto e appuntamenti prima del pagamento.' : 'Controlla il riepilogo e inserisci i tuoi recapiti. La quota online e il saldo al ritiro sono indicati nel riepilogo.' }}</p></div>
<div class="amd-detail amd-booking-layout">
    <aside class="amd-quote" aria-labelledby="booking-summary-title">@include('public-cars.partials.booking-summary')</aside>
    <form method="post" action="{{ route($routePrefix.'.booking.store', $car['id']) }}" class="amd-detail-info amd-booking-form" data-booking-form aria-label="Dati per la prenotazione">
        @csrf
        <input type="hidden" name="checkout_token" value="{{ $checkoutToken }}">
        @foreach(\Illuminate\Support\Arr::only($filters, ['pickup_at', 'return_at', 'place_id', 'delivery_place', 'return_place']) as $field => $fieldValue)<input type="hidden" name="{{ $field }}" value="{{ $fieldValue }}">@endforeach
        @if($customReturn && empty($car['delivery_request_id']))<input type="hidden" name="request_custom_return" value="1">@endif
        <h2>I tuoi dati</h2>
        <p>Non serve creare un account. I recapiti saranno disponibili al noleggiatore che gestirà la prenotazione. @if(!$accountContact)<a href="{{ $loginUrl ?? route('public-account.login') }}">Accedi</a> per usare i dati del tuo profilo.@endif</p>
        @if($errors->any())<div class="amd-errors" role="alert" tabindex="-1" data-booking-errors><strong>Controlla i dati prima di confermare.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <div class="amd-booking-fields">
            @foreach(['first_name' => ['Nome', 'given-name', 'text', 90], 'last_name' => ['Cognome', 'family-name', 'text', 90], 'email' => ['Email', 'email', 'email', 191], 'phone' => ['Telefono', 'tel', 'tel', 32]] as $field => [$label, $autocomplete, $type, $max])
            <div class="amd-field"><label for="booking-{{ $field }}">{{ $label }}</label><input id="booking-{{ $field }}" name="{{ $field }}" type="{{ $type }}" autocomplete="{{ $autocomplete }}" maxlength="{{ $max }}" value="{{ $inputValue($field) }}" required @if($errors->has($field)) aria-invalid="true" aria-describedby="error-{{ $field }}" @endif>@error($field)<small id="error-{{ $field }}" class="amd-field-error">{{ $message }}</small>@enderror</div>
            @endforeach
        </div>
        @if($fixedDelivery)
        <fieldset class="amd-delivery-request">
            <legend>{{ $customReturn ? 'I tuoi appuntamenti' : 'Consegna in hotel o a un indirizzo' }}</legend>
            <p>{{ $car['delivery_area'] }}</p>
            <input type="hidden" name="request_delivery" value="1">
            <div class="amd-field"><label for="delivery-address">Hotel e indirizzo completo di consegna</label><input id="delivery-address" name="delivery_address" minlength="8" maxlength="500" value="{{ $car['delivery_destination']['label'] }}" required readonly></div>
            @if($fixedDelivery)<p>Per cambiare il luogo di ritiro, <a href="{{ route($routePrefix.'.index') }}">avvia una nuova ricerca</a>.</p>@endif
            @if($customReturn)
                <div class="amd-field"><label for="booking-return-address">Luogo di riconsegna richiesto</label><input id="booking-return-address" name="return_address" value="{{ $car['return_address'] }}" readonly required></div>
                <p>Per cambiare il luogo di riconsegna, <a href="{{ route($routePrefix.'.show', ['pricelist' => $car['id']] + $browseFilters) }}">torna all’auto</a>.</p>
            @endif
            <div class="amd-field"><label for="delivery-notes">Indicazioni per la consegna (facoltative)</label><textarea id="delivery-notes" name="delivery_notes" rows="3" maxlength="2000">{{ $inputValue('delivery_notes') }}</textarea></div>
            <p>{{ $customReturn ? 'Il noleggiatore confermerà auto, ritiro e riconsegna nei luoghi indicati. Il prezzo comprende entrambi gli appuntamenti.' : 'Il noleggiatore confermerà auto e appuntamenti. Riconsegna presso '.$car['location'].'.' }} Pagherai dopo aver visto la proposta e il totale aggiornato.</p>
        </fieldset>
        @elseif($canChoosePickup)
        <fieldset class="amd-delivery-request">
            <legend>Preferisci ricevere l’auto altrove?</legend>
            <p>Scegli il punto sulla mappa. Ti mostriamo le auto che raggiungono l’indirizzo e il prezzo aggiornato, prima di continuare.</p>
            <button type="button" class="amd-button amd-button-secondary" data-booking-pickup-open aria-haspopup="dialog" aria-controls="booking-pickup-dialog" hidden>Scegli il ritiro sulla mappa</button>
            <noscript><p>Attiva JavaScript per scegliere un punto sulla mappa, oppure <a href="{{ route($routePrefix.'.index', \Illuminate\Support\Arr::only($filters, ['pickup_at', 'return_at']) + ['request_delivery' => 1]) }}">cerca un indirizzo personalizzato</a>.</p></noscript>
        </fieldset>
        @endif
        <div class="amd-booking-trap" aria-hidden="true"><label for="booking-website">Sito web</label><input id="booking-website" name="website" tabindex="-1" autocomplete="off"></div>
        <p class="amd-booking-help">I dati completi del conducente e i documenti saranno verificati dal noleggiatore prima della consegna. <a href="{{ config('public_cars.privacy_url') ?: route('public-site.privacy') }}" target="_blank" rel="noopener noreferrer">Informativa privacy (nuova scheda)</a>.</p>
        <label class="amd-booking-accept" for="accept-summary"><input id="accept-summary" name="accept_summary" type="checkbox" value="1" required><span>Ho controllato date, sede, importi e condizioni dell’offerta. Accetto la quota online indicata nel riepilogo e il saldo al ritiro. Se richiedo una consegna personalizzata, attendo prima la proposta del noleggiatore.</span></label>
        <button class="amd-button" type="submit" data-checkout-submit data-delivery-checkout-label="Richiedi disponibilità" data-standard-checkout-label="{{ $needsOnlinePayment ? 'Prosegui al pagamento' : 'Conferma prenotazione' }}">{{ $requestDelivery ? 'Richiedi disponibilità' : ($needsOnlinePayment ? 'Prosegui al pagamento' : 'Conferma prenotazione') }}</button>
        <p class="amd-booking-help">Disponibilità e prezzo vengono ricontrollati alla conferma.</p>
    </form>
</div>
@if($canChoosePickup)
    @php
        $pickupMapFilters = \Illuminate\Support\Arr::except($browseFilters, ['place_id', 'city', 'destination', 'delivery_place', 'delivery_address', 'request_custom_return', 'return_address', 'return_place', 'page']);
        $pickupMapFilters['request_delivery'] = 1;
        if (empty($pickupMapFilters['segment']) && !empty($car['segment'])) $pickupMapFilters['segment'] = $car['segment'];
        $pickupMapAddress = $inputValue('delivery_address') ?: $car['city'];
    @endphp
    <dialog id="booking-pickup-dialog" class="amd-return-map-dialog" data-booking-pickup-dialog aria-labelledby="booking-pickup-title">
        <div class="amd-return-map-heading"><div><h2 id="booking-pickup-title">Dove vuoi ricevere l’auto?</h2><p>Cerca il luogo e indica il punto preciso. Conserveremo le date e verificheremo le offerte per il nuovo ritiro.</p></div><button type="button" class="amd-button amd-button-secondary" data-booking-pickup-close autofocus>Chiudi</button></div>
        @include('public-cars.partials.pickup-map', ['filters' => $pickupMapFilters, 'value' => fn ($field) => $field === 'delivery_address' ? $pickupMapAddress : ''])
    </dialog>
@endif
@endsection

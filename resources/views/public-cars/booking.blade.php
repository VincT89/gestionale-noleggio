@extends('layouts.public-cars')
@section('title', 'Prenota la tua auto')
@section('content')
@php
    $accountContact = auth('public_customer')->user();
    $contact = array_replace($accountContact?->hasVerifiedEmail() ? $accountContact->only(['first_name', 'last_name', 'email', 'phone']) : [], $contact ?? []);
    $inputValue = function ($field) use ($contact, $filters) { $v = old($field, $contact[$field] ?? ($field === 'delivery_address' ? ($filters[$field] ?? '') : '')); return is_scalar($v) ? (string) $v : ''; };
    $requestDelivery = (bool) old('request_delivery', $filters['request_delivery'] ?? false);
@endphp
<a class="amd-back" href="{{ route($routePrefix.'.show', ['pricelist' => $car['id']] + $filters) }}">Torna all’auto</a>
<div class="amd-page-heading"><h1>Prenota la tua auto</h1><p>Controlla il riepilogo e inserisci i tuoi recapiti. Versi il 20% del noleggio online, il resto al ritiro. Un’eventuale consegna concordata è indicata separatamente.</p></div>
<div class="amd-detail amd-booking-layout">
    <aside class="amd-quote" aria-labelledby="booking-summary-title">@include('public-cars.partials.booking-summary')</aside>
    <form method="post" action="{{ route($routePrefix.'.booking.store', $car['id']) }}" class="amd-detail-info amd-booking-form" data-booking-form aria-label="Dati per la prenotazione">
        @csrf
        <input type="hidden" name="checkout_token" value="{{ $checkoutToken }}">
        @foreach(\Illuminate\Support\Arr::only($filters, ['pickup_at', 'return_at', 'place_id']) as $field => $fieldValue)<input type="hidden" name="{{ $field }}" value="{{ $fieldValue }}">@endforeach
        <h2>I tuoi dati</h2>
        <p>Non serve creare un account. I recapiti saranno disponibili al noleggiatore che gestirà la prenotazione. @if(!$accountContact)<a href="{{ route('public-account.login') }}">Accedi</a> per usare i dati del tuo profilo.@endif</p>
        @if($errors->any())<div class="amd-errors" role="alert" tabindex="-1" data-booking-errors><strong>Controlla i dati prima di confermare.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <div class="amd-booking-fields">
            @foreach(['first_name' => ['Nome', 'given-name', 'text', 90], 'last_name' => ['Cognome', 'family-name', 'text', 90], 'email' => ['Email', 'email', 'email', 191], 'phone' => ['Telefono', 'tel', 'tel', 32]] as $field => [$label, $autocomplete, $type, $max])
            <div class="amd-field"><label for="booking-{{ $field }}">{{ $label }}</label><input id="booking-{{ $field }}" name="{{ $field }}" type="{{ $type }}" autocomplete="{{ $autocomplete }}" maxlength="{{ $max }}" value="{{ $inputValue($field) }}" required @if($errors->has($field)) aria-invalid="true" aria-describedby="error-{{ $field }}" @endif>@error($field)<small id="error-{{ $field }}" class="amd-field-error">{{ $message }}</small>@enderror</div>
            @endforeach
        </div>
        @if(($car['custom_delivery_enabled'] ?? false) && empty($car['delivery_request_id']))
        <fieldset class="amd-delivery-request">
            <legend>Consegna in hotel o a un indirizzo</legend>
            <p>{{ $car['delivery_area'] }}</p>
            <input type="hidden" name="request_delivery" value="0">
            <label class="amd-booking-accept"><input type="checkbox" name="request_delivery" value="1" data-delivery-choice @checked($requestDelivery)><span>Voglio richiedere una consegna personalizzata.</span></label>
            <div class="amd-field"><label for="delivery-address">Hotel e indirizzo completo di consegna</label><input id="delivery-address" name="delivery_address" minlength="8" maxlength="500" value="{{ $inputValue('delivery_address') }}" data-delivery-address @if($requestDelivery) required @endif></div>
            <div class="amd-field"><label for="delivery-notes">Indicazioni per la consegna (facoltative)</label><textarea id="delivery-notes" name="delivery_notes" rows="3" maxlength="2000">{{ $inputValue('delivery_notes') }}</textarea></div>
            <p>In questo caso invii prima una richiesta: il noleggiatore conferma indirizzo e supplemento. Pagherai dopo aver visto la proposta e il totale aggiornato. Luogo di riconsegna: {{ $car['location'] }}.</p>
        </fieldset>
        @endif
        <div class="amd-booking-trap" aria-hidden="true"><label for="booking-website">Sito web</label><input id="booking-website" name="website" tabindex="-1" autocomplete="off"></div>
        <p class="amd-booking-help">I dati completi del conducente e i documenti saranno verificati dal noleggiatore prima della consegna. @if(config('public_cars.privacy_url'))<a href="{{ config('public_cars.privacy_url') }}" target="_blank" rel="noopener noreferrer">Informativa privacy</a>.@endif</p>
        <label class="amd-booking-accept" for="accept-summary"><input id="accept-summary" name="accept_summary" type="checkbox" value="1" required><span>Ho controllato date, sede, importi e condizioni dell’offerta. Accetto la quota online indicata nel riepilogo e il saldo al ritiro. Se richiedo una consegna personalizzata, attendo prima la proposta del noleggiatore.</span></label>
        <button class="amd-button" type="submit" data-checkout-submit data-standard-checkout-label="{{ config('amd_rent.payment_mode') === 'stripe' ? 'Prosegui al pagamento' : 'Conferma prenotazione' }}">{{ $requestDelivery ? 'Invia richiesta di consegna' : (config('amd_rent.payment_mode') === 'stripe' ? 'Prosegui al pagamento' : 'Conferma prenotazione') }}</button>
        <p class="amd-booking-help">Disponibilità e prezzo vengono ricontrollati alla conferma.</p>
    </form>
</div>
@endsection

@extends('layouts.public-cars')
@section('title', 'Prenota la tua auto')
@section('content')
@php
    $inputValue = function ($field) { $v = old($field, ''); return is_scalar($v) ? (string) $v : ''; };
@endphp
<a class="amd-back" href="{{ route($routePrefix.'.show', ['offer' => $car['id']] + $filters) }}">Torna all’auto</a>
<div class="amd-page-heading"><h1>Prenota la tua auto</h1><p>Controlla il riepilogo e inserisci i tuoi recapiti. Pagherai il noleggio al ritiro.</p></div>
<div class="amd-detail amd-booking-layout">
    <aside class="amd-quote" aria-labelledby="booking-summary-title">@include('public-cars.partials.booking-summary')</aside>
    <form method="post" action="{{ route($routePrefix.'.booking.store', $car['id']) }}" class="amd-detail-info amd-booking-form" data-booking-form aria-label="Dati per la prenotazione">
        @csrf
        <input type="hidden" name="checkout_token" value="{{ $checkoutToken }}">
        @foreach(['pickup_at', 'return_at'] as $field)<input type="hidden" name="{{ $field }}" value="{{ $filters[$field] }}">@endforeach
        <h2>I tuoi dati</h2>
        <p>Non serve creare un account. I recapiti saranno disponibili al noleggiatore che gestirà la prenotazione.</p>
        @if($errors->any())<div class="amd-errors" role="alert" tabindex="-1" data-booking-errors><strong>Controlla i dati prima di confermare.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <div class="amd-booking-fields">
            @foreach(['first_name' => ['Nome', 'given-name', 'text', 90], 'last_name' => ['Cognome', 'family-name', 'text', 90], 'email' => ['Email', 'email', 'email', 191], 'phone' => ['Telefono', 'tel', 'tel', 32]] as $field => [$label, $autocomplete, $type, $max])
            <div class="amd-field"><label for="booking-{{ $field }}">{{ $label }}</label><input id="booking-{{ $field }}" name="{{ $field }}" type="{{ $type }}" autocomplete="{{ $autocomplete }}" maxlength="{{ $max }}" value="{{ $inputValue($field) }}" required @if($errors->has($field)) aria-invalid="true" aria-describedby="error-{{ $field }}" @endif>@error($field)<small id="error-{{ $field }}" class="amd-field-error">{{ $message }}</small>@enderror</div>
            @endforeach
        </div>
        <div class="amd-booking-trap" aria-hidden="true"><label for="booking-website">Sito web</label><input id="booking-website" name="website" tabindex="-1" autocomplete="off"></div>
        <p class="amd-booking-help">I dati completi del conducente e i documenti saranno verificati dal noleggiatore prima della consegna. <a href="{{ config('public_cars.website_url') }}/politiche-del-siito/" target="_blank" rel="noopener noreferrer">Politiche del sito</a>.</p>
        <label class="amd-booking-accept" for="accept-summary"><input id="accept-summary" name="accept_summary" type="checkbox" value="1" required><span>Ho controllato date, sede, importi e condizioni dell’offerta. Confermo il pagamento al ritiro.</span></label>
        @if($bookable)<button class="amd-button" type="submit">Conferma prenotazione</button>
        @else<p class="amd-booking-notice" role="status">Per confermare la prenotazione, pubblica l’offerta con prezzi verificati nel catalogo.</p>@endif
        <p class="amd-booking-help">Disponibilità e prezzo vengono ricontrollati alla conferma.</p>
    </form>
</div>
@endsection

@php
    $money = fn ($cents) => number_format($cents / 100, 2, ',', '.').' €';
    $liveOptions = !empty($bookingOptions['extras']);
    $selectedExtras = $car['selected_extras'] ?? [];
    $extrasTotal = \App\Services\AmdRent\BookingOptions::total($selectedExtras);
@endphp
<h2 id="booking-summary-title">Il tuo noleggio</h2>
@if(!isset($booking) && $car['has_photo'])<img class="amd-booking-car-photo" src="{{ route($routePrefix.'.photo', $car['id']) }}" alt="{{ $car['photo_is_reference'] ? 'Immagine indicativa: '.$car['title'] : $car['title'] }}" width="360" height="200">@if($car['photo_is_reference'])<small>Immagine indicativa del modello</small>@endif @endif
<h3>{{ $car['title'] }}</h3>
@include('public-cars.partials.selected-map-point', ['point' => $car['delivery_destination'] ?? null])
@if(!empty($car['delivery_address']))
<p class="amd-summary-label">Ritiro concordato</p><p><strong>{{ $car['delivery_address'] }}</strong></p>
@else
<div data-summary-standard-pickup @if($requestDelivery ?? false) hidden @endif><p class="amd-summary-label">Luogo di ritiro</p><p>{{ $car['location'] }} — {{ $car['city'] }}<br>{{ $car['address'] }}</p></div>
@if(!isset($booking) && ($car['custom_delivery_enabled'] ?? false))
<div data-summary-custom-pickup @unless($requestDelivery ?? false) hidden @endunless><p class="amd-summary-label">Ritiro richiesto</p><p>{{ $car['delivery_destination']['label'] ?? '' }}</p><p>Disponibilità e appuntamenti da confermare. Consegna e ritiro inclusi.</p></div>
@endif
@endif
<p class="amd-summary-label">Luogo di riconsegna</p>
@if(!empty($car['return_address']))
<p><strong>{{ $car['return_address'] }}</strong></p>
@include('public-cars.partials.selected-map-point', ['point' => $car['return_destination'] ?? null, 'pointType' => 'return'])
@if(empty($car['delivery_request_id']))<p>Appuntamento da confermare.</p>@endif
@else<p>{{ $car['location'] }} — {{ $car['city'] }}<br>{{ $car['address'] }}</p>@endif
@if(isset($booking) || !empty($car['delivery_request_id']))<p>Servizio erogato da <strong>{{ $car['organization'] }}</strong>.</p>@endif
<dl class="amd-quote-lines">
    <div><dt>Ritiro</dt><dd>{{ \Carbon\CarbonImmutable::parse($filters['pickup_at'])->format('d/m/Y H:i') }}</dd></div>
    <div><dt>Riconsegna</dt><dd>{{ \Carbon\CarbonImmutable::parse($filters['return_at'])->format('d/m/Y H:i') }}</dd></div>
    <div><dt>Durata tariffata</dt><dd>{{ $car['days'] }} {{ $car['days'] === 1 ? 'giorno' : 'giorni' }}</dd></div>
</dl>
@if($liveOptions || $selectedExtras !== [])
    <dl class="amd-quote-lines">
        <div><dt>Tariffa di partenza</dt><dd>{{ $money($car['total_cents'] - $extrasTotal) }}</dd></div>
        @if($liveOptions)
            <div data-extra-summary @if($selectedExtras === []) hidden @endif>
                <dt>Extra selezionati</dt><dd data-options-subtotal>{{ $money($extrasTotal) }}</dd>
            </div>
        @else
            @foreach($selectedExtras as $extra)
                <div><dt>{{ $extra['name'] }}</dt><dd>{{ $money($extra['total_cents']) }}</dd></div>
            @endforeach
        @endif
    </dl>
@endif
<p class="amd-total-label">{{ ($requestDelivery ?? false) && empty($car['delivery_request_id']) ? 'Totale richiesto, IVA inclusa' : 'Totale concordato, IVA inclusa' }}</p>
<strong class="amd-total" data-options-total>{{ $money($car['total_cents']) }}</strong><p><span data-options-daily>{{ $money($car['total_cents'] / max(1, $car['days'])) }} al giorno in media.</span>@if(!empty($car['delivery_destination'])) Consegna e ritiro inclusi.@endif</p>
<p>{{ $car['prices_include_vat'] ? 'IVA inclusa' : 'Importo di listino, IVA da verificare' }}</p>
<dl class="amd-quote-lines">
    <div><dt>Cauzione separata</dt><dd>{{ $money($car['deposit_cents']) }}</dd></div>
    <div><dt>Chilometri inclusi</dt><dd>{{ $car['km_per_day'] === null ? 'Illimitati' : $car['km_per_day'].' km/giorno' }}</dd></div>
    @if($car['km_per_day'] !== null)<div><dt>Chilometri extra</dt><dd>{{ $money($car['extra_km_cents']) }}/km</dd></div>@endif
</dl>
@php
    $online = isset($booking) ? $booking->online_due_cents : \App\Services\AmdRent\DeliveryQuotes::onlineDue($car);
    $onlineMode = isset($booking) ? $booking->payment_method === 'stripe' : config('amd_rent.payment_mode') === 'stripe';
    $hasOnline = $onlineMode && $online > 0;
@endphp
@if($hasOnline || ($liveOptions && $onlineMode))
    <div data-options-online-summary @unless($hasOnline) hidden @endunless>
        <dl class="amd-quote-lines">
            <div><dt>Quota online{{ ($requestDelivery ?? false) ? ' prevista' : '' }}</dt><dd data-options-online>{{ $money($online) }}</dd></div>
            <div><dt>Saldo previsto al ritiro</dt><dd data-options-balance>{{ $money(isset($booking) ? $booking->pickup_due_cents : $car['total_cents'] - $online) }}</dd></div>
            @if(isset($booking))<div><dt>Versato online, al netto dei rimborsi</dt><dd>{{ $money($booking->online_paid_cents - $booking->refunded_cents) }}</dd></div>@endif
        </dl>
        <p class="amd-booking-payment">La cauzione si gestisce a parte con il noleggiatore.</p>
    </div>
@endif
@if(!$hasOnline || $liveOptions)
    <p class="amd-booking-payment" data-options-pickup-summary @if($hasOnline) hidden @endif>Pagamento al ritiro. Nessun addebito online.</p>
@endif
@if($car['description'])<h3>Condizioni dell’offerta</h3><p class="amd-description">{{ $car['description'] }}</p>@endif

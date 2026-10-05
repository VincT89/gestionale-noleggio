@php $money = fn ($cents) => number_format($cents / 100, 2, ',', '.').' €'; @endphp
<h2 id="booking-summary-title">Il tuo noleggio</h2>
@if(!isset($booking) && $car['has_photo'])<img class="amd-booking-car-photo" src="{{ route($routePrefix.'.photo', $car['id']) }}" alt="{{ $car['photo_is_reference'] ? 'Immagine indicativa: '.$car['title'] : $car['title'] }}" width="360" height="200">@if($car['photo_is_reference'])<small>Immagine indicativa del modello</small>@endif @endif
<h3>{{ $car['title'] }}</h3>
@if(!empty($car['delivery_address']))
<p class="amd-summary-label">Ritiro concordato</p><p><strong>{{ $car['delivery_address'] }}</strong></p>
@else
<div data-summary-standard-pickup @if($requestDelivery ?? false) hidden @endif><p class="amd-summary-label">Luogo di ritiro</p><p>{{ $car['location'] }} — {{ $car['city'] }}<br>{{ $car['address'] }}</p></div>
@if(!isset($booking) && ($car['custom_delivery_enabled'] ?? false))
<div data-summary-custom-pickup @unless($requestDelivery ?? false) hidden @endunless><p class="amd-summary-label">Ritiro richiesto</p><p data-summary-delivery-address>{{ isset($inputValue) ? $inputValue('delivery_address') : '' }}</p><p>Indirizzo e supplemento da confermare dal noleggiatore.</p></div>
@endif
@endif
<p class="amd-summary-label">Luogo di riconsegna</p>
<p>{{ $car['location'] }} — {{ $car['city'] }}<br>{{ $car['address'] }}</p>
<p>Servizio erogato da <strong>{{ $car['organization'] }}</strong>.</p>
<dl class="amd-quote-lines">
    <div><dt>Ritiro</dt><dd>{{ \Carbon\CarbonImmutable::parse($filters['pickup_at'])->format('d/m/Y H:i') }}</dd></div>
    <div><dt>Riconsegna</dt><dd>{{ \Carbon\CarbonImmutable::parse($filters['return_at'])->format('d/m/Y H:i') }}</dd></div>
    <div><dt>Durata tariffata</dt><dd>{{ $car['days'] }} {{ $car['days'] === 1 ? 'giorno' : 'giorni' }}</dd></div>
</dl>
<p class="amd-total-label">{{ ($requestDelivery ?? false) && empty($car['delivery_request_id']) ? 'Totale noleggio, IVA inclusa' : 'Totale concordato, IVA inclusa' }}</p>
<strong class="amd-total">{{ $money($car['total_cents']) }}</strong>
<p>{{ $car['prices_include_vat'] ? 'IVA inclusa' : 'Importo di listino, IVA da verificare' }}</p>
<dl class="amd-quote-lines">
    <div><dt>Cauzione separata</dt><dd>{{ $money($car['deposit_cents']) }}</dd></div>
    <div><dt>Chilometri inclusi</dt><dd>{{ $car['km_per_day'] === null ? 'Illimitati' : $car['km_per_day'].' km/giorno' }}</dd></div>
    @if($car['km_per_day'] !== null)<div><dt>Chilometri extra</dt><dd>{{ $money($car['extra_km_cents']) }}/km</dd></div>@endif
</dl>
@if((isset($booking) && $booking->payment_method === 'stripe') || (!isset($booking) && config('amd_rent.payment_mode') === 'stripe'))
@php $online = isset($booking) ? $booking->online_due_cents : \App\Services\AmdRent\DeliveryQuotes::onlineDue($car); @endphp
<dl class="amd-quote-lines">@if(!empty($car['delivery_fee_cents']))<div><dt>Noleggio</dt><dd>{{ $money($car['rental_total_cents']) }}</dd></div><div><dt>Supplemento consegna</dt><dd>{{ $money($car['delivery_fee_cents']) }}</dd></div>@endif<div><dt>Quota online</dt><dd>{{ $money($online) }}</dd></div><div><dt>Saldo previsto al ritiro</dt><dd>{{ $money(isset($booking) ? $booking->pickup_due_cents : $car['total_cents'] - $online) }}</dd></div>@if(isset($booking))<div><dt>Versato online, al netto dei rimborsi</dt><dd>{{ $money($booking->online_paid_cents - $booking->refunded_cents) }}</dd></div>@endif</dl><p class="amd-booking-payment">La quota online sul noleggio è il 20%. Cauzione separata, da gestire con il noleggiatore.</p>
@else<p class="amd-booking-payment">Pagamento al ritiro. Nessun addebito online.</p>@endif
@if($car['description'])<h3>Condizioni dell’offerta</h3><p class="amd-description">{{ $car['description'] }}</p>@endif

@php $money = fn ($cents) => number_format($cents / 100, 2, ',', '.').' €'; @endphp
<h2 id="booking-summary-title">Il tuo noleggio</h2>
<h3>{{ $car['title'] }}</h3>
<p>{{ $car['location'] }} — {{ $car['city'] }}<br>{{ $car['address'] }}</p>
<p>Servizio erogato da <strong>{{ $car['organization'] }}</strong>.</p>
<dl class="amd-quote-lines">
    <div><dt>Ritiro</dt><dd>{{ \Carbon\CarbonImmutable::parse($filters['pickup_at'])->format('d/m/Y H:i') }}</dd></div>
    <div><dt>Riconsegna</dt><dd>{{ \Carbon\CarbonImmutable::parse($filters['return_at'])->format('d/m/Y H:i') }}</dd></div>
    <div><dt>Durata tariffata</dt><dd>{{ $car['days'] }} {{ $car['days'] === 1 ? 'giorno' : 'giorni' }}</dd></div>
</dl>
<p class="amd-total-label">{{ isset($booking) && $booking->status_label !== 'Confermata' ? 'Totale noleggio concordato' : 'Totale noleggio da pagare al ritiro' }}</p>
<strong class="amd-total">{{ $money($car['total_cents']) }}</strong>
<p>{{ $car['prices_include_vat'] ? 'IVA inclusa' : 'Importo di listino, IVA da verificare' }}</p>
<dl class="amd-quote-lines">
    <div><dt>Cauzione separata</dt><dd>{{ $money($car['deposit_cents']) }}</dd></div>
    <div><dt>Chilometri inclusi</dt><dd>{{ $car['km_per_day'] ? $car['km_per_day'].' km/giorno' : 'Da concordare' }}</dd></div>
    @if($car['km_per_day'])<div><dt>Chilometri extra</dt><dd>{{ $money($car['extra_km_cents']) }}/km</dd></div>@endif
</dl>
@if(!isset($booking) || $booking->status_label === 'Confermata')<p class="amd-booking-payment">Pagamento al ritiro. Nessun addebito online.</p>@endif
@if($car['description'])<h3>Condizioni dell’offerta</h3><p class="amd-description">{{ $car['description'] }}</p>@endif

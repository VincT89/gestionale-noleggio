<!doctype html>
<html lang="it"><head><meta charset="utf-8"><title>Prenotazione {{ $booking->reference }}</title>
<style>
@page{margin:32px 38px 40px}body{font-family:'DejaVu Sans',sans-serif;font-size:10px;line-height:1.5;color:#172438}h1{font-size:23px;margin:24px 0 5px;color:#1c244b}h2{font-size:13px;margin:20px 0 8px;color:#1c244b}p{margin:5px 0}table{width:100%;border-collapse:collapse;table-layout:fixed}td{vertical-align:top;word-wrap:break-word}.brand{background:white;color:#112e51;border-bottom:2px solid #112e51;padding:0}.brand img{width:160px}.reference{font-size:11px;text-align:right;padding:18px}.details td{padding:8px 10px;border-bottom:1px solid #d5dce3}.label{color:#455469;width:37%}.total{font-size:21px;color:#1c244b;font-weight:bold}.columns td{width:50%;padding-right:22px}.note{margin-top:18px;padding:12px 14px;background:#edf0f5}.footer{margin-top:24px;font-size:9px;color:#455469}.conditions{white-space:pre-line;word-wrap:break-word}.keep{page-break-inside:avoid}
</style></head><body>
@php $money = fn ($cents) => number_format($cents / 100, 2, ',', '.').' EUR'; @endphp
<table class="brand"><tr><td style="padding:12px"><img src="data:image/png;base64,{{ base64_encode(file_get_contents(public_path('images/amd-rent-logo-v2.png'))) }}" alt="AMD Rent"></td><td class="reference">{{ $booking->reference }}<br>Stato: {{ $booking->status_label }}</td></tr></table>
<h1>{{ $booking->status_label === 'Confermata' ? 'Conferma di prenotazione' : 'Riepilogo prenotazione' }}</h1>
<p>Registrata il {{ $booking->accepted_at->format('d/m/Y H:i') }}. Orari italiani.</p>
<table class="columns"><tr><td><h2>Cliente</h2><p>{{ $booking->first_name }} {{ $booking->last_name }}</p></td><td><h2>Noleggiatore operativo</h2><p><strong>{{ $car['organization'] }}</strong></p><p>{{ $car['location'] }}<br>{{ $car['address'] }}<br>{{ $car['city'] }}</p></td></tr></table>
<h2>Auto e periodo</h2>
<p>Luogo di ritiro: <strong>{{ $car['delivery_address'] ?? $car['location'].' — '.$car['city'] }}</strong></p>
<p>Luogo di riconsegna: <strong>{{ $car['return_address'] ?? $car['location'].' — '.$car['city'] }}</strong></p>
@include('public-cars.partials.selected-map-point', ['point' => $car['return_destination'] ?? null, 'pointType' => 'return'])
<table class="details"><tr><td class="label">Auto</td><td>{{ $car['title'] }}</td></tr><tr><td class="label">Ritiro</td><td>{{ $booking->pickup_at->format('d/m/Y H:i') }}</td></tr><tr><td class="label">Riconsegna</td><td>{{ $booking->return_at->format('d/m/Y H:i') }}</td></tr><tr><td class="label">Durata tariffata</td><td>{{ $car['days'] }} {{ $car['days'] === 1 ? 'giorno' : 'giorni' }}</td></tr></table>
<div class="keep"><h2>Importi e condizioni concordate</h2><table class="details"><tr><td class="label">Totale noleggio, IVA inclusa</td><td class="total">{{ $money($booking->total_cents) }}</td></tr>@if($booking->payment_method === 'stripe')<tr><td class="label">Quota online prevista</td><td>{{ $money($booking->online_due_cents) }}</td></tr><tr><td class="label">Versato online (netto rimborsi)</td><td>{{ $money($booking->online_paid_cents - $booking->refunded_cents) }}</td></tr><tr><td class="label">Saldo previsto al ritiro</td><td>{{ $money($booking->pickup_due_cents) }}</td></tr>@else<tr><td class="label">Pagamento previsto</td><td>Al ritiro</td></tr>@endif<tr><td class="label">Cauzione separata</td><td>{{ $money($booking->deposit_cents) }}</td></tr><tr><td class="label">Chilometri inclusi</td><td>{{ $car['km_per_day'] === null ? 'Illimitati' : $car['km_per_day'].' km/giorno' }}</td></tr>@if($car['km_per_day'] !== null)<tr><td class="label">Chilometri extra</td><td>{{ $money($car['extra_km_cents']) }}/km</td></tr>@endif</table></div>
@if($car['description'])<h2>Condizioni dell’offerta</h2><p class="conditions">{{ $car['description'] }}</p>@endif
<div class="note">
@if($booking->status_label === 'Confermata')
La prenotazione è confermata e il veicolo è riservato per il periodo indicato. Il noleggiatore completa e verifica i dati del conducente e il contratto prima della consegna.
@else
Stato attuale della prenotazione: <strong>{{ $booking->status_label }}</strong>.
@endif
<br>Documento di riepilogo della prenotazione.</div>
<p class="footer">Per informazioni indica il riferimento {{ $booking->reference }}.<br>AMD Rent - {{ route('public-cars.index') }}@if(config('public_cars.contact_email')) - {{ config('public_cars.contact_email') }}@endif @if(config('public_cars.contact_phone')) - {{ config('public_cars.contact_phone') }}@endif</p>
</body></html>

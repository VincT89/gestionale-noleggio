@extends('layouts.public-cars')
@section('title', 'Le mie prenotazioni')
@section('content')
@include('public-account.navigation')@include('public-account.feedback')
@php $money = fn ($cents) => number_format($cents / 100, 2, ',', '.').' €'; @endphp
<div class="amd-account-toolbar"><h1>Le tue prenotazioni</h1><form method="get" action="{{ route('public-account.bookings') }}"><div class="amd-field"><label for="period">Mostra</label><select id="period" name="period">@foreach(['all' => 'Tutte le prenotazioni', 'upcoming' => 'In corso e future', 'past' => 'Passate'] as $key => $label)<option value="{{ $key }}" @selected($period === $key)>{{ $label }}</option>@endforeach</select></div><button class="amd-button amd-button-secondary">Filtra</button></form></div>
<p>Qui trovi le prenotazioni collegate al tuo account e quelle effettuate con l’email verificata {{ $customer->email }}.</p>
<div class="amd-account-list">@forelse($bookings as $booking)
<article class="amd-account-record"><div><small>Riferimento {{ $booking->reference }}</small><h2>{{ $booking->quote_snapshot['title'] }}</h2><p><strong>{{ $booking->status_label }}</strong></p><p>Ritiro: {{ $booking->pickup_at->format('d/m/Y H:i') }}<br>Riconsegna: {{ $booking->return_at->format('d/m/Y H:i') }}</p><p>{{ $booking->quote_snapshot['location'] }} · {{ $booking->quote_snapshot['city'] }}</p></div>
<div><dl><div><dt>Totale prenotazione</dt><dd>{{ $money($booking->total_cents) }}</dd></div><div><dt>Pagato online</dt><dd>{{ $money($booking->online_paid_cents) }}</dd></div>@if($booking->refunded_cents > 0)<div><dt>Rimborsato</dt><dd>{{ $money($booking->refunded_cents) }}</dd></div>@endif<div><dt>Saldo previsto al ritiro</dt><dd>{{ $money($booking->pickup_due_cents) }}</dd></div></dl><a class="amd-button" href="{{ route('public-account.booking', $booking->reference) }}">Apri prenotazione</a></div></article>
@empty<div class="amd-account-empty"><h2>{{ $period === 'all' ? 'Non ci sono ancora prenotazioni.' : 'Nessuna prenotazione in questo periodo.' }}</h2><p>Se hai prenotato con un’altra email, usa l’account associato a quell’indirizzo oppure contatta l’assistenza.</p><a class="amd-button" href="{{ route('public-cars.index') }}">Cerca un’auto</a></div>@endforelse</div>
@include('public-account.pagination', ['items' => $bookings])
@endsection

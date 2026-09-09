@extends('layouts.public-cars')
@section('title', 'Prenotazione '.$booking->status_label)
@section('content')
<div class="amd-page-heading">
    <h1>Prenotazione {{ mb_strtolower($booking->status_label) }}</h1>
    <p class="amd-booking-reference">Riferimento <strong>{{ $booking->reference }}</strong></p>
</div>
<div class="amd-detail">
    <section class="amd-detail-info" aria-label="Informazioni sulla prenotazione">
        <h2>Il tuo noleggiatore</h2>
        <p><strong>{{ $car['organization'] }}</strong><br>{{ $car['location'] }} — {{ $car['city'] }}<br>{{ $car['address'] }}</p>
        @if($booking->status_label === 'Confermata')
            <p>La prenotazione è registrata nel gestionale del noleggiatore e l’auto è riservata per il periodo indicato.</p>
            <p>Conserva questa pagina e il riferimento. Il pagamento del noleggio avviene al ritiro; la cauzione è indicata separatamente nel riepilogo.</p>
            <p>Il noleggiatore completerà con te i dati del conducente e il contratto prima della consegna.</p>
        @else<p>Stato aggiornato della prenotazione: <strong>{{ $booking->status_label }}</strong>.</p>@endif
        <p>Per richieste relative alla prenotazione, <a href="{{ config('public_cars.website_url') }}/contact-us/">contatta AMD Mobility</a> indicando il riferimento {{ $booking->reference }}.</p>
        <p><a class="amd-button" href="{{ $booking->pdfUrl(true) }}">Scarica conferma PDF</a></p>
        <p><a class="amd-button amd-button-secondary" href="{{ $booking->pdfUrl() }}" target="_blank" rel="noopener noreferrer">Stampa conferma</a></p>
        <a class="amd-button amd-button-secondary" href="{{ route($routePrefix.'.index', $filters) }}">Torna alla ricerca</a>
    </section>
    <aside class="amd-quote" aria-labelledby="booking-summary-title">@include('public-cars.partials.booking-summary')</aside>
</div>
@endsection

@extends('layouts.public-cars')
@section('title', 'Prenotazione '.$booking->status_label)
@section('content')
@if(request()->routeIs('public-account.*'))@include('public-account.navigation')@endif
<div class="amd-page-heading amd-photo-heading">
    <div>
    <h1>Prenotazione {{ mb_strtolower($booking->status_label) }}</h1>
    <p class="amd-booking-reference">Riferimento <strong>{{ $booking->reference }}</strong></p>
</div>
    @include('public-cars.partials.context-photo', ['photoScene' => 'confirmation'])
</div>
<div class="amd-detail">
    <section class="amd-detail-info" aria-label="Informazioni sulla prenotazione">
        @if(session('payment_error'))<div class="amd-errors" role="alert">{{ session('payment_error') }}</div>@endif
        @if($booking->payment_status === 'pending')<h2>Completa il pagamento per confermare</h2><p>L’auto è riservata temporaneamente. Se hai appena pagato, attendi la verifica e aggiorna questa pagina.</p><form method="post" action="{{ \Illuminate\Support\Facades\URL::signedRoute('public-bookings.pay', ['reference' => $booking->reference]) }}">@csrf<button class="amd-button">Verifica o riprendi il pagamento</button></form>@elseif($booking->payment_status === 'review')<div class="amd-errors" role="alert">Il pagamento richiede una verifica di AMD Rent. Non effettuare un secondo pagamento; contatta l’assistenza indicando il riferimento.</div>@endif
        @if($deliveryRequest ?? null)
            <p>La proposta di consegna resta nella tua richiesta. Puoi riaprirla e verificare se è ancora possibile prenotare.</p>
            <p><a class="amd-button amd-button-secondary" href="{{ $deliveryRequest->publicUrl() }}">Torna alla richiesta di consegna</a></p>
        @endif
        <h2>Il tuo noleggiatore</h2>
        <p><strong>{{ $car['organization'] }}</strong><br>{{ $car['location'] }} — {{ $car['city'] }}<br>{{ $car['address'] }}</p>
        @if($booking->status_label === 'Confermata')
            <p>La prenotazione è registrata nel gestionale del noleggiatore e l’auto è riservata per il periodo indicato.</p>
            <p>Conserva questa pagina e il riferimento. Il saldo previsto si paga al ritiro; la cauzione è indicata separatamente nel riepilogo.</p>
            <p>Il noleggiatore completerà con te i dati del conducente e il contratto prima della consegna.</p>
        @else<p>Stato aggiornato della prenotazione: <strong>{{ $booking->status_label }}</strong>.</p>@endif
        <p>Per richieste relative alla prenotazione, consulta la pagina <a href="{{ route('public-site.support') }}">Assistenza AMD Rent</a> indicando il riferimento {{ $booking->reference }}.</p>
        <p><a class="amd-button" href="{{ request()->routeIs('public-account.*') ? route('public-account.booking.pdf', ['reference' => $booking->reference, 'download' => 1]) : $booking->pdfUrl(true) }}">Scarica conferma PDF</a></p>
        <p><a class="amd-button amd-button-secondary" href="{{ request()->routeIs('public-account.*') ? route('public-account.booking.pdf', $booking->reference) : $booking->pdfUrl() }}" target="_blank" rel="noopener noreferrer">Stampa conferma</a></p>
        <a class="amd-button amd-button-secondary" href="{{ route($routePrefix.'.index', $filters) }}">Torna alla ricerca</a>
    </section>
    <aside class="amd-quote" aria-labelledby="booking-summary-title">@include('public-cars.partials.booking-summary')</aside>
</div>
@include('public-account.invitation')
@endsection

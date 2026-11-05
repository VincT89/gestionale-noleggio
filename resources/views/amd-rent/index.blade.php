<x-app-layout>
<x-slot name="header"><h1 class="text-xl font-semibold text-white">AMD Rent</h1></x-slot>
<div class="amr"><x-amd-rent-nav />
    <header class="amr-heading"><h2>Il lavoro da seguire</h2><p>{{ auth()->user()->hasRole('admin') ? 'Prenotazioni e pratiche di tutti i noleggiatori, in un unico spazio.' : 'Prenotazioni, richieste e pratiche della tua organizzazione.' }}</p></header>
    <div class="amr-columns">
        <section class="amr-panel app-surface"><h2>Richieste da gestire</h2>
            @forelse($requests as $item)<a class="amr-row" href="{{ route('amd-rent.enquiries.show', $item) }}"><strong>{{ $item->customer_name }}</strong><span>{{ $item->type === 'delivery' ? 'Consegna personalizzata' : 'Lungo termine' }} · {{ $item->status_label }}</span><small>{{ $item->reference }}</small></a>@empty<p>Non ci sono richieste in attesa.</p>@endforelse
            <a class="amr-link" href="{{ route('amd-rent.enquiries.index') }}">Apri le pratiche di lungo termine</a>
        </section>
        <section class="amr-panel app-surface"><h2>Prossimi ritiri</h2>
            @forelse($upcoming as $booking)<div class="amr-row"><strong>{{ $booking->first_name }} {{ $booking->last_name }}</strong><span>{{ $booking->pickup_at->format('d/m/Y H:i') }} · {{ $booking->quote_snapshot['title'] }}</span><small>{{ $booking->reference }}</small></div>@empty<p>Nessun ritiro confermato in programma.</p>@endforelse
            <a class="amr-link" href="{{ route('public-bookings.index') }}">Apri prenotazioni e pagamenti</a>
            @if($pendingPayments)<p><a class="amr-link" href="{{ route('public-bookings.index', ['payment' => 'pending']) }}">Pagamenti in attesa: {{ $pendingPayments }}</a></p>@endif
            @if($reviewPayments)<p><a class="amr-link" href="{{ route('public-bookings.index', ['payment' => 'review']) }}">Pagamenti da verificare: {{ $reviewPayments }}</a></p>@endif
        </section>
    </div>
    <section class="amr-panel app-surface"><h2>Luoghi e consegne</h2><p>Luoghi attivi per le ricerche: {{ $deliveryCount }}. Da “Luoghi serviti” puoi abilitare le richieste per hotel e indirizzi nella tua zona. Per ogni richiesta confermi la possibilità di consegna e il supplemento.</p><div class="amr-actions">@can('vehicle_pricing.update')<a class="amr-button" href="{{ route('public-deliveries.index') }}">Gestisci luoghi serviti</a>@endcan<a class="amr-link" href="{{ route('public-cars.index') }}" target="_blank" rel="noopener noreferrer">Apri AMD Rent</a></div></section>
</div></x-app-layout>

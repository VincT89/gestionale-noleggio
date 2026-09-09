<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-white">Prenotazioni dal sito</h2></x-slot>
    <div class="max-w-7xl mx-auto space-y-5">
        <div class="app-surface rounded border p-4 sm:p-6">
            <h1 class="font-semibold text-lg">Prenotazioni con pagamento al ritiro</h1>
            <p class="app-muted mt-2">Ogni prenotazione è già collegata a un noleggio e impegna il veicolo. Apri il noleggio per completare i dati del cliente, preparare il contratto e registrare il pagamento al ritiro.</p>
            <form class="mt-4 flex flex-wrap items-end gap-3" method="get" action="{{ route('public-bookings.index') }}"><div class="flex-1 min-w-0"><label class="block text-sm mb-1" for="booking-search">Riferimento, cognome o email</label><input class="app-field rounded w-full" id="booking-search" name="q" type="search" maxlength="100" value="{{ $filters['q'] ?? '' }}"></div><button class="rounded bg-indigo-700 px-4 py-2 text-white" type="submit">Cerca</button><a class="underline py-2" href="{{ route('public-bookings.index') }}">Azzera</a></form>
        </div>
        @forelse($bookings as $booking)
        <article class="app-surface rounded border p-4 sm:p-6">
            <div class="flex flex-wrap justify-between gap-3"><h2 class="font-semibold text-lg break-all">{{ $booking->reference }}</h2><p>{{ $booking->status_label }}</p></div>
            <div class="mt-4 grid gap-5 md:grid-cols-3">
                <div><h3 class="font-semibold">Cliente</h3><p class="break-words">{{ $booking->first_name }} {{ $booking->last_name }}</p><p class="break-all">{{ $booking->email }}</p><p>{{ $booking->phone }}</p></div>
                <div><h3 class="font-semibold">Auto e ritiro</h3><p>{{ $booking->quote_snapshot['title'] }}</p><p>{{ $booking->quote_snapshot['organization'] }}</p><p>{{ $booking->quote_snapshot['location'] }} — {{ $booking->quote_snapshot['city'] }}</p><p>{{ $booking->pickup_at->format('d/m/Y H:i') }} – {{ $booking->return_at->format('d/m/Y H:i') }}</p></div>
                <div><h3 class="font-semibold">Importi concordati</h3><p>Noleggio: {{ number_format($booking->total_cents / 100, 2, ',', '.') }} €</p><p>Cauzione separata: {{ number_format($booking->deposit_cents / 100, 2, ',', '.') }} €</p><p>Pagamento previsto al ritiro.</p><p class="app-muted text-sm">I pagamenti effettivi sono registrati nel noleggio.</p></div>
            </div>
            @if($booking->rental && !$booking->rental->trashed()) @can('view', $booking->rental)<a class="inline-block mt-4 rounded bg-indigo-700 px-4 py-2 text-white" href="{{ route('rentals.show', $booking->rental_id) }}">Apri noleggio n. {{ $booking->rental->display_number }}</a>@endcan @endif
            <div class="mt-4 flex flex-wrap gap-3">
                <a class="rounded border border-gray-400 px-4 py-2" href="{{ $booking->pdfUrl(true) }}">Scarica PDF</a>
                <a class="rounded border border-gray-400 px-4 py-2" href="{{ $booking->pdfUrl() }}" target="_blank" rel="noopener noreferrer">Stampa conferma</a>
                <a class="rounded border border-gray-400 px-4 py-2" href="{{ $booking->emailComposeUrl() }}">Prepara email</a>
                @if($booking->whatsappComposeUrl())<a class="rounded border border-gray-400 px-4 py-2" href="{{ $booking->whatsappComposeUrl() }}" target="_blank" rel="noopener noreferrer">Prepara WhatsApp</a>@endif
            </div>
            <p class="app-muted text-sm mt-2">Controlla il messaggio e premi Invia nell’app. Il PDF va allegato manualmente dopo averlo scaricato.@if($booking->shareableConfirmationUrl()) Il messaggio include anche il collegamento alla conferma.@endif @unless($booking->whatsappComposeUrl()) Per WhatsApp serve un numero con prefisso internazionale, ad esempio +39.@endunless</p>
        </article>
        @empty<div class="app-surface rounded border p-6"><p>Nessuna prenotazione dal sito{{ !empty($filters['q']) ? ' corrisponde alla ricerca' : ' presente' }}.</p></div>@endforelse
        {{ $bookings->links() }}
    </div>
</x-app-layout>

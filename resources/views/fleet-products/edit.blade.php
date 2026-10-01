<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold text-white break-words">{{ $product->name }}</h1></x-slot>
    <div class="max-w-7xl mx-auto space-y-6">
        <a href="{{ route('fleet-products.index') }}" class="inline-block underline">Tutti i prodotti</a>
        @include('fleet-products.partials.feedback')

        <section class="app-surface rounded border p-4 space-y-4" aria-labelledby="product-title">
            <h2 id="product-title" class="text-lg font-semibold">Dati prodotto</h2>
            <p>ID prodotto: <strong id="product-id">{{ $product->id }}</strong></p>
            <form method="post" action="{{ route('fleet-products.update', $product) }}" class="space-y-4">
                @csrf @method('PUT')
                @include('fleet-products.partials.fields')
                <button type="submit" class="px-4 py-2 rounded bg-indigo-600 text-white font-semibold">Salva prodotto</button>
            </form>
        </section>

        <section class="app-surface rounded border p-4 space-y-4" aria-labelledby="members-title">
            <h2 id="members-title" class="text-lg font-semibold">Auto associate al prodotto</h2>
            <p class="text-sm">Ogni auto conserva il proprio listino, le assegnazioni e le prenotazioni. Rimuovere un’associazione lascia l’auto nella flotta.</p>
            <form method="get" action="{{ route('fleet-products.edit', $product) }}" class="flex flex-wrap items-end gap-3">
                <input type="hidden" name="q" value="{{ $query }}">
                <div class="w-full min-w-0 sm:flex-1 sm:w-auto"><label for="members-search" class="block text-sm font-medium mb-1">Cerca tra le auto associate</label><input id="members-search" type="search" name="members_q" value="{{ $membersQuery }}" maxlength="120" placeholder="Targa, marca o modello" class="app-field w-full rounded border"></div>
                <button type="submit" class="px-4 py-2 rounded border font-semibold">Cerca associate</button>
                @if($membersQuery !== '')<a href="{{ route('fleet-products.edit', ['product' => $product, 'q' => $query]) }}" class="underline py-2">Mostra tutte le associate</a>@endif
            </form>
            <ul class="divide-y" id="product-members">
                @forelse($members as $vehicle)
                    <li class="py-4 flex flex-wrap items-center justify-between gap-3">
                        <div class="min-w-0 break-words"><p class="font-semibold">{{ $vehicle->plate }}</p><p>{{ $vehicle->make }} {{ $vehicle->model }}</p><p class="text-sm">{{ $vehicle->transmission_label }}@if($vehicle->fuel_type_label) · {{ $vehicle->fuel_type_label }}@endif @if($vehicle->trashed()) · Auto archiviata @elseif(!$vehicle->is_active) · Auto non attiva @endif</p></div>
                        <form method="post" action="{{ route('fleet-products.detach', ['product' => $product, 'vehicle' => $vehicle->id]) }}">
                            @csrf @method('DELETE')
                            <button type="submit" class="px-4 py-2 rounded border font-semibold" aria-label="Rimuovi {{ $vehicle->plate }} dal prodotto">Rimuovi dal prodotto</button>
                        </form>
                    </li>
                @empty
                    <li class="py-4">{{ $membersQuery !== '' ? 'Nessuna auto associata corrisponde alla ricerca.' : 'Non ci sono auto associate a questo prodotto.' }}</li>
                @endforelse
            </ul>
            {{ $members->links() }}
        </section>

        <section class="app-surface rounded border p-4 space-y-4" aria-labelledby="candidates-title">
            <h2 id="candidates-title" class="text-lg font-semibold">Associa auto della flotta</h2>
            <p class="text-sm">Cerca il modello o la targa e seleziona le auto da aggiungere. Qui compaiono solo le auto senza prodotto; l’associazione a un altro prodotto va prima rimossa.</p>
            <p class="text-sm">Le auto senza prodotto compaiono singolarmente sul sito. La ricerca usa i luoghi di consegna, i listini attivi e la disponibilità nelle date richieste. Non serve pubblicare un’offerta separata.</p>
            <form method="get" action="{{ route('fleet-products.edit', $product) }}" class="flex flex-wrap items-end gap-3">
                <input type="hidden" name="members_q" value="{{ $membersQuery }}">
                <div class="w-full min-w-0 sm:flex-1 sm:w-auto"><label for="candidate-search" class="block text-sm font-medium mb-1">Cerca auto da associare</label><input id="candidate-search" type="search" name="q" value="{{ $query }}" maxlength="120" placeholder="Targa, marca o modello" class="app-field w-full rounded border"></div>
                <button type="submit" class="px-4 py-2 rounded border font-semibold">Cerca auto</button>
                @if($query !== '')<a href="{{ route('fleet-products.edit', ['product' => $product, 'members_q' => $membersQuery]) }}" class="underline py-2">Mostra tutte le auto</a>@endif
            </form>
            @if($candidates->isNotEmpty())
                <form method="post" action="{{ route('fleet-products.attach', $product) }}" class="space-y-4" x-data="{}">
                    @csrf
                    <div class="flex flex-wrap gap-3">
                        <button type="button" x-cloak @click="$el.closest('form').querySelectorAll('input[type=checkbox]').forEach(input => input.checked = true)" class="underline text-sm">Seleziona tutte le auto di questa pagina</button>
                        <button type="button" x-cloak @click="$el.closest('form').querySelectorAll('input[type=checkbox]').forEach(input => input.checked = false)" class="underline text-sm">Deseleziona tutte</button>
                    </div>
                    <ul class="divide-y" id="product-candidates">
                        @foreach($candidates as $vehicle)
                            <li class="py-3"><label class="flex items-start gap-3 cursor-pointer"><input type="checkbox" name="vehicle_ids[]" value="{{ $vehicle->id }}" class="mt-1 rounded" @checked(in_array((string) $vehicle->id, array_map('strval', array_filter((array) old('vehicle_ids', []), 'is_scalar')), true))><span class="min-w-0 break-words"><strong>{{ $vehicle->plate }}</strong><span class="block">{{ $vehicle->make }} {{ $vehicle->model }}</span><span class="block text-sm">{{ $vehicle->transmission_label }}@if($vehicle->fuel_type_label) · {{ $vehicle->fuel_type_label }}@endif @if(!$vehicle->is_active) · Auto non attiva @endif</span></span></label></li>
                        @endforeach
                    </ul>
                    <button type="submit" class="px-4 py-2 rounded bg-indigo-600 text-white font-semibold">Associa auto selezionate</button>
                    <p class="text-sm">Invia la selezione prima di cambiare pagina o ricerca.</p>
                </form>
                {{ $candidates->links() }}
            @else
                <p>Nessuna auto senza prodotto corrisponde alla ricerca.</p>
            @endif
        </section>
    </div>
</x-app-layout>

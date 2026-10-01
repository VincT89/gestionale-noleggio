<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold text-white">Prodotti della flotta</h1></x-slot>
    <div class="max-w-7xl mx-auto space-y-6">
        <p>Crea un prodotto, come “Panda”, e associa le auto che ne fanno parte. Nel sito pubblico comparirà l’offerta disponibile meno cara per ciascun prodotto, in base alla ricerca del cliente.</p>
        @include('fleet-products.partials.feedback')

        <section class="app-surface rounded border p-4 space-y-4" aria-labelledby="new-product-title">
            <h2 id="new-product-title" class="text-lg font-semibold">Crea prodotto</h2>
            <form method="post" action="{{ route('fleet-products.store') }}" class="space-y-4">
                @csrf
                @include('fleet-products.partials.fields')
                <p class="text-sm">L’ID univoco viene assegnato alla creazione e resta lo stesso anche se cambi il nome.</p>
                <button type="submit" class="px-4 py-2 rounded bg-indigo-600 text-white font-semibold">Crea prodotto</button>
            </form>
        </section>

        <section class="app-surface rounded border p-4 space-y-4" aria-labelledby="products-title">
            <h2 id="products-title" class="text-lg font-semibold">Prodotti</h2>
            <form method="get" action="{{ route('fleet-products.index') }}" class="flex flex-wrap items-end gap-3">
                <div class="w-full min-w-0 sm:flex-1 sm:w-auto"><label for="product-search" class="block text-sm font-medium mb-1">Cerca per nome</label><input type="search" id="product-search" name="q" value="{{ $query }}" maxlength="120" class="app-field w-full rounded border"></div>
                <button type="submit" class="px-4 py-2 rounded border font-semibold">Cerca</button>
                @if($query !== '')<a href="{{ route('fleet-products.index') }}" class="underline py-2">Mostra tutti</a>@endif
            </form>
            <ul class="divide-y">
                @forelse($products as $item)
                    <li class="py-4 flex flex-wrap items-center justify-between gap-3">
                        <div class="min-w-0 break-words"><h3 class="font-semibold">{{ $item->name }}</h3><p class="text-sm">ID prodotto: {{ $item->id }} · Auto in flotta associate: {{ $item->vehicles_count }}</p></div>
                        <a href="{{ route('fleet-products.edit', $item) }}" class="px-4 py-2 rounded border font-semibold" aria-label="Gestisci {{ $item->name }}, ID {{ $item->id }}">Gestisci prodotto</a>
                    </li>
                @empty
                    <li class="py-4">{{ $query !== '' ? 'Nessun prodotto corrisponde alla ricerca.' : 'Non ci sono ancora prodotti. Crea il primo usando il modulo qui sopra.' }}</li>
                @endforelse
            </ul>
            {{ $products->links() }}
        </section>
    </div>
</x-app-layout>

<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-white">Posizione della sede di partenza</h2></x-slot>
    <div class="max-w-5xl mx-auto space-y-6 amr"><x-amd-rent-nav />
        <section class="app-surface rounded border p-4 sm:p-6">
            <h1 class="text-xl font-semibold">Conferma la posizione di {{ $origin->name }}</h1>
            <p>{{ $delivery->organization->name }}. Consegne personalizzate collegate a {{ $delivery->place->name }}.</p>
            <p>Il raggio di {{ $fields['delivery_radius_km'] }} km sarà calcolato in linea d’aria dalla posizione che scegli. Verifica che corrisponda alla sede operativa da cui parti per le consegne.</p>
            @if($lookupError)<p role="alert" class="rounded border border-red-400 p-4">{{ $lookupError }}</p>@endif
            @if(!$lookupError && !$choices)<p>Non è stata trovata una posizione precisa. Cerca l’indirizzo completo della sede, con numero civico e comune.</p>@endif
            @foreach($choices as $choice)
                <form method="post" action="{{ route('public-deliveries.update', $delivery) }}" class="amr-form border-t pt-4 mt-4">
                    @csrf @method('PUT')
                    @foreach($fields as $key => $fieldValue)<input type="hidden" name="{{ $key }}" value="{{ $fieldValue }}">@endforeach
                    <input type="hidden" name="origin_choice" value="{{ $choice['token'] }}">
                    <p id="origin-{{ $loop->index }}">{{ $choice['label'] }}</p>
                    <button class="amr-button" type="submit" aria-describedby="origin-{{ $loop->index }}">Usa questa posizione e salva il servizio</button>
                </form>
            @endforeach
            <form method="post" action="{{ route('public-deliveries.update', $delivery) }}" class="amr-form border-t pt-4 mt-4">
                @csrf @method('PUT')
                @foreach($fields as $key => $fieldValue)<input type="hidden" name="{{ $key }}" value="{{ $fieldValue }}">@endforeach
                <label for="origin-query">Cerca un altro indirizzo per questa sede</label>
                <input class="app-field" id="origin-query" name="origin_query" value="{{ $query }}" minlength="3" maxlength="500" required>
                <button class="amr-button" type="submit" name="locate_origin" value="1">Cerca con OpenStreetMap</button>
            </form>
            <p class="mt-4">Dati <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap e collaboratori, licenza ODbL</a>.</p>
            <p><a href="{{ route('public-deliveries.index') }}">Annulla e torna ai luoghi di consegna</a></p>
        </section>
    </div>
</x-app-layout>

<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-white">Protezione ed extra</h1>
    </x-slot>

    <div class="amr">
        <x-amd-rent-nav />
        @include('amd-rent.feedback')

        <form class="amr-panel app-surface amr-filter" method="get" action="{{ route('amd-rent.options.index') }}">
            <div>
                <label for="option-organization">Noleggiatore</label>
                <select class="app-field" id="option-organization" name="organization_id" required>
                    <option value="">Scegli il noleggiatore</option>
                    @foreach($organizations as $renter)
                        <option value="{{ $renter->id }}" @selected($organization?->id === $renter->id)>{{ $renter->name }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="amr-button">Apri configurazione</button>
        </form>

        @if($organization)
            <section class="amr-panel app-surface">
                <h2>{{ $organization->name }}</h2>
                <p>La protezione standard e AMD Plus più specifiche prevalgono: prima l’auto, poi il gruppo, poi tutte le auto del noleggiatore.</p>
                <div class="amr-actions">
                    @foreach(\App\Models\AmdRentOption::KINDS as $kind => $label)
                        <a class="amr-button" href="{{ route('amd-rent.options.create', [$organization, $kind]) }}">Aggiungi {{ $label }}</a>
                    @endforeach
                </div>

                @forelse($options as $option)
                    <div class="amr-row">
                        <div class="amr-row-title">
                            <a class="amr-link font-semibold" href="{{ route('amd-rent.options.edit', $option) }}">{{ $option->name }}</a>
                            <span>{{ $option->published ? 'Pubblicata' : 'Bozza' }}</span>
                        </div>
                        <p class="app-muted">
                            @if($option->vehicle_id)
                                {{ $option->vehicle ? trim($option->vehicle->make.' '.$option->vehicle->model).' — '.$option->vehicle->plate : 'Veicolo non disponibile' }}
                            @elseif($option->vehicle_product_id)
                                Gruppo {{ $option->product?->name }}
                            @else
                                Tutte le auto del noleggiatore
                            @endif
                            @if($option->price_unit === 'multiplier' && $option->price_multiplier_bps !== null)
                                · Tariffa auto ×{{ rtrim(rtrim(number_format($option->price_multiplier_bps / \App\Models\AmdRentOption::MULTIPLIER_SCALE, 4, ',', ''), '0'), ',') }}
                            @elseif($option->price_cents !== null)
                                · {{ number_format($option->price_cents / 100, 2, ',', '.') }} € {{ $option->price_unit === 'day' ? 'al giorno' : 'a noleggio' }}
                            @endif
                        </p>
                    </div>
                @empty
                    <p>Non ci sono ancora configurazioni per questo noleggiatore.</p>
                @endforelse

                {{ $options->links() }}
            </section>
        @endif
    </div>
</x-app-layout>

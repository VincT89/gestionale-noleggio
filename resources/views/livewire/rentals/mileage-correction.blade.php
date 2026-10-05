<section class="rounded-lg border bg-white text-gray-900 p-4 space-y-3" aria-label="Chilometraggio del noleggio">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="font-semibold">Chilometraggio del noleggio</h2>
        @role('admin')
            <button type="button" wire:click="open" class="rounded bg-slate-100 text-slate-800 px-3 py-1"
                    @disabled($state['out'] === null && $state['in'] === null)>Correggi km del noleggio</button>
        @endrole
    </div>
    <p class="text-sm">
        Uscita: <strong>{{ $state['out'] === null ? 'Non indicata' : number_format($state['out'], 0, ',', '.').' km' }}</strong>.
        Rientro: <strong>{{ $state['in'] === null ? 'Non indicato' : number_format($state['in'], 0, ',', '.').' km' }}</strong>.
        Km extra: <strong>{{ number_format($state['extra_km'], 0, ',', '.') }}</strong>.
    </p>
    @if($corrections->isNotEmpty())
        <div class="border-t pt-3 text-sm space-y-2">
            <p>Chilometraggio rettificato. Le rettifiche vanno allegate al contratto e alle checklist originali; i PDF già firmati sono conservati.</p>
            @foreach($corrections as $correction)
                <div wire:key="mileage-correction-{{ $correction->id }}" class="flex flex-wrap items-center justify-between gap-2">
                    <span>{{ $correction->created_at->format('d/m/Y H:i') }} - {{ $correction->properties['actor_name'] }}</span>
                    <button type="button" wire:click="download({{ $correction->id }})" wire:loading.attr="disabled"
                            class="text-indigo-700 underline">Scarica rettifica #{{ $correction->id }}</button>
                </div>
            @endforeach
            @if($paymentReviewNeeded)
                <p role="status" class="rounded border p-3">
                    I km extra sono stati ricalcolati dopo la correzione. Esistono incassi già registrati per i km extra:
                    verifica gli importi nella gestione dei pagamenti prima di effettuare eventuali rettifiche o rimborsi.
                </p>
            @endif
        </div>
    @endif

    @role('admin')
        <div x-data="{ open: @entangle('showModal') }" x-show="open"
             x-on:keydown.escape.window="if (open) $wire.close()"
             class="fixed inset-0 overflow-y-auto" style="display: none; z-index: 110;"
             role="dialog" aria-modal="true" aria-labelledby="rental-mileage-title">
            <div class="fixed inset-0 bg-black/40" wire:click="close" aria-hidden="true"></div>
            <form wire:submit="save" x-trap.inert.noscroll="open"
                  class="absolute left-1/2 top-1/2 max-w-lg -translate-x-1/2 -translate-y-1/2 rounded-md bg-white text-gray-900 p-4 shadow-xl overflow-y-auto"
                  style="width: calc(100% - 2rem); max-height: calc(100dvh - 2rem);">
                <h2 id="rental-mileage-title" class="text-lg font-semibold">Correggi km di uscita e rientro</h2>
                <p class="mt-2 text-sm text-gray-600">La correzione aggiorna i dati del noleggio e delle checklist, ricalcola i km extra e registra una rettifica scaricabile.</p>
                <div class="mt-3">
                    <label for="rental-mileage-out" class="block text-sm font-medium">Km in uscita</label>
                    <input id="rental-mileage-out" type="number" min="0" max="2000000" step="1" inputmode="numeric"
                           wire:model="out" @disabled(($original['out'] ?? null) === null)
                           class="mt-1 w-full rounded-md border-gray-300 bg-white text-gray-900">
                    @error('out') <p role="alert" class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="mt-3">
                    <label for="rental-mileage-in" class="block text-sm font-medium">Km al rientro</label>
                    <input id="rental-mileage-in" type="number" min="0" max="2000000" step="1" inputmode="numeric"
                           wire:model="in" @disabled(($original['in'] ?? null) === null)
                           class="mt-1 w-full rounded-md border-gray-300 bg-white text-gray-900">
                    @error('in') <p role="alert" class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="mt-3 text-sm">
                    @if($original['can_sync_vehicle'] ?? false)
                        <label class="flex items-start gap-2">
                            <input type="checkbox" wire:model="syncVehicle" class="mt-1 rounded border-gray-300">
                            <span>Aggiorna anche il chilometraggio attuale della scheda veicolo.</span>
                        </label>
                    @else
                        <p>La scheda veicolo ha una rilevazione successiva o diversa. Puoi correggerla separatamente dalla gestione veicoli.</p>
                    @endif
                    @error('sync_vehicle') <p role="alert" class="mt-1 text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="mt-3">
                    <label for="rental-mileage-reason" class="block text-sm font-medium">Motivo della correzione</label>
                    <textarea id="rental-mileage-reason" rows="2" maxlength="255" required wire:model="reason"
                              class="mt-1 w-full rounded-md border-gray-300 bg-white text-gray-900"></textarea>
                    @error('reason') <p role="alert" class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <p class="mt-2 text-sm text-gray-600">I documenti firmati originali e gli incassi registrati vengono conservati. Scarica la rettifica per documentare i nuovi km.</p>
                @error('correction') <p role="alert" class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" wire:click="close" wire:loading.attr="disabled" wire:target="save" class="rounded border px-3 py-1">Annulla</button>
                    <button type="submit" wire:loading.attr="disabled" wire:target="save" class="rounded bg-indigo-600 px-3 py-1 text-white">
                        <span wire:loading.remove wire:target="save">Salva rettifica</span>
                        <span wire:loading wire:target="save">Salvataggio...</span>
                    </button>
                </div>
            </form>
        </div>
    @endrole
</section>

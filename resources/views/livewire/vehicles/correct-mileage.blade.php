<div>
    <div x-data="{ open: @entangle('showModal') }"
         x-show="open"
         x-on:keydown.escape.window="if (open) $wire.close()"
         class="fixed inset-0 overflow-y-auto"
         style="display: none; z-index: 110;"
         role="dialog" aria-modal="true" aria-labelledby="mileage-correction-title">
        <div class="fixed inset-0 bg-black/40" wire:click="close" aria-hidden="true"></div>
        <form wire:submit="save"
              x-trap.inert.noscroll="open"
              class="absolute left-1/2 top-1/2 max-w-md -translate-x-1/2 -translate-y-1/2 rounded-md bg-white p-4 shadow-xl text-gray-900"
              style="width: calc(100% - 2rem);">
            <h2 id="mileage-correction-title" class="text-lg font-semibold">Correggi chilometraggio</h2>
            <p class="mt-2 text-sm text-gray-600">{{ $vehicleLabel }}</p>
            <p class="mt-2 text-sm text-gray-600">
                Valore attuale:
                <strong>{{ $currentMileage === null ? 'Non indicato' : number_format($currentMileage, 0, ',', '.').' km' }}</strong>
            </p>
            <div class="mt-3">
                <label for="mileage-correction-value" class="block text-sm font-medium">Chilometraggio corretto (km)</label>
                <input id="mileage-correction-value" type="number" min="0" max="4294967295" step="1"
                       inputmode="numeric" required wire:model="mileage"
                       aria-describedby="mileage-correction-help mileage-correction-error"
                       aria-invalid="{{ $errors->has('mileage') ? 'true' : 'false' }}"
                       class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-gray-900 bg-white">
                <p id="mileage-correction-help" class="mt-2 text-sm text-gray-600">
                    Puoi correggere anche un valore inserito troppo alto. La modifica viene registrata nello storico.
                </p>
                <div id="mileage-correction-error" role="alert" class="mt-2 text-sm text-red-600">
                    @error('mileage') {{ $message }} @enderror
                </div>
            </div>
            <div class="mt-4 flex justify-end gap-2">
                <button type="button" wire:click="close" wire:loading.attr="disabled" wire:target="save"
                        class="rounded border px-3 py-1">Annulla</button>
                <button type="submit" wire:loading.attr="disabled" wire:target="save"
                        class="rounded bg-indigo-600 px-3 py-1 text-white hover:bg-indigo-700">
                    <span wire:loading.remove wire:target="save">Salva correzione</span>
                    <span wire:loading wire:target="save">Salvataggio...</span>
                </button>
            </div>
        </form>
    </div>
</div>

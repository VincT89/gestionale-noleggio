{{-- resources/views/livewire/vehicles/form.blade.php --}}
{{-- Livewire: Vehicles\Form (Create/Edit) — campi amministrativi rimossi dalla UI --}}
<div class="space-y-4">

    {{-- Header interno --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-lg font-semibold">
                {{ $isEdit ? 'Modifica veicolo' : 'Nuovo veicolo' }}
            </h1>
            <p class="text-sm app-muted">
                I campi organizzazione/sede/attivo sono impostati automaticamente.
            </p>
        </div>

        <div class="flex items-center gap-2">
            <a href="{{ route('vehicles.index') }}"
               class="rounded border px-3 py-2 app-muted hover:bg-gray-50 dark:hover:bg-gray-700">
                Annulla
            </a>
            <button type="button"
                    class="rounded bg-slate-800 px-4 py-2 font-medium text-white hover:bg-slate-900 disabled:opacity-50"
                    wire:click="save"
                    wire:loading.attr="disabled">
                Salva
            </button>
        </div>
    </div>

    {{-- Card form --}}
    <div class="rounded-lg border app-surface p-4">
        <div class="grid grid-cols-12 gap-4">

            {{-- plate --}}
            <div class="col-span-12 sm:col-span-6 lg:col-span-3">
                <label class="block text-xs app-muted" for="vehicle-plate">Targa *</label>
                <input id="vehicle-plate" type="text" class="mt-1 w-full rounded border-gray-300 uppercase app-field"
                       wire:model.defer="form.plate" maxlength="16" placeholder="AB123CD" autofocus>
                @error('form.plate') <div class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</div> @enderror
            </div>

            {{-- make --}}
            <div class="col-span-12 sm:col-span-6 lg:col-span-3">
                <label class="block text-xs app-muted" for="vehicle-make">Marca *</label>
                <input id="vehicle-make" type="text" class="mt-1 w-full rounded border-gray-300 app-field"
                       wire:model.defer="form.make" maxlength="64" placeholder="Fiat">
                @error('form.make') <div class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</div> @enderror
            </div>

            {{-- model --}}
            <div class="col-span-12 sm:col-span-6 lg:col-span-3">
                <label class="block text-xs app-muted" for="vehicle-model">Modello *</label>
                <input id="vehicle-model" type="text" class="mt-1 w-full rounded border-gray-300 app-field"
                       wire:model.defer="form.model" maxlength="64" placeholder="Panda">
                @error('form.model') <div class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</div> @enderror
            </div>

            {{-- year --}}
            <div class="col-span-12 sm:col-span-6 lg:col-span-3">
                <label class="block text-xs app-muted" for="vehicle-year">Anno</label>
                <input id="vehicle-year" type="number" class="mt-1 w-full rounded border-gray-300 app-field"
                       wire:model.defer="form.year" min="1900" max="2100" step="1" placeholder="2024">
                @error('form.year') <div class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</div> @enderror
            </div>

            {{-- vin --}}
            <div class="col-span-12 sm:col-span-6 lg:col-span-4">
                <label class="block text-xs app-muted" for="vehicle-vin">VIN</label>
                <input id="vehicle-vin" type="text" class="mt-1 w-full rounded border-gray-300 app-field"
                       wire:model.defer="form.vin" maxlength="17" placeholder="Numero telaio (17 caratteri)">
                @error('form.vin') <div class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</div> @enderror
            </div>

            {{-- color --}}
            <div class="col-span-12 sm:col-span-6 lg:col-span-4">
                <label class="block text-xs app-muted" for="vehicle-color">Colore</label>
                <input id="vehicle-color" type="text" class="mt-1 w-full rounded border-gray-300 app-field"
                       wire:model.defer="form.color" maxlength="32" placeholder="Nero">
                @error('form.color') <div class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</div> @enderror
            </div>

            {{-- fuel_type (enum) --}}
            <div class="col-span-12 sm:col-span-6 lg:col-span-4">
                <label class="block text-xs app-muted" for="vehicle-fuel-type">Alimentazione *</label>
                <select id="vehicle-fuel-type" class="mt-1 w-full rounded border-gray-300 app-field"
                        wire:model.defer="form.fuel_type">
                    @foreach($fuelOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                @error('form.fuel_type') <div class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</div> @enderror
            </div>

            {{-- transmission (enum) --}}
            <div class="col-span-12 sm:col-span-6 lg:col-span-4">
                <label class="block text-xs app-muted" for="vehicle-transmission">Cambio *</label>
                <select id="vehicle-transmission" class="mt-1 w-full rounded border-gray-300 app-field"
                        wire:model.defer="form.transmission">
                    @foreach($transOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                @error('form.transmission') <div class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</div> @enderror
            </div>

            {{-- seats --}}
            <div class="col-span-12 sm:col-span-6 lg:col-span-4">
                <label class="block text-xs app-muted" for="vehicle-seats">Posti</label>
                <input id="vehicle-seats" type="number" class="mt-1 w-full rounded border-gray-300 app-field"
                       wire:model.defer="form.seats" min="1" max="99" step="1" placeholder="5">
                @error('form.seats') <div class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</div> @enderror
            </div>

            {{-- segment --}}
            <div class="col-span-12 sm:col-span-6 lg:col-span-4">
                <label class="block text-xs app-muted" for="vehicle-segment">Segmento</label>
                <input id="vehicle-segment" type="text" class="mt-1 w-full rounded border-gray-300 app-field"
                       wire:model.defer="form.segment" maxlength="32" placeholder="SUV / Compact / ...">
                @error('form.segment') <div class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</div> @enderror
            </div>

            {{-- Tipologia veicolo CARGOS --}}
            <div class="col-span-12 sm:col-span-6 lg:col-span-4">
                <label class="block text-xs app-muted" for="vehicle-cargos-vehicle-type-code">Tipologia veicolo CARGOS *</label>
                <select id="vehicle-cargos-vehicle-type-code" class="mt-1 w-full rounded border-gray-300 app-field"
                        wire:model.defer="form.cargos_vehicle_type_code">
                    <option value="">Seleziona...</option>

                    @foreach($cargosVehicleTypeOptions as $code => $label)
                        <option value="{{ $code }}">{{ $label }}</option>
                    @endforeach
                </select>
                @error('form.cargos_vehicle_type_code') <div class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</div> @enderror
            </div>

            {{-- mileage_current --}}
            <div class="col-span-12 sm:col-span-6 lg:col-span-4">
                <label class="block text-xs app-muted" for="vehicle-mileage-current">Chilometraggio attuale *</label>
                <input id="vehicle-mileage-current" type="number" class="mt-1 w-full rounded border-gray-300 app-field"
                       wire:model.defer="form.mileage_current" min="0" step="1" placeholder="0">
                @error('form.mileage_current') <div class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</div> @enderror
            </div>

            {{-- --- Divider opzionale --- --}}
            <div class="col-span-12">
                <div class="mt-2 mb-1 h-px bg-gray-200"></div>
            </div>

            {{-- Sezione costi --}}
            <div class="col-span-12">
                <h3 class="text-sm font-semibold text-gray-900">Costi & assicurazioni</h3>
                <p class="text-xs app-muted">Inserisci gli importi in euro.</p>
            </div>

            {{-- Noleggio L/T (mensile) --}}
            <div class="col-span-12 sm:col-span-6 lg:col-span-3">
                <label class="block text-xs app-muted" for="vehicle-lt-rental-monthly-eur">Noleggio L/T (mensile) €</label>
                <input id="vehicle-lt-rental-monthly-eur" type="number" step="0.01" min="0" class="mt-1 w-full rounded border-gray-300 app-field"
                    wire:model.defer="form.lt_rental_monthly_eur" placeholder="es. 399,00">
                @error('form.lt_rental_monthly_eur') <div class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</div> @enderror
            </div>

            {{-- Kasko --}}
            <div class="col-span-12 sm:col-span-6 lg:col-span-3">
                <label class="block text-xs app-muted" for="vehicle-insurance-kasko-eur">FranchigiaKasko €</label>
                <input id="vehicle-insurance-kasko-eur" type="number" step="0.01" min="0" class="mt-1 w-full rounded border-gray-300 app-field"
                    wire:model.defer="form.insurance_kasko_eur" placeholder="es. 25,00">
                @error('form.insurance_kasko_eur') <div class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</div> @enderror
            </div>

            {{-- RCA --}}
            <div class="col-span-12 sm:col-span-6 lg:col-span-3">
                <label class="block text-xs app-muted" for="vehicle-insurance-rca-eur">Franchigia RCA €</label>
                <input id="vehicle-insurance-rca-eur" type="number" step="0.01" min="0" class="mt-1 w-full rounded border-gray-300 app-field"
                    wire:model.defer="form.insurance_rca_eur" placeholder="es. 35,00">
                @error('form.insurance_rca_eur') <div class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</div> @enderror
            </div>

            {{-- Cristalli --}}
            <div class="col-span-12 sm:col-span-6 lg:col-span-3">
                <label class="block text-xs app-muted" for="vehicle-insurance-cristalli-eur">Franchigia Cristalli €</label>
                <input id="vehicle-insurance-cristalli-eur" type="number" step="0.01" min="0" class="mt-1 w-full rounded border-gray-300 app-field"
                    wire:model.defer="form.insurance_cristalli_eur" placeholder="es. 5,00">
                @error('form.insurance_cristalli_eur') <div class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</div> @enderror
            </div>

            {{-- Furto/Incendio --}}
            <div class="col-span-12 sm:col-span-6 lg:col-span-3">
                <label class="block text-xs app-muted" for="vehicle-insurance-furto-eur">Franchigia Furto/Incendio €</label>
                <input id="vehicle-insurance-furto-eur" type="number" step="0.01" min="0" class="mt-1 w-full rounded border-gray-300 app-field"
                    wire:model.defer="form.insurance_furto_eur" placeholder="es. 18,00">
                @error('form.insurance_furto_eur') <div class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</div> @enderror
            </div>

            {{-- notes --}}
            <div class="col-span-12">
                <label class="block text-xs app-muted" for="vehicle-notes">Note</label>
                <textarea id="vehicle-notes" class="mt-1 w-full rounded border-gray-300 app-field" rows="4"
                          wire:model.defer="form.notes" placeholder="Annotazioni utili..."></textarea>
                @error('form.notes') <div class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</div> @enderror
            </div>
        </div>
    </div>

    {{-- Footer fisso --}}
    <div class="sticky bottom-0 border-t app-surface p-3">
        <div class="flex items-center justify-end gap-2">
            <a href="{{ route('vehicles.index') }}" class="rounded border px-3 py-2 app-muted hover:bg-gray-50 dark:hover:bg-gray-700">Annulla</a>
            <button type="button" class="rounded bg-slate-800 px-4 py-2 font-medium text-white hover:bg-slate-900 disabled:opacity-50"
                    wire:click="save" wire:loading.attr="disabled">
                Salva
            </button>
        </div>
    </div>
</div>

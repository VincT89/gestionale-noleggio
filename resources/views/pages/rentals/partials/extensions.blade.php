@php
    $extensions = $this->rentalExtensions;
    $pendingExtension = $extensions->firstWhere('additional_amount', null);
    $canUpdate = auth()->user()->can('update', $rental);
    $canExtend = in_array($rental->status, ['reserved', 'in_use'], true)
        && !$rental->actual_return_at && !$rental->closed_at && $canUpdate;
    $balance = max(0, round((float) ($rental->final_amount_override ?? $rental->amount) - (float) $rental->base_paid_total, 2));
@endphp
@if($canExtend || $extensions->isNotEmpty())
<section class="rounded-lg border border-gray-200 bg-white p-4 text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 space-y-4" aria-labelledby="extensions-heading">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 id="extensions-heading" class="font-semibold">Proroga noleggio</h2>
        @if($canExtend && !$extensionOpen && !$pendingExtension)
            <button type="button" class="rounded-md bg-indigo-700 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-800"
                wire:click="openExtension" wire:loading.attr="disabled" wire:target="openExtension">
                Proroga noleggio
            </button>
        @endif
    </div>
    @if($extensions->isNotEmpty() && !$extensionOpen)
        @if($pendingExtension)
            <div role="status" class="rounded-md border border-amber-300 bg-amber-50 p-3 text-amber-950 space-y-2">
                <p class="font-medium">Importo della proroga da definire</p>
                <p class="text-sm">La data di rientro è aggiornata. Inserisci il costo concordato e ricorda di registrare il pagamento quando incassato.</p>
                @if($canUpdate)
                    <button type="button" wire:click="openExtensionPrice({{ $pendingExtension->id }})"
                        class="rounded-md bg-indigo-700 px-3 py-2 text-sm font-medium text-white">Inserisci costo della proroga</button>
                @endif
            </div>
        @elseif($balance > 0 || $rental->has_combined_payment)
            <div role="status" class="rounded-md border border-amber-300 bg-amber-50 p-3 text-amber-950 space-y-2">
                <p class="font-medium">{{ $rental->has_combined_payment ? 'Verifica il saldo dopo la proroga' : 'Pagamento da registrare' }}</p>
                <p class="text-sm">
                    @if($rental->has_combined_payment)
                        Nello storico ci sono pagamenti che comprendono anche i km extra. Verifica l’importo ancora da incassare.
                    @else
                        Saldo del noleggio ancora da incassare: <strong>{{ number_format($balance, 2, ',', '.') }} €</strong>.
                    @endif
                </p>
                @if($canUpdate)
                    <button type="button" x-data x-on:click="$dispatch('open-payment-modal')"
                        class="rounded-md bg-indigo-700 px-3 py-2 text-sm font-medium text-white">Registra pagamento</button>
                @endif
            </div>
        @endif
    @endif
    @if($extensionOpen && $canUpdate && ($canExtend || $extensionToPrice))
        <form wire:submit="saveExtension" class="space-y-4">
            <p class="text-sm">Rientro attuale: <strong>{{ $rental->planned_return_at?->format('d/m/Y H:i') }}</strong>.
                Importo attuale: <strong>{{ number_format((float) ($extensionExpectedOverride ?? $extensionExpectedAmount), 2, ',', '.') }} €</strong>.</p>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                @if(!$extensionToPrice)
                <div class="min-w-0">
                    <label for="extension-return-at" class="block text-sm font-medium mb-1">Nuova data e ora di rientro</label>
                    <input id="extension-return-at" type="datetime-local" wire:model="extensionReturnAt" required
                        min="{{ $rental->planned_return_at?->copy()->addMinute()->format('Y-m-d\TH:i') }}"
                        class="block w-full min-w-0 rounded-md border-gray-300 bg-white text-gray-900 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                    @error('extensionReturnAt') <p role="alert" class="mt-1 text-sm text-red-700 dark:text-red-300">{{ $message }}</p> @enderror
                </div>
                @endif
                <div class="min-w-0">
                    <label for="extension-amount" class="block text-sm font-medium mb-1">Costo aggiuntivo concordato (€){{ $extensionToPrice ? '' : ' — facoltativo' }}</label>
                    <input id="extension-amount" type="number" min="0" max="99999999.99" step="0.01" wire:model.blur="extensionAmount" @required($extensionToPrice)
                        class="block w-full min-w-0 rounded-md border-gray-300 bg-white text-gray-900 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                        aria-describedby="extension-amount-help">
                    <p id="extension-amount-help" class="mt-1 text-sm text-gray-600 dark:text-gray-300">Importo complessivo, inclusi gli extra concordati.{{ $extensionToPrice ? '' : ' Puoi lasciarlo vuoto e inserirlo dopo.' }}</p>
                    @error('extensionAmount') <p role="alert" class="mt-1 text-sm text-red-700 dark:text-red-300">{{ $message }}</p> @enderror
                </div>
            </div>
            @if(is_numeric($extensionAmount) && (float) $extensionAmount >= 0)
                <p class="text-sm">Nuovo importo: <strong>{{ number_format((float) ($extensionExpectedOverride ?? $extensionExpectedAmount) + (float) $extensionAmount, 2, ',', '.') }} €</strong>.</p>
            @endif
            @if(!$extensionToPrice)
            <div>
                <label for="extension-notes" class="block text-sm font-medium mb-1">Note sull’accordo (facoltative)</label>
                <textarea id="extension-notes" wire:model="extensionNotes" rows="2" maxlength="2000"
                    class="block w-full rounded-md border-gray-300 bg-white text-gray-900 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"></textarea>
                @error('extensionNotes') <p role="alert" class="mt-1 text-sm text-red-700 dark:text-red-300">{{ $message }}</p> @enderror
            </div>
            @endif
            <p class="text-sm text-gray-600 dark:text-gray-300">Il pagamento va registrato separatamente come “Quota base / saldo” quando incassato.</p>
            <div class="flex flex-wrap gap-2">
                <button type="submit" class="rounded-md bg-indigo-700 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-800 disabled:opacity-50"
                    wire:loading.attr="disabled" wire:target="saveExtension">{{ $extensionToPrice ? 'Salva costo' : 'Conferma proroga' }}</button>
                <button type="button" class="rounded-md border border-gray-300 px-4 py-2 text-sm dark:border-gray-600"
                    wire:click="$set('extensionOpen', false)" wire:loading.attr="disabled" wire:target="saveExtension">Annulla</button>
            </div>
        </form>
    @endif
    @if($extensions->isNotEmpty())
        <details>
        <summary class="cursor-pointer text-sm font-medium">Storico proroghe</summary>
        <ul class="divide-y divide-gray-200 dark:divide-gray-700">
            @foreach($extensions as $extension)
                <li class="py-3 space-y-1 text-sm" wire:key="extension-{{ $extension->id }}">
                    <p><strong>{{ $extension->previous_return_at->format('d/m/Y H:i') }}</strong> → <strong>{{ $extension->new_return_at->format('d/m/Y H:i') }}</strong></p>
                    <p>Costo aggiuntivo: <strong>{{ $extension->additional_amount === null ? 'da definire' : number_format((float) $extension->additional_amount, 2, ',', '.') . ' €' }}</strong>.
                        Nuovo importo: {{ number_format((float) ($extension->new_override ?? $extension->new_amount), 2, ',', '.') }} €.</p>
                    <p class="text-gray-600 dark:text-gray-300">{{ $extension->created_at->format('d/m/Y H:i') }} · {{ $extension->creator?->name ?? 'Operatore non disponibile' }}</p>
                    @if($extension->notes) <p class="whitespace-pre-line break-words">{{ $extension->notes }}</p> @endif
                </li>
            @endforeach
        </ul>
        </details>
    @endif
</section>
@endif

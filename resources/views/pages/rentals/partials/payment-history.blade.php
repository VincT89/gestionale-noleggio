@php
    $kindLabels = [
        'base' => 'Quota base / saldo', 'acconto' => 'Acconto',
        'distance_overage' => 'Km extra', 'base+distance_overage' => 'Quota base + km extra',
        'damage' => 'Danni', 'surcharge' => 'Sovrapprezzo', 'fine' => 'Multe', 'other' => 'Altro',
    ];
    $methodLabels = ['cash' => 'Contanti', 'pos' => 'Carta di credito', 'bank_transfer' => 'Bonifico bancario', 'other' => 'Altro'];
    $money = fn ($value) => number_format((float) $value, 2, ',', '.') . ' €';
@endphp

<section aria-labelledby="payment-history-title" class="min-w-0 rounded-lg border border-gray-200 bg-white p-3 text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
    <h2 id="payment-history-title" class="font-semibold">Storico pagamenti</h2>
    @error('payment')
        <p role="alert" class="mt-2 text-sm text-red-700 dark:text-red-300">{{ $message }}</p>
    @enderror

    @if($payments->isEmpty())
        <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">Nessun pagamento registrato.</p>
    @else
        <dl class="mt-3 space-y-1 text-sm">
            <div class="flex flex-wrap justify-between gap-x-3">
                <dt>Totale registrato</dt><dd class="font-semibold tabular-nums">{{ $money($payments->sum('amount')) }}</dd>
            </div>
            <div class="flex flex-wrap justify-between gap-x-3 text-gray-600 dark:text-gray-300">
                <dt>Di cui commissionabili</dt><dd class="tabular-nums">{{ $money($payments->where('is_commissionable', true)->sum('amount')) }}</dd>
            </div>
        </dl>
        <ul class="mt-3 divide-y divide-gray-200 dark:divide-gray-700">
            @foreach($payments as $payment)
                <li wire:key="rental-payment-{{ $payment->id }}" class="min-w-0 py-3 text-sm">
                    <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1 font-medium">
                        <span>{{ $kindLabels[$payment->kind] ?? $payment->kind }}</span>
                        <span class="tabular-nums">{{ $money($payment->amount) }}</span>
                    </div>
                    <dl class="mt-1 space-y-1 break-words text-gray-600 dark:text-gray-300">
                        <div><dt class="sr-only">Data</dt><dd>{{ $payment->payment_recorded_at?->format('d/m/Y H:i') ?? 'Data non disponibile' }}</dd></div>
                        <div><dt class="sr-only">Metodo</dt><dd>{{ $methodLabels[$payment->payment_method] ?? ($payment->payment_method ?: 'Metodo non disponibile') }}</dd></div>
                        <div><dt class="sr-only">Commissione</dt><dd>{{ $payment->is_commissionable ? 'Incluso nella base commissioni' : 'Escluso dalla base commissioni' }}</dd></div>
                        @if($payment->description)
                            <div><dt class="font-medium">Note</dt><dd>{{ $payment->description }}</dd></div>
                        @endif
                        @if($payment->payment_reference)
                            <div><dt class="font-medium">Riferimento</dt><dd>{{ $payment->payment_reference }}</dd></div>
                        @endif
                        @if($payment->creator)
                            <div><dt class="inline">Registrato da:</dt> <dd class="inline">{{ $payment->creator->name }}</dd></div>
                        @endif
                    </dl>
                    @can('update', $rental)
                        <button type="button"
                                wire:click="deletePayment({{ $payment->id }})"
                                wire:confirm="Eliminare il pagamento di {{ $money($payment->amount) }} del {{ $payment->payment_recorded_at?->format('d/m/Y H:i') ?? 'data non disponibile' }}? Il totale registrato e le eventuali commissioni verranno aggiornati."
                                wire:loading.attr="disabled" wire:target="deletePayment"
                                aria-label="Elimina pagamento di {{ $money($payment->amount) }} del {{ $payment->payment_recorded_at?->format('d/m/Y H:i') ?? 'data non disponibile' }}"
                                class="mt-2 min-h-11 rounded-md border border-red-300 px-3 py-2 text-sm font-medium text-red-700 hover:bg-red-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-700 disabled:opacity-50 dark:border-red-700 dark:text-red-300 dark:hover:bg-red-950">
                            Elimina pagamento
                        </button>
                    @endcan
                </li>
            @endforeach
        </ul>
    @endif
</section>

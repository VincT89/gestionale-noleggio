{{-- Livewire: Documents\Index (gestione documenti veicoli) --}}
<div class="space-y-4">

    {{-- KPI pill --}}
    <div class="flex flex-wrap items-center gap-2">
        <span class="rounded bg-rose-100 px-2 py-1 text-sm text-rose-700">Scaduti: {{ $kpi['expired'] }}</span>
        <span class="rounded bg-amber-100 px-2 py-1 text-sm text-amber-700">≤30gg: {{ $kpi['soon30'] }}</span>
        <span class="rounded bg-yellow-100 px-2 py-1 text-sm text-yellow-800">≤60gg: {{ $kpi['soon60'] }}</span>
        <span class="rounded bg-slate-100 px-2 py-1 text-sm text-slate-700">Senza data: {{ $kpi['noDate'] }}</span>
        <span class="ml-auto rounded bg-slate-100 px-2 py-1 text-sm text-slate-700">Totale: {{ $kpi['total'] }}</span>
    </div>

    {{-- Toolbar filtri --}}
    <div class="rounded-lg border app-surface p-3">
        <div class="grid grid-cols-12 gap-3">
            <div class="grid grid-cols-12 gap-3 col-span-12">
                {{-- Ricerca con cancella veloce --}}
                <div class="col-span-12 sm:col-span-6 lg:col-span-3">
                    <label for="documents-search" class="block text-xs app-muted">Ricerca</label>
                    <div class="relative mt-1">
                        <input id="documents-search" x-ref="search" type="text" class="w-full rounded border-gray-300 pr-8 app-field"
                            placeholder="Targa, VIN, numero..."
                            wire:model.live.debounce.400ms="search">
                        <button type="button"
                                class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-700"
                                x-on:click="$wire.set('search',''); $refs.search.focus()"
                                x-show="$wire.search"
                                aria-label="Cancella">
                            &times;
                        </button>
                    </div>
                </div>

                <div class="col-span-12 sm:col-span-6 lg:col-span-2">
                    <label for="documents-type" class="block text-xs app-muted">Tipo</label>
                    <select id="documents-type" class="mt-1 w-full rounded border-gray-300 app-field" wire:model.live="type">
                        <option value="">Tutti</option>
                        @foreach($docLabels as $k => $lbl)
                            <option value="{{ $k }}">{{ $lbl }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-span-12 sm:col-span-6 lg:col-span-2">
                    <label for="documents-state" class="block text-xs app-muted">Stato</label>
                    <select id="documents-state" class="mt-1 w-full rounded border-gray-300 app-field" wire:model.live="state">
                        <option value="">Tutti</option>
                        <option value="expired">Scaduti</option>
                        <option value="soon30">≤ 30 giorni</option>
                        <option value="soon60">≤ 60 giorni</option>
                        <option value="ok">Oltre 60 giorni</option>
                        <option value="no_date">Senza data</option>
                    </select>
                </div>

                @if($canManage)
                    <div class="col-span-12 sm:col-span-6 lg:col-span-2">
                        <label for="documents-orgId" class="block text-xs app-muted">Organizzazione</label>
                        <select id="documents-orgId" class="mt-1 w-full rounded border-gray-300 app-field" wire:model.live="orgId">
                            <option value="">Tutte</option>
                            @foreach($orgs as $o)
                                <option value="{{ $o->id }}">{{ $o->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <div class="col-span-12 sm:col-span-6 lg:col-span-2">
                    <label for="documents-locId" class="block text-xs app-muted">Sede</label>
                    <select id="documents-locId" class="mt-1 w-full rounded border-gray-300 app-field" wire:model.live="locId">
                        <option value="">Tutte</option>
                        @foreach($locs as $l)
                            <option value="{{ $l->id }}">{{ $l->name }}</option>
                        @endforeach
                    </select>
                </div>
                
                <div class="col-span-12 sm:col-span-6 lg:col-span-1">
                    <label for="documents-vehicleId" class="block text-xs app-muted">ID veicolo</label>
                    <input id="documents-vehicleId" type="number" class="mt-1 w-full rounded border-gray-300 app-field" wire:model.live="vehicleId" min="1">
                </div>
            </div>

            <div class="col-span-12 sm:col-span-6 lg:col-span-2">
                <label for="documents-from" class="block text-xs app-muted">Scadenza dal</label>
                <input id="documents-from" type="date" class="mt-1 w-full rounded border-gray-300 app-field" wire:model.live="from">
            </div>
            <div class="col-span-12 sm:col-span-6 lg:col-span-2">
                <label for="documents-to" class="block text-xs app-muted">al</label>
                <input id="documents-to" type="date" class="mt-1 w-full rounded border-gray-300 app-field" wire:model.live="to">
            </div>

            <div class="col-span-12 sm:col-span-6 lg:col-span-2">
                <label for="documents-sort" class="block text-xs app-muted">Ordina per</label>
                <div class="mt-1 flex gap-2">
                    <select id="documents-sort" class="w-full rounded border-gray-300 app-field" wire:model.live="sort">
                        <option value="expiry_date">Scadenza</option>
                        <option value="vehicle.plate">Targa</option>
                        <option value="type">Tipo</option>
                        <option value="number">Numero</option>
                    </select>
                    <select class="w-24 rounded border-gray-300 app-field" wire:model.live="dir" aria-label="Direzione ordinamento">
                        <option value="asc">Asc</option>
                        <option value="desc">Desc</option>
                    </select>
                </div>
            </div>

            <div class="col-span-12 sm:col-span-6 lg:col-span-2">
                <label for="documents-perPage" class="block text-xs app-muted">Righe/pagina</label>
                <select id="documents-perPage" class="mt-1 w-full rounded border-gray-300 app-field" wire:model.live="perPage">
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </div>

            <div class="col-span-12 lg:col-span-4 flex flex-wrap items-end gap-3">
                <label class="inline-flex items-center gap-2 text-sm">
                    <input type="checkbox" class="rounded border-gray-300" wire:model.live="showArchived" @if($canManage === false && $canUpdate === true) disabled @endif>
                    <span>Mostra archiviati</span>
                </label>

                @if($canCreate)
                    <button type="button"
                            class="ml-auto inline-flex h-10 items-center rounded-md bg-slate-800 px-3 text-white hover:bg-slate-900 disabled:cursor-not-allowed disabled:opacity-50"
                            wire:click="openCreate"
                            @if(!$vehicleId) disabled @endif>
                        Nuovo <span class="ml-1 text-xs opacity-75">(veicolo {{ $vehicleId ?: '—' }})</span>
                    </button>
                @endif
            </div>
        </div>
    </div>

    {{-- Azioni bulk --}}
    <div class="flex flex-wrap items-center gap-2">
        <span class="text-sm app-muted">Selezionati: {{ count($selected) }}</span>
            @if($canUpdate)
            <div class="flex flex-wrap items-center gap-2">
                <input type="date" aria-label="Nuova scadenza per i documenti selezionati" class="max-w-full rounded border-gray-300 app-field" wire:model.live="bulkRenewDate">
                <button type="button" class="rounded bg-amber-700 px-3 py-1.5 text-white hover:bg-amber-700"
                        wire:click="bulkRenew">
                    Rinnova selezionati
                </button>
            </div>
            @endif
    </div>

    {{-- Tabella --}}
    <div class="overflow-auto rounded-lg border app-surface">
        <table class="min-w-full text-sm">
            <thead class="app-surface-subtle">
            <tr>
                <th class="px-3 py-2">
                    @php
                        $pageIds = $docs->pluck('id')->all();
                        $allOnPageSelected = count($pageIds) > 0 && count(array_diff($pageIds, $selected)) === 0;
                    @endphp
                    <input type="checkbox"
                           class="rounded border-gray-300"
                           aria-label="Seleziona tutti i documenti della pagina" wire:click="toggleSelectPage($event.target.checked)"
                           @if($allOnPageSelected) checked @endif>
                </th>
                <th class="px-3 py-2 text-left">Targa</th>
                <th class="px-3 py-2 text-left">Marca/Modello</th>
                <th class="px-3 py-2 text-left">Tipo</th>
                <th class="px-3 py-2 text-left">Numero</th>
                <th class="px-3 py-2 text-left">Scadenza</th>
                <th class="px-3 py-2 text-left">Giorni</th>
                <th class="px-3 py-2 text-left">Azioni</th>
            </tr>
            </thead>
            <tbody class="divide-y">
            @forelse($docs as $doc)
                @php
                    $v = $doc->vehicle;
                    $exp = $doc->expiry_date ? \Illuminate\Support\Carbon::parse($doc->expiry_date)->startOfDay() : null;
                    $days = $exp ? now()->startOfDay()->diffInDays($exp, false) : null;
                    $cls  = is_null($days) ? '' : ($days <= 7 ? 'bg-rose-100 text-rose-700' : ($days <= 60 ? 'bg-amber-100 text-amber-700' : 'bg-green-100 text-green-700'));
                    $isArchived = $v && method_exists($v, 'trashed') && $v->trashed();
                @endphp
                <tr class="hover:bg-gray-50 {{ $isArchived ? 'opacity-60' : '' }} dark:hover:bg-gray-700" wire:key="doc-{{ $doc->id }}">
                    <td class="px-3 py-2">
                        <input type="checkbox" class="rounded border-gray-300"
                               value="{{ $doc->id }}" wire:model.live="selected" aria-label="Seleziona documento {{ $doc->id }} del veicolo {{ $v?->plate }}"
                               @if($isArchived) disabled @endif>
                    </td>
                    <td class="px-3 py-2">
                        <a href="{{ route('vehicles.show', $v->id) }}" class="text-indigo-700 hover:underline dark:text-indigo-300">
                            {{ $v->plate }}
                        </a>
                    </td>
                    <td class="px-3 py-2 app-muted">{{ $v->make }} {{ $v->model }}</td>
                    <td class="px-3 py-2">{{ $docLabels[$doc->type] ?? strtoupper($doc->type) }}</td>
                    <td class="px-3 py-2">{{ $doc->number ?? '—' }}</td>
                    <td class="px-3 py-2">{{ $exp?->format('d/m/Y') ?? '—' }}</td>
                    <td class="px-3 py-2">
                        @if(!is_null($days))
                            <span class="rounded px-2 py-1 text-xs {{ $cls }}">{{ (int) $days }} gg</span>
                        @else
                            <span class="text-xs text-gray-400">—</span>
                        @endif
                    </td>
                    <td class="px-3 py-2">
                        <div class="flex flex-wrap items-center gap-2">
                            <button type="button" class="rounded border px-2 py-1" wire:click="openEdit({{ $doc->id }})">
                                Apri
                            </button>

                            @if($canUpdate)
                                <button type="button" class="rounded bg-slate-800 px-2 py-1 text-white hover:bg-slate-900"
                                        wire:click="openEdit({{ $doc->id }})" @if($isArchived) disabled @endif>
                                    Modifica
                                </button>
                            @endif

                            @if($canDelete)
                                <button type="button" class="rounded bg-rose-600 px-2 py-1 text-white hover:bg-rose-700"
                                        wire:click="delete({{ $doc->id }})" @if($isArchived) disabled @endif>
                                    Elimina
                                </button>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="px-3 py-6 text-center app-muted">Nessun documento trovato.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div>
        {{ $docs->onEachSide(1)->links() }}
    </div>

    {{-- Drawer Editor --}}
    @if($drawerOpen)
        <div class="fixed inset-0 z-[70]">
            <div class="fixed inset-0 bg-black/40" wire:click="closeDrawer"></div>
            <div class="fixed right-0 top-0 h-dvh w-full max-w-2xl overflow-y-auto app-surface shadow-2xl">
                <div class="flex items-center justify-between border-b px-4 py-3">
                    <div class="text-lg font-semibold">
                        {{ $editingId ? 'Modifica documento' : 'Nuovo documento' }}
                    </div>
                    <button type="button" class="rounded border px-2 py-1" wire:click="closeDrawer">Chiudi</button>
                </div>

                <div class="space-y-4 p-4">
                    @if($editingVehicleArchived)
                        <div class="rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                            Il veicolo è archiviato: modifica disabilitata.
                        </div>
                    @endif

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="documents-form-vehicle_id" class="block text-xs app-muted">Veicolo</label>
                            <input id="documents-form-vehicle_id" type="number" class="mt-1 w-full rounded border-gray-300 app-field"
                                   wire:model.live="form.vehicle_id"
                                   disabled>
                            @error('form.vehicle_id') <div class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</div> @enderror
                        </div>

                        <div>
                            <label for="documents-form-type" class="block text-xs app-muted">Tipo</label>
                            <select id="documents-form-type" class="mt-1 w-full rounded border-gray-300 app-field" wire:model.live="form.type">
                                <option value="">Seleziona…</option>
                                @foreach($docLabels as $k => $lbl)
                                    <option value="{{ $k }}">{{ $lbl }}</option>
                                @endforeach
                            </select>
                            @error('form.type') <div class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</div> @enderror
                        </div>

                        <div>
                            <label for="documents-form-number" class="block text-xs app-muted">Numero</label>
                            <input id="documents-form-number" type="text" class="mt-1 w-full rounded border-gray-300 app-field" wire:model.live="form.number" maxlength="100">
                            @error('form.number') <div class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</div> @enderror
                        </div>

                        <div>
                            <label for="documents-form-expiry_date" class="block text-xs app-muted">Scadenza</label>
                            <input id="documents-form-expiry_date" type="date" class="mt-1 w-full rounded border-gray-300 app-field" wire:model.live="form.expiry_date">
                            @error('form.expiry_date') <div class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    <div class="flex justify-end gap-2">
                        <button type="button" class="rounded border px-3 py-1" wire:click="closeDrawer">Annulla</button>
                        @if($canManage && !$editingVehicleArchived)
                            <button type="button" class="rounded bg-indigo-600 px-3 py-1 text-white hover:bg-indigo-700" wire:click="save">
                                Salva
                            </button>
                        @endif
                    </div>
                </div>

            </div>
        </div>
    @endif
</div>

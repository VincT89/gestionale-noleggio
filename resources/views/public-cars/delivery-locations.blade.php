<x-app-layout>
    <x-slot name="header"><div class="flex flex-wrap justify-between items-center gap-3"><h2 class="font-semibold text-xl text-white">Luoghi di consegna</h2><a href="{{ route(auth()->user()->hasRole('admin') ? 'public-cars.preview.index' : 'public-cars.index') }}" class="rounded bg-white px-3 py-2 text-gray-900">Visualizza le auto</a></div></x-slot>
    @php
        $v = fn ($key, $default = '') => is_scalar(old($key, $default)) ? (string) old($key, $default) : '';
        $bariGroups = \App\Domain\Rentals\PublicPickupDirectory::BARI_GROUPS;
    @endphp
    <div class="max-w-5xl mx-auto space-y-6 amr era-delivery-settings">@if(auth()->user()->hasRole('admin'))<x-amd-rent-nav />@endif
        @if(session('status'))<div class="rounded border border-green-300 bg-green-50 text-green-900 p-4" role="status">{{ session('status') }}</div>@endif
        @if($errors->any())<div role="alert" class="rounded border border-red-400 bg-red-50 text-red-900 p-4"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <section class="app-surface rounded border p-4 sm:p-6" aria-labelledby="bari-coverage-heading">
            <h2 id="bari-coverage-heading" class="text-lg font-semibold">Aree di Bari servite</h2>
            <p class="app-muted mt-2">Attiva un punto di ritiro nell’area che servi. Per smettere di servire un’area, disattiva tutti i tuoi punti al suo interno. La copertura per indirizzo resta configurabile separatamente.</p>
            <ul class="divide-y mt-4">
                @foreach($bariGroups as $groupKey => $group)
                    @php $activePoints = $deliveries->filter(fn ($delivery) => $delivery->is_active && \App\Domain\Rentals\PublicPickupDirectory::groupForPlace($delivery->place) === $groupKey); @endphp
                    <li class="py-3"><h3 class="font-semibold">{{ $group['name'] }}</h3>
                        @forelse($activePoints as $delivery)
                            <p class="mt-1"><a class="underline" href="#delivery-{{ $delivery->id }}">{{ $delivery->place->name }}</a>@if(auth()->user()->hasRole('admin')) — {{ $delivery->organization->name }}@endif</p>
                        @empty<p class="app-muted mt-1">Nessun punto attivo.</p>@endforelse
                    </li>
                @endforeach
            </ul>
            <a class="inline-block underline mt-3" href="#delivery-create">Configura un punto nelle aree servite</a>
        </section>
        <section id="delivery-create" class="app-surface rounded border p-4 sm:p-6">
            <h1 class="text-xl font-semibold">Dove consegni e ritiri le auto?</h1>
            <p class="app-muted mt-2">Indica i luoghi serviti. I luoghi attivi compaiono subito sul sito. Il cliente vedrà le tue auto disponibili per tutto il periodo, ai prezzi dei listini attivi.</p>
            <form method="post" action="{{ route('public-deliveries.store') }}" class="space-y-4 mt-5" x-data="{ pickupGroup: @js($v('pickup_group')), placeId: @js($v('place_id')) }">
                @csrf
                <div><label for="delivery-organization" class="block mb-2">Noleggiatore</label><select id="delivery-organization" name="organization_id" class="w-full rounded border app-field" required>@foreach($organizations as $organization)<option value="{{ $organization->id }}" @selected($v('organization_id', auth()->user()->organization_id) === (string) $organization->id)>{{ $organization->name }}</option>@endforeach</select></div>
                <div><label for="delivery-group" class="block mb-2">Area di ritiro</label><select id="delivery-group" name="pickup_group" class="w-full rounded border app-field" x-model="pickupGroup" @change="placeId = ''"><option value="">Altro luogo</option>@foreach($bariGroups as $groupKey => $group)<option value="{{ $groupKey }}" @selected($v('pickup_group') === $groupKey)>{{ $group['name'] }}</option>@endforeach</select><p class="app-muted text-sm mt-2">Scegli l’area e il punto preciso in cui incontri il cliente. Le tue auto appariranno nella ricerca dell’area solo con un punto attivo e disponibilità per le date richieste.</p></div>
                <div><label for="delivery-place" class="block mb-2">Luogo già presente</label><select id="delivery-place" name="place_id" class="w-full rounded border app-field" x-model="placeId"><option value="">Seleziona un luogo, oppure aggiungine uno sotto</option>@foreach($places as $place)<option value="{{ $place->id }}" x-bind:disabled="pickupGroup !== '' && pickupGroup !== @js(\App\Domain\Rentals\PublicPickupDirectory::groupForPlace($place) ?? '')" @selected($v('place_id') === (string) $place->id)>{{ $place->label }}</option>@endforeach</select><p class="app-muted text-sm mt-2">Scegli un punto esistente nell’area selezionata, se corrisponde al tuo servizio, oppure inserisci il tuo punto d’incontro. L’attivazione riguarda solo il noleggiatore selezionato.</p></div>
                <details class="border rounded p-4" @if($errors->hasAny(['name','city','kind','address_line','country_code']) || $v('name')) open @endif>
                    <summary class="cursor-pointer font-semibold">Il luogo non è presente? Aggiungilo</summary>
                    <p class="app-muted text-sm mt-3">Compila questi dati soltanto se non hai selezionato un luogo dall’elenco. Usa il nome completo per renderlo riconoscibile.</p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-4">
                        <div><label for="delivery-name" class="block mb-2">Nome del luogo</label><input id="delivery-name" name="name" value="{{ $v('name') }}" maxlength="191" class="w-full rounded border app-field"></div>
                        <div x-show="pickupGroup === ''"><label for="delivery-kind" class="block mb-2">Tipo di luogo</label><select id="delivery-kind" name="kind" class="w-full rounded border app-field" x-bind:disabled="pickupGroup !== ''">@foreach(\App\Models\PublicPickupPlace::KINDS as $key => $label)<option value="{{ $key }}" @selected($v('kind', 'location') === $key)>{{ $label }}</option>@endforeach</select></div>
                        <div x-show="pickupGroup === ''"><label for="delivery-city" class="block mb-2">Città</label><input id="delivery-city" name="city" value="{{ $v('city') }}" maxlength="128" class="w-full rounded border app-field" x-bind:disabled="pickupGroup !== ''"></div>
                        <div x-show="pickupGroup === ''"><label for="delivery-country" class="block mb-2">Codice paese (IT per Italia)</label><input id="delivery-country" name="country_code" value="{{ $v('country_code', 'IT') }}" maxlength="2" pattern="[A-Z]{2}" class="w-full rounded border app-field" x-bind:disabled="pickupGroup !== ''"></div>
                        <div class="sm:col-span-2"><label for="delivery-address" class="block mb-2">Indirizzo o punto d’incontro</label><input id="delivery-address" name="address_line" value="{{ $v('address_line') }}" maxlength="191" class="w-full rounded border app-field" x-bind:required="pickupGroup !== '' && placeId === ''"></div>
                    </div>
                </details>
                <label class="flex gap-3 items-start"><input type="checkbox" name="confirm_delivery" value="1" required class="mt-1"><span>Confermo di poter consegnare e ritirare le auto in questo luogo, alle condizioni e ai prezzi dei miei listini attivi.</span></label>
                <button type="submit" class="rounded bg-indigo-700 text-white px-4 py-3">Attiva luogo di consegna</button>
            </form>
        </section>
        <section class="app-surface rounded border p-4 sm:p-6">
            <h2 class="text-lg font-semibold">Luoghi configurati</h2>
            <p class="app-muted mt-2">I luoghi italiani con città Bari e tipo Aeroporto, Porto o Stazione sono raggruppati nella ricerca pubblica. Il punto d’incontro preciso resta nella prenotazione. La consegna per indirizzo continua a usare la copertura configurata.</p>
            <p class="app-muted mt-2">Disattivando un luogo lo escludi dalle nuove ricerche per la tua organizzazione. Le prenotazioni esistenti restano associate al luogo concordato.</p>
            <div class="divide-y mt-4">@forelse($deliveries as $delivery)
                <article id="delivery-{{ $delivery->id }}" class="py-4 flex flex-wrap justify-between items-center gap-4">
                    <div class="min-w-0"><h3 class="font-semibold break-words">{{ $delivery->place->label }}</h3><p class="text-sm app-muted">{{ $delivery->organization->name }}</p><p class="text-sm app-muted">{{ $delivery->place->address_line }}</p><p class="text-sm mt-1">{{ $delivery->is_active ? 'Attivo nelle ricerche' : 'Disattivato' }}</p></div>
                    <form method="post" action="{{ route('public-deliveries.update', $delivery) }}">@csrf @method('PUT')<input type="hidden" name="is_active" value="{{ $delivery->is_active ? 0 : 1 }}"><button class="rounded border px-4 py-3" type="submit">{{ $delivery->is_active ? 'Disattiva' : 'Riattiva' }}</button></form>
                </article>
                @if(auth()->user()->hasRole('admin'))
                <form class="amr-form" method="post" action="{{ route('public-deliveries.update', $delivery) }}">@csrf @method('PUT')
                    <input type="hidden" name="is_active" value="{{ $delivery->is_active ? 1 : 0 }}">
                    <label for="public-place-kind-{{ $delivery->id }}">Tipo del luogo pubblico</label>
                    <select class="app-field" id="public-place-kind-{{ $delivery->id }}" name="place_kind">@foreach(\App\Models\PublicPickupPlace::KINDS as $key => $label)<option value="{{ $key }}" @selected($delivery->place->kind === $key)>{{ $label }}</option>@endforeach</select>
                    <p>La classificazione vale per tutti i noleggiatori che servono questo stesso luogo. Verifica il punto prima di assegnarlo a un gruppo.</p>
                    <button class="amr-button">Salva tipo di luogo</button>
                </form>
                @endif
                <details class="amr-disclosure"><summary>Consegne personalizzate per {{ $delivery->place->name }}</summary><form class="amr-form" method="post" action="{{ route('public-deliveries.update', $delivery) }}">@csrf @method('PUT')<input type="hidden" name="is_active" value="{{ $delivery->is_active ? 1 : 0 }}"><input type="hidden" name="custom_delivery_enabled" value="0"><label class="amr-check"><input type="checkbox" name="custom_delivery_enabled" value="1" @checked($delivery->custom_delivery_enabled)><span>Accetto richieste di consegna in hotel o ad altri indirizzi.</span></label><label for="delivery-area-{{ $delivery->id }}">Zona servita e limiti del servizio</label><textarea class="app-field" id="delivery-area-{{ $delivery->id }}" name="delivery_area" rows="3" maxlength="500">{{ $delivery->delivery_area }}</textarea><label for="delivery-origin-{{ $delivery->id }}">Sede da cui parti per la consegna</label>
<select class="app-field" id="delivery-origin-{{ $delivery->id }}" name="delivery_origin_location_id"><option value="">Seleziona una sede della tua organizzazione</option>@foreach($origins->where('organization_id', $delivery->organization_id) as $origin)<option value="{{ $origin->id }}" @selected((int) $delivery->delivery_origin_location_id === (int) $origin->id)>{{ $origin->name }} — {{ $origin->address_line }}, {{ $origin->city }}</option>@endforeach</select>
<label for="delivery-radius-{{ $delivery->id }}">Raggio massimo di consegna, in km</label><input class="app-field" id="delivery-radius-{{ $delivery->id }}" name="delivery_radius_km" type="number" min="0.01" max="999999.99" step="0.01" value="{{ $delivery->delivery_radius_km }}">
<label for="delivery-package-{{ $delivery->id }}">Prezzo complessivo di consegna e ritiro, IVA inclusa (€)</label><input class="app-field" id="delivery-package-{{ $delivery->id }}" name="custom_delivery_fee" type="number" min="0" max="100000" step="0.01" value="{{ $delivery->custom_delivery_fee_cents === null ? '' : $delivery->custom_delivery_fee_cents / 100 }}" aria-describedby="delivery-package-help-{{ $delivery->id }}">
<p id="delivery-package-help-{{ $delivery->id }}">Un unico importo per entrambi gli appuntamenti, anche in luoghi diversi. Inserisci 0 solo se offri il servizio gratuitamente. Il prezzo sarà incluso nel totale e nella tariffa media giornaliera mostrata al cliente.</p>
<p>La ricerca per indirizzo include il servizio solo quando sede, posizione, raggio e prezzo sono configurati. Entrambi i punti devono rientrare nel raggio, misurato in linea d’aria.</p>
@if($delivery->origin && $delivery->origin->lat !== null && $delivery->origin->lng !== null)<p>Posizione configurata per {{ $delivery->origin->name }}: {{ $delivery->origin->lat }}, {{ $delivery->origin->lng }}.</p>@endif
<button class="amr-button" type="submit" name="locate_origin" value="1">Cerca o aggiorna la posizione della sede</button>
<p>La ricerca usa l’indirizzo della sede su OpenStreetMap e ti chiede di confermare il risultato prima di salvare. La posizione aggiornata vale per tutti i servizi collegati alla stessa sede.</p>
<p>Riceverai auto, date, luoghi e recapiti nella richiesta. Conferma disponibilità e appuntamenti prima del pagamento del cliente.</p><button class="amr-button">Salva servizio di consegna</button></form></details>
            @empty<p class="py-4">Non hai ancora configurato luoghi di consegna.</p>@endforelse</div>
        </section>
    </div>
</x-app-layout>

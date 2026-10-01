<x-app-layout>
    <x-slot name="header"><div class="flex flex-wrap justify-between items-center gap-3"><h2 class="font-semibold text-xl text-white">Luoghi di consegna</h2><a href="{{ route('public-cars.preview.index') }}" class="rounded bg-white px-3 py-2 text-gray-900">Visualizza le auto</a></div></x-slot>
    @php $v = fn ($key, $default = '') => is_scalar(old($key, $default)) ? (string) old($key, $default) : ''; @endphp
    <div class="max-w-5xl mx-auto space-y-6 amr"><x-amd-rent-nav />
        @if(session('status'))<div class="rounded border border-green-300 bg-green-50 text-green-900 p-4" role="status">{{ session('status') }}</div>@endif
        @if($errors->any())<div role="alert" class="rounded border border-red-400 bg-red-50 text-red-900 p-4"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <section class="app-surface rounded border p-4 sm:p-6">
            <h1 class="text-xl font-semibold">Dove consegni e ritiri le auto?</h1>
            <p class="app-muted mt-2">Indica i luoghi serviti. I luoghi attivi compaiono subito sul sito. Il cliente vedrà le tue auto disponibili per tutto il periodo, ai prezzi dei listini attivi.</p>
            <form method="post" action="{{ route('public-deliveries.store') }}" class="space-y-4 mt-5">
                @csrf
                <div><label for="delivery-organization" class="block mb-2">Noleggiatore</label><select id="delivery-organization" name="organization_id" class="w-full rounded border app-field" required>@foreach($organizations as $organization)<option value="{{ $organization->id }}" @selected($v('organization_id', auth()->user()->organization_id) === (string) $organization->id)>{{ $organization->name }}</option>@endforeach</select></div>
                <div><label for="delivery-place" class="block mb-2">Luogo già presente</label><select id="delivery-place" name="place_id" class="w-full rounded border app-field"><option value="">Seleziona un luogo, oppure aggiungine uno sotto</option>@foreach($places as $place)<option value="{{ $place->id }}" @selected($v('place_id') === (string) $place->id)>{{ $place->label }}</option>@endforeach</select><p class="app-muted text-sm mt-2">Per lo stesso aeroporto o stazione, scegli la voce già presente: sarà condivisa con gli altri noleggiatori.</p></div>
                <details class="border rounded p-4" @if($errors->hasAny(['name','city','kind','address_line','country_code']) || $v('name')) open @endif>
                    <summary class="cursor-pointer font-semibold">Il luogo non è presente? Aggiungilo</summary>
                    <p class="app-muted text-sm mt-3">Compila questi dati soltanto se non hai selezionato un luogo dall’elenco. Usa il nome completo per renderlo riconoscibile.</p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-4">
                        <div><label for="delivery-name" class="block mb-2">Nome del luogo</label><input id="delivery-name" name="name" value="{{ $v('name') }}" maxlength="191" class="w-full rounded border app-field"></div>
                        <div><label for="delivery-kind" class="block mb-2">Tipo di luogo</label><select id="delivery-kind" name="kind" class="w-full rounded border app-field">@foreach(\App\Models\PublicPickupPlace::KINDS as $key => $label)<option value="{{ $key }}" @selected($v('kind', 'location') === $key)>{{ $label }}</option>@endforeach</select></div>
                        <div><label for="delivery-city" class="block mb-2">Città</label><input id="delivery-city" name="city" value="{{ $v('city') }}" maxlength="128" class="w-full rounded border app-field"></div>
                        <div><label for="delivery-country" class="block mb-2">Codice paese (IT per Italia)</label><input id="delivery-country" name="country_code" value="{{ $v('country_code', 'IT') }}" maxlength="2" pattern="[A-Z]{2}" class="w-full rounded border app-field"></div>
                        <div class="sm:col-span-2"><label for="delivery-address" class="block mb-2">Indirizzo o punto d’incontro</label><input id="delivery-address" name="address_line" value="{{ $v('address_line') }}" maxlength="191" class="w-full rounded border app-field"></div>
                    </div>
                </details>
                <label class="flex gap-3 items-start"><input type="checkbox" name="confirm_delivery" value="1" required class="mt-1"><span>Confermo di poter consegnare e ritirare le auto in questo luogo, alle condizioni e ai prezzi dei miei listini attivi.</span></label>
                <button type="submit" class="rounded bg-indigo-700 text-white px-4 py-3">Attiva luogo di consegna</button>
            </form>
        </section>
        <section class="app-surface rounded border p-4 sm:p-6">
            <h2 class="text-lg font-semibold">Luoghi configurati</h2>
            <p class="app-muted mt-2">Disattivando un luogo lo escludi dalle nuove ricerche per la tua organizzazione. Le prenotazioni esistenti restano associate al luogo concordato.</p>
            <div class="divide-y mt-4">@forelse($deliveries as $delivery)
                <article class="py-4 flex flex-wrap justify-between items-center gap-4">
                    <div class="min-w-0"><h3 class="font-semibold break-words">{{ $delivery->place->label }}</h3><p class="text-sm app-muted">{{ $delivery->organization->name }}</p><p class="text-sm app-muted">{{ $delivery->place->address_line }}</p><p class="text-sm mt-1">{{ $delivery->is_active ? 'Attivo nelle ricerche' : 'Disattivato' }}</p></div>
                    <form method="post" action="{{ route('public-deliveries.update', $delivery) }}">@csrf @method('PUT')<input type="hidden" name="is_active" value="{{ $delivery->is_active ? 0 : 1 }}"><button class="rounded border px-4 py-3" type="submit">{{ $delivery->is_active ? 'Disattiva' : 'Riattiva' }}</button></form>
                </article>
                <details class="amr-disclosure"><summary>Consegne personalizzate per {{ $delivery->place->name }}</summary><form class="amr-form" method="post" action="{{ route('public-deliveries.update', $delivery) }}">@csrf @method('PUT')<input type="hidden" name="is_active" value="{{ $delivery->is_active ? 1 : 0 }}"><input type="hidden" name="custom_delivery_enabled" value="0"><label class="amr-check"><input type="checkbox" name="custom_delivery_enabled" value="1" @checked($delivery->custom_delivery_enabled)><span>Accetto richieste di consegna in hotel o ad altri indirizzi.</span></label><label for="delivery-area-{{ $delivery->id }}">Zona servita e limiti del servizio</label><textarea class="app-field" id="delivery-area-{{ $delivery->id }}" name="delivery_area" rows="3" maxlength="500">{{ $delivery->delivery_area }}</textarea><p>Ogni indirizzo deve essere verificato. Il supplemento viene indicato nella singola richiesta, prima che il cliente paghi.</p><button class="amr-button">Salva servizio di consegna</button></form></details>
            @empty<p class="py-4">Non hai ancora configurato luoghi di consegna.</p>@endforelse</div>
        </section>
    </div>
</x-app-layout>

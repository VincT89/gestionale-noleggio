@extends('layouts.public-cars')
@section('title', $car ? $car['title'] : 'Disponibilità aggiornata')
@section('content')
@php
    $money = fn($cents) => number_format($cents / 100, 2, ',', '.').' €';
    $customPickup = !empty($filters['request_delivery']);
    $customReturn = $customPickup && !empty($filters['request_custom_return']);
@endphp
<a class="amd-back" href="{{ route($routePrefix.'.index', $filters) }}">Torna ai risultati</a>
@if(!$car)
    <div class="amd-empty"><h1>La disponibilità è cambiata</h1><p>Questa auto non è disponibile per tutto il periodo richiesto. Controlla le altre auto o modifica le date.</p><a class="amd-button" href="{{ route($routePrefix.'.index', $filters) }}">Cerca altre auto</a></div>
@else
    <div class="amd-page-heading"><h1>{{ $car['title'] }}</h1><p>{{ $customPickup ? 'Ritiro richiesto: '.$filters['delivery_address'] : $car['city'].' — '.$car['location'] }}</p></div>
    @if($errors->any())<div class="amd-errors" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <div class="amd-detail">
        <section class="amd-detail-info" aria-label="Dettagli dell’auto">
            @if($car['has_photo'])
                <figure class="amd-detail-figure">
                    <img class="amd-detail-photo" src="{{ route($routePrefix.'.photo', $car['id']) }}" alt="{{ $car['photo_is_reference'] ? 'Immagine indicativa: '.$car['title'] : $car['title'] }}" width="720" height="480">
                    @if($car['photo_is_reference'])<figcaption>Immagine indicativa del modello. Colore e allestimento possono variare.</figcaption>@endif
                    <button class="amd-button amd-button-secondary amd-photo-open" type="button" data-photo-open aria-haspopup="dialog" aria-controls="car-photo-dialog" hidden>Guarda la foto</button>
                </figure>
                <dialog id="car-photo-dialog" class="amd-photo-dialog" data-photo-dialog aria-label="Foto di {{ $car['title'] }}">
                    <button class="amd-button amd-button-secondary" type="button" data-photo-close autofocus>Chiudi foto</button>
                    <figure><img src="{{ route($routePrefix.'.photo', $car['id']) }}" alt="{{ $car['title'] }}" loading="lazy" width="720" height="480"><figcaption>{{ $car['photo_is_reference'] ? 'Immagine indicativa del modello. Colore e allestimento possono variare.' : $car['title'] }}</figcaption></figure>
                </dialog>
            @endif
            <h2>Caratteristiche</h2><dl class="amd-specs amd-specs-detail">
                @if($car['segment'])<div><dt>Categoria</dt><dd>{{ $car['segment'] }}</dd></div>@endif
                @if($car['seats'])<div><dt>Posti</dt><dd>{{ $car['seats'] }}</dd></div>@endif
                @if($car['transmission'])<div><dt>Cambio</dt><dd>{{ $car['transmission'] }}</dd></div>@endif
                @if($car['fuel'])<div><dt>Alimentazione</dt><dd>{{ $car['fuel'] }}</dd></div>@endif
                @if($car['year'])<div><dt>Anno</dt><dd>{{ $car['year'] }}</dd></div>@endif
            </dl>
            @if($customPickup)<h2>Ritiro richiesto</h2><p>{{ $filters['delivery_address'] }}</p><p>Indirizzo ed eventuale supplemento devono essere confermati dal noleggiatore. Zona del servizio: {{ $car['delivery_area'] }}</p>@endif
            @include('public-cars.partials.selected-map-point', ['point' => $car['delivery_destination'] ?? null])
            <h2>{{ $customPickup ? 'Luogo di riconsegna' : 'Luoghi del noleggio' }}</h2>
            @if($customReturn)<p><strong>{{ $filters['return_address'] }}</strong><br>Da confermare dal noleggiatore.</p>
                @include('public-cars.partials.selected-map-point', ['point' => $returnPoint ?? null, 'pointType' => 'return'])
            @elseif($customPickup && empty($filters['place_id']))<p>Scegli un punto servito oppure indica un altro luogo nel riepilogo del noleggio.</p>
            @else<p>@unless($customPickup)Ritiro: @endunless<strong>{{ $car['location'] }}</strong><br>{{ $car['address'] }}<br>{{ $car['city'] }}</p>@endif
            @unless($customPickup)<p>Riconsegna: {{ $car['location'] }}.</p>@endunless<p>Offerta da {{ $car['organization'] }}.</p>
            @if($car['description'])<h2>Informazioni sull’offerta</h2><p class="amd-description">{{ $car['description'] }}</p>@endif
        </section>
        <aside class="amd-quote" aria-labelledby="quote-title"><h2 id="quote-title">Il tuo noleggio</h2>
            <dl class="amd-quote-lines"><div><dt>Ritiro</dt><dd>{{ \Carbon\CarbonImmutable::parse($filters['pickup_at'])->format('d/m/Y H:i') }}</dd></div><div><dt>Riconsegna</dt><dd>{{ \Carbon\CarbonImmutable::parse($filters['return_at'])->format('d/m/Y H:i') }}</dd></div><div><dt>Durata tariffata</dt><dd>{{ $car['days'] }} {{ $car['days'] === 1 ? 'giorno' : 'giorni' }}</dd></div></dl>
            <p class="amd-total-label">Totale noleggio</p><strong class="amd-total">{{ $money($car['total_cents']) }}</strong><p>{{ $car['prices_include_vat'] ? 'IVA inclusa' : 'Importo di listino, IVA da verificare' }}</p>
            <dl class="amd-quote-lines"><div><dt>Cauzione separata</dt><dd>{{ $money($car['deposit_cents']) }}</dd></div><div><dt>Chilometri inclusi</dt><dd>{{ $car['km_per_day'] === null ? 'Illimitati' : $car['km_per_day'].' km/giorno' }}</dd></div>@if($car['km_per_day'] !== null)<div><dt>Chilometri extra</dt><dd>{{ $money($car['extra_km_cents']) }}/km</dd></div>@endif</dl>
            @if(isset($filters['budget']) && $car['total_cents'] > round((float)$filters['budget'] * 100))<p class="amd-errors" role="status">Il prezzo aggiornato supera il budget indicato. Puoi modificare la ricerca.</p>@endif
            <p class="amd-availability">Disponibile per il periodo selezionato.</p>
            @if($customPickup)
                <form class="amd-return-choice" method="get" action="{{ route($routePrefix.'.booking.create', ['pricelist' => $car['id']]) }}" data-return-choice>
                    @foreach(\Illuminate\Support\Arr::only($filters, ['pickup_at', 'return_at', 'request_delivery', 'delivery_address', 'delivery_place']) as $key => $fieldValue)<input type="hidden" name="{{ $key }}" value="{{ $fieldValue }}">@endforeach
                    <div class="amd-field" data-return-standard><label for="return-place">Dove riconsegni l’auto?</label><select id="return-place" name="place_id" aria-describedby="return-place-help"><option value="">Scegli un punto di riconsegna</option>@foreach($returnPlaces as $place)<option value="{{ $place->id }}" @selected((string) ($filters['place_id'] ?? '') === (string) $place->id)>{{ $place->label }}</option>@endforeach</select><small id="return-place-help">I punti serviti da {{ $car['organization'] }} per questa consegna.</small></div>
                    <label class="amd-booking-accept amd-return-toggle" for="request-custom-return"><input id="request-custom-return" name="request_custom_return" type="checkbox" value="1" data-custom-return-toggle aria-controls="custom-return-field" @checked($customReturn)><span>Voglio riconsegnare l’auto in un altro luogo</span></label>
                    <div class="amd-field amd-custom-return-field" id="custom-return-field" data-custom-return-field>
                        <label for="return-address">Luogo di riconsegna personalizzato</label>
                        <input id="return-address" name="return_address" type="text" value="{{ $customReturn ? $filters['return_address'] : '' }}" placeholder="Scegli un punto sulla mappa" readonly aria-describedby="return-address-help" data-custom-return-address>
                        <input name="return_place" type="hidden" value="{{ $customReturn ? ($filters['return_place'] ?? '') : '' }}" data-custom-return-place>
                        <button class="amd-button amd-button-secondary" type="button" data-return-map-open aria-haspopup="dialog" aria-controls="return-map-dialog" hidden>{{ $customReturn ? 'Modifica il punto sulla mappa' : 'Scegli il punto sulla mappa' }}</button>
                        <small id="return-address-help">Cerca un aeroporto, un hotel o un indirizzo e segna il punto esatto. Il noleggiatore confermerà la riconsegna e l’eventuale supplemento.</small>
                        <small data-return-choice-status role="status" aria-live="polite"></small>
                        <noscript><p>Attiva JavaScript per scegliere sulla mappa, oppure usa un punto di riconsegna dall’elenco.</p></noscript>
                    </div>
                    <button class="amd-button" type="submit" data-return-submit>{{ $customReturn ? 'Richiedi ritiro e riconsegna' : 'Richiedi il ritiro a questo indirizzo' }}</button>
                </form>
            @else
                <a class="amd-button" href="{{ route($routePrefix.'.booking.create', ['pricelist' => $car['id']] + \Illuminate\Support\Arr::only($filters, ['pickup_at', 'return_at']) + ['place_id' => $car['place_id']]) }}">Prenota con il 20% online</a>
            @endif
            @if($customPickup)
                <small>Prima invii la richiesta. Pagherai dopo aver visto la proposta con il supplemento e il totale aggiornato.</small>
            @elseif(!$preview)
                <small>Versi il 20% con Stripe. Conferma dopo il pagamento verificato; saldo al ritiro.</small>
            @endif
        </aside>
    </div>
    @if($customPickup)
        <dialog id="return-map-dialog" class="amd-return-map-dialog" data-return-map-dialog data-confirmed-point="{{ json_encode($returnPoint ?? null) }}" aria-labelledby="return-map-title">
            <div class="amd-return-map-heading"><div><h2 id="return-map-title">Dove riconsegni l’auto?</h2><p>Un aeroporto, il tuo hotel, un altro indirizzo. Scegli il punto in cui incontrare il noleggiatore.</p></div><button type="button" class="amd-button amd-button-secondary" data-return-map-close autofocus>Chiudi</button></div>
            <p class="amd-return-map-feedback" data-return-map-feedback role="status" aria-live="polite"></p>
            @include('public-cars.partials.pickup-map', ['mapPurpose' => 'return', 'mapCenter' => $car['delivery_destination'] ?? null])
        </dialog>
    @endif
@endif
@endsection

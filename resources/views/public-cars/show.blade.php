@extends('layouts.public-cars')
@section('title', $car ? $car['title'] : 'Disponibilità aggiornata')
@section('content')
@php
    $money = fn($cents) => number_format($cents / 100, 2, ',', '.').' €';
    $customPickup = !empty($filters['request_delivery']);
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
            <h2>{{ $customPickup ? 'Luogo di riconsegna' : 'Luoghi del noleggio' }}</h2>
            @if($customPickup && empty($filters['place_id']))<p>Scegli il punto di riconsegna nel riepilogo del noleggio prima di inviare la richiesta.</p>
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
                <form class="amd-return-choice" method="get" action="{{ route($routePrefix.'.booking.create', ['pricelist' => $car['id']]) }}">
                    @foreach(\Illuminate\Support\Arr::only($filters, ['pickup_at', 'return_at', 'request_delivery', 'delivery_address', 'delivery_place']) as $key => $fieldValue)<input type="hidden" name="{{ $key }}" value="{{ $fieldValue }}">@endforeach
                    <div class="amd-field"><label for="return-place">Dove riconsegni l’auto?</label><select id="return-place" name="place_id" required aria-describedby="return-place-help"><option value="">Scegli un punto di riconsegna</option>@foreach($returnPlaces as $place)<option value="{{ $place->id }}" @selected((string) ($filters['place_id'] ?? '') === (string) $place->id)>{{ $place->label }}</option>@endforeach</select><small id="return-place-help">I punti serviti da {{ $car['organization'] }} per questa consegna.</small></div>
                    <button class="amd-button" type="submit">Richiedi il ritiro a questo indirizzo</button>
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
@endif
@endsection

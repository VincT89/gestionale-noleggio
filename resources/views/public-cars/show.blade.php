@extends('layouts.public-cars')
@section('title', $car ? $car['title'] : 'Disponibilità aggiornata')
@section('content')
@php $money = fn($cents) => number_format($cents / 100, 2, ',', '.').' €'; @endphp
<a class="amd-back" href="{{ route($routePrefix.'.index', $filters) }}">Torna ai risultati</a>
@if(!$car)
    <div class="amd-empty"><h1>La disponibilità è cambiata</h1><p>Questa auto non è disponibile per tutto il periodo richiesto. Controlla le altre auto o modifica le date.</p><a class="amd-button" href="{{ route($routePrefix.'.index', $filters) }}">Cerca altre auto</a></div>
@else
    <div class="amd-page-heading"><h1>{{ $car['title'] }}</h1><p>{{ $car['city'] }} — {{ $car['location'] }}</p></div>
    <div class="amd-detail">
        <section class="amd-detail-info" aria-label="Dettagli dell’auto">
            @if($car['has_photo'])
                <figure class="amd-detail-figure">
                    <img class="amd-detail-photo" src="{{ route($routePrefix.'.photo', $car['id']) }}" alt="{{ $car['photo_is_reference'] ? 'Immagine indicativa: '.$car['title'] : $car['title'] }}" width="720" height="480">
                    @if($car['photo_is_reference'])<figcaption>Immagine indicativa del modello. Colore e allestimento possono variare.</figcaption>@endif
                </figure>
            @endif
            <h2>Caratteristiche</h2><dl class="amd-specs amd-specs-detail">
                @if($car['segment'])<div><dt>Categoria</dt><dd>{{ $car['segment'] }}</dd></div>@endif
                @if($car['seats'])<div><dt>Posti</dt><dd>{{ $car['seats'] }}</dd></div>@endif
                @if($car['transmission'])<div><dt>Cambio</dt><dd>{{ $car['transmission'] }}</dd></div>@endif
                @if($car['fuel'])<div><dt>Alimentazione</dt><dd>{{ $car['fuel'] }}</dd></div>@endif
                @if($car['year'])<div><dt>Anno</dt><dd>{{ $car['year'] }}</dd></div>@endif
            </dl>
            <h2>Ritiro e riconsegna</h2><p><strong>{{ $car['location'] }}</strong><br>{{ $car['address'] }}<br>{{ $car['city'] }}</p><p>Offerta da {{ $car['organization'] }}.</p>
            @if($car['description'])<h2>Informazioni sull’offerta</h2><p class="amd-description">{{ $car['description'] }}</p>@endif
        </section>
        <aside class="amd-quote" aria-labelledby="quote-title"><h2 id="quote-title">Il tuo noleggio</h2>
            <dl class="amd-quote-lines"><div><dt>Ritiro</dt><dd>{{ \Carbon\CarbonImmutable::parse($filters['pickup_at'])->format('d/m/Y H:i') }}</dd></div><div><dt>Riconsegna</dt><dd>{{ \Carbon\CarbonImmutable::parse($filters['return_at'])->format('d/m/Y H:i') }}</dd></div><div><dt>Durata tariffata</dt><dd>{{ $car['days'] }} {{ $car['days'] === 1 ? 'giorno' : 'giorni' }}</dd></div></dl>
            <p class="amd-total-label">Totale noleggio</p><strong class="amd-total">{{ $money($car['total_cents']) }}</strong><p>{{ $car['prices_include_vat'] ? 'IVA inclusa' : 'Importo di listino, IVA da verificare' }}</p>
            <dl class="amd-quote-lines"><div><dt>Cauzione separata</dt><dd>{{ $money($car['deposit_cents']) }}</dd></div><div><dt>Chilometri inclusi</dt><dd>{{ $car['km_per_day'] ? $car['km_per_day'].' km/giorno' : 'Da concordare' }}</dd></div>@if($car['km_per_day'])<div><dt>Chilometri extra</dt><dd>{{ $money($car['extra_km_cents']) }}/km</dd></div>@endif</dl>
            @if(isset($filters['budget']) && $car['total_cents'] > round((float)$filters['budget'] * 100))<p class="amd-errors" role="status">Il prezzo aggiornato supera il budget indicato. Puoi modificare la ricerca.</p>@endif
            <p class="amd-availability">Disponibile per il periodo selezionato.</p>
            <a class="amd-button" href="{{ route($routePrefix.'.booking.create', ['offer' => $car['id'], 'pickup_at' => $filters['pickup_at'], 'return_at' => $filters['return_at']]) }}">Prenota con pagamento al ritiro</a>
            @if(!$preview)
                <small>Conferma immediata. Il pagamento del noleggio avviene al ritiro.</small>
            @endif
        </aside>
    </div>
@endif
@endsection

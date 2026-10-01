@extends('layouts.public-cars')
@section('title', 'Assistenza')
@section('main-class', 'amd-info-page')
@section('content')
<div class="amd-info-heading"><div class="amd-section-inner amd-photo-heading">
    <div>
    <h1>Assistenza per la tua prenotazione</h1>
    <p>Tieni a portata di mano il riferimento della prenotazione, le date e il nome del noleggiatore.</p>
</div>
    @include('public-cars.partials.context-photo', ['photoScene' => 'support'])
</div></div>
<div class="amd-section-inner amd-info-layout">
<aside class="amd-info-nav"><nav aria-label="Informazioni utili">
    <h2>Come possiamo orientarti</h2>
    <ul>
        <li><a href="#prenotazione-esistente">Hai già prenotato?</a></li>
        <li><a href="#contatti-amd-rent">Contatti AMD Rent</a></li>
        <li><a href="{{ route('public-site.how-it-works') }}">Come funziona la prenotazione</a></li>
    </ul>
</nav></aside>
<article class="amd-information">
    <h2 id="prenotazione-esistente">Hai già prenotato?</h2>
    <p>Nella conferma e nel PDF trovi il riferimento, il noleggiatore, il luogo di ritiro e il riepilogo della prenotazione. Per modifiche o richieste sul ritiro, fai riferimento al noleggiatore indicato nella prenotazione.</p>
    <h2 id="contatti-amd-rent">Contatti AMD Rent</h2>
    @if(config('public_cars.contact_email') || config('public_cars.contact_phone'))
        <address class="amd-contact-details">
            @if(config('public_cars.contact_email'))<p>Email: <a href="mailto:{{ config('public_cars.contact_email') }}">{{ config('public_cars.contact_email') }}</a></p>@endif
            @if(config('public_cars.contact_phone'))<p>Telefono: <a href="tel:{{ preg_replace('/[^+0-9]/', '', config('public_cars.contact_phone')) }}">{{ config('public_cars.contact_phone') }}</a></p>@endif
        </address>
    @else
        <p>I recapiti dell’assistenza AMD Rent non sono ancora disponibili: saranno pubblicati prima dell’apertura del sito.</p>
    @endif
    <h2>Prima di prenotare</h2>
    <p>Consulta le condizioni dell’auto scelta e <a href="{{ route('public-site.how-it-works') }}">come funziona la prenotazione</a>. Il totale del noleggio e la cauzione sono mostrati separatamente nel riepilogo.</p>
    <p><a class="amd-button amd-button-secondary" href="{{ route('public-cars.index') }}">Torna alla ricerca</a></p>
</article>
</div>
@endsection

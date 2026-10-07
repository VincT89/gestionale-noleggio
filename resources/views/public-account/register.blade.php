@extends('layouts.public-cars')
@section('title', 'Crea la tua area cliente')
@section('content')
<div class="amd-account-auth">
    <div class="amd-account-intro"><h1>Un posto per i tuoi noleggi.</h1><p>Le conferme di prenotazione, gli importi pagati online e i preventivi di lungo termine, raccolti nella tua area personale.</p><p>Usa l’email della prenotazione. Ti invieremo un collegamento per verificarla prima di mostrare i tuoi dati.</p><p>La registrazione è facoltativa e non comporta una prenotazione.</p><a href="{{ route('public-account.login') }}">Hai già un account? Accedi</a></div>
    <form class="amd-account-form" method="post" action="{{ route('public-account.register.store') }}">@csrf<h2>Crea il tuo account</h2>@include('public-account.feedback')
        @include('public-account.contact-fields', ['customer' => null])
        <div class="amd-field"><label for="password">Password</label><x-password-input id="password" name="password" autocomplete="new-password" minlength="12" maxlength="128" aria-describedby="password-help" required /><small id="password-help">Almeno 12 caratteri, con lettere e numeri.</small></div>
        <div class="amd-field"><label for="password_confirmation">Conferma password</label><x-password-input id="password_confirmation" name="password_confirmation" autocomplete="new-password" minlength="12" maxlength="128" required /></div>
        <p><small>I dati servono a gestire il tuo account e le tue richieste. <a href="{{ config('public_cars.privacy_url') ?: route('public-site.privacy') }}" target="_blank" rel="noopener noreferrer">Leggi l’informativa privacy (nuova scheda)</a>.</small></p>
        <button class="amd-button">Crea account e verifica email</button>
    </form>
</div>
@endsection

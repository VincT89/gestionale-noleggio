@extends('layouts.public-cars')
@section('title', 'Il mio profilo')
@section('content')
@include('public-account.navigation')<div class="amd-page-heading"><h1>Il mio profilo</h1><p>Aggiorna i recapiti del tuo account. Per modificare una prenotazione o un contratto già emesso, contatta l’assistenza indicando il riferimento.</p></div>@include('public-account.feedback')
<div class="amd-account-grid amd-account-profile">
<form class="amd-account-form" method="post" action="{{ route('public-account.profile.update') }}">@csrf @method('PUT')<h2>Dati personali</h2>@include('public-account.contact-fields')
<div class="amd-field"><label for="profile_current_password">Password attuale, se cambi email</label><x-password-input id="profile_current_password" name="current_password" autocomplete="current-password" /><small>Il nuovo indirizzo dovrà essere verificato. Le prenotazioni già collegate resteranno nel tuo account.</small></div><button class="amd-button">Salva i dati</button></form>
<form class="amd-account-form" method="post" action="{{ route('public-account.password.change') }}">@csrf @method('PUT')<h2>Cambia password</h2>
<div class="amd-field"><label for="current_password">Password attuale</label><x-password-input id="current_password" name="current_password" autocomplete="current-password" required /></div>
<div class="amd-field"><label for="password">Nuova password</label><x-password-input id="password" name="password" autocomplete="new-password" minlength="12" maxlength="128" aria-describedby="password-help" required /><small id="password-help">Almeno 12 caratteri, con lettere e numeri.</small></div>
<div class="amd-field"><label for="password_confirmation">Conferma nuova password</label><x-password-input id="password_confirmation" name="password_confirmation" autocomplete="new-password" minlength="12" maxlength="128" required /></div><button class="amd-button">Aggiorna password</button></form>
</div>
@endsection

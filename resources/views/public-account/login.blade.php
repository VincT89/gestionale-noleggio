@extends('layouts.public-cars')
@section('title', 'Accedi alla tua area cliente')
@section('content')
<div class="amd-account-auth">
    <div class="amd-account-intro"><h1>Il tuo viaggio, sempre a portata di mano.</h1><p>Accedi per ritrovare le prenotazioni AMD Rent, controllare i pagamenti e seguire le richieste di consegna o di lungo termine.</p><p>Hai prenotato senza account? Registrati con la stessa email usata nella prenotazione: dopo la verifica la ritroverai qui.</p><a href="{{ route('public-account.register') }}">Crea il tuo account</a></div>
    <form class="amd-account-form" method="post" action="{{ route('public-account.login.store') }}">@csrf<h2>Accedi</h2>@include('public-account.feedback')
        <div class="amd-field"><label for="email">Email</label><input id="email" type="email" name="email" value="{{ is_string(old('email')) ? old('email') : '' }}" autocomplete="username" maxlength="191" required></div>
        <div class="amd-field"><label for="password">Password</label><x-password-input id="password" name="password" autocomplete="current-password" required /></div>
        <a href="{{ route('public-account.password.request') }}">Hai dimenticato la password?</a><button class="amd-button">Accedi alla tua area</button>
        <p><small>Puoi continuare a <a href="{{ route('public-cars.index') }}">cercare e prenotare un’auto</a> anche senza account.</small></p>
    </form>
</div>
@endsection

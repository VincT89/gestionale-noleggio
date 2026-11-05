@extends('layouts.public-cars')
@section('title', 'Recupera la password')
@section('content')
<div class="amd-account-auth"><div class="amd-account-intro"><h1>Ritrova il tuo accesso.</h1><p>Inserisci l’email dell’account AMD Rent. Riceverai un collegamento per scegliere una nuova password, valido per 60 minuti.</p><a href="{{ route('public-account.login') }}">Torna all’accesso</a></div>
<form class="amd-account-form" method="post" action="{{ route('public-account.password.email') }}">@csrf<h2>Password dimenticata</h2>@include('public-account.feedback')<div class="amd-field"><label for="email">Email</label><input id="email" name="email" type="email" autocomplete="email" value="{{ is_string(old('email')) ? old('email') : '' }}" maxlength="191" required></div><button class="amd-button">Invia il collegamento</button></form></div>
@endsection

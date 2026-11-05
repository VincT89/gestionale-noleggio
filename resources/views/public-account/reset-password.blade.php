@extends('layouts.public-cars')
@section('title', 'Scegli una nuova password')
@section('content')
<div class="amd-account-auth"><div class="amd-account-intro"><h1>Una nuova password per il tuo account.</h1><p>Scegli una password di almeno 12 caratteri con lettere e numeri. Al termine potrai accedere di nuovo alla tua area cliente.</p><a href="{{ route('public-account.password.request') }}">Richiedi un nuovo collegamento</a></div>
<form class="amd-account-form" method="post" action="{{ route('public-account.password.update') }}">@csrf<input type="hidden" name="token" value="{{ $token }}"><h2>Reimposta password</h2>@include('public-account.feedback')
<div class="amd-field"><label for="email">Email dell’account</label><input id="email" name="email" type="email" value="{{ $email }}" autocomplete="username" maxlength="191" required readonly></div>
<div class="amd-field"><label for="password">Nuova password</label><x-password-input id="password" name="password" autocomplete="new-password" minlength="12" maxlength="128" required /></div>
<div class="amd-field"><label for="password_confirmation">Conferma nuova password</label><x-password-input id="password_confirmation" name="password_confirmation" autocomplete="new-password" minlength="12" maxlength="128" required /></div><button class="amd-button">Salva nuova password</button></form></div>
@endsection

@extends('layouts.public-cars')
@section('title', 'Verifica la tua email')
@section('content')
@include('public-account.navigation')
<div class="amd-account-auth"><div class="amd-account-intro"><h1>Conferma che sei tu.</h1><p>Apri il collegamento inviato a <strong>{{ $customer->email }}</strong>. Le prenotazioni e i documenti saranno visibili solo dopo la verifica dell’indirizzo.</p><p>Usa questo browser oppure accedi con lo stesso account quando apri il collegamento. L’email di verifica è valida per 60 minuti.</p></div><div class="amd-account-form">@include('public-account.feedback')<h2>Non trovi l’email?</h2><p>Controlla la posta indesiderata oppure richiedi un nuovo invio.</p><form method="post" action="{{ route('public-account.verification.send') }}">@csrf<button class="amd-button">Invia di nuovo</button></form><a href="{{ route('public-account.profile') }}">Correggi il tuo indirizzo email</a></div></div>
@endsection

@if(!auth('public_customer')->check())
<section class="amd-account-callout"><h2>Ritrova tutto nella tua area cliente.</h2><p>Crea un account con l’email usata per questa richiesta o prenotazione. Dopo la verifica potrai consultare lo stato e i documenti disponibili.</p><a href="{{ route('public-account.register') }}">Crea il tuo account</a> · <a href="{{ route('public-account.login') }}">Accedi</a></section>
@elseif(!auth('public_customer')->user()->hasVerifiedEmail())
<p class="amd-account-notice"><a href="{{ route('public-account.verification.notice') }}">Verifica la tua email</a> per ritrovare le prenotazioni e le richieste nella tua area.</p>
@endif

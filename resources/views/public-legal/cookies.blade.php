@extends('public-legal.layout')
@section('title', 'Informativa cookie')
@section('description', 'I cookie tecnici utilizzati da AMD Rent, le loro durate e le informazioni su mappe, pagamenti e gestione del browser.')
@section('legal-title', 'Cookie, con chiarezza')
@section('legal-intro', 'Gli strumenti che fanno funzionare il sito e le informazioni per gestirli dal tuo browser.')
@section('legal-index')
<ul>
    <li><a href="#cookie-utilizzati">Cosa usa il sito</a></li>
    <li><a href="#durate">Nomi e durata</a></li>
    <li><a href="#mappe-pagamenti">Mappe e pagamenti</a></li>
    <li><a href="#gestione">Gestire il browser</a></li>
    <li><a href="#contatti-cookie">Titolare e contatti</a></li>
</ul>
@endsection
@section('legal-copy')
<section aria-labelledby="cookie-utilizzati">
    <h2 id="cookie-utilizzati">Solo ciò che serve al servizio</h2>
    <p>I cookie sono piccoli dati conservati dal browser. AMD Rent usa cookie tecnici per mantenere la sessione, proteggere i moduli e permettere l’accesso all’area cliente. Non sono integrati cookie pubblicitari, strumenti di profilazione o di analisi delle visite.</p>
    <p>Per questi cookie tecnici non è richiesto il consenso. L’avviso presente sul sito è informativo: il pulsante “Ho capito” lo chiude e non autorizza marketing o nuovi tracciamenti.</p>
</section>
<section aria-labelledby="durate">
    <h2 id="durate">Cosa viene conservato nel browser</h2>
    <div class="amd-cookie-entry">
        <h3>Sessione del sito</h3>
        <p>Mantiene la continuità della navigazione, le selezioni temporanee e l’accesso all’area cliente. È un cookie tecnico di prima parte.</p>
        <dl class="amd-legal-facts">
            <div><dt>Nome</dt><dd><code>{{ config('session.cookie') }}</code></dd></div>
            <div><dt>Durata</dt><dd>@if(config('session.expire_on_close')) Fino alla chiusura del browser. @else {{ config('session.lifetime') }} minuti, rinnovati durante l’uso del sito. @endif</dd></div>
        </dl>
    </div>
    <div class="amd-cookie-entry">
        <h3>Protezione dei moduli</h3>
        <p>Aiuta a verificare che l’invio di un modulo provenga dalla sessione corretta. È un cookie tecnico di prima parte.</p>
        <dl class="amd-legal-facts">
            <div><dt>Nome</dt><dd><code>XSRF-TOKEN</code></dd></div>
            <div><dt>Durata</dt><dd>{{ config('session.lifetime') }} minuti, rinnovati durante l’uso del sito.</dd></div>
        </dl>
    </div>
    <div class="amd-cookie-entry">
        <h3>Chiusura dell’avviso</h3>
        <p>Quando chiudi l’avviso, salviamo solo la versione letta e la data di chiusura nella memoria locale del browser. Non è un identificativo pubblicitario e non viene trasmesso al server.</p>
        <dl class="amd-legal-facts">
            <div><dt>Nome e tipo</dt><dd><code>amd-rent.cookie-notice</code> — memoria locale (localStorage).</dd></div>
            <div><dt>Durata</dt><dd>La preferenza viene utilizzata per {{ $privacy['notice_days'] }} giorni. Alla visita successiva alla scadenza viene rimossa. Se cancelli i dati del sito o cambia l’informativa, l’avviso può comparire di nuovo.</dd></div>
        </dl>
    </div>
    <p>Il browser può conservare nella propria cache immagini e altri file per velocizzare il caricamento. Questo non crea un profilo pubblicitario.</p>
</section>
<section aria-labelledby="mappe-pagamenti">
    <h2 id="mappe-pagamenti">I servizi che usi durante la ricerca</h2>
    <h3>Mappe OpenStreetMap</h3>
    <p>Le mappe vengono caricate quando visualizzi la funzione di scelta del luogo. Il browser si collega ai server delle immagini: OpenStreetMap riceve l’indirizzo IP, l’area della mappa richiesta e le informazioni tecniche della connessione. La ricerca del nome del luogo e dell’indirizzo avviene tramite il nostro server e Nominatim.</p>
    <p>L’integrazione presente nel sito non carica strumenti pubblicitari di OpenStreetMap. Consulta la <a href="https://osmfoundation.org/wiki/Privacy_Policy" target="_blank" rel="noopener noreferrer">privacy di OpenStreetMap Foundation</a> e la sezione <a href="{{ route('public-site.privacy') }}#servizi-esterni">mappe e pagamenti</a> per sapere quali dati sono trattati.</p>
    <h3>Pagina di pagamento Stripe</h3>
    <p>Se avvii un pagamento online disponibile, si apre il servizio Stripe, che gestisce i propri cookie e strumenti di sicurezza. Questi strumenti non vengono caricati nella normale navigazione AMD Rent. Le condizioni sono descritte nella <a href="https://stripe.com/it/privacy" target="_blank" rel="noopener noreferrer">privacy di Stripe</a> e nella sua <a href="https://stripe.com/it/legal/cookies-policy" target="_blank" rel="noopener noreferrer">informativa cookie</a>.</p>
</section>
<section aria-labelledby="gestione">
    <h2 id="gestione">Gestire i dati del browser</h2>
    <p>Puoi eliminare cookie e memoria locale dalle impostazioni di privacy del browser, nella sezione dedicata ai dati dei siti. Se blocchi i cookie tecnici, l’accesso all’account e l’invio delle richieste potrebbero non funzionare. Eliminare i dati del browser non cancella prenotazioni o documenti conservati dal servizio.</p>
    <p>L’avviso compare automaticamente alla prima visita. Dopo averlo chiuso, può comparire di nuovo alla scadenza della preferenza, se cambia l’informativa o se elimini i dati del sito. Non sono presenti categorie facoltative da attivare o disattivare.</p>
</section>
<section aria-labelledby="contatti-cookie">
    <h2 id="contatti-cookie">Titolare e contatti</h2>
    @include('public-legal.partials.controller')
    <p>Le informazioni sui tuoi diritti, sui destinatari e sui tempi di conservazione dei dati del servizio si trovano nell’<a href="{{ config('public_cars.privacy_url') ?: route('public-site.privacy') }}">informativa privacy</a>.</p>
</section>
@endsection

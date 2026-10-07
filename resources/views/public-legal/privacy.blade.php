@extends('public-legal.layout')
@section('title', 'Informativa privacy')
@section('description', 'Come AMD Rent tratta i dati della ricerca, dell’account e delle richieste di noleggio. Informazioni su servizi esterni, conservazione e diritti.')
@section('legal-title', 'La tua privacy')
@section('legal-intro', 'Quali dati usiamo, perché ci servono e come puoi esercitare i tuoi diritti.')
@section('legal-index')
<ul>
    <li><a href="#titolare">Chi tratta i dati</a></li>
    <li><a href="#dati">Dati e finalità</a></li>
    <li><a href="#destinatari">Con chi li condividiamo</a></li>
    <li><a href="#servizi-esterni">Mappe e pagamenti</a></li>
    <li><a href="#conservazione">Tempi di conservazione</a></li>
    <li><a href="#diritti">I tuoi diritti</a></li>
</ul>
@endsection
@section('legal-copy')
<section aria-labelledby="titolare">
    <h2 id="titolare">Chi tratta i tuoi dati</h2>
    <p>Questa informativa riguarda il sito pubblico AMD Rent e la sua area cliente. Spiega il trattamento dei dati personali ai sensi dell’articolo 13 del Regolamento (UE) 2016/679 (GDPR).</p>
    @include('public-legal.partials.controller')
</section>
<section aria-labelledby="dati">
    <h2 id="dati">I dati che servono al tuo noleggio</h2>
    <h3>Ricerca dell’auto e dei luoghi</h3>
    <p>Usiamo date, orari, luoghi di ritiro e riconsegna, filtri e, se scegli un punto sulla mappa, le sue coordinate. Servono a mostrarti le proposte e verificare la zona richiesta. Non rileviamo automaticamente la posizione del tuo dispositivo.</p>
    <p>Il trattamento è necessario per rispondere alla tua richiesta prima di un eventuale contratto (art. 6, par. 1, lett. b GDPR).</p>
    <h3>Account e richieste</h3>
    <p>Per l’area cliente raccogliamo nome, cognome, email, password e il telefono, se fornito. Per prenotazioni, consegne e preventivi usiamo anche i recapiti, i dettagli del viaggio, le preferenze di noleggio, gli eventuali dati aziendali e le note che inserisci. La password viene conservata in forma non leggibile.</p>
    <p>Questi dati permettono di gestire l’accesso, verificare l’email, rispondere alle richieste, preparare proposte e seguire le prenotazioni. La base giuridica è l’esecuzione del servizio richiesto e delle misure precontrattuali (art. 6, par. 1, lett. b GDPR). Le comunicazioni relative alla pratica non sono newsletter.</p>
    <h3>Documenti e pagamenti</h3>
    <p>I documenti che carichi nell’area cliente sono utilizzati per gestire la pratica e le verifiche richieste per il noleggio. Carica solo ciò che ti viene richiesto. Per i pagamenti conserviamo importi, riferimenti, stato ed eventuali rimborsi; i dati completi della carta vengono inseriti sul servizio di pagamento.</p>
    <p>Le basi giuridiche sono l’esecuzione del contratto e, quando applicabili, gli obblighi di legge, anche contabili e fiscali (art. 6, par. 1, lett. b e c GDPR).</p>
    <h3>Navigazione e sicurezza</h3>
    <p>Per far funzionare il sito, mantenere la sessione e limitare abusi e accessi non autorizzati trattiamo informazioni tecniche, come indirizzo IP, richieste al server ed eventi di accesso. La sicurezza del servizio costituisce un legittimo interesse del titolare (art. 6, par. 1, lett. f GDPR).</p>
    <p>La <a href="{{ route('public-site.cookies') }}">pagina cookie</a> descrive gli strumenti tecnici usati dal sito. Il sito non integra strumenti di profilazione pubblicitaria o di analisi delle visite. I filtri servono a confrontare le proposte e non a prendere decisioni automatizzate con effetti giuridici sulla persona.</p>
    <h3>Quali informazioni sono necessarie</h3>
    <p>I campi obbligatori sono necessari per la funzione richiesta: senza di essi potremmo non poter creare l’account, gestire il preventivo o completare la prenotazione. I campi facoltativi possono essere lasciati vuoti. Non occorre un consenso al marketing per usare il sito.</p>
</section>
<section aria-labelledby="destinatari">
    <h2 id="destinatari">Con chi condividiamo i dati</h2>
    <p>Le persone autorizzate da AMD Rent e il noleggiatore coinvolto nella richiesta possono accedere ai dati necessari per seguirla. I documenti caricati nella pratica sono accessibili ad AMD Rent e al noleggiatore assegnato. Le informazioni possono inoltre essere comunicate a consulenti e autorità quando necessario per gli adempimenti applicabili o la tutela dei diritti.</p>
    <dl class="amd-legal-facts">
        <div><dt>Ruoli di AMD Rent e dei noleggiatori</dt><dd>{{ $privacy['supplier_roles'] ?: 'Da completare: ruoli privacy e responsabilità dei soggetti coinvolti.' }}</dd></div>
        <div><dt>Hosting e infrastruttura</dt><dd>{{ $privacy['hosting'] ?: 'Da completare: fornitore, localizzazione dei dati e ruolo nel trattamento.' }}</dd></div>
        <div><dt>Invio delle email di servizio</dt><dd>{{ $privacy['email_provider'] ?: 'Da completare: fornitore e localizzazione dei dati.' }}</dd></div>
    </dl>
    <p>L’accesso ai dati deve restare limitato alle finalità della richiesta e agli obblighi applicabili. Gli eventuali fornitori che operano per conto del titolare devono essere disciplinati secondo l’art. 28 GDPR.</p>
</section>
<section aria-labelledby="servizi-esterni">
    <h2 id="servizi-esterni">Mappe e pagamenti</h2>
    <h3>OpenStreetMap e Nominatim</h3>
    <p>Quando cerchi un indirizzo, un hotel o un luogo, il nostro server invia a Nominatim il testo del luogo. Se scegli un punto sulla mappa, invia le coordinate per proporre l’indirizzo. Non invia nome, email, telefono o dettagli della prenotazione. Scrivi nel campo del luogo solo le informazioni necessarie a individuare l’indirizzo.</p>
    <p>Quando visualizzi la mappa, il browser scarica le immagini da OpenStreetMap: il fornitore riceve l’indirizzo IP e le informazioni tecniche della connessione, compresa l’area della mappa richiesta. Questi servizi sono usati per la ricerca e la scelta del luogo, non per pubblicità.</p>
    <p>OpenStreetMap Foundation descrive il trattamento dei dati e la propria rete di distribuzione nella sua <a href="https://osmfoundation.org/wiki/Privacy_Policy" target="_blank" rel="noopener noreferrer">informativa privacy</a>. Le immagini delle mappe possono essere distribuite da server in diversi Paesi.</p>
    <h3>Stripe</h3>
    <p>Quando il pagamento online è disponibile e lo avvii, vieni indirizzato alla pagina di Stripe. Per creare il pagamento trasmettiamo l’email, il riferimento della prenotazione e le informazioni sull’importo e sul noleggio. Il sito riceve gli identificativi e l’esito dell’operazione, senza conservare il numero completo della carta o il codice di sicurezza.</p>
    <p>Il trattamento effettuato da Stripe, inclusi i servizi antifrode, è descritto nella sua <a href="https://stripe.com/it/privacy" target="_blank" rel="noopener noreferrer">informativa privacy</a>. Aprire una normale pagina AMD Rent non carica script di Stripe.</p>
    <h3>Trasferimenti internazionali</h3>
    <p>{{ $privacy['international_transfers'] ?: 'Da completare prima della pubblicazione: Paesi di trattamento dei fornitori effettivamente scelti e garanzie applicabili agli eventuali trasferimenti fuori dallo Spazio economico europeo, con le modalità per ottenerne copia.' }}</p>
</section>
<section aria-labelledby="conservazione">
    <h2 id="conservazione">Per quanto tempo conserviamo i dati</h2>
    <p>I tempi devono dipendere dalla finalità, dagli obblighi applicabili e dall’eventuale necessità di gestire contestazioni. Le scadenze dei collegamenti di accesso non coincidono con la cancellazione dei dati della pratica.</p>
    <dl class="amd-legal-facts">
        <div><dt>Preventivi, richieste e corrispondenza</dt><dd>{{ $privacy['retention_enquiries'] ?: 'Da completare: periodo o criteri di conservazione delle richieste.' }}</dd></div>
        <div><dt>Account cliente</dt><dd>{{ $privacy['retention_accounts'] ?: 'Da completare: gestione degli account attivi, inattivi e delle richieste di cancellazione.' }}</dd></div>
        <div><dt>Prenotazioni e dati amministrativi</dt><dd>{{ $privacy['retention_bookings'] ?: 'Da completare: periodi e obblighi applicabili a prenotazioni, contratti e pagamenti.' }}</dd></div>
        <div><dt>Documenti caricati</dt><dd>{{ $privacy['retention_documents'] ?: 'Da completare: tempi, accessi e rimozione dei documenti e delle copie di sicurezza.' }}</dd></div>
        <div><dt>Registri tecnici e copie di sicurezza</dt><dd>{{ $privacy['retention_logs'] ?: 'Da completare: durata dei registri di sicurezza, dei log e dei backup.' }}</dd></div>
    </dl>
    <p>Per la ricerca dei luoghi, i risultati vengono riutilizzati per un massimo di 7 giorni; le ricerche senza risultati per 15 minuti. Il collegamento temporaneo a un punto selezionato è valido per 2 ore nella sessione. Il recupero della password scade dopo {{ config('auth.passwords.public_customers.expire', 60) }} minuti.</p>
</section>
<section aria-labelledby="diritti">
    <h2 id="diritti">I tuoi diritti</h2>
    <p>Nei casi previsti dal GDPR puoi chiedere accesso, rettifica, cancellazione e limitazione del trattamento, ricevere i dati in formato portabile e opporti ai trattamenti basati sul legittimo interesse. La cancellazione può incontrare limiti quando i dati devono essere conservati per un obbligo di legge o per la tutela di un diritto.</p>
    <p>Se un trattamento si basa sul consenso, puoi revocarlo in qualsiasi momento senza pregiudicare la liceità del trattamento precedente. La chiusura dell’avviso cookie non costituisce un consenso al marketing.</p>
    @if(filter_var($privacy['privacy_email'], FILTER_VALIDATE_EMAIL))
        <p>Per esercitare i diritti scrivi a <a href="mailto:{{ $privacy['privacy_email'] }}">{{ $privacy['privacy_email'] }}</a>. Il titolare può chiedere le informazioni necessarie a verificare l’identità e risponde nei termini previsti dall’art. 12 GDPR.</p>
    @else
        <p class="amd-legal-pending">Contatto per esercitare i diritti: da completare prima della pubblicazione.</p>
    @endif
    <p>Puoi presentare un reclamo al <a href="https://www.garanteprivacy.it/home/diritti/come-agire-per-tutelare-i-tuoi-dati-personali" target="_blank" rel="noopener noreferrer">Garante per la protezione dei dati personali</a> o all’autorità di controllo competente. La data in questa pagina indica l’ultimo aggiornamento dell’informativa.</p>
</section>
@endsection

@extends('layouts.public-cars')
@section('title', 'Come funziona')
@section('main-class', 'amd-info-page')
@section('content')
<div class="amd-info-heading"><div class="amd-section-inner amd-photo-heading">
    <div>
    <h1>Prenotare con AMD Rent</h1>
    <p>Dal luogo di ritiro alla conferma: tutte le informazioni per scegliere la tua auto e prepararti al viaggio.</p>
</div>
    @include('public-cars.partials.context-photo', ['photoScene' => 'pickup'])
</div></div>
<div class="amd-section-inner amd-info-layout">
<aside class="amd-info-nav"><nav aria-label="In questa pagina">
    <h2>La tua prenotazione</h2>
    <ul>
        <li><a href="#luoghi-e-disponibilita">Luoghi e disponibilità</a></li>
        <li><a href="#prezzi-e-condizioni">Prezzi e condizioni</a></li>
        <li><a href="#conferma-e-ritiro">Conferma e ritiro</a></li>
        <li><a href="{{ route('public-site.support') }}">Informazioni e assistenza</a></li>
    </ul>
</nav></aside>
<article class="amd-information">
    <h2 id="luoghi-e-disponibilita">Luoghi serviti e auto disponibili</h2>
    <p>Scegli il luogo, le date e gli orari. Per il ritiro personalizzato, attiva l’opzione nella ricerca e inserisci un indirizzo oppure il nome dell’hotel o del B&B con il comune. Dopo aver scelto l’auto, scegli un punto di riconsegna servito oppure richiedi un altro luogo, per esempio un aeroporto.</p>
    <p>Vuoi ricevere l’auto in hotel? Conferma l’indirizzo e scopri i noleggiatori che offrono la consegna nella zona, a partire dai più vicini. Confronta auto e prezzi, poi richiedi la consegna. Se la struttura non compare, prova il suo indirizzo completo.</p>
    <p>Puoi confrontare le auto e filtrare i risultati per noleggiatore e caratteristiche dell’auto.</p>
    <p>Budget, posti e tipo di cambio: scegli quello che conta per il tuo viaggio. In ogni scheda trovi chi ti consegnerà l’auto, il prezzo e le condizioni dell’offerta.</p>
    <p>Controlla separatamente il luogo di ritiro e quello di riconsegna nel riepilogo. Date e orari sono riferiti all’Italia.</p>
    <h2 id="prezzi-e-condizioni">Importi e condizioni prima di prenotare</h2>
    <p>Ogni scheda riporta il totale del noleggio, IVA inclusa, la cauzione separata e il chilometraggio incluso. Se previsti, trovi anche il costo dei chilometri extra e le condizioni indicate dal noleggiatore.</p>
    <h2 id="conferma-e-ritiro">Il 20% online, il resto al ritiro</h2>
    <p>Prenoti senza creare un account: inserisci i recapiti e controlla il riepilogo. Verifichiamo nuovamente prezzo e disponibilità prima del pagamento online, gestito da Stripe. Il pagamento riuscito conferma la prenotazione.</p>
    <p>Conserva il riferimento e scarica il PDF della prenotazione. Versi ad AMD Rent il 20% del noleggio online e paghi il restante 80% al noleggiatore al ritiro; il noleggiatore verifica i documenti del conducente e completa il contratto prima della consegna.</p>
    <h2 id="consegna-personalizzata">Consegna in hotel o a un indirizzo</h2><p>Il servizio dipende dal noleggiatore e dalla zona. Puoi indicare l’indirizzo già nella ricerca e ritrovarlo nel modulo di richiesta. La richiesta non impegna l’auto: il noleggiatore deve confermare la consegna e il supplemento.</p><p>Prima di pagare, vedrai separatamente noleggio, consegna, quota online e saldo al ritiro. Ritiro e riconsegna restano distinti. Se richiedi una riconsegna personalizzata, il noleggiatore deve confermare entrambi i luoghi e il supplemento complessivo. Un prezzo per la consegna non ancora concordato non viene incluso nel confronto iniziale delle auto.</p>
    <h2>Lungo termine</h2><p>Con il <a href="{{ route('public-site.long-term') }}">modulo dedicato</a> chiedi un preventivo per un privato o un’azienda. Seguiamo richiesta, proposte delle società e documenti. Il contratto viene concluso in presenza; la richiesta non comporta un pagamento online.</p>
    <p><a class="amd-button" href="{{ route('public-cars.index') }}">Cerca un’auto</a></p>
</article>
</div>
@endsection

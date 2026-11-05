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
    <p>Vuoi ricevere l’auto in hotel? Conferma il punto di ritiro e scopri le auto che possono raggiungerti. Per ogni tipo di veicolo mostriamo la proposta disponibile al prezzo più basso per le tue date.</p>
    <p>Filtra per tipo di veicolo, budget, numero di posti e cambio. Scegli l’auto, poi indica dove riconsegnarla: può essere un aeroporto o un altro indirizzo nella zona servita.</p>
    <p>Controlla separatamente il luogo di ritiro e quello di riconsegna nel riepilogo. Date e orari sono riferiti all’Italia.</p>
    <h2 id="prezzi-e-condizioni">Importi e condizioni prima di prenotare</h2>
    <p>Ogni scheda riporta il totale del noleggio, IVA inclusa, la cauzione separata e il chilometraggio incluso. Se previsti, trovi anche il costo dei chilometri extra e le condizioni indicate dal noleggiatore.</p>
    @if(\App\Services\AmdRent\CustomerDeposit::currentBps() === 0)
    <h2 id="conferma-e-ritiro">Prenoti e paghi al ritiro</h2>
    <p>Inserisci i recapiti e controlla il riepilogo. Per la consegna personalizzata attendi la conferma del noleggiatore prima di prenotare. Non sono previsti addebiti online.</p>
    <p>Conserva il riferimento e scarica il PDF della prenotazione. Il noleggiatore verifica i documenti del conducente e completa il contratto prima della consegna. Paghi il totale al ritiro; la cauzione rimane separata.</p>
    @else
    <h2 id="conferma-e-ritiro">Il {{ \App\Services\AmdRent\CustomerDeposit::label() }} online, il resto al ritiro</h2>
    <p>Prenoti senza creare un account: inserisci i recapiti e controlla il riepilogo. Verifichiamo nuovamente prezzo e disponibilità prima del pagamento online, gestito da Stripe. Il pagamento riuscito conferma la prenotazione.</p>
    <p>Conserva il riferimento e scarica il PDF della prenotazione. Versi ad AMD Rent il {{ \App\Services\AmdRent\CustomerDeposit::label() }} del totale online e paghi il saldo al noleggiatore al ritiro; il noleggiatore verifica i documenti del conducente e completa il contratto prima della consegna.</p>
    @endif
    <h2 id="consegna-personalizzata">Consegna e ritiro dove ti servono</h2>
    <p>Il prezzo comprende noleggio, consegna e ritiro: trovi un unico totale e la tariffa media giornaliera. I giorni sono periodi di 24 ore; una frazione aggiuntiva conta come un giorno. La cauzione resta separata.</p>
    <p>Prima invii la richiesta completa con auto, date, luoghi e recapiti. Il noleggiatore verifica la disponibilità e conferma gli appuntamenti. Dopo la conferma controlli il riepilogo e procedi al pagamento. Se la proposta non è disponibile, puoi scegliere un’altra auto dello stesso tipo.</p>
    <h2>Lungo termine</h2><p>Con il <a href="{{ route('public-site.long-term') }}">modulo dedicato</a> chiedi un preventivo per un privato o un’azienda. Seguiamo richiesta, proposte delle società e documenti. Il contratto viene concluso in presenza; la richiesta non comporta un pagamento online.</p>
    <p><a class="amd-button" href="{{ route('public-cars.index') }}">Cerca un’auto</a></p>
</article>
</div>
@endsection

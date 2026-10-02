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
    <p>Scegli il luogo, le date e gli orari. Se vuoi ritirare l’auto in hotel o a un indirizzo personalizzato, attiva l’opzione nella ricerca e indica dove riconsegnarla, per esempio un aeroporto servito. Nei risultati trovi le auto disponibili per il periodo e i noleggiatori che accettano quel tipo di richiesta.</p>
    <p>Puoi confrontare le auto e filtrare i risultati per noleggiatore e caratteristiche dell’auto.</p>
    <p>Quando più auto appartengono allo stesso gruppo, nei risultati compare quella disponibile con il totale più basso, in base ai filtri scelti. Il noleggiatore e le condizioni mostrati sono quelli dell’auto selezionata.</p>
    <p>Controlla separatamente il luogo di ritiro e quello di riconsegna nel riepilogo. Date e orari sono riferiti all’Italia.</p>
    <h2 id="prezzi-e-condizioni">Importi e condizioni prima di prenotare</h2>
    <p>Ogni scheda riporta il totale del noleggio, IVA inclusa, la cauzione separata e il chilometraggio incluso. Se previsti, trovi anche il costo dei chilometri extra e le condizioni indicate dal noleggiatore.</p>
    <h2 id="conferma-e-ritiro">Il 20% online, il resto al ritiro</h2>
    <p>Inserisci i tuoi recapiti e conferma il riepilogo. Non serve creare un account. Prima del pagamento, disponibilità e prezzo vengono ricontrollati e l’auto viene riservata temporaneamente. La prenotazione è confermata quando Stripe comunica il pagamento riuscito.</p>
    <p>Conserva il riferimento e scarica il PDF della prenotazione. Versi ad AMD Rent il 20% del noleggio online e paghi il restante 80% al noleggiatore al ritiro; il noleggiatore verifica i documenti del conducente e completa il contratto prima della consegna.</p>
    <h2 id="consegna-personalizzata">Consegna in hotel o a un indirizzo</h2><p>Il servizio dipende dal noleggiatore e dalla zona. Puoi indicare l’indirizzo già nella ricerca e ritrovarlo nel modulo di richiesta. La richiesta non impegna l’auto: il noleggiatore deve confermare la consegna e il supplemento.</p><p>Prima di pagare, vedrai separatamente noleggio, consegna, quota online e saldo al ritiro. Il luogo di riconsegna scelto nella ricerca è distinto dall’indirizzo di ritiro personalizzato. Un prezzo per la consegna non ancora concordato non viene incluso nel confronto iniziale delle auto.</p>
    <h2>Lungo termine</h2><p>Con il <a href="{{ route('public-site.long-term') }}">modulo dedicato</a> chiedi un preventivo per un privato o un’azienda. Seguiamo richiesta, proposte delle società e documenti. Il contratto viene concluso in presenza; la richiesta non comporta un pagamento online.</p>
    <p><a class="amd-button" href="{{ route('public-cars.index') }}">Cerca un’auto</a></p>
</article>
</div>
@endsection

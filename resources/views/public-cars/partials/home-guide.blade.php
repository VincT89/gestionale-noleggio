<svg class="amd-home-route" data-home-route aria-hidden="true" focusable="false" fill="none" preserveAspectRatio="none">
    <path class="amd-home-route-bed" data-route-path></path>
    <path class="amd-home-route-line" data-route-path pathLength="1"></path>
</svg>
<section id="luoghi-di-ritiro" class="amd-home-pickup" aria-labelledby="pickup-heading" data-pickup-options>
    <div class="amd-section-inner amd-pickup-layout">
        <div class="amd-pickup-copy">
            <h2 id="pickup-heading">Il punto di partenza<br>lo scegli tu.</h2>
            <p class="amd-pickup-lead">Hai già un programma? Trova il ritiro che si adatta al tuo viaggio.</p>
            <div class="amd-pickup-tabs" data-pickup-tabs aria-label="Come vuoi ritirare l’auto" hidden>
                <button type="button" id="pickup-point-tab" data-pickup-tab="pickup-point">Punto di ritiro</button>
                <button type="button" id="pickup-address-tab" data-pickup-tab="pickup-address">Hotel o indirizzo</button>
            </div>
            <div class="amd-pickup-panel" id="pickup-point">
                <h3>Scendi. Ritira. Parti.</h3>
                <p>Cerca tra gli aeroporti, le stazioni e i punti serviti. Confronta le auto disponibili nelle tue date e scegli quella per te.</p>
                <a class="amd-button" href="#car-search" data-search-standard>Scegli il punto di ritiro</a>
            </div>
            <div class="amd-pickup-panel" id="pickup-address">
                <h3>Il tuo viaggio, dal tuo indirizzo.</h3>
                <p>Scrivi l’hotel, il B&B o un indirizzo. Scopri i noleggiatori vicini che offrono la consegna, guarda auto e prezzi e invia la richiesta.</p>
                <a class="amd-button" href="#car-search" data-search-hotel>Cerca dal tuo indirizzo</a>
                <small>Consegna e supplemento vengono confermati prima del pagamento. Puoi scegliere un altro punto per la riconsegna.</small>
            </div>
        </div>
        <div class="amd-pickup-gallery">
        <div class="amd-pickup-photos" aria-hidden="true">
            <figure class="amd-places-photo" data-home-photo>
                <img src="{{ asset('images/amd-rent-airport-pickup-v1-640.webp') }}" srcset="{{ asset('images/amd-rent-airport-pickup-v1-640.webp') }} 640w, {{ asset('images/amd-rent-airport-pickup-v1-1200.webp') }} 1200w" sizes="(max-width: 800px) calc(100vw - 40px), 55vw" alt="" width="1536" height="1024" loading="lazy" decoding="async">
            </figure>
            <figure class="amd-delivery-photo" data-home-photo>
                <img src="{{ asset('images/amd-rent-hotel-delivery-640.webp') }}" srcset="{{ asset('images/amd-rent-hotel-delivery-640.webp') }} 640w, {{ asset('images/amd-rent-hotel-delivery-1200.webp') }} 1200w" sizes="(max-width: 800px) calc(100vw - 40px), 55vw" alt="" width="1200" height="800" loading="lazy" decoding="async">
            </figure>
        </div>
        <p class="amd-photo-note">Immagini illustrative dei servizi di ritiro e consegna.</p>
        </div>
    </div>
</section>
<section class="amd-home-long-term" aria-labelledby="long-term-heading">
    <div class="amd-section-inner amd-home-long-term-layout">
        <figure class="amd-long-term-photo" data-home-photo><img src="{{ asset('images/amd-rent-rental-planning-640.webp') }}" srcset="{{ asset('images/amd-rent-rental-planning-640.webp') }} 640w, {{ asset('images/amd-rent-rental-planning-1200.webp') }} 1200w" sizes="(max-width: 700px) 100vw, 45vw" alt="Chiavi dell’auto, un taccuino e un telefono su una scrivania" width="1200" height="800" loading="lazy" decoding="async"></figure>
        <div class="amd-long-term-copy">
            <h2 id="long-term-heading">I tuoi programmi<br>vanno più lontano?</h2>
            <p>Per un’auto da tenere più a lungo, raccontaci cosa cerchi. Ricevi una proposta da valutare in base alle tue esigenze.</p>
            <a class="amd-button" href="{{ route('public-site.long-term') }}">Parliamo del tuo noleggio</a>
        </div>
    </div>
</section>
<section id="domande-frequenti" class="amd-home-answers" aria-labelledby="answers-heading">
    <div class="amd-section-inner amd-answers-layout">
        <div><h2 id="answers-heading">Parti con<br>le idee chiare.</h2><p>Le risposte utili, prima di scegliere.</p><a class="amd-text-link" href="{{ route('public-site.how-it-works') }}">Come funziona la prenotazione</a></div>
        <div class="amd-home-faq">
            <details><summary>Quanto pago per prenotare?</summary><div><p>Per il noleggio breve versi il 20% online con Stripe. Il saldo si paga al ritiro. La prenotazione viene confermata dopo la verifica del pagamento; la cauzione è separata.</p></div></details>
            <details><summary>Posso ricevere l’auto in hotel?</summary><div><p>Sì, dove il servizio è offerto. Cerca l’hotel o un indirizzo, scegli tra i noleggiatori disponibili e invia la richiesta per l’auto che preferisci. Aspetta la conferma della consegna e dell’eventuale supplemento prima di pagare.</p></div></details>
            <details><summary>Posso riconsegnarla in un altro luogo?</summary><div><p>Con il ritiro personalizzato puoi scegliere un punto di riconsegna tra quelli serviti dal noleggiatore. Il luogo scelto sarà nel riepilogo della tua richiesta.</p></div></details>
            <details><summary>Il prezzo comprende tutto?</summary><div><p>Il totale del noleggio è per l’intero periodo e include l’IVA. Cauzione, chilometri inclusi ed eventuali costi per chilometri extra sono indicati nella proposta. Per il ritiro personalizzato, l’eventuale supplemento arriva con la conferma del noleggiatore.</p></div></details>
            <details><summary>Devo creare un account?</summary><div><p>No. Puoi cercare, inviare la richiesta di consegna o prenotare dal sito pubblico senza registrarti.</p></div></details>
        </div>
    </div>
</section>

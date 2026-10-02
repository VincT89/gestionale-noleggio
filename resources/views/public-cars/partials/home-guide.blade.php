<svg class="amd-home-route" data-home-route aria-hidden="true" focusable="false" fill="none">
    <defs><clipPath id="amd-home-coast-clip"><rect data-route-coast-clip /></clipPath></defs>
    <path class="amd-home-route-coast" data-route-coast clip-path="url(#amd-home-coast-clip)" />
    <path class="amd-home-route-bed" data-route-path />
    <path class="amd-home-route-line" data-route-path pathLength="1" />
</svg>

<section id="luoghi-di-ritiro" class="amd-home-places" aria-labelledby="places-intro-title">
    <div class="amd-section-inner amd-places-layout">
        <figure class="amd-places-photo" data-home-reveal="photo" data-home-photo>
            <img src="{{ asset('images/amd-rent-coastal-background-640.webp') }}"
                 srcset="{{ asset('images/amd-rent-coastal-background-640.webp') }} 640w, {{ asset('images/amd-rent-coastal-background-1536.webp') }} 1536w"
                 sizes="(max-width: 600px) calc(100vw - 40px), (max-width: 900px) calc(100vw - 48px), 576px"
                 width="1536" height="1024" loading="lazy" decoding="async"
                 alt="Una strada che segue la costa, tra il mare e la vegetazione mediterranea">
        </figure>
        <div class="amd-places-copy" data-home-reveal>
            <h2 id="places-intro-title">Da dove vuoi partire?</h2>
            <p class="amd-places-lead">Un aeroporto, una stazione o una città. Parti dalla tua destinazione e trova l’auto per il viaggio che stai organizzando.</p>
            <p>Indica luogo, date e orari per confrontare i veicoli disponibili. Nella scheda di ogni auto trovi il prezzo totale, i chilometri inclusi, la cauzione e le condizioni di ritiro.</p>
            <p>Quando hai scelto, prenoti versando il 20% online e paghi il resto al ritiro. Tutti gli importi sono riepilogati prima del pagamento.</p>
            <a class="amd-button" href="#car-search">Trova la tua auto</a>
        </div>
    </div>
</section>

<section class="amd-rental-options" aria-labelledby="rental-options-title">
    <div class="amd-section-inner amd-rental-options-inner">
        <div data-home-reveal>
            <h2 id="rental-options-title">Un’auto per qualche giorno.<br>O per un progetto più lungo.</h2>
            <p>Per il tuo viaggio scegli tra le auto disponibili nelle date che ti servono. Se cerchi una soluzione a lungo termine, parti dalle tue esigenze: durata, chilometri e tipo di auto.</p>
            <a class="amd-button" href="{{ route('public-site.long-term') }}">Scopri il lungo termine</a>
        </div>
        <div class="amd-delivery-feature" data-home-reveal>
            <figure class="amd-delivery-photo" data-home-reveal="photo" data-home-photo>
                <img src="{{ asset('images/amd-rent-hotel-delivery-640.webp') }}"
                     srcset="{{ asset('images/amd-rent-hotel-delivery-640.webp') }} 640w, {{ asset('images/amd-rent-hotel-delivery-1200.webp') }} 1200w"
                     sizes="(max-width: 600px) calc(100vw - 40px), 520px"
                     width="1200" height="800" loading="lazy" decoding="async"
                     alt="Un’auto compatta e una valigia davanti all’ingresso di un hotel">
            </figure>
            <div class="amd-delivery-feature-copy">
                <h3>Preferisci ricevere l’auto in hotel?</h3>
                <p>Nella prenotazione puoi chiedere una consegna personalizzata ai noleggiatori che offrono il servizio. L’indirizzo e il supplemento vengono confermati prima del pagamento.</p>
                <a href="{{ route('public-site.how-it-works') }}#consegna-personalizzata">Come funziona la consegna</a>
            </div>
        </div>
    </div>
</section>

<section id="domande-frequenti" class="amd-home-explainer" aria-labelledby="booking-guide-title">
  <div class="amd-section-inner amd-booking-guide-layout">
    <div class="amd-home-explainer-intro" data-home-reveal>
        <h2 id="booking-guide-title">Prima di prenotare,<br>tutto in chiaro.</h2>
        <p>Confronta le auto, controlla le condizioni e scegli sapendo quanto pagherai e dove ritirare il veicolo.</p>
        <dl class="amd-booking-facts">
            <div><dt>Prezzo</dt><dd>Totale per il periodo scelto</dd></div>
            <div><dt>Pagamento noleggio</dt><dd>20% online, 80% al ritiro</dd></div>
            <div><dt>Account</dt><dd>Non richiesto per prenotare</dd></div>
        </dl>
        <a href="{{ route('public-site.how-it-works') }}">Come funziona la prenotazione</a>
    </div>
    <div class="amd-home-faq" data-home-reveal>
        <details open>
            <summary>Quale prezzo vedo nella ricerca?</summary>
            <div><p>Il totale del noleggio per le date e gli orari scelti, IVA inclusa. La cauzione è indicata a parte e non viene sommata al prezzo del noleggio.</p><p>Prima di confermare puoi controllare il chilometraggio incluso e il costo degli eventuali chilometri extra. I prezzi e le condizioni dipendono dal noleggiatore e dall’auto selezionata.</p></div>
        </details>
        <details>
            <summary>Come vengono scelte le auto che vedo?</summary>
            <div><p>La ricerca incrocia il luogo di ritiro con i noleggiatori che lo servono e controlla la disponibilità per tutto il periodo. Per ogni gruppo di auto mostra quella disponibile con il prezzo totale più basso, rispettando i filtri che hai scelto.</p><p>Nella scheda trovi il noleggiatore, le caratteristiche e le condizioni dell’auto selezionata. Disponibilità e prezzo vengono ricontrollati quando confermi la prenotazione.</p></div>
        </details>
        <details>
            <summary>Devo pagare online o creare un account?</summary>
            <div><p>Non serve un account. Per il noleggio auto versi il 20% online con Stripe ad AMD Rent; il restante 80% lo paghi al noleggiatore al ritiro. La prenotazione viene confermata dopo la verifica del pagamento. La cauzione rimane separata.</p></div>
        </details>
        <details>
            <summary>Posso chiedere la consegna in hotel?</summary>
            <div><p>Se il noleggiatore offre questo servizio nella zona cercata, puoi richiedere la consegna in hotel o a un altro indirizzo dal modulo di prenotazione. Il noleggiatore verifica l’indirizzo e propone un supplemento, che vedi prima di pagare. La riconsegna rimane presso il luogo selezionato nella ricerca.</p></div>
        </details>
        <details>
            <summary>Cosa succede dopo la conferma?</summary>
            <div><p>Dopo il pagamento verificato, l’auto è confermata per il periodo scelto. Nella pagina di conferma trovi il riferimento della prenotazione e il PDF da scaricare e conservare.</p><p>Al ritiro, il noleggiatore verifica i documenti del conducente e completa il contratto prima della consegna. Per richieste sulla prenotazione, tieni a portata di mano il riferimento e il nome del noleggiatore.</p></div>
        </details>
    </div>
  </div>
</section>

# AMD Rent: prenotazioni, consegne e lungo termine

Implementazione sul branch `dev_vincenzo`. Il portale pubblico e il gestionale condividono dati e disponibilità; il marchio e gli accessi pubblici restano separati da AMD Mobility. Queste modifiche richiedono la migrazione `2026_09_24_100000_create_amd_rent_operations`.

## Cosa vede il cliente

La ricerca continua a usare luoghi configurati e listini attivi. Per ogni prodotto viene mostrata l’auto meno cara tra quelle disponibili per l’intero periodo: una vettura già prenotata o temporaneamente riservata per il pagamento viene esclusa, lasciando la successiva disponibile. Il supplemento per un indirizzo personalizzato non entra nel confronto iniziale perché deve ancora essere concordato.

Ricerca, risultati, dettaglio, prenotazione, guida e footer indicano il 20% online con Stripe e il saldo al ritiro. La cauzione rimane separata. Il ritorno dal sito Stripe non basta a confermare: la conferma dipende dal pagamento verificato dal server.

Il cliente può chiedere la consegna a hotel o altro indirizzo quando il noleggiatore abilita il servizio nel luogo selezionato. Invia una richiesta senza pagare né impegnare l’auto; il noleggiatore verifica l’indirizzo e propone un supplemento finale e una scadenza. Dal riepilogo firmato, il cliente può accettare la proposta, rivedere prezzo e disponibilità aggiornati e passare al pagamento. La riconsegna resta nel luogo selezionato nella ricerca: non è previsto un ritiro personalizzato a domicilio.

### Recupero di una consegna dopo un pagamento non riuscito

Dal riepilogo della richiesta, il cliente può usare **Riapri richiesta** quando Stripe ha rifiutato definitivamente l'apertura del pagamento oppure la scadenza è stata riconciliata, e il noleggio risulta annullato senza incassi. La conferma della prenotazione non riuscita contiene il collegamento alla richiesta originale. Lo stesso recupero è disponibile in ERA all'admin e al noleggiatore assegnato con permesso di gestione delle pratiche.

Il comando conserva supplemento, indirizzo e scadenza della proposta. La prenotazione precedente rimane nello storico e una voce della pratica ne conserva il riferimento e l'autore del recupero, quando è un operatore. Una proposta scaduta richiede ancora una nuova conferma del noleggiatore. La riapertura non prenota l'auto e non avvia Stripe: il cliente deve accettare un nuovo riepilogo, con prezzo e disponibilità ricontrollati anche al salvataggio.

Un clic ripetuto non ripete l'operazione; un modulo precedente non può scollegare un nuovo tentativo. Pagamenti pendenti, incassati, rimborsati o da verificare non possono essere riaperti con questo comando. Gli incassi manuali del contratto impediscono il recupero. Se un vecchio tentativo riceve una notifica tardiva di incasso, sono bloccati la creazione di nuove prenotazioni e la ripresa del pagamento di un tentativo successivo: occorre la verifica admin.

Il recupero vale anche per le richieste già bloccate prima di questo aggiornamento e non richiede una nuova migrazione. Il test DeliveryBookingRecoveryTest verifica recupero, scadenza invariata, ricalcolo del prezzo, indisponibilità sopraggiunta, accessi, moduli superati, invii ripetuti e incassi tardivi. Usa un database di prova e risposte Stripe simulate.

Il confronto tra riepilogo cifrato e modulo mantiene il controllo rigoroso di campi e valori, ma ignora l'ordine delle chiavi: MySQL può riordinare gli oggetti JSON salvati nelle proposte di consegna. Un test di regressione riproduce questo caso, che altrimenti impediva la conferma anche con date e luogo invariati.

Nel collaudo mirato del 29 settembre 2026 sono passati 104 test automatici (1.165 asserzioni), sei scenari concorrenti su MySQL 8.0.36 (39 asserzioni), sei controlli HTTP reali e 154 controlli d'interfaccia in Chrome isolato. Le schermate cliente e noleggiatore sono state ispezionate a 1.440, 390 e 320 pixel, provando riapertura, proposta scaduta, nuovo riepilogo, errore simulato, aggiornamento della proposta, tocco e tastiera. L'avviso dopo il pagamento fallito di una consegna rimanda alla richiesta originale. I dati erano dimostrativi; Stripe non era collegato. Le dimensioni smartphone sono emulate, non provate su un dispositivo fisico. Font e icone esterni del gestionale erano esclusi dalla rete di collaudo.

### Richieste di lungo termine

La pagina “Lungo termine” raccoglie recapiti, privato/azienda, auto desiderata, durata, chilometri e note. Crea una pratica da assegnare, senza pagamento Stripe e senza prenotare una vettura della flotta. Il cliente conserva il collegamento riservato al proprio riepilogo. Non sono stati inventati canoni, società fornitrici o offerte commerciali.

## Area di lavoro e permessi

Nel menu AMD Rent sono disponibili panoramica, prenotazioni e pagamenti, richieste/pratiche, luoghi serviti e impostazioni riservate all’admin. La navigazione interna distingue consegne personalizzate e lungo termine.

| Operazione | Admin | Noleggiatore attivo |
| --- | --- | --- |
| Consultare prenotazioni e richieste | Tutte | Solo della propria organizzazione |
| Gestire preventivi e documenti | Tutti | Solo delle pratiche assegnate, con permesso `rentals.create` |
| Assegnare una pratica | Sì | No |
| Impostare commissioni e configurazione consegne | Sì | No |
| Consultare commissioni lungo termine | AMD Rent e noleggiatore | Solo la propria quota |
| Risolvere pagamenti da verificare | Sì | No |

Gli utenti inattivi, le organizzazioni inattive e i ruoli diversi da admin/renter non accedono alla nuova area operativa. Sul dominio pubblico restano esclusi gli accessi del gestionale. I documenti delle pratiche sono conservati in `storage/app/amd-rent-private`, fuori dal disco pubblico, e scaricati solo dopo il controllo dell’organizzazione. Il riepilogo del cliente non espone documenti privati, commissioni interne o note di verifica dei pagamenti.

Per il lungo termine si conservano preventivi distinti con società fornitrice, veicolo, canone, anticipo, durata, chilometri, IVA, validità e condizioni. Si seleziona il preventivo accettato, si allega il contratto firmato e si conclude la pratica indicando riferimento, data e conferma della firma in presenza. Le commissioni sono importi gestiti dall’admin, con stato prevista, maturata o liquidata. Non vengono prodotti contratti automatici con le società finanziarie né effettuati controlli di credito.

## Pagamenti e commissioni

Le nuove prenotazioni usano il conto Stripe di AMD Rent. Il 20% è una parte del prezzo del noleggio e rappresenta la commissione della piattaforma. Il gestionale registra l’incasso online una sola volta e lo considera nel saldo, senza applicare nuovamente la commissione sul pagamento base al ritiro. Gli importi rappresentano quanto pagato dal cliente, al netto di eventuali rimborsi; le commissioni tecniche di Stripe sul conto non vengono sottratte dal prezzo del contratto.

Le regole precedenti del gestionale restano applicate agli ulteriori incassi commissionabili, come chilometri extra, quando il veicolo è assegnato al noleggiatore. Per le prenotazioni AMD Rent, saldo base e chilometri extra vanno registrati separatamente. La percentuale degli extra viene fotografata alla chiusura, distinta dal 20% già incassato online. Le prenotazioni precedenti mantengono il metodo di pagamento accettato in origine.

La commissione sul supplemento di consegna è ancora da definire. Nelle impostazioni AMD Rent, un campo vuoto significa **non definita**: è possibile preparare le richieste e i preventivi, ma non pagarli. Inserire `0` è una scelta esplicita: nessuna commissione sul supplemento, che verrà pagato interamente al ritiro. Una percentuale diversa aggiunge la relativa quota al pagamento online. La condizione accettata viene congelata nella prenotazione.

Esempio soltanto dimostrativo: noleggio 300 €, consegna 30 €, commissione sulla consegna esplicitamente impostata a 0%. Online 60 €, saldo al ritiro 270 €. Il supplemento non diventa automaticamente gratuito.

Il pagamento riserva temporaneamente la vettura. Un rifiuto definitivo di apertura da parte di Stripe libera la riserva; un errore ambiguo non viene interpretato come un mancato pagamento. Il controllo pianificato riconcilia la scadenza con Stripe prima di liberarla; un errore di rete conserva la riserva. Le notifiche duplicate non generano nuovi incassi. Una notifica di pagamento tardiva non riattiva una prenotazione annullata e viene segnalata per verifica.

I rimborsi si eseguono dal pannello Stripe e vengono riportati come movimenti separati. Non si elimina l’incasso originale. Dopo un rimborso, la prenotazione richiede una verifica admin: dopo avere controllato Stripe e l’accordo col cliente, l’admin può mantenere una prenotazione ancora valida con saldo aggiornato, oppure annullarla dopo un rimborso completo se il noleggio non è iniziato. La decisione e la nota vengono conservate. Una prenotazione già annullata non viene riattivata. Non è stata impostata una politica automatica di annullamento o rimborso.

## Configurazione prima dell’attivazione

Le credenziali operative non sono state inserite. `.env.example` documenta:

```dotenv
AMD_RENT_PAYMENT_MODE=stripe
AMD_RENT_STRIPE_SECRET=
AMD_RENT_STRIPE_WEBHOOK_SECRET=
AMD_RENT_STRIPE_LIVE=false
```

Usare inizialmente le chiavi dell’ambiente di test Stripe. La chiave segreta e il segreto del webhook vanno nel `.env` del server, mai nel repository o nei campi pubblici. `AMD_RENT_STRIPE_LIVE=true` richiede una chiave live e un webhook dello stesso ambiente. Senza configurazione valida, il portale non crea una nuova prenotazione a pagamento.

Configurare il webhook sul dominio pubblico, percorso `/amd-rent/stripe/webhook`. Eventi supportati: `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.expired`, `charge.refunded`. Checkout attualmente accetta carte; non usa metodi con conferma differita. Le notifiche sono validate con firma, importo, valuta, ambiente e riferimenti della prenotazione. Stripe non garantisce l’ordine degli eventi: il codice riconcilia anche un rimborso arrivato prima della conferma. Riferimenti: [webhook Stripe](https://docs.stripe.com/webhooks), [firma delle notifiche](https://docs.stripe.com/webhooks/signature), [Checkout](https://docs.stripe.com/api/checkout/sessions/create).

Il processo pianificato Laravel deve essere attivo ogni minuto (`php artisan schedule:run` nella cartella dell’applicazione). Esegue anche `amd-rent:expire-checkouts`. Non eseguire le migrazioni, la build o il processo pianificato da una seconda installazione collegata per errore a un database diverso da quello previsto per il gestionale.

Per il rilascio, dopo il backup e con il database corretto selezionato: installare le dipendenze dal nuovo `composer.lock`, applicare le migrazioni e aggiornare cache e asset. I comandi sono:

```sh
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan view:cache
```

Usare `composer install`, non soltanto `dump-autoload --no-dev`: l’installazione allinea davvero i pacchetti al lockfile e rigenera la discovery dei provider. Gli asset compilati sono inclusi in `public/build`; per rigenerarli in sviluppo è stato usato `node node_modules/vite/bin/vite.js build --configLoader native`.

La migrazione aggiunge tabelle e colonne; non modifica gli incassi precedenti. Dopo l’inserimento di nuovi pagamenti o documenti non usare un rollback del database come annullamento del rilascio: eliminerebbe i nuovi dati. Dominio, HTTPS, credenziali, processo pianificato e un pagamento/rimborso completo in ambiente Stripe di test vanno verificati sul server prima di abilitare gli incassi reali.

## Collaudo locale

`AmdRentOperationsTest` copre incassi, duplicati, scadenza, rimborsi fuori ordine, verifiche admin, disponibilità del prodotto, permessi, documenti riservati, lungo termine e preventivi di consegna. Il collaudo usa database separati e chiamate Stripe simulate: non certifica ancora un collegamento con il conto Stripe reale. Sono state verificate anche le pagine pubbliche e gestionali in un browser isolato a larghezze desktop e telefono.

Esito: 199 test funzionali (1.332 asserzioni), un test di integrazione MySQL con prenotazioni simultanee (61 asserzioni) e 8 test JavaScript superati. Build completata; sintassi di 71 file PHP e coerenza del lockfile verificate. Nel browser sono stati provati i moduli pubblici a 320, 390 e 1.440 pixel, le viste admin/noleggiatore e il saldo del noleggio dopo l’incasso online. Nello scenario dimostrativo da 60 €, il gestionale propone 48 € dopo i 12 € online.

Il controllo delle dipendenze del 24 settembre 2026 segnala 47 avvisi di sicurezza in 14 pacchetti runtime già presenti. Il confronto dei lockfile conferma che questa modifica aggiunge soltanto `stripe/stripe-php`, per il quale non sono riportati avvisi. Gli altri pacchetti non sono stati aggiornati in questa modifica: le segnalazioni richiedono un intervento sulle dipendenze prima dell’esposizione pubblica con incassi reali.

Non sono stati eseguiti push, deploy o modifiche al database operativo locale.

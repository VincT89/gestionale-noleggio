# Ricerca e prenotazione per luogo di consegna

Il portale Laravel AMD Rent è disponibile su `/cerca-auto` quando non è configurato un dominio dedicato; con `AMD_RENT_DOMAIN` usa la pagina iniziale del proprio dominio. La configurazione è descritta in [amd-rent-portale.md](amd-rent-portale.md). La ricerca mette al centro il luogo di ritiro e le date; i risultati mostrano auto, noleggiatore, punto di consegna, prezzo dell’intero periodo, cauzione e chilometraggio. I filtri comprendono noleggiatore, budget e caratteristiche dell’auto. Il noleggiatore compare nel filtro soltanto quando ha un’auto che soddisfa gli altri criteri.

## Configurazione nel gestionale

Da **AMD Rent → Luoghi di consegna**, ogni renter sceglie aeroporti, stazioni, città, zone o altri punti che serve. L’amministratore può scegliere l’organizzazione da gestire. Il renter può gestire soltanto la propria.

Per un luogo già presente, si seleziona la voce condivisa. Se manca, si aggiungono nome, tipo, città, paese e indirizzo o punto d’incontro. La conferma dichiara che le auto possono essere consegnate e ritirate lì alle condizioni e ai prezzi dei listini attivi. Questa versione mantiene ritiro e riconsegna nello stesso luogo e pagamento al ritiro. Non introduce supplementi, orari di consegna o tempi di trasferimento.

Un luogo può essere disattivato e riattivato per la singola organizzazione. Disattivarlo esclude nuove prenotazioni in quel luogo, comprese quelle iniziate prima della disattivazione ma non ancora confermate. Le prenotazioni già confermate restano valide.

## Corrispondenza e prenotazione

Il luogo ha un identificativo condiviso: la ricerca non confronta il testo delle note né presume che una sede nella stessa città possa consegnare in aeroporto. La normalizzazione evita doppioni con le sole differenze di maiuscole o spazi; nomi e indirizzi diversi non vengono unificati automaticamente. Quando più renter servono lo stesso punto, devono selezionare la stessa voce.

Tutti i luoghi con almeno una copertura attiva compaiono sia in homepage sia nella ricerca, anche quando non hanno ancora auto o listini. Non esiste un passaggio separato di pubblicazione delle offerte. I risultati usano direttamente i listini attivi in EUR del gestionale, con prezzi finali al pubblico IVA inclusa come confermato per questo progetto; il sito non aggiunge automaticamente imposte. Un risultato richiede veicolo e organizzazioni attivi, listino valido, assegnazione che copre il periodo, assenza di altri noleggi, blocchi e stati incompatibili. I controlli del calendario precedono il calcolo del prezzo.

Il punto viene mantenuto nella scheda auto e nel riepilogo, incluso nel controllo delle condizioni accettate e salvato nella prenotazione. Il noleggio del gestionale usa una sede appartenente al renter che rappresenta quel punto; conferma e PDF conservano i dati concordati. Alla conferma si ricontrollano disponibilità, copertura e prezzo nella transazione che blocca organizzazione e veicolo. Le conferme ripetute dello stesso modulo restano idempotenti.

Il chilometraggio segue il listino: `null` indica illimitato, `0` indica zero chilometri inclusi. Il prezzo applica le regole già esistenti del gestionale.

## Compatibilità con il catalogo precedente

La sezione “Offerte del sito” e le operazioni di pubblicazione sono state rimosse. Il vecchio indirizzo `/catalogo-pubblico` rimanda ai luoghi di consegna. Gli indirizzi nuovi usano `/auto/{pricelist}`; i vecchi link con ID offerta rimandano al listino esatto della stessa auto e organizzazione, se ancora valido. I moduli di prenotazione aperti prima dell’aggiornamento devono essere riaperti.

La tabella `public_rental_offers` resta per i collegamenti delle prenotazioni esistenti. Non viene interrogata per scegliere i luoghi o le auto. Soltanto alla conferma di una nuova prenotazione viene riutilizzato o creato un record tecnico per rispettare la chiave esterna esistente; i record precedenti non sono modificati. Listino, auto, luogo e importi realmente accettati rimangono negli snapshot della prenotazione e del contratto. Le conferme e i PDF già emessi restano validi. La navigazione e la ricerca non scrivono record nel catalogo.

Questa rimozione del passaggio delle offerte non aggiunge migrazioni e non richiede la pubblicazione in massa dei dati precedenti. Restano necessarie le migrazioni di flotta, prenotazioni e luoghi già previste sotto.

## Aggiornamento

La migrazione `2026_09_15_100000_create_public_pickup_places_table.php` crea `public_pickup_places` e `public_delivery_locations`. Trasferisce come punti di ritiro soltanto le sedi già indicate nelle offerte, incluse le bozze, quando la sede appartiene all’organizzazione dell’offerta. Non pubblica offerte né attribuisce aeroporti o zone ai renter per supposizione. I nuovi punti condivisi vengono configurati dal gestionale.

Gli asset compilati e il manifest in `public/build` sono inclusi nella modifica. Dopo il deploy del branch approvato occorre applicare le migrazioni pendenti e pulire le cache:

```sh
php artisan migrate --force
php artisan optimize:clear
```

Prima del rilascio si controlla `migrate:status` per verificare l’elenco delle migrazioni pendenti. Le due migrazioni precedenti del catalogo e delle prenotazioni pubbliche sono necessarie se non erano già state applicate. Non occorre rigenerare la chiave applicativa. Resta valida la configurazione Composer già funzionante sul server.

## Verifica locale

I controlli automatici coprono corrispondenza per luogo, isolamento tra organizzazioni, indisponibilità, manipolazione del luogo nel modulo, disattivazione, prenotazioni ripetute, migrazione delle sedi esistenti e distinzione tra chilometraggio illimitato e zero chilometri inclusi. Il test MySQL verifica la migrazione e la concorrenza fra due prenotazioni.

Il collaudo nel browser usa un database separato con dati e importi esplicitamente dimostrativi. Comprende desktop, smartphone, ricerca senza JavaScript, gestione dei luoghi, filtri, conferma e download del PDF. I dati del gestionale operativo non vengono utilizzati per le simulazioni.

# Ricerca del ritiro personalizzato con OpenStreetMap

Il cliente seleziona il luogo di riconsegna e le date, attiva il ritiro personalizzato e cerca un indirizzo oppure un hotel o B&B con il comune. La ricerca parte solo premendo il pulsante: non interroga OpenStreetMap durante la digitazione. Il cliente deve confermare la corrispondenza, anche se il risultato è unico.

Dopo la conferma vede i noleggiatori con copertura e auto disponibili, ordinati per distanza in linea d’aria dalla sede di partenza. Sceglie il noleggiatore, confronta auto e prezzi, sceglie l’auto e invia la richiesta di consegna. Il supplemento resta da concordare; il prezzo iniziale riguarda il noleggio. La richiesta non riserva l’auto e non avvia un pagamento.

La ricerca ordinaria e la richiesta manuale di consegna dal modulo di prenotazione restano disponibili. Il luogo di riconsegna è distinto dall’indirizzo del ritiro personalizzato. I noleggiatori e le disponibilità provengono da ERA, non da OpenStreetMap.

## Configurazione del noleggiatore

In **AMD Rent → Luoghi di consegna → Consegne personalizzate**, il noleggiatore abilita il servizio, descrive la zona, sceglie una propria sede operativa e indica esplicitamente il raggio in chilometri. La posizione della sede si cerca con OpenStreetMap e si conferma prima del salvataggio; è possibile correggere la ricerca se l’indirizzo archiviato non è sufficiente.

Il raggio non viene assegnato automaticamente. I servizi precedenti restano nello stato esistente, ma non compaiono nella nuova ricerca geografica finché mancano sede, coordinate valide o raggio. Per comparire occorrono inoltre un luogo di riconsegna servito e attivo, organizzazione e veicoli idonei, assegnazioni valide, listini attivi e disponibilità per tutto il periodo.

Il raggio e l’ordinamento usano la distanza in linea d’aria, non chilometri stradali o tempo di viaggio. La copertura geometrica non garantisce la fattibilità della consegna: il noleggiatore deve ancora confermarla. Aggiornare la posizione di una sede aggiorna tutti i servizi collegati a quella stessa sede.

## Dati e verifiche del percorso

La migrazione `2026_10_02_120000_add_custom_delivery_coverage.php` aggiunge a `public_delivery_locations` i campi nullable `delivery_origin_location_id` e `delivery_radius_km`. Le coordinate utilizzano i campi `lat` e `lng` già presenti in `locations`. Non vengono assegnati raggi o coordinate ai record esistenti.

Il browser invia un identificativo casuale della scelta conservata nella propria sessione, valido per due ore. Coordinate arbitrarie, identificativi di altre sessioni e indirizzi modificati non sono accettati. La sede operativa deve appartenere alla stessa organizzazione del servizio.

Il punto confermato entra nel riepilogo cifrato e nella richiesta salvata, separato dai campi del periodo. Copertura, prezzo e disponibilità vengono ricontrollati all’invio della richiesta, all’apertura del preventivo e alla prenotazione. Una proposta salvata può essere aperta in una nuova sessione anche dopo la scadenza della selezione iniziale. La riconsegna resta associata al luogo concordato nel percorso esistente.

## Servizio Nominatim

`config/geocoding.php` usa l’endpoint pubblico Nominatim senza chiavi API. La gratuità del servizio pubblico comporta limiti di utilizzo e non garantisce disponibilità o risultati completi. Hotel e B&B possono mancare: l’interfaccia propone di cercare l’indirizzo completo. Risultati generici come un comune intero non vengono usati come indirizzi precisi.

Configurazioni supportate, da impostare dal responsabile del rilascio senza modificare il codice:

| Variabile | Funzione |
| --- | --- |
| `AMD_RENT_GEOCODING_URL` | Endpoint HTTPS compatibile con Nominatim Search, sostituibile se necessario. |
| `AMD_RENT_GEOCODING_USER_AGENT` | Identificativo dell’applicazione; al rilascio può includere un contatto pubblico reale. |
| `AMD_RENT_GEOCODING_CACHE_STORE` | Store Laravel dedicato; se omesso usa la cache dell’applicazione. |

La cache e i lock devono essere condivisi da tutti i worker, domini e istanze che utilizzano l’applicazione, con lo stesso prefisso. `database` o Redis condivisi sono adatti a più istanze; `file` è adatto solo a worker sullo stesso filesystem. Cache in memoria per singolo processo, nulla o Octane non sono accettate fuori dai test. Non distribuire più istanze con cache locali indipendenti.

Il servizio applica un lock globale, un intervallo minimo di 1,1 secondi tra richieste e una pausa dopo risposte 429/503. Memorizza i risultati per sette giorni e le ricerche vuote per quindici minuti. Richieste concorrenti non servibili ricevono un messaggio temporaneo; non vengono accodate né ritentate automaticamente. Gli errori del provider o della cache non producono noleggiatori arbitrari.

Si trasmette soltanto la query del luogo con i parametri di ricerca e lo User-Agent dell’applicazione. Non vengono trasmessi recapiti, dati di prenotazione o IP del visitatore. Le pagine riportano attribuzione e collegamento alla licenza OpenStreetMap. Non sono utilizzate mappe o tile.

Riferimenti: [policy Nominatim](https://operations.osmfoundation.org/policies/nominatim/), [API Search](https://nominatim.org/release-docs/latest/api/Search/), [attribuzione OpenStreetMap](https://www.openstreetmap.org/copyright).

## Collaudo e attivazione

I test automatici coprono selezione esplicita e scadenza, cache e limite globale, errori del provider, ordinamento, raggi mancanti e insufficienti, isolamento tra organizzazioni, disponibilità, prezzi, richiesta, preventivo e prenotazione. Le verifiche del browser utilizzano risposte OSM simulate e dati esplicitamente dimostrativi, su desktop e smartphone, anche senza JavaScript. Il database MySQL di collaudo è separato; il percorso successivo al preventivo usa il pagamento al ritiro soltanto nella configurazione temporanea di prova, senza modificare la politica del progetto.

La migrazione è stata provata esclusivamente nel database temporaneo. Prima dell’uso nell’ambiente effettivo occorre applicarla tramite la procedura di rilascio, aggiornare gli asset, verificare cache condivisa e connettività HTTPS verso Nominatim e configurare le coperture dei noleggiatori. La prova live da questo ambiente non è stata completata: PHP non è riuscito a collegarsi a Nominatim sulla porta 443 (`cURL error 7`). Nessun pagamento Stripe o invio email reale è stato eseguito.

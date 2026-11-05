# Gestionale SubNoleggio

Gestionale Laravel per la **gestione del sub-noleggio auto** tra un proprietario del parco veicoli (**admin**) e uno o più **noleggiatori/renter**.

Il sistema copre l'intero ciclo operativo:

- gestione anagrafiche di organizzazioni, sedi, clienti e veicoli
- affidamento dei veicoli dall'admin ai renter
- disponibilità flotta tramite assegnazioni, blocchi e stati veicolo
- creazione e gestione dei noleggi renter → cliente finale
- contratti, checklist, firme, allegati e danni
- addebiti, pagamenti, commissioni e reportistica
- integrazione con **CARGOS** per l'invio normativo dei contratti

---

## Indice

- [Panoramica](#panoramica)
- [Problema che risolve](#problema-che-risolve)
- [Attori del sistema](#attori-del-sistema)
- [Funzionalità principali](#funzionalità-principali)
- [Flussi operativi](#flussi-operativi)
- [Ruoli, permessi e isolamento dati](#ruoli-permessi-e-isolamento-dati)
- [Documenti, media e checklist](#documenti-media-e-checklist)
- [Integrazione CARGOS](#integrazione-cargos)
- [Report e controllo](#report-e-controllo)
- [Stack tecnico](#stack-tecnico)
- [Installazione](#installazione)
- [Struttura funzionale](#struttura-funzionale)
- [Note progettuali](#note-progettuali)
- [Aggiornamenti ERA e AMD Rent](#aggiornamenti-era-e-amd-rent)
- [Collegamento tra sito pubblico e gestionale](#collegamento-tra-sito-pubblico-e-gestionale)
- [Portale pubblico AMD Rent](#portale-pubblico-amd-rent)
- [Prodotti della flotta e ricerca per disponibilità](#prodotti-della-flotta-e-ricerca-per-disponibilità)
- [Luoghi serviti e listini del gestionale](#luoghi-serviti-e-listini-del-gestionale)
- [Prenotazioni pubbliche e conferme](#prenotazioni-pubbliche-e-conferme)
- [Pagamento online e commissioni AMD Rent](#pagamento-online-e-commissioni-amd-rent)
- [Consegna in hotel o a un indirizzo personalizzato](#consegna-in-hotel-o-a-un-indirizzo-personalizzato)
- [Lungo termine: richieste, preventivi e contratti](#lungo-termine-richieste-preventivi-e-contratti)
- [Area cliente](#area-cliente)
- [Permessi e documenti riservati](#permessi-e-documenti-riservati)
- [Miglioramenti operativi del gestionale ERA](#miglioramenti-operativi-del-gestionale-era)
- [Configurazione e rilascio delle nuove funzionalità](#configurazione-e-rilascio-delle-nuove-funzionalità)
- [Verifiche e documentazione di approfondimento](#verifiche-e-documentazione-di-approfondimento)

---

## Panoramica

Questo progetto non è un semplice gestionale di autonoleggio “classico”.

È pensato per un modello **multi-organizzazione** in cui:

- l'**admin** possiede il parco veicoli
- i **renter** ricevono veicoli in affidamento per intervalli temporali definiti
- ogni renter gestisce i propri clienti e i propri contratti di noleggio
- l'admin mantiene controllo su flotta, documenti, commissioni, audit e report aggregati

L'obiettivo è avere un unico sistema capace di gestire sia la parte **operativa** sia la parte **documentale e amministrativa** del sub-noleggio.

---

## Problema che risolve

Nel sub-noleggio il veicolo non viene solo "prenotato":

1. deve essere **assegnato** dal proprietario a un renter
2. deve risultare **disponibile** nel periodo corretto
3. deve poter essere usato solo dal renter autorizzato
4. il contratto deve produrre documenti, media, checklist e addebiti coerenti
5. i dati devono restare separati tra organizzazioni diverse
6. alcune informazioni devono essere inviate a sistemi esterni per finalità normative

Il gestionale nasce per tenere insieme questi vincoli in un unico flusso.

---

## Attori del sistema

### Admin

È il proprietario del parco veicoli.

Può:

- creare e gestire i veicoli
- assegnarli ai renter
- controllare disponibilità, manutenzioni, documenti e danni
- configurare fee e regole economiche
- consultare audit e report globali
- gestire le integrazioni di compliance

### Renter

È il noleggiatore operativo.

Può:

- vedere solo i veicoli assegnati al proprio perimetro
- gestire sedi e clienti del proprio tenant
- creare noleggi verso clienti finali
- gestire listini, addebiti, checklist, contratti e pagamenti
- registrare check-out, check-in, danni e media del noleggio

### Cliente finale

È il soggetto che utilizza il veicolo.

Nel sistema vengono gestiti dati anagrafici, patente, documenti, residenza, cittadinanza e informazioni necessarie alla produzione del contratto e all'eventuale invio CARGOS.

---

## Funzionalità principali

### Anagrafiche

- organizzazioni `admin` e `renter`
- sedi operative e punti di ritiro/restituzione
- clienti finali
- parco veicoli

### Flotta

- assegnazioni veicolo → renter
- blocchi calendario
- storico stati veicolo
- manutenzioni
- documenti veicolo con scadenze
- danni persistenti lato veicolo

### Noleggi

- creazione contratto
- numerazione progressiva per noleggiatore
- pianificazione ritiro e rientro
- stato del noleggio (`draft`, `reserved`, `in_use`, `checked_in`, `closed`, `cancelled`, `no_show`)
- seconda guida opzionale
- calcolo importi e override finali

### Contratti e documentazione

- generazione contratto PDF
- snapshot economico del contratto
- checklist di pickup e return
- firme e allegati
- lock documentale sulle checklist concluse

### Economico

- righe economiche del noleggio
- addebiti commissionabili e non commissionabili
- pagamenti registrati
- fee amministrative per renter
- report economici e aggregazioni

### Compliance

- dati compatibili con il tracciato richiesto per CARGOS
- mappature codici e luoghi ufficiali
- flusso di invio contratti verso il servizio esterno

---

## Flussi operativi

## 1. Setup iniziale

Si configurano:

- organizzazioni
- utenti e ruoli
- sedi operative
- parco veicoli
- eventuali mappature utili per compliance e CARGOS

Questo è il livello in cui si definisce chi possiede i veicoli e chi potrà usarli.

## 2. Affidamento veicolo admin → renter

Il renter non lavora su un veicolo solo perché il veicolo esiste nel sistema.

Deve esistere un'**assegnazione** valida che definisce almeno:

- veicolo
- organizzazione renter
- periodo di validità
- stato dell'assegnazione
- eventuali vincoli aggiuntivi

Questo è il cuore del dominio: l'assegnazione determina il perimetro operativo reale del renter.

## 3. Disponibilità del mezzo

La disponibilità non dipende da una singola tabella, ma dalla combinazione di:

- assegnazioni attive
- blocchi temporanei
- altri noleggi sovrapposti
- stato attuale del mezzo

In questo modo il sistema evita sovrapposizioni e utilizzi fuori perimetro.

## 4. Creazione del noleggio

Il renter crea il noleggio selezionando:

- cliente
- veicolo assegnato
- date pianificate di ritiro e rientro
- sedi di pickup e return
- eventuale seconda guida
- note operative

Durante questo passaggio il sistema prepara la base contrattuale ed economica del noleggio.

## 5. Contratto e snapshot economico

Quando il contratto viene generato, il gestionale congela i dati economici principali in uno **snapshot**.

Questo serve a mantenere coerenza tra:

- importi mostrati nel contratto
- km inclusi
- regole applicate al momento della stipula
- eventuali calcoli successivi, come overage chilometrico e fee

In pratica il contratto non resta agganciato in modo fragile a listini che potrebbero cambiare dopo.

## 6. Pagamenti e addebiti

Il noleggio può contenere più righe economiche, ad esempio:

- quota base
- extra
- penali
- overage chilometrico
- voci commissionabili o non commissionabili

Le righe pagate alimentano sia il flusso operativo sia la reportistica.

## 7. Check-out

Alla consegna del veicolo si registrano le informazioni di uscita:

- data/ora effettiva
- chilometraggio di uscita
- carburante in uscita
- checklist pickup
- eventuali danni e foto

Il veicolo entra così nella fase di utilizzo effettivo.

## 8. Durante il noleggio

Il sistema conserva la storia operativa tramite:

- stato del noleggio
- media allegati
- eventuali danni emersi in corso d'uso
- documenti collegati al contratto

## 9. Check-in e chiusura

Al rientro si registrano:

- data/ora effettiva di ritorno
- chilometraggio di ingresso
- carburante in ingresso
- checklist return
- eventuali danni finali

Da questi dati il gestionale può determinare, se previsto:

- km eccedenti
- eventuali extra da addebitare
- possibilità di chiusura amministrativa del noleggio

La chiusura avviene solo quando il record è coerente con i requisiti previsti dal flusso.

## 10. Storico veicolo

Parallelamente al noleggio, il sistema mantiene una traccia storica dello stato del veicolo:

- assegnato
- disponibile
- bloccato
- in noleggio
- rientrato
- soggetto a manutenzione o danno

Questo consente all'admin di ricostruire cosa è successo al mezzo nel tempo.

---

## Ruoli, permessi e isolamento dati

L'applicazione usa un modello combinato di sicurezza:

- **ruoli** (`admin`, `renter`)
- **permessi granulari** per risorsa e azione
- **policy** applicative
- **query scoping** per organizzazione

Questo è importante perché in un progetto multi-tenant i permessi da soli non bastano.

Un renter può avere il permesso di vedere i clienti, ma deve comunque poter vedere **solo i clienti del proprio tenant**. Lo stesso vale per sedi, noleggi, veicoli assegnati e documenti collegati.

In sintesi:

- l'admin governa l'intero ecosistema
- il renter lavora soltanto nel proprio perimetro
- l'accesso diretto via URL o ID non deve bypassare il confine organizzativo

---

## Documenti, media e checklist

Il progetto usa una gestione documentale strutturata per conservare prove operative e allegati.

Sono previsti, tra gli altri:

- contratto di noleggio
- contratto firmato
- firme cliente e noleggiante
- checklist pickup/return
- foto checklist
- foto danni
- documenti cliente
- documenti veicolo

Un aspetto importante è il **lock documentale**: quando una checklist entra nello stato finale previsto, non deve più essere alterabile. Questo protegge il valore probatorio del materiale raccolto.

---

## Integrazione CARGOS

Il gestionale include un'integrazione con **CARGOS**, il servizio usato per l'invio dei dati dei contratti di noleggio secondo il tracciato previsto dal Ministero dell'Interno / Polizia di Stato.

Il flusso, in termini funzionali, prevede:

1. produzione dei dati del contratto nel formato richiesto
2. mappatura dei codici ufficiali necessari
3. gestione autenticazione tramite token
4. cifratura del token con API key
5. invio dei contratti
6. acquisizione dell'esito e delle eventuali attestazioni / errori

Questo modulo consente di collegare l'operatività del gestionale a un obbligo normativo reale.

---

## Report e controllo

La reportistica è pensata soprattutto per la parte amministrativa e direzionale.

Permette di analizzare i dati per:

- periodo
- renter
- veicolo
- metodo di pagamento
- tipologia voce economica
- commissionabilità
- singolo noleggio

L'obiettivo non è solo vedere quanto è stato incassato, ma anche distinguere:

- totale noleggiato
- quota commissionabile
- fee admin
- distribuzione economica per renter e veicolo

Accanto ai report è presente anche una sezione di **audit** per la tracciabilità delle operazioni.

---

## Stack tecnico

- **Laravel 12**
- **PHP 8**
- **Blade** per le viste server-side
- **Livewire** per le interfacce dinamiche
- **Spatie Laravel Permission** per ruoli e permessi
- **Spatie Media Library** per la gestione dei media
- **PDF generation** per i documenti contrattuali e checklist

---

## Installazione

### Requisiti

- PHP 8.x
- Composer
- Node.js / npm
- Database MySQL o compatibile

### Setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
```

Configura poi il file `.env` con:

- credenziali database
- filesystem
- mail
- eventuali parametri dedicati a CARGOS

Esegui quindi:

```bash
php artisan migrate --seed
npm run build
php artisan serve
```

Per lo sviluppo frontend puoi usare:

```bash
npm run dev
```

---

## Struttura funzionale

Le aree principali del progetto sono:

- **Dashboard**
- **Clienti**
- **Veicoli**
- **Sedi**
- **Noleggi**
- **Assegnazioni**
- **Blocchi**
- **Documenti veicolo**
- **Media e contratti**
- **Report**
- **Audit**

La navigazione effettiva dipende dai permessi del ruolo autenticato.

---

## Note progettuali

### Multi-tenant applicativo

Il progetto segue una logica multi-tenant basata su organizzazioni e policy, non su istanze separate del database per tenant.

### Snapshot contrattuali

Gli importi e alcune regole vengono congelati al momento corretto per evitare inconsistenze future dovute a cambi di listino.

### Storico e tracciabilità

Assegnazioni, stati, checklist, danni, documenti e audit sono pensati per rendere ricostruibile il ciclo di vita del veicolo e del noleggio.

### Compliance come parte del dominio

L'integrazione con CARGOS non è un'aggiunta marginale: è parte integrante del disegno del sistema, perché influenza sia i dati richiesti sia il flusso documentale.

---

## Stato del progetto

Il repository rappresenta un gestionale verticale costruito su esigenze operative reali del settore sub-noleggio.

Le aree chiave già coperte dal dominio sono:

- gestione tenant admin / renter
- assegnazioni flotta
- noleggi e contratti
- checklist e danni
- media documentali
- reportistica economica
- integrazione CARGOS


---

## Aggiornamenti ERA e AMD Rent

Funzionalità presenti sul branch `dev_vincenzo`, aggiornate al 25 settembre 2026. Le sezioni precedenti sono conservate; questa parte integra la descrizione originaria e precisa le regole attuali, in particolare per gli accessi dei renter alle anagrafiche e per i pagamenti delle prenotazioni pubbliche. La presenza del codice nel branch non certifica il suo rilascio in produzione.

### Collegamento tra sito pubblico e gestionale

**ERA** continua a gestire l'operatività di AMD Mobility. **AMD Rent** è il portale rivolto al cliente finale, realizzato nella stessa applicazione Laravel e collegato agli stessi dati del gestionale: flotta, listini, disponibilità, prenotazioni e pratiche. Non richiede un secondo gestionale o una sincronizzazione fra copie del database.

Il sito pubblico può avere un dominio dedicato, mentre ERA mantiene il proprio sottodominio. Marchio, navigazione e accessi sono distinti. Sul dominio pubblico i percorsi amministrativi del gestionale non sono disponibili; admin e noleggiatori continuano ad accedere dall'indirizzo di ERA.

Nel gestionale la sezione **AMD Rent** comprende panoramica, prenotazioni, richieste di consegna, lungo termine, luoghi serviti e impostazioni. Le voci disponibili e le operazioni consentite dipendono dal ruolo e dai permessi dell'operatore.

### Portale pubblico AMD Rent

- Identità AMD Rent con logo dedicato, blu del marchio, navigazione pubblica e footer con collegamenti utili.
- Homepage con ricerca di luogo e date, informazioni su prezzi e condizioni, consegna personalizzata e domande frequenti.
- Pagine di risultati, dettaglio auto, prenotazione, conferma, lungo termine, Come funziona e Assistenza.
- Fotografia principale con scritta AMD Rent sull'auto e fotografie di contesto distinte per le altre sezioni, in riquadri che lasciano leggibili testi e moduli.
- Presentazione generale delle destinazioni, senza il precedente box che elencava i singoli garage. Nel selettore iniziale compaiono città e luoghi configurati come aeroporti, stazioni e zone; i nomi delle sedi dei garage non vengono elencati come destinazioni.
- Layout responsive, navigazione da tastiera, FAQ espandibili e selezione alternativa del luogo anche senza JavaScript.

Le immagini di contesto sono illustrative e non attestano disponibilità di veicoli, sedi o hotel convenzionati. Le informazioni del noleggiatore effettivo rimangono nei risultati, nel dettaglio e nei documenti della prenotazione. Recapiti, privacy e dati societari vengono mostrati quando configurati, senza introdurre informazioni commerciali dimostrative.

### Prodotti della flotta e ricerca per disponibilità

L'admin può creare prodotti della flotta, per esempio un raggruppamento per modello, ciascuno con un **ID univoco stabile**, e associare le vetture delle diverse organizzazioni. Può consultare la flotta di tutti i noleggiatori; i renter mantengono il proprio perimetro operativo. La gestione dei prodotti è riservata all'admin.

Una vettura può appartenere a un solo prodotto. Rimuovere l'associazione non elimina l'auto, i listini o i noleggi. Il raggruppamento è esplicito: il sistema non associa automaticamente veicoli con nomi simili.

La ricerca pubblica verifica luogo servito, periodo, disponibilità della singola vettura, listino e filtri; soltanto dopo seleziona **il prezzo totale più basso per ciascun prodotto**. Se la vettura meno cara è già prenotata, bloccata o temporaneamente riservata per un pagamento, una nuova ricerca mostra la successiva disponibile. Non viene sostituita silenziosamente la vettura di un preventivo già aperto.

Foto, caratteristiche, prezzo e condizioni del risultato si riferiscono alla stessa vettura selezionata. La cauzione resta separata. Le auto senza prodotto possono comparire singolarmente; i prodotti senza auto disponibili non generano risultati.

### Luoghi serviti e listini del gestionale

Ogni noleggiatore configura i luoghi in cui consegna e ritira le auto, scegliendo punti condivisi o inserendo quelli mancanti con tipo, città e indirizzo. L'admin può gestire le coperture di tutte le organizzazioni. Una copertura può essere attivata o disattivata per il singolo noleggiatore.

La corrispondenza usa gli identificativi dei luoghi configurati. Servire una città non equivale automaticamente a servire il suo aeroporto. La ricerca per città considera i punti attivi di quella città; la selezione di un aeroporto, una stazione o una zona resta circoscritta al luogo scelto. Una località configurata può essere selezionabile anche se non ci sono auto disponibili nelle date richieste.

**Non occorre creare o pubblicare un'offerta separata.** I risultati usano direttamente i listini attivi in EUR del gestionale, con importi finali al cliente, IVA inclusa secondo la configurazione commerciale concordata. La sezione precedente “Offerte del sito” non è più necessaria; i record storici rimangono per compatibilità con le prenotazioni già emesse.

La disponibilità considera assegnazioni valide per l'intero periodo, noleggi sovrapposti, blocchi, stati del veicolo e organizzazioni attive. Ritiro e riconsegna ordinari avvengono nello stesso luogo; prezzi, chilometri e condizioni vengono ricontrollati alla prenotazione.

### Prenotazioni pubbliche e conferme

La prenotazione dal sito è collegata al noleggio in ERA e all'organizzazione che eroga il servizio. Conserva le condizioni economiche accettate, il punto concreto di ritiro e la vettura scelta. Il gestionale permette di completare i dati operativi e contrattuali prima della consegna.

- Disponibilità e prezzo vengono verificati nuovamente al salvataggio; i controlli concorrenti proteggono la stessa vettura da prenotazioni incompatibili.
- Richieste ripetute dello stesso modulo non generano prenotazioni duplicate.
- Conferma riservata con riferimento, stato aggiornato, condizioni accettate e PDF scaricabile o stampabile.
- Dal gestionale sono disponibili la preparazione manuale di un messaggio WhatsApp e la composizione di un'email; il testo va verificato e inviato dall'operatore.
- La conferma PDF documenta la prenotazione e non sostituisce una ricevuta fiscale o il contratto firmato.

La creazione dell'account cliente è facoltativa. Non sono attivi avvisi automatici generalizzati di nuova prenotazione o nuovo preventivo: le email di verifica account e recupero password hanno un flusso distinto.

### Pagamento online e commissioni AMD Rent

Le nuove prenotazioni del breve termine prevedono **il 20% online con Stripe, incassato da AMD Rent come propria commissione, e il saldo al ritiro**. Il 20% è parte del prezzo del noleggio, non un costo aggiuntivo. Cauzione ed eventuali supplementi restano distinti. Le prenotazioni precedenti conservano il metodo di pagamento accettato in origine.

- Conferma dell'incasso tramite verifica sul server: il semplice ritorno del browser da Stripe non basta a confermare il pagamento.
- Riserva temporanea della vettura durante il pagamento e riconciliazione delle sessioni scadute tramite attività pianificata.
- Controllo di firma, importo, valuta e riferimenti delle notifiche Stripe; notifiche ripetute non producono doppi incassi.
- Registrazione dell'incasso online nel gestionale e aggiornamento del saldo previsto, senza applicare nuovamente la commissione alla quota base pagata al ritiro.
- Gestione separata degli extra commissionabili secondo le regole del gestionale e la percentuale storica applicabile.
- Rimborsi eseguiti dal pannello Stripe e riportati come movimenti distinti; i casi da riconciliare richiedono la verifica dell'admin.

Per le prenotazioni AMD Rent, quota base e chilometri extra devono essere registrati separatamente. Gli incassi Stripe non si correggono eliminando il pagamento manualmente dal contratto. Non è stata definita una politica automatica di cancellazione o rimborso.

### Consegna in hotel o a un indirizzo personalizzato

Quando il noleggiatore abilita il servizio per il luogo cercato, il cliente può richiedere la consegna in hotel o a un altro indirizzo, indicando indirizzo e note. L'invio della richiesta non prenota la vettura e non avvia un pagamento.

Il noleggiatore verifica la fattibilità e prepara una proposta con supplemento e scadenza. Il cliente consulta il riepilogo riservato, accetta la proposta e passa alla prenotazione dopo un nuovo controllo di prezzo e disponibilità. La riconsegna resta al luogo selezionato nella ricerca: il ritiro personalizzato presso l'indirizzo non è incluso in questo flusso.

Se l'apertura del pagamento viene rifiutata definitivamente o la sessione risulta scaduta, il cliente, l'admin o il noleggiatore autorizzato possono **riaprire la richiesta di consegna**. Il recupero richiede una prenotazione annullata e senza incassi; conserva proposta, indirizzo, scadenza e storico del tentativo precedente. Non proroga un preventivo scaduto e non avvia pagamenti: una nuova prenotazione richiede un riepilogo aggiornato e un nuovo controllo di disponibilità e prezzo. I tentativi ripetuti e i moduli superati non scollegano una prenotazione successiva. Un incasso tardivo da verificare blocca nuovi tentativi e la ripresa del pagamento di una prenotazione successiva.

La commissione AMD Rent sul supplemento di consegna è configurabile dall'admin. Un valore vuoto significa **da definire** e impedisce il pagamento della proposta; `0` significa esplicitamente nessuna commissione sul supplemento, da pagare interamente al ritiro. Una percentuale diversa aggiunge la relativa quota al pagamento online. Le condizioni accettate vengono conservate nella prenotazione.

### Lungo termine: richieste, preventivi e contratti

La pagina pubblica raccoglie richieste di privati e aziende con recapiti, auto desiderata, durata, chilometraggio annuo e note. La richiesta crea una pratica in ERA da assegnare; non blocca un'auto della flotta e non richiede il pagamento Stripe del 20%.

Nel gestionale admin e operatori autorizzati possono:

- Creare una pratica anche direttamente da ERA; l'admin può assegnarla a un'organizzazione.
- Gestire lo stato: da gestire, in lavorazione, preventivo pronto, accettato, contratto concluso in presenza o archiviata.
- Conservare più preventivi con società fornitrice, veicolo, canone, anticipo, durata, chilometri, IVA inclusa/esclusa, validità e condizioni.
- Selezionare il preventivo accettato e raccogliere documenti cliente, documenti aziendali, preventivi e contratto.
- Concludere la pratica dopo l'accettazione del preventivo, allegando il contratto firmato e registrando riferimento, data e conferma della firma in presenza.
- Registrare lo storico delle operazioni e, per l'admin, gli importi delle commissioni AMD Rent e noleggiatore con stato prevista, maturata o liquidata, anche dopo la conclusione della pratica.

I preventivi inseriti nella pratica sono consultabili dal relativo cliente. La registrazione dell'accettazione e della firma resta gestita dall'operatore. I documenti caricati dal gestionale vengono mostrati nell'area cliente soltanto se condivisi espressamente.

Il modulo copre il percorso commerciale **dalla richiesta alla firma in presenza**. Non implementa l'addebito automatico dei canoni mensili, la fatturazione ricorrente, i controlli di credito o collegamenti automatici alle società fornitrici. La pratica di lungo termine non viene trasformata automaticamente in un normale noleggio della flotta.

### Area cliente

L'area cliente AMD Rent comprende:

- Registrazione, accesso, verifica email, recupero password, modifica dei recapiti e della password.
- Elenco delle proprie prenotazioni con stato, incasso online, eventuali rimborsi, saldo previsto al ritiro e conferma PDF.
- Richieste di consegna personalizzata e pratiche di lungo termine con stato e preventivi.
- Download dei documenti condivisi dal gestionale e caricamento di documenti cliente o aziendali per le pratiche di lungo termine aperte.
- Pulsante con icona per mostrare e nascondere ogni password, anche nei moduli del gestionale, utilizzabile da tastiera e nei componenti dinamici.

Gli account pubblici sono separati dagli utenti di ERA. La registrazione non assegna ruoli admin/renter e non crea automaticamente un'anagrafica operativa del gestionale. Dopo la verifica dell'email, l'account può ritrovare prenotazioni e pratiche non ancora associate a un account e registrate con lo stesso indirizzo. I record già associati a un altro account non vengono trasferiti.

Cambiare email richiede la password attuale e una nuova verifica. I contratti già emessi non vengono riscritti. Il saldo visualizzato nell'area cliente è quello previsto al ritiro secondo la prenotazione: non costituisce un estratto conto completo degli incassi successivi del contratto.

### Permessi e documenti riservati

| Operazione | Admin | Noleggiatore | Cliente pubblico |
| --- | --- | --- | --- |
| Gestire prodotti della flotta | Sì, su tutta la flotta | No | No |
| Consultare prenotazioni e pratiche AMD Rent | Tutte | Solo della propria organizzazione | Solo le proprie |
| Assegnare una pratica a un'organizzazione | Sì | No | No |
| Gestire preventivi e documenti | Tutti, con i permessi previsti | Solo delle proprie pratiche, con i permessi previsti | Consulta preventivi e documenti condivisi; carica i propri documenti ammessi |
| Impostare commissioni e configurazione AMD Rent | Sì | No; consulta soltanto la propria quota lungo termine | No |
| Risolvere pagamenti da verificare | Sì | No | No |
| Consultare l'archivio generale dei clienti ERA | Sì | No | No |

Gli accessi operativi richiedono utente e organizzazione attivi, ruolo appropriato e permessi per l'azione. I controlli vengono applicati anche quando si richiamano direttamente URL e identificativi.

I documenti delle pratiche sono conservati nel disco privato `amd_rent_private`, fuori dal percorso pubblico del sito. Il download controlla organizzazione o titolarità della pratica e visibilità del documento. Il cliente non vede commissioni interne, note riservate o documenti non condivisi.

### Miglioramenti operativi del gestionale ERA

Questi aggiornamenti completano le funzionalità descritte nella prima parte del README:

- **Modifica cliente dal contratto:** il renter autorizzato può correggere l'anagrafica del cliente collegato al proprio noleggio, senza accedere all'archivio generale o sostituire liberamente il cliente. L'admin conserva l'accesso alle anagrafiche. I PDF e gli snapshot già emessi non vengono riscritti automaticamente.
- **Storico pagamenti:** importi, date, metodo, riferimento, note, operatore e inclusione nelle commissioni nella scheda noleggio; supporto a più acconti e versamenti della quota base, con proposta del residuo e protezione dai doppi invii.
- **Correzione dei pagamenti manuali:** eliminazione autorizzata con tracciabilità e aggiornamento di storico, saldo e commissioni; per i contratti chiusi viene mantenuta la percentuale storica. I pagamenti online seguono il flusso di rimborso e riconciliazione Stripe.
- **Chiusura e chilometri:** la chiusura di un contratto già saldato non aggiunge un'altra quota base. Il chilometraggio illimitato è distinto da zero chilometri inclusi; gli extra reali restano soggetti ai controlli di saldo. Una nota “KM EXTRA” in un pagamento Altro non viene interpretata automaticamente come tipologia Km extra.
- **Commissioni:** Altro e Sovrapprezzi rientrano fra gli incassi commissionabili secondo le regole del gestionale; la percentuale storica mancante viene segnalata senza ricostruirla dalla percentuale attuale. Per le prenotazioni AMD Rent valgono anche le regole specifiche del 20% già incassato online.
- **Proroghe:** nuova data di rientro, controlli di disponibilità e patenti, costo concordato facoltativo, promemoria per costo da definire e saldo da registrare, storico delle modifiche. La proroga non registra da sola un incasso; il contratto aggiornato richiede una nuova firma e conserva i documenti precedenti.
- **Stampe:** intestazioni con noleggiatore operativo e periodo nei contratti e nei report, conservando le parti contrattuali previste.

Il recupero delle commissioni sui pagamenti storici è un'operazione separata dal deploy e dalle migrazioni. `php artisan rentals:include-extra-commissions` mostra soltanto un riepilogo; l'applicazione richiede esplicitamente `--apply`, dopo avere verificato importi e contratti nel database di destinazione. Non è un comando obbligatorio di ogni rilascio.

### Configurazione e rilascio delle nuove funzionalità

Il requisito PHP del progetto è **8.2 o successivo compatibile con `composer.lock`**. Le dipendenze includono ora `stripe/stripe-php`; installarle dal lockfile, senza sostituire l'installazione con il solo aggiornamento dell'autoload.

| Configurazione | Utilizzo |
| --- | --- |
| `AMD_RENT_DOMAIN` | Host del portale, senza protocollo, porta o percorso. Vuoto mantiene l'ingresso locale `/cerca-auto`; impostato rende la ricerca la pagina `/` del dominio pubblico. |
| `AMD_RENT_MANAGEMENT_URL` | Indirizzo completo del gestionale; in assenza usa `APP_URL`. |
| `AMD_RENT_CONTACT_EMAIL`, `AMD_RENT_CONTACT_PHONE` | Recapiti verificati dell'assistenza pubblica. |
| `AMD_RENT_PRIVACY_URL`, `AMD_RENT_LEGAL_NOTICE` | Informativa e dati identificativi confermati del gestore AMD Rent. |
| `AMD_RENT_PAYMENT_MODE` | Modalità di pagamento delle nuove prenotazioni; configurazione prevista `stripe`. |
| `AMD_RENT_STRIPE_SECRET`, `AMD_RENT_STRIPE_WEBHOOK_SECRET` | Credenziali Stripe da conservare nell'ambiente del server. |
| `AMD_RENT_STRIPE_LIVE` | `false` per il collaudo; `true` richiede credenziali e webhook coerenti con l'ambiente live. |
| `MAIL_*` | Trasporto funzionante per verifica email e recupero password. `log` e `array` non consegnano email reali. |
| `SESSION_DOMAIN` | Mantenere vuoto/null per limitare i cookie al rispettivo host. |

In Plesk il dominio pubblico deve servire la cartella Laravel `public`, con HTTPS, usando la stessa applicazione e il database previsto per ERA. `APP_URL` e `APP_NAME` continuano a identificare il gestionale. L'applicazione mantiene il portale `noindex` durante la preparazione.

Percorsi principali senza dominio pubblico dedicato:

| Area | Percorso |
| --- | --- |
| Ricerca pubblica | `/cerca-auto` |
| Richiesta lungo termine | `/cerca-auto/lungo-termine` |
| Accesso cliente | `/area-cliente/accedi` |
| Prenotazioni del cliente | `/area-cliente` |
| Richieste e preventivi del cliente | `/area-cliente/richieste` |
| Gestione AMD Rent in ERA | `/amd-rent` |
| Pratiche lungo termine in ERA | `/amd-rent/pratiche?type=long_term` |

Con il dominio dedicato, i percorsi pubblici di ricerca e informazione perdono il prefisso `/cerca-auto`; quelli dell'area cliente restano sotto `/area-cliente`. Il gestionale rimane sul proprio host.

Le migrazioni del catalogo e delle prenotazioni pubbliche, dei luoghi condivisi, dei prodotti della flotta, delle operazioni AMD Rent e degli account pubblici devono essere applicate nel database di destinazione. Le ultime due sono `2026_09_24_100000_create_amd_rent_operations` e `2026_09_25_100000_create_public_customer_accounts`. Il pull o il deploy dei file non applica le migrazioni, salvo un'azione di deploy configurata espressamente.

Per un aggiornamento di un'installazione esistente, dopo il backup e la verifica della configurazione e delle migrazioni pendenti, dalla radice Laravel:

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate:status
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan view:cache
```

Questa procedura aggiorna l'installazione esistente: non richiede di ricreare `.env`, rigenerare `APP_KEY` o rieseguire i seed del setup iniziale. Distribuire anche il manifest e gli asset compilati in `public/build`; per rigenerarli in sviluppo usare `npm run build`.

Il webhook Stripe è `/amd-rent/stripe/webhook` sul dominio pubblico. Gli eventi supportati sono `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.expired` e `charge.refunded`. Va attivato anche il processo Laravel `php artisan schedule:run` ogni minuto: comprende la riconciliazione `amd-rent:expire-checkouts`.

Prima dell'apertura agli utenti verificare dominio e HTTPS, recapiti e condizioni commerciali, invio email, luoghi/listini effettivi e un pagamento con rimborso completo in ambiente Stripe di test. La commissione sul supplemento di consegna richiede una decisione dell'admin. Il precedente controllo delle dipendenze ha segnalato avvisi su pacchetti già presenti: vanno verificati e risolti prima dell'esposizione pubblica con incassi reali. I test con Stripe simulato non certificano il collegamento al conto reale.

Un ritorno al codice precedente non annulla incassi o modifiche al database. I rollback delle migrazioni possono rimuovere dati introdotti dalle nuove funzionalità; non sono una procedura automatica di ripristino dopo l'uso operativo.

### Verifiche e documentazione di approfondimento

Sono presenti test dedicati a prodotti e ricerca, corrispondenza dei luoghi, prenotazioni concorrenti, pagamenti e correzioni, proroghe, modifica cliente dal contratto, isolamento tra organizzazioni, pagamenti Stripe simulati, lungo termine, documenti privati e area cliente. Le verifiche del sito comprendono desktop e smartphone, tastiera, moduli, navigazione e pulsanti mostra/nascondi password.

Eseguire i test su database e runtime isolati, senza usare il database operativo. I resoconti dei singoli interventi distinguono il collaudo locale dalle configurazioni e verifiche ancora necessarie sul server; non rappresentano una certificazione del deploy.

- [Pagamenti, commissioni, proroghe e stampe R4](docs/R4-PAGAMENTI-COMMISSIONI.md)
- [Prodotti della flotta](docs/prodotti-flotta.md)
- [Luoghi di consegna e prenotazione](docs/prenotazione-luoghi-consegna.md)
- [Località pubbliche e ricerca per città](docs/amd-rent-localita-pubbliche.md)
- [Portale AMD Rent e separazione dei domini](docs/amd-rent-portale.md)
- [Homepage AMD Rent](docs/amd-rent-homepage.md)
- [Fotografie e superfici del sito pubblico](docs/amd-rent-sfondi-pubblici.md)
- [Pagamenti Stripe, consegne e lungo termine](docs/amd-rent-operazioni.md)
- [Area cliente AMD Rent](docs/amd-rent-area-cliente.md)
- [Conferme di prenotazione e PDF](docs/conferme-prenotazione.md)

Le guide datate documentano anche versioni precedenti, per esempio pagamento interamente al ritiro o pubblicazione manuale delle offerte. Per il comportamento attuale considerare le sezioni di questo aggiornamento e le guide più recenti su operazioni AMD Rent, località pubbliche e area cliente.

# Ricerca pubblica AMD Mobility

Implementazione locale dell’8 settembre 2026, nel progetto ADM-ERA. La ricerca è una pagina pubblica Laravel con l’identità grafica del [sito AMD Mobility](https://amdmobility.it/). Usa direttamente i dati di ERA; WordPress potrà collegarla dal menu quando verrà scelto l’indirizzo definitivo.

## Pagine disponibili

| Pagina | Indirizzo locale | Accesso |
| --- | --- | --- |
| Ricerca pubblica | http://localhost:8000/cerca-auto | Aperta a tutti, soltanto offerte pubblicate e confermate |
| Gestione offerte | http://localhost:8000/catalogo-pubblico | Utente attivo con permesso `vehicle_pricing.update` |
| Anteprima delle offerte | http://localhost:8000/catalogo-pubblico/anteprima | Stesso permesso; comprende le bozze |

Gli amministratori possono gestire tutte le organizzazioni. Gli altri utenti autorizzati vedono e modificano soltanto le proprie offerte. Il catalogo pubblico mantiene la stessa selezione anche se il visitatore è un amministratore autenticato: per vedere le bozze si usa l’anteprima separata.

## Disponibilità prima del prezzo

La ricerca considera data e ora di ritiro e riconsegna. Prima di calcolare qualsiasi preventivo esclude i veicoli con un impegno nel periodo richiesto, anche quando l’impegno appartiene a un’altra organizzazione.

| Controllo | Comportamento |
| --- | --- |
| Noleggi | Controlla le sovrapposizioni usando date effettive, se presenti, altrimenti quelle previste. Esclude annullati, mancata presentazione e record eliminati. Anche una bozza con date impegnative blocca il periodo, come nella creazione dei noleggi esistente. |
| Rientri in ritardo | Un noleggio ancora in uso, oltre il rientro previsto e senza rientro effettivo, impedisce di proporre il mezzo per date future. |
| Blocchi | Esclude blocchi attivi o pianificati che intersecano il periodo, compresi quelli senza fine. |
| Stato tecnico | Esclude manutenzione, blocco e fuori servizio sovrapposti al periodo. Lo stato derivato “noleggiato” non blocca da solo ogni ricerca futura. |
| Assegnazioni | Un noleggiatore può proporre il mezzo soltanto se l’assegnazione copre l’intero periodo, senza intervalli scoperti. Un’assegnazione a un’altra organizzazione impedisce la proposta. |
| Entità disattivate | Veicoli, proprietari o organizzazioni disattivati o archiviati non vengono proposti. |

Gli intervalli seguono la regola già usata nel gestionale: una riconsegna esattamente all’ora del ritiro successivo non si sovrappone. Non è stato introdotto un tempo aggiuntivo per preparazione o trasferimento; una regola commerciale diversa richiederà una scelta esplicita.

Solo dopo questi controlli il servizio tariffario di ERA applica base giornaliera, stagioni, weekend, fasce di durata e arrotondamenti. Il budget riguarda il totale del noleggio; la cauzione è mostrata separatamente. Ordinamento e paginazione vengono applicati dopo disponibilità e budget. La scheda ripete il controllo e ricalcola il prezzo: se il mezzo è diventato occupato, nasconde il preventivo e invita a cercare altre auto.

I filtri comprendono località, budget, categoria, posti minimi, cambio, alimentazione e marca/modello. Le differenze soltanto tra maiuscole e minuscole nei nomi di località e categorie non creano filtri duplicati. I valori originali nel database restano invariati.

## Pubblicazione e dati locali

Ogni offerta collega un veicolo, un’organizzazione, una sua sede e un listino attivo in euro. La sede deve appartenere alla stessa organizzazione del listino. La pubblicazione richiede la conferma esplicita che il listino contenga prezzi finali al pubblico, IVA compresa: il motore non aggiunge imposte ipotetiche.

Nel database locale `amd_era` sono state aggiunte le tabelle `public_rental_offers` e `public_bookings`. Sono state preparate 26 bozze collegando listini attivi e sedi di ritiro predefinite già presenti, dove le organizzazioni coincidevano. Nessuna offerta è stata pubblicata. Le prenotazioni e le variazioni degli importi usate nel collaudo sono state eseguite in database dimostrativi separati.

Nella prova del 9–12 settembre 2026, dalle 10:00 alle 10:00, 15 bozze risultavano disponibili. Questi numeri descrivono la copia locale al momento della prova e cambiano con date e dati.

Le foto reali provengono dalla raccolta `vehicle_photos` e hanno la precedenza. Se gli allegati mancano, il catalogo usa immagini indicative di 16 modelli, associate tramite marca e modello verificati: nella copia locale coprono tutte le 26 offerte. La didascalia distingue le immagini del modello dalle foto del singolo mezzo. La configurazione è in `config/public_car_images.php` e le fonti sono documentate in `public/images/car-models/FONTI.md`. Il servizio non scarica immagini da siti esterni durante la navigazione. Un modello non riconosciuto o un file assente produce “Foto non disponibile”. Non sono state aggiunte foto o modificati dati nel database.

I posti non compilati non soddisfano un filtro sui posti minimi. I chilometri non configurati sono indicati come “Da concordare”. Prima della pubblicazione vanno verificati i dati commerciali delle singole offerte, incluse eventuali anagrafiche di prova già presenti nel backup.

Le pagine pubbliche espongono un elenco esplicito di campi: targa, telaio, clienti, credenziali, costi del fornitore e margini non vengono inclusi. Le immagini servite dalla nuova ricerca seguono la visibilità dell’offerta. I percorsi preesistenti degli allegati del gestionale restano separati e non sono oggetto di questa modifica.

## Limiti di questa versione

Ritiro e riconsegna avvengono nella stessa sede. Il modulo accetta periodi fino a 365 giorni, limite configurabile in `config/public_cars.php`.

L’azione finale apre ora la prenotazione con conferma immediata e pagamento al ritiro. Il cliente inserisce nome, cognome, email e telefono, poi accetta il riepilogo. Sulle offerte pubblicate, ERA ricontrolla disponibilità e condizioni e registra un noleggio `reserved`, senza registrare alcun pagamento. Le offerte in bozza restano consultabili ma non sono confermabili finché non vengono pubblicate con prezzi verificati. Le diciture di prova sono state rimosse; una conferma viene mostrata soltanto dopo la registrazione effettiva del noleggio.

La prenotazione appartiene all’organizzazione operativa dell’offerta, della sede e del listino, anche quando il proprietario del veicolo è un’altra organizzazione. La voce “Prenotazioni dal sito” mostra agli operatori autorizzati le proprie prenotazioni; gli amministratori autorizzati possono vedere tutte le organizzazioni. Da ogni prenotazione si apre il noleggio esistente per completare anagrafica, contratto e pagamento al ritiro.

La conferma e il salvataggio nel wizard interno condividono una transazione e un lock sul veicolo fisico, con lock sull’organizzazione per la numerazione. Il controllo delle sovrapposizioni viene ripetuto prima del salvataggio, anche quando si salva direttamente una bozza interna. Non sono stati introdotti hook globali sui modelli di noleggi, blocchi o assegnazioni.

Il riepilogo ha validità tecnica di 30 minuti e non blocca il veicolo durante la compilazione. Un prezzo, una cauzione o altre condizioni cambiate richiedono una nuova accettazione. Un token protetto e associato alla sessione evita duplicati per doppio clic o ritrasmissione; il server determina veicolo, organizzazione e importi. Il riferimento pubblico ha un collegamento firmato, non indicizzabile e non memorizzabile in cache; la pagina non espone email o telefono del cliente.

Prezzo e condizioni vengono conservati nella prenotazione e nello snapshot contrattuale già previsto da ERA. L’anagrafica iniziale è nuova e minimale: un visitatore non può modificare o appropriarsi di un cliente esistente fornendo la stessa email. Il noleggiatore completa e verifica i dati prima della consegna. L’annullamento dal flusso del noleggio aggiorna lo stato della conferma e libera il periodo. Non sono state inventate regole di cancellazione o rimborso.

La conferma dispone di un PDF protetto da collegamento firmato, da aprire, salvare o stampare. Contiene cliente, riferimento, noleggiatore operativo, veicolo, periodo, sede, prezzo e cauzione; non attesta un pagamento. Lo stato è riletto al momento del download, quindi un annullamento risulta anche sul documento.

Nel gestionale i comandi Prepara email e Prepara WhatsApp aprono un messaggio precompilato destinato al cliente. Il collegamento firmato viene incluso soltanto se usa HTTPS e non è riconosciuto come locale o privato. L’operatore scarica il PDF con Scarica PDF, lo allega manualmente, controlla e invia il messaggio nella propria applicazione. Stampa conferma apre invece il visualizzatore PDF. Non vengono allegati automaticamente file né eseguiti invii in background. WhatsApp è disponibile solo per numeri già completi di prefisso internazionale con `+`: nessun prefisso viene aggiunto per supposizione.

Gli avvisi email automatici restano in sospeso: per questa versione è stato scelto il PDF con messaggio WhatsApp manuale. I testi proposti per eventuali avvisi futuri sono conservati in `docs/avvisi-prenotazione-da-approvare.md`. La conferma nel browser e la consegna nel gestionale del noleggiatore sono indipendenti dall’invio delle email. I pagamenti online non sono implementati.

Il sito WordPress e la produzione non sono stati modificati. Restano da scegliere l’indirizzo pubblico e il collegamento dal menu WordPress. Le pagine sono attualmente escluse dall’indicizzazione con `noindex`; la strategia di indicizzazione andrà definita al momento della pubblicazione. La repository locale resta senza remote Git.

## Verifica e installazione

I risultati aggiornati sono in `docs/conferme-prenotazione.md`: 139 test nella suite generale, con 130 superati, 2 non superati nell’area account e 7 saltati, più una prova completa della prenotazione con 61 verifiche su MariaDB separato. I test mirati continuano a usare anche schemi SQLite in memoria. Coprono intervalli, ritardi, assegnazioni, disponibilità prima del prezzo, tariffazione, budget, filtri, accessi tra organizzazioni, pubblicazione, immagini e richieste non valide. Le verifiche sulle immagini includono la precedenza delle foto reali, gli allegati mancanti, i modelli simili da non confondere e il rispetto della visibilità delle bozze. Nessun test usa il database importato per creare o modificare dati operativi.

Compilazione degli asset e delle viste completata. Navigazione e prove interattive eseguite nel browser su desktop e con viewport da 390 × 844: ricerca, budget, cambio, ordinamento, scheda, ritorno ai risultati, menu mobile, errore sulle date e salvataggio di una bozza senza pubblicazione. L’anteprima usa i dati locali e il foglio di stile pubblico separato dai temi del gestionale. La linguetta del menu laterale amministrativo è stata ridotta su mobile per evitare che copra le etichette dei moduli, mantenendo un’area di attivazione di 28 × 44 pixel.

Le due nuove migrazioni sono già applicate in locale. Per installare la funzionalità in un altro ambiente, dopo la normale preparazione del rilascio:

```bash
php artisan migrate --path=database/migrations/2026_09_08_120000_create_public_rental_offers_table.php
php artisan migrate --path=database/migrations/2026_09_08_160000_create_public_bookings_table.php
npm run build -- --configLoader native
php artisan view:cache
```

Non occorre rieseguire o azzerare il database esistente. La migrazione crea la struttura; non pubblica offerte e non crea automaticamente le bozze preparate nella copia locale.

Test mirati:

```bash
php artisan test --compact --filter='VehicleAvailabilityTest|PublicCarSearchTest|PublicVehiclePhotoTest|RentalSearchTest|PublicBookingTest'
```

Il calcolo è in `app/Domain/Rentals/VehicleAvailabilityService.php` e `PublicVehicleSearch.php`; viste e stile sono in `resources/views/public-cars`, `resources/views/layouts/public-cars.blade.php` e `resources/css/public-cars.css`.

## Aspetto e collegamento dal gestionale

La sidebar contiene “Ricerca auto pubblica”. Per gli utenti autorizzati a gestire il catalogo apre `/catalogo-pubblico/anteprima` in una nuova scheda, includendo le bozze accessibili al loro utente e verificando la disponibilità sulle date scelte. Gli altri utenti aprono `/cerca-auto`. Il collegamento non pubblica le offerte in bozza: il percorso anonimo continua a mostrare soltanto le offerte pubblicate.

Su richiesta sono state rimosse le diciture “Anteprima riservata” dalle pagine della ricerca e del dettaglio. L’accesso alle bozze e i controlli sui prezzi non cambiano. La navbar e il footer seguono il sito AMD; titolo e form occupano una fascia blu separata dai risultati, senza foto introduttiva. Il menu include il sottomenu Chi Siamo, usa un unico comando su smartphone e supporta la chiusura con Escape. Le immagini dei veicoli restano nelle schede.

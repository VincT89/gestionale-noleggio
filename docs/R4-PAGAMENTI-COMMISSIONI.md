# Aggiornamento R4: pagamenti, commissioni, proroghe e stampe

Preparato il 9 settembre 2026 sul branch `feature_r4`, dalla versione `c2ed7c0`. La pubblicazione concordata conserva il `master` precedente in `master_origin` e porta le correzioni R4 su `master`, mantenendole anche su `feature_r4`. `dev_vincenzo` rimane separato. Questa consegna riguarda il gestionale originario; il catalogo pubblico e le prenotazioni sviluppate su `dev_vincenzo` non sono inclusi. La preparazione dei file non certifica l'avvenuta pubblicazione: verificare il commit ricevuto da GitHub e Plesk.

## Risultato delle richieste del cliente

| Richiesta | Risultato |
| --- | --- |
| Saldo di 65 euro escluso dalle commissioni | La nuova regola include anche Altro e Sovrapprezzi; è disponibile il recupero dei pagamenti storici. Nella copia locale il caso segnalato è stato aggiornato. |
| Storico sotto CARGOS | Mostra tutti i pagamenti registrati, con importo, data, metodo, riferimento, note, operatore e inclusione nelle commissioni. |
| Secondo pagamento nelle commissioni admin | Sono ammessi più acconti e più versamenti della quota base. Un ulteriore pagamento commissionabile aggiorna anche un contratto già chiuso, conservando la percentuale storica. |
| Contratto di tre giorni prolungato di altri due | È disponibile la proroga dalla scheda noleggio, con controllo della disponibilità, costo aggiuntivo concordato e promemoria del pagamento. |
| Nome del rent nelle stampe | Inserito nel contratto PDF, nel contratto vuoto e nelle stampe dei report salvati e senza salvataggio. |

## Come si usa la proroga

Nella scheda di un noleggio prenotato o in uso, aprire **Proroga noleggio** e inserire la nuova data e ora di rientro. Il costo aggiuntivo è quello concordato dall'operatore, comprensivo degli eventuali extra: non viene ricalcolato automaticamente dal listino.

È possibile salvare anche soltanto la nuova data. Il campo costo vuoto significa **da definire**; zero indica invece una proroga concordata senza costo. Dopo il salvataggio il promemoria **Importo della proroga da definire** resta visibile anche riaprendo il contratto. Il pulsante **Inserisci costo della proroga** permette di completarlo in seguito.

Una volta inserito il costo, l'importo del contratto e il saldo proposto si aggiornano. Il promemoria **Pagamento da registrare** indica il saldo ancora da incassare e apre il modulo pagamenti. L'incasso deve essere registrato separatamente come **Quota base / saldo**, quando viene effettivamente ricevuto. La proroga non crea un pagamento e non aggiunge da sola una commissione.

Il promemoria del saldo scompare quando i pagamenti di quota base e gli acconti coprono l'importo aggiornato. Se nello storico esiste un vecchio pagamento cumulativo Quota base + km extra, il sistema invita a verificare il saldo: non è disponibile la ripartizione di quell'incasso. I pagamenti Altro e Sovrapprezzi entrano nelle commissioni, ma non vengono automaticamente interpretati come versamenti della quota base.

Prima di confermare, vengono verificati sovrapposizioni con altri noleggi, blocchi del veicolo, copertura dell'assegnazione e validità delle patenti dei conducenti. I controlli vengono ripetuti al salvataggio. Non è possibile prorogare un noleggio già rientrato o chiuso, né aggiungere un'altra proroga finché il costo della precedente è ancora da definire.

Lo **Storico proroghe** conserva date precedenti e nuove, costo, importo aggiornato, operatore e note. Richieste ripetute o moduli aperti prima di un'altra modifica non aggiungono accidentalmente due proroghe.

Il PDF aggiornato usa la nuova data, la durata e il costo concordato. Per i chilometri mantiene la regola giornaliera già salvata nel contratto. La cauzione e gli extra originari restano quelli concordati; la seconda guida non genera un supplemento automatico per i giorni aggiunti. Occorre definire il costo prima di generare o firmare il nuovo PDF. I documenti precedenti restano archiviati e per il contratto prorogato viene richiesta una nuova firma del cliente.

## Pagamenti e commissioni

| Tipo di pagamento | Incluso nella base commissioni |
| --- | --- |
| Quota base / saldo | Sì |
| Acconto | Sì |
| Km extra | Sì |
| Quota base + km extra | Sì |
| Sovrapprezzi | Sì |
| Altro | Sì |
| Danni | No |
| Multe | No |

Resta la regola del gestionale sui noleggi con veicolo assegnato al renter. Contano soltanto le righe commissionabili effettivamente pagate e non eliminate. Il metodo di pagamento, contanti, POS, bonifico o altro, non modifica questa regola.

Il modulo permette più acconti e più versamenti della quota base e propone il residuo sottraendo i versamenti precedenti. I km extra già pagati non vengono riproposti automaticamente. Riferimento e note vengono salvati; la ripetizione della stessa richiesta non crea un secondo pagamento. Lo storico e lo stato della scheda si aggiornano dopo la registrazione.

Per un contratto già chiuso, il nuovo pagamento commissionabile aggiorna la commissione conservando percentuale, data e autore della chiusura. La percentuale storica mancante richiede una verifica; non viene ricostruita usando automaticamente quella attuale.

## Recupero delle commissioni sui pagamenti storici

Il solo caricamento dei file non include retroattivamente i vecchi pagamenti Altro e Sovrapprezzi. È previsto il comando seguente, che **senza `--apply` mostra soltanto il riepilogo**:

```bash
php artisan rentals:include-extra-commissions
```

Dopo aver controllato i contratti e gli importi nel database di destinazione, applicare il recupero:

```bash
php artisan rentals:include-extra-commissions --apply
```

Per limitare una verifica a un contratto si può aggiungere `--rental=ID_INTERNO`, sostituendo il segnaposto con l'ID verificato. Il numero stampato del contratto non coincide necessariamente con l'ID interno.

Il comando include le voci Altro e Sovrapprezzi precedentemente escluse, conservandone importi, tipo, data, metodo, riferimento e operatore. Ricalcola le commissioni dei contratti chiusi con la percentuale già salvata, conserva i dati della chiusura e registra l'operazione nelle attività. Ripeterlo non duplica pagamenti e non applica due volte gli stessi importi.

**I contratti chiusi con percentuale storica mancante vengono lasciati invariati e segnalati.** In questo caso il comando restituisce esito 1 anche se ha aggiornato correttamente gli altri contratti. Leggere il riepilogo: l'esito richiede di esaminare i casi sospesi, non significa che tutti gli aggiornamenti siano stati annullati.

Nella copia locale `amd_era` sono state aggiornate **19 voci**. È stata conservata una copia dei dati interessati prima dell'operazione. È stata verificata la conservazione degli importi, dei tipi di pagamento e dei dati di chiusura.

Per il contratto n. 3 segnalato dal cliente, ID interno locale 642, i 455 euro iniziali e i 65 euro già registrati come Altro producono ora:

| Voce | Prima | Dopo |
| --- | ---: | ---: |
| Incassi registrati | 520,00 euro | 520,00 euro |
| Base commissioni | 455,00 euro | 520,00 euro |
| Percentuale salvata | 15% | 15% |
| Commissione admin | 68,25 euro | 78,00 euro |

I 65 euro restano classificati come Altro. Non è stato creato un altro incasso. **Non registrare nuovamente quei 65 euro.**

Il contratto n. 1 di **Edil Ca Solutions SRL**, ID interno locale **596**, resta da verificare con il tutor, come richiesto: manca la percentuale salvata alla chiusura. Non è stato applicato il 15% ipotizzato e il contratto non è stato modificato dal recupero.

È conservata nel codice anche l'utilità precedente `rentals:correct-base-payment`, destinata a una riclassificazione esplicita di un singolo saldo. **Non fa parte della procedura di questo aggiornamento**: con la nuova regola non serve convertire Altro in Quota base per includerlo nelle commissioni.

## Nome del noleggiatore nelle stampe

Il contratto usa l'organizzazione del noleggio. Se il noleggiatore con licenza è già il noleggiante contrattuale, il suo nome resta visibile. Se il noleggiante è AMD Mobility, viene aggiunto il noleggiatore operativo, mantenendo dati e firme delle parti contrattuali. Il contratto vuoto usa l'organizzazione dell'operatore che lo apre. I loghi hanno uno spazio separato dai nomi.

I report riportano i noleggiatori dei risultati e il periodo effettivamente elaborato. Se i risultati comprendono più noleggiatori, i nomi vengono elencati. Cambiare i filtri senza rilanciare il report non modifica l'intestazione dei risultati precedenti.

La nuova intestazione compare sui PDF generati dopo l'aggiornamento. I PDF già archiviati o firmati non vengono riscritti automaticamente; i report vanno rilanciati.

## Collegamento Git a Plesk

Per la pubblicazione concordata si usa la repository `VincT89/gestionale-noleggio`, branch `master`. Il ramo `master_origin` conserva il codice precedente alle correzioni R4; non è un backup del database. Prima di pubblicare `master`, verificare che Plesk non esegua un deploy automatico non ancora preparato.

Il frontend compilato in `public/build` è ora incluso tra i file da versionare. Prima del commit verificare che siano presenti il manifest e i due asset elencati nella consegna. Non aggiungere `.env`, database, documenti caricati o `public/hot`.

Il pull aggiorna la copia Git; il deploy copia i file nella cartella configurata. Con il deploy automatico possono avvenire insieme. La cartella di destinazione deve essere la radice corretta del progetto Laravel, mentre la radice web del dominio deve continuare a puntare alla relativa cartella `public`. La configurazione del dominio Plesk non è stata verificata da questa sessione.

Le migrazioni e il recupero delle commissioni descritti sotto sono necessari anche quando si distribuiscono i file tramite Git. Il semplice pull non li esegue, salvo azioni automatiche configurate sul server. Le migrazioni aggiungono struttura senza cancellare i dati esistenti; il recupero modifica invece i contrassegni commissionabili e i conteggi storici. Tornare al codice di `master_origin` non annulla queste modifiche al database.

## Caricamento su Plesk

Il pacchetto definitivo `feature_r4-PLESK.zip` contiene **35 file**: 32 file applicativi, comprese le due migrazioni, e 3 file del frontend compilato. `ELENCO-FILE.md` riporta tutti i percorsi; `CHECKSUMS.json` contiene le impronte verificate. Non serve eseguire npm sul server.

La base della consegna è `c2ed7c0`: eventuali modifiche successive fatte direttamente sul server vanno confrontate prima di sovrascrivere gli stessi file. Eseguire i comandi dalla **radice Laravel, dove si trova `artisan`**, con la versione PHP del dominio. Negli esempi l'eseguibile è `php`; il percorso effettivo dipende da Plesk. È richiesto PHP 8.2 o successivo; il collaudo locale usa PHP 8.3.1.

1. Creare un backup dei file da sostituire e del database del gestionale.
2. Mettere temporaneamente il gestionale in manutenzione:

   ```bash
   php artisan down
   ```

3. Caricare ed estrarre il contenuto dello ZIP nella radice Laravel, conservando le cartelle `app`, `database`, `resources` e `public`. Non estrarlo tutto dentro `public`. Il pacchetto non contiene `.env`, credenziali, database, documenti clienti, `vendor`, `node_modules` o il file di sviluppo `public/hot`. Non cancellare gli altri asset già presenti sul server.
4. Rigenerare l'autoload, applicare le **due migrazioni specifiche** e aggiornare le cache:

   ```bash
   composer dump-autoload --optimize --no-dev --no-scripts
   php artisan migrate --path=database/migrations/2026_09_09_140000_add_payment_reference_and_request_key_to_rental_charges.php --force
   php artisan migrate --path=database/migrations/2026_09_09_160000_create_rental_extensions_table.php --force
   php artisan optimize:clear
   php artisan view:cache
   ```

   La prima migrazione aggiunge riferimento e identificativo univoco delle richieste di pagamento. La seconda crea lo storico delle proroghe, con costo facoltativo. Nessuna delle due registra incassi o applica il recupero delle commissioni storiche. Sono entrambe necessarie prima di utilizzare le nuove schermate. Se un comando fallisce, risolvere l'errore prima di riaprire il servizio.
5. Eseguire il riepilogo del recupero storico e poi applicarlo, come descritto nella sezione precedente. Verificare gli importi nel database di destinazione; i risultati locali non sostituiscono quel controllo. Lasciare sospesi i contratti con percentuale storica mancante, compreso il caso da discutere con il tutor.
6. Riattivare il gestionale:

   ```bash
   php artisan up
   ```

7. Aprire una scheda noleggio e controllare storico pagamenti, modulo di proroga, report e generazione PDF. Per prove con nuovi incassi o proroghe usare l'ambiente di collaudo; sul sito operativo verificare i dati effettivi senza registrare operazioni dimostrative.

Le istruzioni sono per il caricamento manuale. **Nessun file e nessun dato sono stati modificati in produzione durante questo lavoro.**

Se occorre ritirare l'aggiornamento, ripristinare i file precedenti e pulire le cache. Non eseguire rollback indiscriminati: le nuove colonne possono restare, e lo storico delle proroghe eventualmente già utilizzato va conservato. Ripristinare il codice non annulla le modifiche a date, importi o commissioni applicate nel frattempo; i dati richiedono una gestione separata partendo dal backup.

## Verifiche eseguite

- Tutti i **91 test specifici R4 superati**: 53 sui pagamenti e sulle commissioni, 9 sulle intestazioni, 29 sulle proroghe.
- Le prove delle commissioni coprono anche 32 combinazioni tra tipo e metodo di pagamento, esclusione delle righe non pagate o eliminate, recupero storico ripetibile e conservazione della percentuale salvata.
- **6 test JavaScript superati** sul residuo e sui pagamenti cumulativi o dei km extra.
- Suite generale: **123 test, 485 verifiche; 114 superati, 7 saltati e 2 errori già presenti** nei test degli account (`RegistrationTest` e `DeleteAccountTest`). Le funzioni di registrazione ed eliminazione account non sono state modificate. Non si dichiara quindi l'intera suite priva di errori.
- Controllo sintattico dei 25 file PHP interessati senza errori; compilazione delle viste e del frontend riuscita.
- Browser su ambiente separato: controllo disponibilità con blocco manutenzione, proroga con sola data, promemoria dopo ricaricamento, inserimento del costo in seguito, proposta del saldo, registrazione dell'incasso e scomparsa del promemoria. Verificate le indicazioni di inclusione per Altro e Sovrapprezzi.
- Layout dei percorsi modificati verificato a 1440, 390 e 320 pixel; nessuno scorrimento orizzontale della pagina e nessun errore JavaScript nei casi provati.
- PDF: contratti con e senza licenza, compilati e vuoti; contratto prorogato e prorogato con seconda guida. Verificati impaginazione, nuova data, importi, chilometri, firme della revisione corrente e conservazione dei file precedenti.
- Report: intestazione coerente con organizzazioni, periodo e risultati, nei percorsi salvato e senza salvataggio.

I test automatici usano database locali separati. Per ripeterli: `php tests/mysql-payments.php`, oppure `php tests/mysql-payments.php --all` per la suite generale; il programma crea un database con prefisso `adm_era_qa_payments_` e conserva il log. I test JavaScript si eseguono con `node --test tests/js/rental-payments.test.mjs`. Non eseguire i test sul database operativo.

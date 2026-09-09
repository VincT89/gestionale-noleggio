# ADM-ERA: controllo prima del rilascio, 9 settembre 2026

**Esito: rilascio da rinviare finché non vengono risolti i problemi di accesso confermati.** La sovrapposizione della dashboard è corretta; ricerca e prenotazione superano le prove descritte sotto. La suite generale non è interamente superata e la verifica online delle dipendenze resta bloccata.

Revisione della copia locale sul branch `dev_vincenzo`, a partire da `e521c74` (`Aggiunge ricerca pubblica, offerte e prenotazioni`). Il remote attuale è `VincT89/gestionale-noleggio`; non è collegato alla repository originaria di Nastu94. Le correzioni di questo audit sono locali e non sono state committate o inviate. Non sono stati eseguiti pull, push, deploy o modifiche WordPress.

Il modello di accesso resta quello del README: amministratore con visibilità globale, noleggiatore nel proprio perimetro. La visibilità globale dell'amministratore non è un difetto. Gli interventi estesi su account e documenti, precedentemente esclusi, non sono stati applicati durante questo controllo.

**Correzioni frontend**

| Problema | Modifica | Verifica |
| --- | --- | --- |
| In dashboard, aprendo Amministrazione, le voci del menu radiale si sovrapponevano. | Sottomenu più ampi, testi a capo, posizioni sopra/sotto per due voci e distribuzione adatta alle tre voci di Anagrafiche. Spazi tra righe e margini si adeguano alla sezione aperta. | Amministrazione e Anagrafiche controllate a 320, 390, 768 e 1280 px: nessuna collisione tra pulsanti e sottomenu, nessun allargamento della pagina. Verificati anche Noleggi, Flotta e Report & Audit a 320 px. Assegna veicoli apre la pagina delle assegnazioni. |
| Il menu radiale non esponeva chiaramente apertura e chiusura alla tastiera e alle tecnologie assistive. | Aggiunti stato espanso, associazione al sottomenu, contorno del focus e chiusura con Escape che restituisce il focus al pulsante. Sistemata la gestione del ridimensionamento. | Chiusura e restituzione del focus verificate nel browser. |
| Nella navbar della ricerca pubblica era presente Login. | Rimosso il collegamento dal layout condiviso di ricerca e prenotazione. | Assente nel markup pubblico e nel menu mobile. Le rotte del gestionale conservano i controlli di accesso. |

File applicativi modificati: `resources/views/components/radial-grid-menu.blade.php` e `resources/views/layouts/public-cars.blade.php`. Destinazioni e permessi dei menu sono invariati. Il titolo Dashboard mantiene il testo bianco sullo sfondo blu previsto dal tema chiaro.

Sono state riaperte a 390 × 844 le pagine catalogo pubblico, prenotazioni dal sito, noleggi, veicoli, documenti veicolo, report, clienti, sedi, organizzazioni e assegnazioni amministrative. Le pagine controllate non allargano il contenitore principale; le tabelle larghe scorrono al loro interno. Nessun errore JavaScript rilevato nel campione di navigazione raccolto. Questo controllo di apertura e disposizione non equivale al collaudo di ogni comando di salvataggio.

La ricerca con bozze è stata provata con il periodo 10–13 settembre 2026, ore 10:00: la copia locale restituiva 16 offerte disponibili. Verificati menu mobile, ricerca, separazione tra form e risultati e caricamento della foto indicativa nella scheda visibile. Schede confrontate anche a 1280 px. Il numero è un risultato del database locale, non una disponibilità commerciale in produzione; le bozze non diventano prenotabili pubblicamente.

Le prove visive usano viewport emulate nel browser integrato. Il gestionale era in tema scuro; per il tema chiaro sono state controllate le regole CSS, senza una seconda sessione visiva completa. Non eseguite prove su dispositivi fisici, Safari/iOS o una certificazione di accessibilità.

**Verifiche tecniche**

| Controllo | Esito |
| --- | --- |
| Suite generale avviata con `php tests/mysql-checks.php suite` | 139 test, 696 asserzioni: 130 superati, 2 falliti, 7 saltati. Database MariaDB nuovo e separato; alcune suite mirate usano proprie fixture SQLite in memoria. |
| Prenotazioni concorrenti, `php tests/mysql-checks.php booking` | 1 test, 61 asserzioni superate su schema creato dalle migrazioni reali in MariaDB 10.4.32. Due conferme simultanee sullo stesso veicolo e periodo producono una sola prenotazione. |
| Controlli aggiuntivi degli accessi | 8 test, 53 asserzioni: 4 superati e 4 falliti. I fallimenti riproducono i problemi di account e firma aziendale indicati sotto. |
| Accesso senza autenticazione | Le 17 pagine private campionate richiedono login. Conferma e PDF senza firma valida restituiscono accesso negato. |
| Compilazione frontend | Superata con `npm run build -- --configLoader native`. Avviso sui dati Browserslist datati, senza errore di compilazione. |
| Viste e sintassi | Compilazione delle viste Blade superata. Sintassi di 291 file PHP tracciati, esclusi i template Blade, verificata senza errori. |
| Composer | Configurazione valida; requisiti della piattaforma soddisfatti sul PHP locale 8.3.1. Non costituisce verifica della configurazione PHP di Plesk. |
| Differenze Git | Nessun errore di spaziatura. Nel controllo dei file tracciati correnti non sono emersi file `.env` operativi o dump del database. Non è un'analisi completa dei segreti nella cronologia Git. |
| Vulnerabilità delle dipendenze | Nessun esito disponibile: npm audit e Composer audit rifiutati dalla revisione automatica prima dell'esecuzione. |

La prova concorrente verifica anche destinazione al noleggiatore operativo, conservazione di prezzo e cauzione, PDF, collegamenti firmati e annullamento. Non è una prova di carico né di tutte le possibili scritture simultanee su blocchi, assegnazioni e manutenzioni. Non sono stati inviati messaggi o registrati pagamenti.

I due fallimenti della suite generale riguardano `DeleteAccountTest::test_user_accounts_can_be_deleted` e `RegistrationTest::test_new_users_can_register`. Il primo pretende la cancellazione fisica mentre il modello usa eliminazione logica e conserva il record con `deleted_at`. Il secondo non raggiunge l'autenticazione attesa: la registrazione resta attiva in Fortify e l'azione `CreateNewUser` non assegna l'organizzazione obbligatoria. Il flusso account va riallineato e ricollaudato; non sono stati modificati i test per nascondere i fallimenti. La prenotazione pubblica non richiede la creazione di un account gestionale.

**Problemi ancora aperti**

| Priorità | Evidenza verificata | Intervento necessario |
| --- | --- | --- |
| Alta | Un renter autenticato di un'organizzazione diversa ottiene HTTP 200 aprendo tramite `media.open` una firma aziendale fittizia memorizzata sul disco privato. In `RentalMediaController::open`, il ramo relativo a Organization non esegue un'autorizzazione. | Consentire all'admin la visibilità prevista e limitare il renter alla propria organizzazione; aggiungere una prova di regressione. La riproduzione usa esclusivamente un file fittizio. |
| Alta | Un renter con `is_active=false` può iniziare una sessione. I flag `is_active=false` dell'utente e dell'organizzazione non bloccano neppure una sessione esistente. Tre prove aggiuntive falliscono. L'organizzazione renter archiviata è invece bloccata correttamente. | Applicare il significato di disattivazione al login e alle richieste successive, mantenendo intenzionale la gestione del recupero amministrativo. Riferimenti: `EnsureOrganizationIsActive`, `RedirectIfOrganizationTrashed`, pipeline Fortify. |
| Alta, da verificare nell'ambiente di rilascio | Alcune raccolte di firme ricadono sul disco predefinito pubblico di Media Library; altre raccolte usano il disco locale. Rimangono collegamenti diretti `getUrl()`. | Verificare dischi effettivi e collegamenti dei documenti riservati in produzione, poi pianificare eventuali spostamenti e apertura autorizzata. Non è stata dimostrata un'esposizione di documenti di produzione. |
| Media | Elenco e scheda clienti richiedono `manage.renters`, quindi sono riservati all'admin, mentre il README descrive clienti nel perimetro del renter. La query della tabella e la policy di consultazione non applicano autonomamente quel perimetro. | Allineare requisiti, rotte, policy e query insieme. Non rimuovere soltanto il blocco amministrativo. Il noleggio ha già un modulo per completare il conducente: il disallineamento non dimostra da solo che ogni flusso di consegna sia bloccato. |
| Media | Registrazione account non superata dalla suite; alcuni metodi alternativi dei controller restano segnaposto. | Definire se la registrazione gestionale debba essere pubblica; completare o escludere i percorsi realmente previsti e ricollaudarli. |
| Prima della vendita | Offerte locali in bozza con IVA da verificare; presenti anche anagrafiche di prova nel backup. | Il responsabile commerciale deve approvare organizzazione operativa, sede, prezzi finali, cauzione, chilometri e condizioni prima di pubblicare le offerte. |

**Collegamento WordPress e rilascio**

È fattibile un normale collegamento dal sito AMD alla ricerca pubblica di ERA, senza plugin WordPress. Con il dominio indicato dall'utente, l'indirizzo previsto dopo il rilascio è `https://era.amdmobility.it/cerca-auto`. Non è stata verificata la disponibilità di questa pagina in produzione. Non collegare WordPress al percorso riservato `/catalogo-pubblico/anteprima`.

Il cliente cerca, prenota con pagamento al ritiro e consulta la conferma nelle pagine pubbliche; i collegamenti del sito riportano ad AMD. La rimozione di Login migliora il percorso, ma la protezione dei dati dipende da autenticazione e autorizzazioni sul server. Chi conosce l'indirizzo della pagina di login può comunque raggiungerla: non riceve per questo accesso al gestionale.

Prima del rilascio su Plesk occorrono la risoluzione dei difetti confermati e la verifica delle dipendenze. Vanno poi controllati sul server: versione PHP ed estensioni, document root verso `public`, HTTPS e URL dell'applicazione, asset compilati, permessi di storage, migrazioni pendenti e generazione dei collegamenti firmati. Il file `public/hot` appartiene allo sviluppo locale e non deve essere trasferito sul server. Questa verifica locale non certifica la configurazione Plesk.

Il rilascio deve conservare il database operativo, i documenti, la configurazione e la chiave applicativa esistenti, con backup verificato. Non importare il database locale e non rigenerare `APP_KEY`. Non eseguire migrazioni che ricreano o svuotano il database. Eseguire una prova finale esterna di ricerca, prenotazione e conferma prima del collegamento WordPress, utilizzando dati e modalità di collaudo concordati per quell'ambiente.

PDF e messaggi precompilati restano disponibili; email e WhatsApp richiedono l'invio manuale dell'operatore. Il controllo non ha attivato notifiche, CARGOS, pagamenti online o automazioni.

**Evidenze conservate**

- Suite: `storage/logs/adm_era_qa_suite_20260909_070742_5784cd.log`.
- Concorrenza e PDF: `storage/logs/adm_era_qa_booking_20260909_071046_fe400c.log` e relativo documento `-confirmation.pdf`.
- Test aggiuntivi, log e JUnit: cartella locale di lavoro Codex `work/release-audit-2026-09-09`, esterna alla repository. Fixture SQLite in memoria, file fittizi e invii intercettati.
- Database di collaudo conservati con prefisso `adm_era_qa_`; il database importato `amd_era` non è stato usato per scritture di prova.

La revisione automatica ha rifiutato i controlli delle vulnerabilità perché trasmettono nomi e versioni delle dipendenze a npm e Packagist e richiede un'autorizzazione esplicita per questa comunicazione. Il permesso generico di rete non è stato sufficiente. Non sono stati tentati percorsi alternativi e non è stata ottenuta alcuna valutazione delle vulnerabilità.

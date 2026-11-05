# Conferme di prenotazione e collaudo — 8 settembre 2026

La consegna scelta per questa versione è PDF scaricabile e stampabile, con messaggio WhatsApp preparato per l’invio manuale. Le email automatiche restano in sospeso. La prenotazione viene già registrata nel gestionale del noleggiatore operativo; non è stato aggiunto un avviso automatico all’operatore.

## Uso

Il cliente trova **Scarica conferma PDF** e **Stampa conferma** nella pagina di conferma. Il primo comando scarica il file; il secondo lo apre nel visualizzatore PDF, dal quale si può stampare.

In **Prenotazioni dal sito**, l’operatore trova **Scarica PDF**, **Stampa conferma** e **Prepara WhatsApp**. WhatsApp apre la conversazione con il numero indicato e il messaggio compilato: riferimento, stato, noleggiatore, auto, periodo, sede, prezzo e cauzione. L’operatore controlla il testo, allega manualmente il PDF scaricato e preme Invia. È disponibile anche la composizione manuale di un’email.

WhatsApp richiede un numero completo di prefisso internazionale con `+`. Il sistema non aggiunge un prefisso ipotetico. Il messaggio non incorpora collegamenti HTTP, localhost, indirizzi di rete privati o domini locali. Quando l’indirizzo della conferma è HTTPS e non è riconosciuto come locale, include anche il collegamento firmato. Questo controllo non certifica che un dominio sia raggiungibile: prima del rilascio il collegamento va provato dall’esterno.

PDF e messaggio riportano lo stato corrente. Il prezzo e la cauzione restano quelli accettati nella prenotazione; modificare il listino non riscrive gli importi già concordati. Il PDF è una conferma di prenotazione, non una ricevuta di pagamento. Il pagamento resta al ritiro.

## Verifiche eseguite

| Verifica | Risultato |
| --- | --- |
| Download e stampa | Risposte PDF valide; download con allegato e apertura nel browser distinti. Collegamenti firmati, manomissioni respinte. Controllo aggiuntivo sul server dimostrativo: entrambi gli indirizzi restituiscono HTTP 200, con disposizione attachment per il download e inline per la stampa. PDF scaricato di 90.430 byte, una pagina A4, renderizzata e controllata visivamente senza tagli o sovrapposizioni. |
| WhatsApp | Numero e testo correttamente codificati; riferimenti locali esclusi; stato annullato aggiornato. Nessun invio effettuato. |
| Prenotazioni simultanee | Due processi PHP indipendenti sono arrivati al lock della prenotazione sul database locale separato. Una sola conferma e un rifiuto per indisponibilità; un solo cliente, noleggio, riferimento e numero contrattuale. |
| Consegna operativa | Prenotazione assegnata al noleggiatore dell’offerta, anche con proprietario del veicolo diverso. Visibile al relativo operatore e assente dalla lista dell’altra organizzazione. |
| Cauzione | Modificata nell’offerta dimostrativa da 500,00 a 750,25 euro attraverso il salvataggio applicativo. La prenotazione e il relativo riepilogo contrattuale hanno conservato 500,00 euro. |
| Annullamento | Eseguito attraverso il comando del noleggio. Conferma aggiornata e veicolo nuovamente disponibile per lo stesso periodo. |
| Pagamenti e comunicazioni | Nessun addebito creato; pagamento previsto al ritiro. Nessun messaggio, email o chiamata CARGOS inviato. |
| Compilazione | Asset, viste e sintassi dei file PHP modificati verificati senza errori. Controllo delle differenze Git superato; nessun remote configurato. |

Il server locale identificato dal collaudo è **MariaDB 10.4.32**, utilizzato attraverso il driver MySQL di Laravel. Non è stata effettuata una prova su MySQL 8 o sul database di produzione.

La suite generale ha eseguito **139 test: 130 superati, 2 non superati e 7 saltati**, con 696 verifiche. Le migrazioni storiche sono state eseguite nel nuovo database MariaDB. I test che definiscono uno schema SQLite isolato mantengono quel proprio ambiente: non tutti i 139 test sono quindi prove MariaDB.

I due test non superati riguardano gli account: la cancellazione si aspetta l’assenza fisica dell’utente, mentre il modello conserva un record eliminato logicamente; il test di registrazione non raggiunge l’autenticazione attesa. L’area account resta fuori dall’intervento concordato e non è stata modificata per far passare questi test.

La prova aggiuntiva dell’intero percorso di prenotazione, con schema ottenuto dalle migrazioni reali e due processi concorrenti su MariaDB, ha superato **61 verifiche**. Copre due conferme pubbliche simultanee; non rappresenta una prova di carico né di tutte le possibili scritture concorrenti su blocchi, assegnazioni e interventi tecnici.

Nel browser è stata ricontrollata la pagina locale delle prenotazioni a 1280 × 900 e 390 × 844, con ricerca e azzeramento del filtro funzionanti, senza allargamento della pagina o errori JavaScript rilevati. La lista del database importato è vuota.

Dopo l’avvio manuale da parte dell’utente del server dimostrativo sulla porta 8012, è stato completato anche il controllo delle pagine compilate. Accesso con il noleggiatore di prova, scheda della prenotazione e conferma cliente verificati su desktop e telefono: dati leggibili, pulsanti a capo su schermo stretto, nessuno scorrimento orizzontale della pagina e focus da tastiera visibile. Il testo del collegamento WhatsApp contiene riferimento, auto, date, sede, totale e cauzione corretti, senza collegamenti locali. Non è stata aperta una conversazione WhatsApp né inviato alcun messaggio.

I pulsanti PDF sono stati azionati nel browser integrato, che non ha esposto un evento di download né una nuova scheda PDF verificabile dagli strumenti. Il file e le intestazioni sono stati quindi controllati direttamente sul medesimo server dimostrativo, con l’esito riportato nella tabella. Il documento A4 è stato renderizzato e ispezionato; non è stata effettuata una stampa fisica. I precedenti blocchi di avvio del server e apertura dei file HTML locali non impediscono più il controllo visivo delle pagine applicative, ora completato. Nessun dato operativo importato o di produzione è stato modificato durante queste prove.

## Ripetere il collaudo isolato

Eseguire dalla cartella del progetto:

```bash
php tests/mysql-checks.php suite
php tests/mysql-checks.php booking
```

Il programma usa le credenziali locali senza stamparle o salvarle nei report, ammette soltanto un server di loopback e crea ogni volta un database nuovo con prefisso `adm_era_qa_`. Non usa il database importato `amd_era`. La prova di prenotazione rifiuta database già popolati o con nome diverso da quello assegnato dal programma. I database e i report vengono conservati per ispezione; i report e il PDF dimostrativo sono in `storage/logs/adm_era_qa_*`. Non è stata eseguita una cancellazione automatica dei database di prova.

## Preparazione del collegamento pubblico

La pagina da collegare da WordPress è il percorso Laravel `/cerca-auto`. L’indirizzo pubblico non è stato ancora scelto e WordPress non è stato modificato. Per questa architettura il collegamento dal menu non richiede un nuovo plugin WordPress.

Prima di attivare le offerte, il titolare deve controllare dal catalogo: organizzazione operativa e sede, listino finale IVA compresa anche per stagioni ed extra, cauzione, chilometri e condizioni pubbliche. La presenza dei dati nel backup non equivale all’approvazione commerciale. L’offerta Jaecoo esaminata è ancora in bozza, con IVA da verificare; il campo delle informazioni pubbliche è vuoto. Non sono state compilate condizioni o pubblicate offerte per supposizione.

Una volta scelto l’indirizzo, vanno configurati HTTPS, l’URL dell’applicazione e l’eventuale proxy; poi va provato dall’esterno il collegamento firmato della conferma. Il menu WordPress dovrà puntare alla ricerca pubblica, mentre l’indirizzo riservato che comprende le bozze resta nel gestionale. Installazione delle due migrazioni e compilazione sono descritte in `ricerca-pubblica.md`.

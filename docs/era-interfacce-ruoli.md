# ERA: interfaccia per ruolo

Intervento su `dev_modifiche`, 15 ottobre 2026. Nessuna modifica a `master`, nessun commit, push, deploy o intervento sul database operativo.

## Comportamento

- L'utente con ruolo `admin` vede l'interfaccia aggiornata e gestisce AMD Rent, prenotazioni pubbliche, richieste, tipi di veicolo, luoghi serviti, raggi, costi e impostazioni.
- I noleggiatori e gli altri utenti senza ruolo `admin` mantengono menu e presentazione precedenti. Le nuove sezioni sono bloccate anche accedendo direttamente agli URL o inviando richieste di modifica.
- Il sito pubblico mantiene la propria interfaccia, anche in presenza di una sessione ERA.
- Autorizzazioni sui dati, controlli di disponibilità, gestione dei contratti e protezioni dei pagamenti rimangono condivisi. Un pagamento online in attesa impedisce modifiche al noleggio anche dall'interfaccia precedente; il noleggiatore viene indirizzato all'amministratore.

In questa fase l'admin gestisce quindi anche la configurazione delle consegne per conto dei noleggiatori. Non sono richieste nuove variabili `.env` per scegliere l'interfaccia.

## Implementazione e manutenzione

`App\Support\EraInterface` seleziona la vista ad ogni rendering, anche per Livewire. La selezione non viene memorizzata nel risolutore delle viste: cambiare utente non deve riutilizzare la presentazione dell'altro ruolo.

Le viste in `resources/views/era-classic`, il menu `config/era_classic_menu.php` e il foglio `public/era-classic/app-6If3uo48.css` derivano da `master` al commit `275cb4c7342a2d614fa9628b954789591ca83921`. Il CSS precedente è conservato separatamente perché le due versioni utilizzano versioni diverse di DaisyUI. `compatibility.css` contiene soltanto correzioni per menu e filtri su schermi stretti; il JavaScript applicativo resta condiviso.

Le viste di azioni, storico pagamenti e dati contratto restano condivise per conservare le protezioni Stripe e gli indirizzi concordati. Anche i miglioramenti funzionali della ricerca contratti e del riconoscimento delle checklist firmate restano attivi. Il campo descrittivo storico del veicolo non modifica l'associazione al tipo pubblico, riservata all'admin.

Non duplicare controller o servizi dentro la presentazione precedente. Le nuove funzioni gestionali devono avere il middleware `RequireModernEra` o una verifica equivalente lato server, oltre alle verifiche dei permessi esistenti.

## Verifiche

Il comando `php tests/isolated-interface-checks.php` esegue PHPUnit senza `.env`, con SQLite in memoria, cache e sessioni di prova, email in memoria e viste compilate separate. I test che richiedono MySQL vengono saltati da questo comando: vanno eseguiti su un'istanza temporanea con database dedicato, mai su un database operativo.

Controllati nel browser separato a 1440 e 390 pixel: dashboard, veicoli, sedi, noleggi, creazione bozza, profilo, pagine AMD Rent e accessi vietati. Provati ricerca Livewire, menu mobile, schede del contratto, apertura/chiusura della finestra pagamenti e salvataggio/ripristino della commissione su dati sintetici. Compilazione Vite riuscita con destinazione temporanea, preservando gli asset locali già presenti.

Esito: 415 test SQLite e 136 test su un'istanza MySQL 8.0 temporanea superati, per 551 test distinti. Le 42 combinazioni pagina/ruolo/larghezza controllate nel browser non hanno mostrato errori HTTP inattesi, errori JavaScript o overflow della pagina; le tabelle larghe conservano lo scorrimento nel proprio contenitore. Verificate inoltre 14 aperture delle schede contratto tramite Livewire.

La separazione per ruolo non sostituisce il collaudo di rilascio: il backend aggiornato e le migrazioni AMD Rent già presenti nel branch devono essere verificati insieme su una copia del database prima del deploy. Questo intervento non aggiunge migrazioni e non certifica ancora la configurazione di produzione o i pagamenti Stripe reali.

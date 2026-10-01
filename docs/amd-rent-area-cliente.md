# Area cliente AMD Rent

L’area cliente si apre da “Area cliente” nella navigazione pubblica, all’indirizzo `/area-cliente`. Usa lo stesso dominio configurato per AMD Rent, anche quando è diverso da quello del gestionale.

## Funzioni

- Registrazione facoltativa, accesso, verifica email, recupero e modifica password.
- Prenotazioni effettuate sul sito: stato, pagamento online, eventuali rimborsi, saldo previsto al ritiro e conferma PDF.
- Richieste di consegna personalizzata e pratiche di lungo termine: stato e preventivi pubblicati.
- Documenti delle pratiche condivisi espressamente dal gestionale e invio dei documenti richiesti per il lungo termine.
- Modifica dei recapiti dell’account. Cambiare email richiede la password attuale e una nuova verifica; i contratti già emessi non vengono modificati.
- Pulsante mostra/nascondi password in tutti i campi password, anche nei moduli di admin e noleggiatori. Funziona da tastiera e nei componenti dinamici.

La prenotazione senza account resta disponibile. Registrandosi con l’email usata per prenotare, il cliente ritrova i record dopo aver verificato quell’indirizzo. Una prenotazione già assegnata a un account non viene trasferita automaticamente a un altro account.

## Separazione degli accessi

Gli account pubblici sono nella tabella `public_customers`, con guard e recupero password dedicati. Non vengono creati utenti del gestionale, ruoli admin/noleggiatore o anagrafiche operative alla registrazione. Il cliente non può accedere alle liste clienti, alle flotte o alle pratiche altrui.

Le pagine dell’account richiedono autenticazione e verifica email prima di mostrare prenotazioni o documenti. I download controllano sia il proprietario della pratica sia la visibilità del documento; i file rimangono nel disco privato `amd_rent_private`. I collegamenti firmati alle conferme già emesse continuano a funzionare.

Admin e noleggiatore assegnato possono usare “Condividi con il cliente” o “Nascondi al cliente” nella pratica. I documenti già presenti e i nuovi caricamenti dal gestionale sono riservati per impostazione predefinita, salvo selezione esplicita. I documenti caricati dal cliente sono visibili al cliente stesso e a chi gestisce la pratica. Commissioni interne e storico operativo non vengono mostrati nell’area pubblica.

Il saldo indicato è quello previsto al ritiro secondo la prenotazione. Non è un estratto conto degli incassi registrati successivamente nel contratto. Le cancellazioni e i rimborsi continuano a essere gestiti con l’assistenza, secondo le condizioni applicabili alla prenotazione.

## Attivazione

La migrazione `2026_09_25_100000_create_public_customer_accounts` crea gli account e i token di recupero separati e aggiunge i collegamenti alle prenotazioni, alle pratiche e ai documenti. Presuppone le migrazioni AMD Rent precedenti.

Il database operativo non è stato migrato durante lo sviluppo: la migrazione resta da eseguire dall’operatore, come concordato.

```sh
php artisan migrate --force
php artisan optimize:clear
```

Per lo sviluppo locale è sufficiente `php artisan migrate`, seguito da `php artisan optimize:clear` se erano presenti cache.

La registrazione richiede un servizio email funzionante. Controllare la configurazione `MAIL_*` già usata dall’applicazione, compreso un mittente autorizzato: un trasporto `log` o `array` non consegna le email agli utenti. Le email dell’area cliente hanno nome mittente e contenuto AMD Rent. Verifica e recupero usano collegamenti validi per 60 minuti.

Se il portale usa un dominio dedicato, configurare `AMD_RENT_DOMAIN` e HTTPS prima di inviare le email; i collegamenti devono puntare al dominio pubblico. Non abilitare cookie di sessione su domini non controllati. Il sito del gestionale mantiene i propri percorsi di accesso.

Gli asset compilati in `public/build` includono i nuovi moduli. Non servono nuove dipendenze Composer. In caso di installazioni con autoload autorevole, rigenerare l’autoload con le opzioni coerenti con i pacchetti effettivamente installati.

## Verifiche

I test `PublicCustomerAccountTest` coprono accessi separati, verifica email, collegamento delle prenotazioni ospite, controlli di proprietà, cambio email/password, recupero accesso, documenti privati e condivisione limitata al noleggiatore assegnato. `PublicPortalDomainTest` verifica anche la separazione dei domini.

I test devono usare un database isolato, mai quello operativo. La migrazione è stata provata anche su MySQL di collaudo. I test nel browser usano email simulate e non inviano messaggi né effettuano pagamenti reali.

Il rollback della migrazione conserva i file e i record dei documenti, lasciando `uploaded_by` nullable per i caricamenti effettuati dai clienti. Rimuove invece account, token e collegamenti introdotti dalla migrazione: eseguire un backup prima di un eventuale rollback.

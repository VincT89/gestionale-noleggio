# Pulizia dei dati dimostrativi AMD Rent

Il comando `amd-rent:demo-cleanup` opera su un solo noleggiatore DEMO, indicato tramite ID. Non viene avviato da migrazioni, seeder o operazioni di pubblicazione. Senza `--apply` esegue soltanto letture.

Negli esempi, sostituire `ID_DEMO` con l’ID verificato nell’ambiente di destinazione. Nel campo Artisan di Plesk che contiene già il prefisso `php artisan`, inserire soltanto la parte successiva a quel prefisso.

## Comandi su Plesk per DEMO AMD Rent

Il destinatario previsto per questa demo è **DEMO AMD Rent, ID 14**. Prima dell’utilizzo, il nuovo comando deve essere già pubblicato su `amdrent.it` dal ramo `dev_modifiche`.

Aprire **Plesk → amdrent.it → Laravel Toolkit → Artisan**. I comandi seguenti sono già scritti senza il prefisso `php artisan`, presente nel campo del pannello.

Per controllare l’anteprima senza modificare dati o file:

```text
amd-rent:demo-cleanup 14
```

Verificare che il riepilogo indichi **14 — DEMO AMD Rent**, il sito e il database corretti, gli ID previsti e nessun motivo di blocco. Conservare il riepilogo e copiare il valore completo indicato come **Impronta**.

Solo dopo il via libera del cliente e un backup recente del database condiviso e delle foto, per applicare la pulizia:

```text
amd-rent:demo-cleanup 14 --apply --confirm=IMPRONTA
```

Sostituire `IMPRONTA` con il valore completo restituito dall’anteprima appena verificata. Il testo `IMPRONTA` è un segnaposto, non un valore da lasciare nel comando. Se l’anteprima segnala blocchi o cambia, fermarsi e verificare il nuovo riepilogo.

La pulizia riguarda le copie demo riconosciute del noleggiatore 14. Il profilo DEMO viene conservato e disattivato; gli utenti vengono conservati. Eventuali fake senza contrassegni e foto nello storage separato di ERA richiedono un controllo distinto. La pulizia del database condiviso non va ripetuta automaticamente dall’altro dominio.

Per scegliere invece la sola disattivazione, conservando tutte le copie e le foto, generare prima l’anteprima specifica:

```text
amd-rent:demo-cleanup 14 --deactivate
```

Dopo il controllo e la conferma dell’operazione, usare l’impronta di questa anteprima:

```text
amd-rent:demo-cleanup 14 --deactivate --apply --confirm=IMPRONTA
```

Le impronte delle due operazioni sono diverse: quella della pulizia non può confermare la sola disattivazione, e viceversa.

## Anteprima

```text
php artisan amd-rent:demo-cleanup ID_DEMO
```

Mostra sito, database, destinatario, numero e ID dei record riconosciuti, riferimenti alle fotografie e motivi di eventuale blocco. Restituisce un’impronta da usare soltanto dopo aver controllato il riepilogo.

Le copie devono avere i contrassegni creati dalle procedure `demo-fleet` e `demo-insurance`. Il nome “DEMO” da solo non autorizza la cancellazione di auto o fotografie. Vecchi dati di prova inseriti manualmente e senza provenienza verificabile richiedono un esame separato.

## Disattivazione

```text
php artisan amd-rent:demo-cleanup ID_DEMO --deactivate
```

Anche questa è un’anteprima. Propone soltanto di disattivare il profilo DEMO, conservando veicoli, listini, account e fotografie. Il catalogo pubblico esclude i noleggiatori disattivati.

## Applicazione futura

Dopo il via libera del cliente, effettuare un backup recente del database condiviso e degli storage coinvolti, conservare il riepilogo e controllare tutti gli ID. Ripetere esattamente l’anteprima scelta aggiungendo:

```text
--apply --confirm=IMPRONTA
```

L’impronta deve corrispondere all’anteprima corrente, al tipo di operazione, al sito, al database, ai collegamenti e al contenuto delle foto. Se qualcosa è cambiato, occorre una nuova anteprima.

La pulizia completa rimuove le copie riconosciute di auto, assegnazioni, stati iniziali, listini e relative stagioni e fasce, sedi, collegamenti di consegna, punti di ritiro, segmenti, protezioni, accessori, eventuali offerte e fotografie verificate. Il profilo DEMO viene conservato e disattivato; gli utenti vengono conservati. Gli account creati da `DomainDemoSeeder`, i ruoli e le tabelle CARGOS non vengono classificati come dati fake.

Prenotazioni, noleggi anche archiviati, clienti, pratiche, documenti, dati operativi, collegamenti esterni o condivisi impediscono l’operazione. Vengono controllati anche i vincoli del database e i riferimenti nei riepiloghi storici. Motori di tabella non transazionali e trigger personalizzati sulle tabelle da modificare bloccano l’applicazione automatica.

## Fotografie e due domini

Il database può essere condiviso da ERA e dal pubblico, mentre i file possono risiedere in storage distinti. Il comando verifica esclusivamente lo storage locale dell’installazione da cui viene avviato. Un file principale mancante, uno storage remoto, percorsi non standard, collegamenti simbolici o cartelle utilizzate da altre foto bloccano la rimozione automatica delle copie. La sola disattivazione non richiede la cancellazione dei file.

Prima della pulizia definitiva verificare su Plesk come sono collegati gli storage dei due domini. Conservare gli ID, i dischi e i percorsi relativi mostrati dal riepilogo: eventuali altre copie fisiche dei file richiedono una verifica separata. Le immagini generiche dei modelli non vengono rimosse.

I file non vengono cancellati durante la transazione che rimuove i record della flotta. Dopo il commit viene eliminato soltanto l’elenco esatto delle foto verificate, controllandone di nuovo percorso e contenuto. Non vengono eseguite cancellazioni ricorsive generiche.

Se una foto non può essere eliminata, il profilo resta disattivato e i riferimenti alle foto ancora da completare vengono conservati nei metadati media con un’impronta firmata. Ripetere prima l’anteprima sullo stesso sito e poi, solo dopo il controllo, applicarla con la nuova impronta. File modificati o aggiunti, metadati alterati o un ambiente diverso richiedono una verifica manuale. Un errore sulle fotografie non va interpretato come un ripristino automatico dei record della flotta già rimossi.

## Collaudo locale

I test usano il runner `tests/isolated-booking-checks.php`, che evita il vero `.env`, e fixture SQLite in memoria con fotografie temporanee. Le cancellazioni simulate non operano sul database ERA o sui file dei siti online.

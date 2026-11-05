# Località pubbliche AMD Rent

Aggiornamento del 24 settembre 2026 su `dev_vincenzo`.

La sezione «Da dove vuoi partire?» presenta una fotografia a sinistra e un testo generale a destra, con informazioni sulla ricerca, sul confronto delle auto e sul pagamento. Il box con l’elenco dei luoghi è stato eliminato. Il pulsante «Trova la tua auto» porta al modulo di ricerca. Su smartphone la fotografia precede il testo.

Il selettore della ricerca presenta le città e i luoghi configurati come aeroporti, stazioni o zone. I nomi delle sedi dei garage non compaiono nell’elenco. Ogni città compare una sola volta, anche se più noleggiatori hanno punti di ritiro attivi nella stessa città.

«Tutti i punti di ritiro» cerca le auto presso i punti attivi della città. La ricerca di un aeroporto, una stazione o una zona rimane circoscritta al luogo selezionato. La disponibilità e la scelta dell’auto meno cara per prodotto continuano a essere controllate sulle date richieste.

La scelta pubblica viene trasformata nei filtri esistenti `city` o `place_id`. Una nuova scelta sostituisce quella precedente. Il dettaglio conserva la città cercata e il collegamento alla prenotazione identifica il punto concreto dell’auto scelta, evitando di passare alla sede di un’altra città.

Le sedi importate come «Punto di ritiro» contribuiscono alla ricerca per città. Non vengono riclassificate automaticamente come aeroporti o stazioni in base al nome. La classificazione rimane quella configurata nel gestionale. I precedenti collegamenti a un punto preciso continuano a funzionare; nel selettore iniziale il nome viene mostrato in forma generica.

L’intervento riguarda la scelta della località. Le informazioni del noleggiatore nei risultati, nel dettaglio e nei documenti non sono state nascoste. Nessuna modifica alle anagrafiche o al database operativo e nessuna nuova migrazione.

## Verifica

- 60 test automatici superati, con 535 verifiche, su SQLite in memoria e cache separate.
- Dopo la sostituzione del box con fotografia e testo, ricontrollati i 22 test della ricerca e delle località: 194 verifiche superate.
- Build pubblica completata.
- Controllati nel browser desktop a 1440 pixel e smartphone a 390 e 320 pixel: foto a sinistra e testo a destra, disposizione verticale su telefono, assenza del box, collegamento al modulo, selezione della città, ricerca, filtri e mantenimento del punto di ritiro nel modulo prenotazione.
- Provata la selezione dell’aeroporto da tastiera e la ricerca per città senza JavaScript.
- Nessun errore JavaScript, risposta HTTP di errore o fuoriuscita orizzontale nelle pagine verificate.

Le verifiche nel browser usano i dati dimostrativi del database di collaudo separato. Non sono stati effettuati pagamenti o inviate prenotazioni.

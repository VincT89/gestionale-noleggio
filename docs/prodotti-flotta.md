# Prodotti della flotta e offerta meno cara

L’admin trova **Prodotti della flotta** nel menu del gestionale. Può creare un prodotto (per esempio Panda), modificarne il nome e associare le auto della flotta cercando per targa, marca o modello. L’ID viene generato dal database e non cambia quando il prodotto viene rinominato. La descrizione è interna al gestionale.

Un’auto appartiene al massimo a un prodotto. Le selezioni aggiungono auto senza rimuovere quelle già presenti, anche se non visibili nella pagina corrente. Per spostare un’auto bisogna rimuovere la precedente associazione. L’operazione non cancella il veicolo e non modifica listini, assegnazioni o noleggi. I renter non possono gestire i prodotti, neppure richiamando direttamente gli indirizzi delle operazioni.

La ricerca pubblica controlla luogo servito, disponibilità della singola auto, listino e filtri prima di scegliere l’offerta con il totale più basso per ciascun prodotto. Il confronto usa il preventivo dell’intero periodo, comprensivo delle regole del listino, con cauzione separata. A parità di totale viene scelta il listino con ID minore, per avere un risultato stabile. L’ordinamento crescente o decrescente viene applicato dopo la scelta dell’offerta meno cara. Anche la paginazione conta i risultati già raggruppati.

Foto, caratteristiche, condizioni, prezzo e noleggiatore appartengono tutti alla stessa offerta selezionata. La prenotazione mantiene l’offerta e l’auto concreta e ne ricontrolla disponibilità e prezzo. Se l’auto meno cara viene occupata, una nuova ricerca mostra la successiva disponibile; non viene sostituita silenziosamente un’auto durante la conferma di un preventivo già aperto.

Le auto senza prodotto compaiono singolarmente usando i propri listini attivi. Non viene eseguita un’associazione automatica in base ai nomi dei modelli. Un prodotto vuoto non compare tra i risultati. Non serve creare un’offerta: occorrono copertura del luogo, listino attivo e disponibilità effettiva.

## Attivazione

La modifica richiede la migrazione `2026_09_15_120000_create_vehicle_products_table`: crea il catalogo e aggiunge un collegamento facoltativo ai veicoli, senza raggruppare o riscrivere i dati esistenti. Eseguire le migrazioni prima di usare il nuovo codice di ricerca. Come per ogni aggiornamento dello schema, usare la normale procedura di backup del database prima del rilascio.

Non servono nuove librerie Composer o pacchetti npm. Gli asset compilati devono essere distribuiti con il codice. Il dominio dedicato AMD Rent resta una configurazione separata.

## Verifiche

`FleetProductsTest` verifica autorizzazioni, creazione e rinomina con ID stabile, nomi duplicati, associazioni multiple e ripetute, conflitti, auto archiviate, rimozione delle associazioni, conservazione di listini e noleggi e protezione dei dati mostrati.

`PublicProductSearchTest` verifica offerta minima per prodotto, disponibilità, luoghi serviti, filtri, prezzo totale del periodo, ordinamento, paginazione, parità di prezzo, anteprima renter e prenotazione dell’auto selezionata con successivo passaggio alla prossima disponibile nella ricerca.

Le verifiche usano database di collaudo separati. La migrazione non viene applicata automaticamente al database operativo locale o al server di produzione.

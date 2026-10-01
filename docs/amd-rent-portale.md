# Portale pubblico AMD Rent

Per pagamenti Stripe, consegne su richiesta e pratiche di lungo termine, vedere [AMD Rent: operazioni](amd-rent-operazioni.md). Queste funzionalità aggiungono una migrazione e nuovi requisiti di configurazione.

AMD Rent ha un’identità e una navigazione proprie: ricerca auto, informazioni sulla prenotazione, e assistenza. Logo, titoli, intestazione, footer e conferme PDF usano il nuovo marchio. I nomi reali dei noleggiatori nelle offerte restano i loro.

Il portale usa la stessa applicazione Laravel e gli stessi servizi di disponibilità e prenotazione del gestionale. Non è stata creata una seconda applicazione né una copia dei dati. Un dominio dedicato può servire il sito pubblico mentre il gestionale continua sul proprio indirizzo.

## Dominio e accessi

Con `AMD_RENT_DOMAIN` vuoto resta disponibile `/cerca-auto`, utile per il lavoro locale. Configurando il dominio pubblico, la ricerca diventa la sua pagina iniziale `/`; dettaglio auto, prenotazione e pagine informative usano quel dominio. I collegamenti generati nel gestionale alle conferme e ai PDF puntano al sito AMD Rent.

Sul dominio pubblico sono consentite soltanto le rotte del portale: accessi amministrativi, API del gestionale, profilo, aggiornamenti Livewire e anteprime interne restituiscono 404. L’accesso dei noleggiatori rimane sul dominio del gestionale e non compare nella navigazione pubblica.

Le conferme firmate già emesse sul precedente indirizzo restano utilizzabili: dopo la verifica della firma vengono reindirizzate alla corrispondente pagina o al PDF AMD Rent. Un link senza firma valida non permette il reindirizzamento né l’accesso ai dati. Per mantenere validi quei link deve restare invariata la chiave applicativa.

Il dominio esatto non è stato comunicato: non è stato configurato alcun hostname, DNS o virtual host reale.

## Configurazione da completare per l’apertura

Le variabili sono documentate in `.env.example`. Il file `.env` operativo non è stato modificato.

| Variabile | Valore da fornire |
| --- | --- |
| `AMD_RENT_DOMAIN` | Host del sito pubblico, senza protocollo, porta o percorso; diverso dal dominio del gestionale |
| `AMD_RENT_MANAGEMENT_URL` | URL completo del gestionale, senza `/login`; in assenza usa `APP_URL` |
| `AMD_RENT_CONTACT_EMAIL` | Email confermata dell’assistenza AMD Rent |
| `AMD_RENT_CONTACT_PHONE` | Telefono confermato dell’assistenza AMD Rent |
| `AMD_RENT_PRIVACY_URL` | URL dell’informativa applicabile al portale |
| `AMD_RENT_LEGAL_NOTICE` | Ragione sociale e dati identificativi confermati del gestore del portale |

Non vengono riutilizzati automaticamente recapiti o dati societari di AMD Mobility. In assenza di contatti, la pagina assistenza dichiara che devono ancora essere pubblicati. Privacy e dati societari compaiono soltanto dopo la configurazione. Il portale mantiene `noindex` durante questa preparazione.

L’attivazione del dominio richiede HTTPS sui due indirizzi e il virtual host pubblico diretto alla cartella Laravel `public`. Non va esposta la cartella radice del progetto. Mantenere i cookie di sessione limitati al rispettivo host (`SESSION_DOMAIN` vuoto/null), senza allargarli ai due siti. `APP_URL` e `APP_NAME` continuano a identificare il gestionale; il marchio pubblico è indipendente.

Questa modifica dell’identità non introduce migrazioni. Restano necessarie le migrazioni del catalogo, delle prenotazioni e dei luoghi di consegna già previste dal lavoro precedente. Gli asset compilati sono inclusi nel progetto. Non sono stati eseguiti push o deploy.

## Logo

Versione derivata dal logo AMD Mobility esistente con lo strumento integrato `imagegen`, su richiesta dell’utente. Mantiene la A stilizzata, il tratto curvo e il motto «Muoversi in Sicurezza!», sostituendo Mobility con Rent:

- File: `public/images/amd-rent-logo-v2.png`.
- PNG su fondo bianco, 1937 × 812 pixel, adatto alle intestazioni bianche del portale e dei PDF.
- Prompt completo: [amd-rent-logo-v2-prompt.txt](amd-rent-logo-v2-prompt.txt).
- Uso: navbar del portale e intestazione delle conferme PDF.

## Verifiche

`PublicPortalDomainTest` copre pagina iniziale, navigazione, separazione dei domini, accessi interni esclusi anche per utenti autenticati, prenotazione, firme delle conferme/PDF, collegamenti preparati dal gestionale e compatibilità con le conferme precedenti.

I controlli esistenti continuano a verificare corrispondenza tra luogo e noleggiatore, calendario, prezzi, doppie prenotazioni e permessi. Il collaudo nel browser ha usato soltanto un database di prova e dati dichiaratamente dimostrativi. Comprende desktop, telefono, larghezze di 320/900/901 pixel, navigazione senza JavaScript, selezione del luogo da tastiera, conferma della prenotazione, download e controllo visivo del PDF.

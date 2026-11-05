# Homepage AMD Rent

La homepage mantiene il blu del logo e mette in primo piano la ricerca. La foto con l’auto AMD Rent apre la pagina; gli sfondi costieri delle sezioni e delle intestazioni proseguono nelle pagine interne. Risultati, scheda auto e prenotazione conservano superfici leggibili per confrontare prezzi e condizioni. Il trattamento attuale è documentato in [Sfondi del sito pubblico](amd-rent-sfondi-pubblici.md).

I luoghi sono quelli configurati e attivi nel gestionale. Le informazioni prima della prenotazione spiegano la corrispondenza fra luogo e disponibilità, il confronto per prezzo totale, la cauzione separata, il chilometraggio, il 20% online e il saldo al ritiro, l’assenza di un account obbligatorio, la riconsegna nello stesso luogo e la conferma con PDF. Non sono stati aggiunti servizi, recapiti, tariffe dimostrative o promesse di cancellazione gratuita.

Le risposte espandibili usano elementi HTML `details` e funzionano anche senza JavaScript. Rimane disponibile anche la ricerca con selezione nativa del luogo quando JavaScript è disattivato.

## Fotografia

Immagine illustrativa creata con lo strumento integrato **imagegen**. Non è una fotografia della flotta né la rappresentazione di uno specifico luogo servito. La dicitura sulla foto è stata rimossa su richiesta. L’immagine originale è stata conservata; i file WebP sono versioni ottimizzate della medesima composizione, senza modifiche a soggetti o colori.

Asset distribuiti nel progetto:

- `public/images/amd-rent-coastal-drive-640.webp`: 640 × 267, circa 34 KB.
- `public/images/amd-rent-coastal-drive-1200.webp`: 1200 × 500, circa 113 KB.
- `public/images/amd-rent-coastal-drive-1942.webp`: 1942 × 809, circa 255 KB.

Il browser sceglie la dimensione tramite `srcset`. Le dimensioni esplicite riservano lo spazio dell’immagine; il modulo di ricerca rimane su fondo bianco e il titolo su fondo blu uniforme.

Prompt finale usato con imagegen:

```text
Use case: photorealistic-natural. Asset type: wide photographic background for the homepage of AMD Rent, an Italian car rental search and booking website, not a UI mockup. Create a tasteful editorial travel photograph: an unbranded contemporary pearl-white compact five-door hatchback safely parked in a paved panoramic pull-off beside a quiet southern Italian Adriatic coastal road, low pale limestone walls, Mediterranean scrub, broad blue sea and a distant natural coastline, clear soft late-afternoon daylight, calm and believable. No identifiable landmark, no company premises or advertised destination. Landscape panoramic composition approximately 2.4:1, high resolution. Camera slightly above eye-level, restrained natural colors, navy and blue sea, limestone and pale sky. The car and most visually interesting landscape must be in the right half, the car roughly at x=70%, y=60%, small enough to leave generous landscape around it and fully visible with all four wheels plausible, three-quarter front view angled left. Left 45% mostly uncluttered sea and coastal atmosphere: website will put a solid navy text panel over that portion, and a white search form will overlap the lower part. Keep the car above the bottom 20%. Clean premium natural photographic realism, crisp but not HDR, not luxury or racing, no artificial lens flare. No people, no text, no badges, no logos, no watermarks, no graphic gradients, no collage, no UI elements. This is an illustrative travel background, not an image of the actual rental fleet.
```

## Verifica

Le verifiche automatiche e le prove nel browser usano cache, sessioni e database di collaudo separati. Non richiede nuove migrazioni o nuove dipendenze. Gli asset di Vite e il relativo manifest sono inclusi nel progetto.

Verifica del 16 settembre 2026: build completata; 64 test pertinenti superati (607 asserzioni); ricerca, filtri, ordinamento, dettaglio, modulo di prenotazione e domande espandibili provati a sette larghezze fra 320 e 1440 pixel. Verificate anche la navigazione da tastiera e la ricerca senza JavaScript.

## Aggiornamento del 24 settembre 2026

La homepage usa sezioni a tutta larghezza: blu del marchio per ricerca e fotografia, sabbia (`#f1ece2`) per i luoghi di ritiro, azzurro chiaro (`#e6eff4`) per le informazioni prima della prenotazione. I campi della ricerca conservano lo sfondo bianco per la leggibilità. I luoghi provengono ancora esclusivamente dal gestionale e sono raggruppati per città; nessun luogo o prezzo è aggiunto a scopo grafico.

Il footer blu profondo (`#102d4b`) raccoglie ricerca, luoghi, prezzi e condizioni, conferma e ritiro, domande frequenti e assistenza. Email, telefono, privacy e dati legali vengono mostrati soltanto quando configurati. Le pagine Come funziona e Assistenza condividono la palette, con collegamenti alle sezioni interne. Non sono aggiunti accessi al gestionale nel portale pubblico.

La foto originale è stata modificata con lo strumento integrato **imagegen**, aggiungendo soltanto la scritta «AMD Rent» in blu sullo sportello visibile. Non viene ripristinata la dicitura sulla foto precedentemente rimossa. Gli asset precedenti sono conservati.

Verifiche dell’aggiornamento: build completata; 64 test superati (607 asserzioni) in ambiente isolato; flusso di ricerca, filtri, dettaglio, modulo di prenotazione e FAQ controllato a sette larghezze, anche senza JavaScript. Controllati anche i collegamenti del nuovo footer e la selezione del luogo dai link della homepage su desktop e smartphone. Le anteprime usano esclusivamente dati di collaudo.

Asset finali della foto con scritta:

- `public/images/amd-rent-coastal-drive-branded-640.webp`: 640 × 267, 38.270 byte.
- `public/images/amd-rent-coastal-drive-branded-1200.webp`: 1200 × 500, 119.780 byte.
- `public/images/amd-rent-coastal-drive-branded-1942.webp`: 1942 × 809, 245.200 byte.

Prompt finale dell’edit, in modalità integrata:

```text
Use case: precise-object-edit. Asset type: existing AMD Rent homepage travel photograph. Input image 1 is the EDIT TARGET. Make exactly one localized change: add the lettering "AMD Rent" in deep navy blue (#183b64) on the visible front passenger-side door of the white compact car, below the window and door handle, centered on that door panel. Text must read exactly "AMD Rent", with uppercase AMD and capital R followed by lowercase ent. Use clean bold sans-serif lettering resembling a professionally applied car rental vinyl decal. Keep it large enough to read, but entirely on the front door without crossing the wheel arch, door edges or windows. Match the perspective, curvature, reflections and natural shading of the car door. Preserve the original panoramic composition, all surroundings, coastline, sea, sky, lighting, car position, car shape, scale, paint, wheels and framing. Do not add any other text, slogans, people, logos, graphics or watermarks. Keep the image photorealistic and as close as possible to the original. Output the same wide aspect ratio as the input.
```

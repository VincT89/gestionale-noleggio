# Fotografie e superfici del sito pubblico AMD Rent

Aggiornamento del 24 settembre 2026 su `dev_vincenzo`.

## Impostazione grafica

Le fotografie occupano riquadri precisi collegati al contenuto. La foto con la scritta AMD Rent resta in apertura; una strada costiera accompagna i luoghi di ritiro; un’auto davanti a un ingresso di hotel accompagna le informazioni sulla consegna personalizzata.

Ogni pagina e sezione ha una fotografia di contesto diversa. Come funziona mostra il passaggio delle chiavi; Assistenza cuffie e computer; Lungo termine chiavi, taccuino e telefono; la conferma della prenotazione bagagli pronti; il riepilogo della pratica di lungo termine una cartellina con documenti; il riepilogo della consegna personalizzata un’auto davanti a un ingresso in città. Le fotografie della homepage restano riservate alle rispettive sezioni. Nei risultati, nel dettaglio e nel modulo prenotazione si mantiene invece la foto del veicolo selezionato: rappresenta la stessa auto lungo il percorso di acquisto. L’assenza di una foto reale o indicativa del modello non viene mascherata usando una foto generica.

Testi, menu, moduli e footer hanno superfici piene. Il disegno ripetuto è stato rimosso dal sito. La palette usa il blu AMD Rent, il bianco e un grigio neutro leggero, senza fondi beige, velature fotografiche o immagini dietro ai campi.

Le fotografie di contesto sono scene generate: non documentano una sede, un hotel convenzionato o un’auto effettivamente disponibile. I luoghi di ritiro e i risultati della ricerca provengono dal gestionale. La consegna personalizzata rimane soggetta alla disponibilità del servizio e alla conferma del supplemento.

## Immagini nel progetto

Generazione eseguita con lo strumento integrato **imagegen**, senza CLI o API esterne. Gli originali sono conservati e le copie WebP sono state ridimensionate e compresse senza modificare la scena.

| Impiego | File salvato | Dimensioni | Byte |
| --- | --- | --- | --- |
| Strada costiera | [amd-rent-coastal-background-1536.webp](C:/Users/vince/OneDrive/Desktop/SODANO/ADM-ERA/public/images/amd-rent-coastal-background-1536.webp) | 1536 × 1024 | 223.122 |
| Strada costiera, piccola | [amd-rent-coastal-background-640.webp](C:/Users/vince/OneDrive/Desktop/SODANO/ADM-ERA/public/images/amd-rent-coastal-background-640.webp) | 640 × 426 | 59.958 |
| Consegna in hotel | [amd-rent-hotel-delivery-1200.webp](C:/Users/vince/OneDrive/Desktop/SODANO/ADM-ERA/public/images/amd-rent-hotel-delivery-1200.webp) | 1200 × 800 | 123.328 |
| Consegna in hotel, piccola | [amd-rent-hotel-delivery-640.webp](C:/Users/vince/OneDrive/Desktop/SODANO/ADM-ERA/public/images/amd-rent-hotel-delivery-640.webp) | 640 × 427 | 47.622 |
| Preparazione del noleggio | [amd-rent-rental-planning-1200.webp](C:/Users/vince/OneDrive/Desktop/SODANO/ADM-ERA/public/images/amd-rent-rental-planning-1200.webp) | 1200 × 800 | 52.594 |
| Preparazione del noleggio, piccola | [amd-rent-rental-planning-640.webp](C:/Users/vince/OneDrive/Desktop/SODANO/ADM-ERA/public/images/amd-rent-rental-planning-640.webp) | 640 × 427 | 23.168 |

Le fotografie usano `srcset`, `sizes`, dimensioni riservate, caricamento differito e descrizioni alternative. La fotografia della strada costiera occupa la colonna sinistra della sezione introduttiva, accanto al testo, con altezza di 380 pixel su desktop e 220 su telefono. La fotografia della consegna rimane nel suo riquadro di 220 pixel; le intestazioni delle pagine interne usano riquadri di 180 pixel, ridotti a 160 pixel su telefono. La foto principale mantiene i suoi file esistenti.

## Prompt della strada costiera

```text
Use case: photorealistic-natural. Asset type: photographic background for the AMD Rent public car-rental website, to enrich section backgrounds and narrow page-heading crops, not a website mockup. Create one high-resolution landscape editorial aerial photograph of an unidentifiable Mediterranean limestone coast with a quiet curving paved coastal road. Composition: approximately 3:2 landscape; broad calm blue sea occupies the left two thirds and upper area with very subtle natural ripples and open space; a narrow warm pale limestone shoreline with restrained olive-green scrub curves along the right third; a believable two-lane asphalt road follows the coast at the far right, with low stone retaining walls. The shoreline and road create a natural sense of a journey. Soft clear morning light, refined natural colors: muted deep navy and powder blue water, warm ivory stone and sand, modest sage foliage. Natural photographic detail, calm, realistic, low visual clutter, not over-saturated, no HDR or hard sunlight. This is an atmospheric illustration of travel, not a real destination offered by the service. No car (the site already has its branded car photograph), no people, no boats, no hotels, no buildings, no recognizable landmark, no text, no logos, no watermark, no map symbols, no UI elements, no added graphic gradients.
```

## Prompt della consegna in hotel

```text
Use case: photorealistic-natural. Asset type: a restrained editorial photograph inside a small website content panel for AMD Rent car rental, accompanying information about an optional car delivery to a hotel. Create one landscape 3:2 photograph, not a website mockup. Scene: a clean white modern compact hatchback, unbranded, parked legally in a quiet arrival forecourt next to a generic Mediterranean hotel's modest entrance. Pale limestone walls, a simple shaded doorway, one small olive tree in a planter, a single upright closed travel suitcase near the passenger side. The setting is welcoming and plausible rather than a luxury resort. Natural daylight, real materials, balanced white paint and warm stone, subtle dark blue window reflections, low saturation, no color wash. Compose the car in the lower central half and the entrance in the upper half; keep the main subjects within the central 70 percent so the photograph works cropped horizontally in a 420 by 220 pixel panel. Realistic car proportions, natural perspective from standing eye level, professional travel editorial photography, no exaggerated wide angle. No people, no signs, no text, no logo, no visible registration numbers, no watermark, no interface, no graphics, no gradient. No recognizable hotel or destination. The photograph should suggest arrival and car delivery, without depicting a specific bookable property.
```

## Prompt della preparazione del noleggio

```text
Use case: photorealistic-natural. Asset type: a small editorial photograph for the AMD Rent public website, accompanying long-term rental enquiries and booking support. Create a landscape 3:2 still-life photograph of preparing a car rental: a modern unbranded car key fob, a closed plain dark navy notebook, and a smartphone with a completely blank dark screen on a clean light stone desk beside a window. The key fob in the foreground is the visual subject; the notebook and phone are secondary. A softly blurred white compact hatchback can be glimpsed through the window in the background, parked outside in daylight, with no identifiable place or signage. No people, no hands, no certificates, no readable text or numbers, no paperwork claiming an agreement, no logos, no brand emblems, no visible number plate, no watermark, no interface overlays. Calm realistic travel and professional service photography with natural daylight, accurate materials and restrained colors: navy, neutral white and light grey. Eye-level close-up, realistic proportions, not a flat icon, not a collage, not a mockup of a webpage. Keep the key and other important objects in the central 70 percent to work as a cropped 340 by 180 pixel photo panel. Natural soft focus without dramatic blur or artificial effects.
```

## Fotografie diverse per ogni pagina

Cinque nuove scene generate con lo strumento integrato imagegen. Gli originali sono conservati nella cartella locale di lavoro public-unique-photos-20260924; questi sono i file WebP installati nel progetto.

| Impiego | File salvato | Dimensioni | Byte |
| --- | --- | --- | --- |
| Come funziona: consegna delle chiavi | [amd-rent-pickup-handover-1200.webp](C:/Users/vince/OneDrive/Desktop/SODANO/ADM-ERA/public/images/amd-rent-pickup-handover-1200.webp) | 1200 × 800 | 46.838 |
| Come funziona: consegna delle chiavi, piccola | [amd-rent-pickup-handover-640.webp](C:/Users/vince/OneDrive/Desktop/SODANO/ADM-ERA/public/images/amd-rent-pickup-handover-640.webp) | 640 × 427 | 19.416 |
| Assistenza: cuffie e computer | [amd-rent-customer-support-1200.webp](C:/Users/vince/OneDrive/Desktop/SODANO/ADM-ERA/public/images/amd-rent-customer-support-1200.webp) | 1200 × 800 | 37.428 |
| Assistenza: cuffie e computer, piccola | [amd-rent-customer-support-640.webp](C:/Users/vince/OneDrive/Desktop/SODANO/ADM-ERA/public/images/amd-rent-customer-support-640.webp) | 640 × 427 | 17.606 |
| Conferma prenotazione: bagagli pronti | [amd-rent-journey-ready-1200.webp](C:/Users/vince/OneDrive/Desktop/SODANO/ADM-ERA/public/images/amd-rent-journey-ready-1200.webp) | 1200 × 800 | 164.724 |
| Conferma prenotazione: bagagli pronti, piccola | [amd-rent-journey-ready-640.webp](C:/Users/vince/OneDrive/Desktop/SODANO/ADM-ERA/public/images/amd-rent-journey-ready-640.webp) | 640 × 427 | 61.242 |
| Riepilogo pratica di lungo termine: documenti | [amd-rent-long-term-dossier-1200.webp](C:/Users/vince/OneDrive/Desktop/SODANO/ADM-ERA/public/images/amd-rent-long-term-dossier-1200.webp) | 1200 × 800 | 47.404 |
| Riepilogo pratica di lungo termine: documenti, piccola | [amd-rent-long-term-dossier-640.webp](C:/Users/vince/OneDrive/Desktop/SODANO/ADM-ERA/public/images/amd-rent-long-term-dossier-640.webp) | 640 × 427 | 18.864 |
| Riepilogo consegna personalizzata: arrivo in città | [amd-rent-city-delivery-1200.webp](C:/Users/vince/OneDrive/Desktop/SODANO/ADM-ERA/public/images/amd-rent-city-delivery-1200.webp) | 1200 × 800 | 120.936 |
| Riepilogo consegna personalizzata: arrivo in città, piccola | [amd-rent-city-delivery-640.webp](C:/Users/vince/OneDrive/Desktop/SODANO/ADM-ERA/public/images/amd-rent-city-delivery-640.webp) | 640 × 427 | 46.624 |

### Prompt: Come funziona: consegna delle chiavi

```text
Use case: photorealistic-natural. Asset type: a distinct editorial photograph for a small AMD Rent car-rental website panel, not a webpage mockup. Landscape 3:2 composition, refined natural daylight, realistic proportions and materials, restrained navy, white and neutral tones. Important subjects must fit in the central 70 percent so the image can be cropped into a 320 by 180 pixel panel. No logos, brand marks, legible words or numbers, watermark, UI overlays, collages, dramatic filters or gradient graphics. This depicts a generic context, not a specific company, employee, vehicle offer or property. Primary request: explain collecting a rental car through a close-up of a key handover. Beside a parked dark navy compact car in a bright clean pickup bay, two adult hands are visible: a rental operator wearing a pale blue cotton sleeve holds one black car key fob by the metal keyring, while a customer's naturally open hand in a cream sleeve receives it. Show just the hands and forearms, with coherent anatomy, the key between them, and the car door softly out of focus behind. No faces, no paperwork, no phone, no suitcase, no hotel entrance. Authentic candid rental handover, clear focal point on the key and the natural gesture.
```

### Prompt: Assistenza: cuffie e computer

```text
Use case: photorealistic-natural. Asset type: a distinct editorial photograph for a small AMD Rent car-rental website panel, not a webpage mockup. Landscape 3:2 composition, refined natural daylight, realistic proportions and materials, restrained navy, white and neutral tones. Important subjects must fit in the central 70 percent so the image can be cropped into a 320 by 180 pixel panel. No logos, brand marks, legible words or numbers, watermark, UI overlays, collages, dramatic filters or gradient graphics. This depicts a generic context, not a specific company, employee, vehicle offer or property. Primary request: booking assistance. Photograph a professional black over-ear telephone headset with a short boom microphone resting on a clean light desk, next to the edge of an open slim laptop. The headset is the sharply focused main subject, positioned near the center; a neutral sunlit office background is softly out of focus. The laptop display faces away or shows a blank neutral screen, with no text or interface. A small navy ceramic cup can sit far behind, without a logo. No people, no hands, no car keys, no notebooks, no phones, no fleet of cars, no signs or identifiable office. Understated credible support environment, not a staged call-center advertisement.
```

### Prompt: Conferma prenotazione: bagagli pronti

```text
Use case: photorealistic-natural. Asset type: a distinct editorial photograph for a small AMD Rent car-rental website panel, not a webpage mockup. Landscape 3:2 composition, refined natural daylight, realistic proportions and materials, restrained navy, white and neutral tones. Important subjects must fit in the central 70 percent so the image can be cropped into a 320 by 180 pixel panel. No logos, brand marks, legible words or numbers, watermark, UI overlays, collages, dramatic filters or gradient graphics. This depicts a generic context, not a specific company, employee, vehicle offer or property. Primary request: a journey ready to start, for a rental booking confirmation. Rear three-quarter close-up of a parked light silver compact hatchback with its tailgate open. One dark navy cabin suitcase and one small tan soft travel bag fit neatly inside the clean trunk; the luggage and open boot are the main subject. The car is safely parked off the road on a small paved countryside turnout, with soft green hills and trees beyond. No people, no license plate visible, no rental signs, no sea, no coast, no hotel, no documents or digital screens. Realistic scale and an unhurried sense of departure, not a car catalog image.
```

### Prompt: Riepilogo pratica di lungo termine: documenti

```text
Use case: photorealistic-natural. Asset type: a distinct editorial photograph for a small AMD Rent car-rental website panel, not a webpage mockup. Landscape 3:2 composition, refined natural daylight, realistic proportions and materials, restrained navy, white and neutral tones. Important subjects must fit in the central 70 percent so the image can be cropped into a 320 by 180 pixel panel. No logos, brand marks, legible words or numbers, watermark, UI overlays, collages, dramatic filters or gradient graphics. This depicts a generic context, not a specific company, employee, vehicle offer or property. Primary request: a long-term rental enquiry dossier under review. An open slim dark navy document folder on a neutral light wood desk, a few neatly aligned entirely blank sheets inside, a simple silver pen resting diagonally on the open folder. Close-up from a gentle oblique angle, precise paper and fabric texture, daylight from a window on the left, uncluttered composition. This is a blank generic file, not a signed contract; do not show signatures, stamps, figures, handwriting or text. No notebook, no key fob, no phone, no computer, no headset, no people, no model car. The folder and pen should provide a visually distinct image from other travel photos.
```

### Prompt: Riepilogo consegna personalizzata: arrivo in città

```text
Use case: photorealistic-natural. Asset type: a distinct editorial photograph for a small AMD Rent car-rental website panel, not a webpage mockup. Landscape 3:2 composition, refined natural daylight, realistic proportions and materials, restrained navy, white and neutral tones. Important subjects must fit in the central 70 percent so the image can be cropped into a 320 by 180 pixel panel. No logos, brand marks, legible words or numbers, watermark, UI overlays, collages, dramatic filters or gradient graphics. This depicts a generic context, not a specific company, employee, vehicle offer or property. Primary request: delivery of a rental car to a requested city address. A dark blue modern compact hatchback parked in a small legal off-street arrival bay next to a simple contemporary urban building entrance. Side three-quarter view from the pavement, the car occupies the lower middle portion, with a discreet glass doorway, pale grey stone and a single potted green plant in the background. Calm Italian urban atmosphere without recognizable landmarks or specific properties. No hotel canopy, no suitcases, no people, no text, no visible number plate, no road signs, no document folder, no keys in foreground. Clear natural daytime photography, different setting and car color from the existing white car at a Mediterranean hotel.
```

## Verifiche

Build Vite completata. Il layout è stato controllato nel browser su home, risultati, dettaglio auto, prenotazione, lungo termine, come funziona, assistenza, conferma e riepilogo di una richiesta di lungo termine, alle larghezze 1440, 768, 390 e 320 pixel.

Dopo l’assegnazione delle fotografie diverse per pagina sono state ricontrollate le stesse nove pagine a 1440 e 390 pixel. Le otto fotografie di contesto presenti in queste pagine sono tutte distinte; le immagini dei veicoli rimangono collegate all’auto selezionata. Confermato il caricamento delle immagini, comprese quelle caricate durante lo scorrimento, e controllati visivamente i ritagli su desktop e telefono. Anche il riepilogo della consegna personalizzata ha un asset dedicato diverso da tutti gli altri; questo tipo di richiesta non era presente tra i casi già disponibili nel database di collaudo.

Nessuna immagine mancante, errore JavaScript, risposta HTTP di errore o fuoriuscita orizzontale nelle pagine controllate. Provati selezione del luogo da tastiera, date, ricerca, filtri, menu mobile, FAQ, validazione obbligatoria del modulo prenotazione e selezione della consegna personalizzata. Verificati navigazione e scelta del luogo anche senza JavaScript.

Il testo piccolo verificato supera 5,57:1 sulle superfici neutre e 9,34:1 sulle superfici blu. Le foto non sono usate come fondo del testo.

Le prove usano il database di collaudo separato. Nessuna migrazione o modifica al database operativo. L’intervento riguarda presentazione e immagini e non cambia il calcolo dei prezzi o la gestione dei pagamenti.

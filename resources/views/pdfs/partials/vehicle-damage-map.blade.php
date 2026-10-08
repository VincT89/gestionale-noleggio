<div class="damage-map mb-4">
    <h3>Schema danni al rientro</h3>
    <p class="tiny">Segna sul disegno i punti danneggiati e descrivili nelle righe sotto. Schema indicativo del veicolo.</p>
    <img class="damage-map-image" src="data:image/png;base64,{{ base64_encode(file_get_contents(resource_path('images/checklist-vehicle-damage.png'))) }}" alt="Schema auto: fiancate sinistra e destra, anteriore, posteriore e vista dall'alto">
    <h3>Dettagli dei danni segnati</h3>
    <div class="damage-detail-line"></div>
    <div class="damage-detail-line"></div>
</div>

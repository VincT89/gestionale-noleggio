import '../css/public-pickup-map.css';

const maps = new Map();
const tileSize = 256;
const maxLatitude = 85.05112878;
const clamp = (value, min, max) => Math.max(min, Math.min(max, value));
const wrap = (value, size) => ((value % size) + size) % size;

// Web Mercator pixels, shared by tile placement and pointer/keyboard selection.
export function project(lat, lng, zoom) {
    const size = tileSize * 2 ** zoom;
    const sin = Math.sin(clamp(lat, -maxLatitude, maxLatitude) * Math.PI / 180);
    return { x: (lng + 180) / 360 * size, y: (.5 - Math.log((1 + sin) / (1 - sin)) / (4 * Math.PI)) * size };
}

export function unproject(x, y, zoom) {
    const size = tileSize * 2 ** zoom;
    return { lat: Math.atan(Math.sinh(Math.PI * (1 - 2 * clamp(y, 0, size) / size))) * 180 / Math.PI,
        lng: wrap(x / size * 360, 360) - 180 };
}

function mount(root) {
    const viewport = root.querySelector('[data-map-viewport]');
    const layer = root.querySelector('[data-map-tiles]');
    const pin = root.querySelector('[data-map-pin]');
    const message = root.querySelector('[data-map-status]');
    const form = root.querySelector('[data-map-confirm]');
    const isReturn = root.dataset.mapPurpose === 'return';
    const locationName = isReturn ? 'riconsegna' : 'ritiro';
    const address = form.elements[isReturn ? 'return_address' : 'delivery_address'];
    const addressStatus = root.querySelector('[data-map-address-status]');
    const confirm = form.querySelector('button[type="submit"]');
    const areaForm = root.querySelector('[data-map-area-search]');
    const areaStatus = root.querySelector('[data-map-search-status]');
    const results = root.querySelector('[data-map-area-results]');
    const images = new Map();
    const pointers = new Map();
    const initial = { lat: Number(root.dataset.initialLat || 42.5), lng: Number(root.dataset.initialLng || 12.5) };
    let center = { ...initial }, zoom = Number(root.dataset.initialZoom || 5), chosen = false;
    let gesture = null, request = null, disposed = false;
    let reverseRequest = null, reverseTimer = null, reversePending = false;
    let manualAddress = address.value, suggestedAddress = false;
    const tileUrl = root.dataset.tilesUrl;
    const validTiles = /^https:\/\//.test(tileUrl) && ['{z}', '{x}', '{y}'].every(part => tileUrl.includes(part));

    function selectionState() {
        const ready = images.size > 0 && [...images.values()].every(img => img.dataset.loaded === 'yes');
        const failed = [...images.values()].some(img => img.dataset.loaded === 'error');
        const usable = chosen && zoom >= 16 && ready && !gesture && !reversePending;
        root.dataset.mapState = usable ? 'selected' : reversePending ? 'locating' : 'choosing';
        pin.hidden = !chosen || zoom < 16;
        confirm.disabled = !usable || root.inert;
        for (const [name, value] of Object.entries({ map_lat: center.lat.toFixed(7), map_lng: center.lng.toFixed(7), map_zoom: zoom })) {
            form.elements[name].value = usable ? value : '';
        }
        const status = !validTiles || failed ? 'La mappa non è disponibile. Riaprila per riprovare oppure modifica la ricerca.'
            : !ready ? 'Caricamento della mappa…'
            : zoom < 16 ? `Ingrandisci ancora per scegliere il punto di ${locationName} sulla strada.`
            : !chosen ? `Tocca il punto di ${locationName} sulla mappa, oppure premi Invio con la mappa selezionata.`
            : reversePending ? 'Punto scelto. Cerchiamo l’indirizzo da inserire qui sotto…'
            : suggestedAddress ? 'Indirizzo aggiornato qui sotto. Controlla il civico e conferma il punto.'
            : isReturn ? 'Punto scelto. Controlla l’indirizzo e conferma la riconsegna.'
            : 'Punto scelto. Controlla l’indirizzo e conferma per vedere i noleggiatori.';
        if (message.textContent !== status) message.textContent = status;
        root.querySelector('[data-map-zoom-in]').disabled = zoom === 19;
        root.querySelector('[data-map-zoom-out]').disabled = zoom === 3;
    }

    function cancelAddress() {
        clearTimeout(reverseTimer); reverseRequest?.abort(); reverseRequest = null; reversePending = false;
        addressStatus.textContent = '';
    }

    function findAddress() {
        if (suggestedAddress) address.value = manualAddress;
        suggestedAddress = false;
        reversePending = true;
        addressStatus.textContent = 'Cerchiamo l’indirizzo del punto scelto…';
        const pending = new AbortController(); reverseRequest = pending;
        const coordinates = { lat: center.lat.toFixed(7), lng: center.lng.toFixed(7) };
        // Only an explicit point selection triggers this lookup; rapid clicks use the last point.
        reverseTimer = setTimeout(async () => {
            try {
                const body = new FormData(); body.set('_token', form.elements._token.value);
                body.set('lat', coordinates.lat); body.set('lng', coordinates.lng);
                let response;
                for (let attempt = 0; attempt < 2; attempt++) {
                    response = await fetch(root.dataset.addressUrl, { method: 'POST', body,
                        headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: pending.signal });
                    if (response.status !== 429 || attempt === 1) break;
                    await new Promise(resolve => {
                        const timer = setTimeout(resolve, 1200);
                        pending.signal.addEventListener('abort', () => { clearTimeout(timer); resolve(); }, { once: true });
                    });
                    if (pending.signal.aborted) return;
                }
                if (!response.ok) throw new Error();
                const data = await response.json();
                if (pending.signal.aborted || reverseRequest !== pending || disposed) return;
                if (typeof data.label === 'string' && data.label.length >= 8 && data.label.length <= 500) {
                    address.value = data.label; suggestedAddress = true;
                    address.dispatchEvent(new Event('change', { bubbles: true }));
                    addressStatus.textContent = 'Indirizzo trovato vicino al punto scelto. Controlla via e civico: puoi modificarli.';
                } else {
                    addressStatus.textContent = 'Il punto è segnato, ma non abbiamo trovato un indirizzo preciso. Completa il campo a mano.';
                }
            } catch {
                if (!pending.signal.aborted && reverseRequest === pending && !disposed) {
                    addressStatus.textContent = 'Il punto è segnato. Non riusciamo a trovare l’indirizzo ora: controllalo o completalo a mano.';
                }
            } finally {
                if (reverseRequest === pending) { reversePending = false; selectionState(); }
            }
        }, 450);
    }

    function selectPoint() {
        cancelAddress(); chosen = zoom >= 16;
        if (chosen) findAddress();
        render();
    }
    address.addEventListener('input', () => {
        manualAddress = address.value; suggestedAddress = false; cancelAddress(); selectionState();
    });

    function render() {
        if (disposed || !root.open || !validTiles) return selectionState();
        const { width, height } = viewport.getBoundingClientRect();
        if (!width || !height) return;
        const pixel = project(center.lat, center.lng, zoom);
        const left = pixel.x - width / 2, top = pixel.y - height / 2, count = 2 ** zoom;
        const needed = new Set();
        for (let y = Math.max(0, Math.floor(top / tileSize)); y <= Math.min(count - 1, Math.floor((top + height - 1) / tileSize)); y++) {
            for (let x = Math.floor(left / tileSize); x <= Math.floor((left + width - 1) / tileSize); x++) {
                const tileX = wrap(x, count), key = `${zoom}/${tileX}/${y}`;
                needed.add(key);
                let img = images.get(key);
                if (!img) {
                    img = new Image(tileSize, tileSize);
                    img.alt = ''; img.draggable = false; img.dataset.loaded = 'pending';
                    // Keep the address query private while sending OSM the website origin.
                    img.referrerPolicy = 'strict-origin';
                    img.onload = () => { img.dataset.loaded = 'yes'; if (!disposed) selectionState(); };
                    img.onerror = () => { img.dataset.loaded = 'error'; if (!disposed) selectionState(); };
                    images.set(key, img); layer.append(img);
                    img.src = tileUrl.replace('{z}', zoom).replace('{x}', tileX).replace('{y}', y);
                }
                img.style.left = `${x * tileSize - left}px`;
                img.style.top = `${y * tileSize - top}px`;
            }
        }
        for (const [key, img] of images) if (!needed.has(key)) { img.remove(); images.delete(key); }
        layer.style.transform = '';
        selectionState();
    }

    function move(dx, dy, select = false) {
        cancelAddress();
        const pixel = project(center.lat, center.lng, zoom);
        center = unproject(pixel.x + dx, pixel.y + dy, zoom);
        if (select) return selectPoint();
        chosen = false;
        render();
    }

    function magnify(amount) {
        cancelAddress();
        zoom = clamp(zoom + amount, 3, 19); chosen = false; render();
    }
    root.querySelector('[data-map-zoom-in]').addEventListener('click', () => magnify(1));
    root.querySelector('[data-map-zoom-out]').addEventListener('click', () => magnify(-1));
    viewport.addEventListener('keydown', event => {
        if (event.altKey || event.ctrlKey || event.metaKey) return;
        const shifts = { ArrowLeft: [-80, 0], ArrowRight: [80, 0], ArrowUp: [0, -80], ArrowDown: [0, 80] };
        if (shifts[event.key]) { event.preventDefault(); move(...shifts[event.key]); }
        else if (['+', '=', '-'].includes(event.key)) { event.preventDefault(); magnify(event.key === '-' ? -1 : 1); }
        else if (event.key === 'Enter') { event.preventDefault(); selectPoint(); }
    });
    viewport.addEventListener('pointerdown', event => {
        if (event.button !== 0) return;
        cancelAddress();
        viewport.setPointerCapture(event.pointerId);
        pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
        if (pointers.size === 1) gesture = { x: event.clientX, y: event.clientY, dx: 0, dy: 0, pinch: false };
        if (pointers.size === 2) {
            const [a, b] = [...pointers.values()];
            gesture = { pinch: true, distance: Math.hypot(a.x - b.x, a.y - b.y), scale: 1 };
        }
        chosen = false; selectionState();
    });
    viewport.addEventListener('pointermove', event => {
        if (!pointers.has(event.pointerId) || !gesture) return;
        pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
        if (gesture.pinch && pointers.size === 2) {
            const [a, b] = [...pointers.values()];
            gesture.scale = clamp(Math.hypot(a.x - b.x, a.y - b.y) / Math.max(gesture.distance, 1), .25, 4);
            layer.style.transform = `scale(${gesture.scale})`;
        } else if (!gesture.pinch) {
            gesture.dx = event.clientX - gesture.x; gesture.dy = event.clientY - gesture.y;
            layer.style.transform = `translate(${gesture.dx}px, ${gesture.dy}px)`;
        }
    });
    function endPointer(event) {
        if (!pointers.has(event.pointerId)) return;
        pointers.delete(event.pointerId);
        const ended = gesture;
        gesture = null;
        if (!ended || event.type === 'pointercancel') return render();
        if (ended.pinch) magnify(Math.round(Math.log2(ended.scale)));
        else if (Math.hypot(ended.dx, ended.dy) > 5) move(-ended.dx, -ended.dy);
        else {
            const rect = viewport.getBoundingClientRect();
            move(event.clientX - rect.left - rect.width / 2, event.clientY - rect.top - rect.height / 2, true);
        }
    }
    viewport.addEventListener('pointerup', endPointer);
    viewport.addEventListener('pointercancel', endPointer);
    form.addEventListener('submit', event => { if (confirm.disabled) event.preventDefault(); });

    areaForm.addEventListener('submit', async event => {
        event.preventDefault();
        if (!areaForm.reportValidity()) return;
        request?.abort();
        const pending = new AbortController(); request = pending;
        const button = areaForm.querySelector('button');
        button.disabled = true; results.replaceChildren(); areaStatus.textContent = 'Cerchiamo il luogo sulla mappa…';
        try {
            const response = await fetch(areaForm.action, { method: 'POST', body: new FormData(areaForm),
                headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: pending.signal });
            const data = await response.json();
            if (!response.ok) {
                const failure = new Error();
                failure.publicMessage = response.status === 419 ? 'La pagina è scaduta. Ricaricala e riprova.'
                    : response.status === 429 ? 'Hai fatto diverse ricerche ravvicinate. Attendi un minuto e riprova.'
                    : response.status === 503 && typeof data.message === 'string' ? data.message
                    : 'Non riusciamo a cercare il luogo in questo momento. Riprova tra poco.';
                throw failure;
            }
            if (!Array.isArray(data.places)) throw new Error();
            if (pending.signal.aborted || request !== pending || disposed) return;
            for (const place of data.places.slice(0, 5)) {
                if (!Number.isFinite(place.lat) || !Number.isFinite(place.lng) || !Number.isInteger(place.zoom)) continue;
                const item = document.createElement('li'), choice = document.createElement('button');
                choice.type = 'button'; choice.textContent = place.label;
                choice.addEventListener('click', () => {
                    cancelAddress();
                    center = { lat: clamp(place.lat, -maxLatitude, maxLatitude), lng: place.lng };
                    zoom = clamp(place.zoom, 3, 19); chosen = false; render();
                    areaStatus.textContent = `Mappa centrata sul luogo scelto. Indica ora il punto esatto di ${locationName}.`;
                    results.replaceChildren(); viewport.focus({ preventScroll: true });
                });
                item.append(choice); results.append(item);
            }
            areaStatus.textContent = results.children.length ? 'Scegli un risultato per avvicinare la mappa.'
                : 'Nessun risultato. Prova con il solo comune, oppure spostati sulla mappa.';
        } catch (error) {
            if (!pending.signal.aborted) areaStatus.textContent = `${error.publicMessage || 'Non riusciamo a cercare il luogo in questo momento. Riprova tra poco.'} Puoi comunque spostarti sulla mappa.`;
        } finally {
            if (request === pending) button.disabled = false;
        }
    });

    const resize = new ResizeObserver(() => render()); resize.observe(viewport);
    const suspend = () => {
        request?.abort(); request = null; cancelAddress();
        areaForm.querySelector('button').disabled = false;
        pointers.clear(); gesture = null;
    };
    root.addEventListener('map:suspend', suspend);
    root.addEventListener('map:reset', event => {
        suspend();
        const point = event.detail?.point;
        chosen = Number.isFinite(point?.lat) && Number.isFinite(point?.lng);
        center = chosen ? { lat: point.lat, lng: point.lng } : { ...initial };
        zoom = chosen ? 18 : Number(root.dataset.initialZoom || 5);
        address.value = chosen ? point.label : '';
        manualAddress = address.value; suggestedAddress = false;
        areaForm.reset(); results.replaceChildren(); areaStatus.textContent = '';
        for (const [key, img] of images) if (img.dataset.loaded === 'error') { img.remove(); images.delete(key); }
        render();
    });
    root.addEventListener('map:refresh', render);
    window.addEventListener('pagehide', suspend);
    root.addEventListener('toggle', () => {
        if (root.open) {
            for (const [key, img] of images) if (img.dataset.loaded === 'error') { img.remove(); images.delete(key); }
            render();
        }
    });
    root.hidden = false;
    if (location.hash === '#pickup-map') root.open = true;
    render();
    return () => { disposed = true; suspend(); resize.disconnect(); window.removeEventListener('pagehide', suspend); };
}

export function setupPickupMaps() {
    for (const [root, dispose] of maps) if (!root.isConnected) { dispose(); maps.delete(root); }
    document.querySelectorAll('[data-pickup-map]').forEach(root => {
        if (!maps.has(root)) maps.set(root, mount(root));
    });
}

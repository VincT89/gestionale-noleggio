// The confirmed return is separate from the map draft. Closing the dialog never
// replaces it; only a successful session-bound confirmation updates the form.
const form = document.querySelector('[data-return-choice]');
const dialog = document.querySelector('[data-return-map-dialog]');
if (form && dialog) {
    const choice = form.querySelector('[data-custom-return-toggle]');
    const field = form.querySelector('[data-custom-return-field]');
    const address = form.querySelector('[data-custom-return-address]');
    const token = form.querySelector('[data-custom-return-place]');
    const standard = form.querySelector('[data-return-standard]');
    const select = standard.querySelector('select');
    const submit = form.querySelector('[data-return-submit]');
    const open = form.querySelector('[data-return-map-open]');
    const status = form.querySelector('[data-return-choice-status]');
    const feedback = dialog.querySelector('[data-return-map-feedback]');
    const map = dialog.querySelector('[data-pickup-map]');
    const mapForm = map.querySelector('[data-map-confirm]');
    let confirmedPoint = JSON.parse(dialog.dataset.confirmedPoint || 'null');
    let request = null;

    const update = () => {
        field.hidden = !choice.checked;
        address.disabled = token.disabled = !choice.checked;
        select.disabled = choice.checked;
        select.required = !choice.checked;
        standard.hidden = choice.checked;
        submit.textContent = choice.checked ? 'Richiedi ritiro e riconsegna' : 'Richiedi il ritiro a questo indirizzo';
        open.textContent = token.value ? 'Modifica il punto sulla mappa' : 'Scegli il punto sulla mappa';
    };
    const show = () => {
        feedback.textContent = '';
        dialog.showModal();
        map.open = true;
        map.dispatchEvent(new CustomEvent('map:reset', { detail: { point: confirmedPoint } }));
        dialog.scrollTop = 0;
        dialog.querySelector('[data-return-map-close]').focus({ preventScroll: true });
    };
    open.hidden = false;
    open.addEventListener('click', show);
    choice.addEventListener('change', () => { update(); if (choice.checked && !token.value) show(); });
    dialog.querySelector('[data-return-map-close]').addEventListener('click', () => dialog.close());
    const cancel = () => {
        request?.abort(); request = null;
        map.inert = false; dialog.removeAttribute('aria-busy');
        map.dispatchEvent(new Event('map:suspend'));
    };
    dialog.addEventListener('close', () => { cancel(); if (choice.checked) open.focus({ preventScroll: true }); });
    window.addEventListener('pagehide', cancel);
    window.addEventListener('pageshow', update);
    form.addEventListener('submit', event => {
        if (choice.checked && !token.value) {
            event.preventDefault(); status.textContent = 'Scegli e conferma il punto di riconsegna per continuare.'; show();
        }
    });
    mapForm.addEventListener('submit', async event => {
        if (event.defaultPrevented) return;
        event.preventDefault();
        if (request || !mapForm.reportValidity() || map.dataset.mapState !== 'selected') return;
        const body = new FormData(mapForm); body.set('map_confirmed', '1');
        const pending = new AbortController(); request = pending;
        map.inert = true; dialog.setAttribute('aria-busy', 'true');
        map.querySelector('button[name="map_confirmed"]').disabled = true;
        feedback.textContent = 'Confermiamo il punto di riconsegna…';
        try {
            const response = await fetch(mapForm.action, { method: 'POST', body, credentials: 'same-origin',
                headers: { Accept: 'application/json' }, signal: pending.signal });
            const data = await response.json();
            if (pending.signal.aborted || request !== pending || !dialog.open) return;
            if (!response.ok) {
                feedback.textContent = response.status === 422 ? Object.values(data.errors || {}).flat().join(' ')
                    : response.status === 419 ? 'La pagina è scaduta. Ricaricala prima di scegliere nuovamente il punto.'
                    : response.status === 429 ? 'Attendi un minuto e riprova a confermare il punto.'
                    : 'Non è stato possibile confermare il punto. Riprova tra poco.';
                return;
            }
            if (typeof data.selection?.token !== 'string' || typeof data.selection?.label !== 'string'
                || !Number.isFinite(data.point?.lat) || !Number.isFinite(data.point?.lng)) throw new Error();
            token.value = data.selection.token; address.value = data.selection.label;
            confirmedPoint = data.point;
            status.textContent = 'Punto preciso di riconsegna selezionato.';
            update(); dialog.close();
        } catch {
            if (!pending.signal.aborted && request === pending) feedback.textContent = 'Non è stato possibile confermare il punto. La tua scelta resta sulla mappa: riprova.';
        } finally {
            if (request === pending) {
                request = null; map.inert = false; dialog.removeAttribute('aria-busy');
                map.dispatchEvent(new Event('map:refresh'));
            }
        }
    });
    update();
}

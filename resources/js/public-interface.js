const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
const compact = window.matchMedia('(max-width: 900px)');

function enter(element) {
    if (reducedMotion.matches || !element?.animate) return;
    element.animate([{ opacity: 0, transform: 'translateY(10px)' }, { opacity: 1, transform: 'translateY(0)' }], {
        duration: 280, easing: 'cubic-bezier(.2,.7,.3,1)',
    });
}

const header = document.querySelector('.amd-header');
const updateHeader = () => header?.classList.toggle('amd-header--scrolled', window.scrollY > 12);
window.addEventListener('scroll', updateHeader, { passive: true });
updateHeader();

const search = document.getElementById('car-search');
const searchToggle = document.querySelector('[data-search-toggle]');
function expandSearch(open, focus = false) {
    if (!search) return;
    search.hidden = !open;
    searchToggle?.setAttribute('aria-expanded', String(open));
    if (searchToggle) searchToggle.textContent = open ? 'Chiudi modifica' : 'Modifica ricerca';
    if (focus && open) search.querySelector('input:not([type="hidden"]):not([disabled]), select:not([hidden])')?.focus({ preventScroll: true });
}
if (searchToggle) {
    document.querySelector('[data-search-recap]').hidden = false;
    expandSearch(Boolean(search.querySelector('.amd-errors')) || location.hash === '#car-search');
    searchToggle.addEventListener('click', () => expandSearch(search.hidden, true));
}

function goToSearch(target) {
    expandSearch(true);
    search?.scrollIntoView({ behavior: reducedMotion.matches ? 'instant' : 'smooth', block: 'start' });
    target?.focus({ preventScroll: true });
}
document.addEventListener('click', event => {
    const link = event.target.closest('a');
    if (!link || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || !search) return;
    if (link.hasAttribute('data-search-destination')) {
        const select = document.querySelector('[data-place-select]');
        if (![...select.options].some(option => option.value === link.dataset.searchDestination)) return;
        event.preventDefault();
        select.value = link.dataset.searchDestination;
        select.dispatchEvent(new Event('change', { bubbles: true }));
        goToSearch(document.getElementById('pickup-at'));
    } else if (link.hasAttribute('data-search-hotel')) {
        event.preventDefault();
        const checkbox = document.querySelector('[data-search-delivery]');
        checkbox.checked = true;
        checkbox.dispatchEvent(new Event('change', { bubbles: true }));
        goToSearch(document.querySelector('[data-search-delivery-address]'));
    } else if (link.getAttribute('href') === '#car-search') {
        event.preventDefault();
        if (link.hasAttribute('data-search-standard')) {
            const checkbox = document.querySelector('[data-search-delivery]');
            checkbox.checked = false;
            checkbox.dispatchEvent(new Event('change', { bubbles: true }));
        }
        goToSearch(document.getElementById('pickup-place-search') || document.getElementById('pickup-place'));
    }
});

document.querySelectorAll('[data-pickup-options]').forEach(container => {
    const list = container.querySelector('[data-pickup-tabs]');
    const tabs = [...list.querySelectorAll('[data-pickup-tab]')];
    list.hidden = false;
    list.setAttribute('role', 'tablist');
    const activate = (selected, animate = true) => {
        tabs.forEach(tab => {
            const panel = document.getElementById(tab.dataset.pickupTab);
            const active = tab === selected;
            tab.setAttribute('role', 'tab');
            tab.setAttribute('aria-controls', panel.id);
            tab.setAttribute('aria-selected', String(active));
            tab.tabIndex = active ? 0 : -1;
            panel.setAttribute('role', 'tabpanel');
            panel.setAttribute('aria-labelledby', tab.id);
            panel.hidden = !active;
            if (active && animate) enter(panel);
            if (active) container.dataset.pickupActive = tab.dataset.pickupTab;
        });
    };
    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => activate(tab));
        tab.addEventListener('keydown', event => {
            let next;
            if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
            if (event.key === 'ArrowLeft') next = (index + tabs.length - 1) % tabs.length;
            if (event.key === 'Home') next = 0;
            if (event.key === 'End') next = tabs.length - 1;
            if (next === undefined) return;
            event.preventDefault();
            activate(tabs[next]);
            tabs[next].focus();
        });
    });
    activate(tabs[0], false);
});

document.querySelectorAll('.amd-home-faq details').forEach(details => {
    details.addEventListener('toggle', () => {
        if (details.open) enter(details.querySelector('div'));
    });
});

const resultRegion = document.getElementById('public-search-results');
const status = document.querySelector('[data-search-status]');
function setupResults(open = !compact.matches) {
    const disclosure = resultRegion?.querySelector('[data-filter-disclosure]');
    if (disclosure) disclosure.open = open;
}
setupResults();
compact.addEventListener('change', () => setupResults());

if (resultRegion) {
    const indexUrl = new URL(resultRegion.dataset.searchUrl, location.href);
    const isSearchUrl = url => url.origin === indexUrl.origin && url.pathname === indexUrl.pathname;
    let controller;
    history.replaceState({ ...history.state, amdSearch: true }, '', location.href);

    async function updateResults(url, { historyMode = 'push', focusId = null, moveToResults = true } = {}) {
        controller?.abort();
        const request = new AbortController();
        controller = request;
        const filterOpen = resultRegion.querySelector('[data-filter-disclosure]')?.open;
        resultRegion.setAttribute('aria-busy', 'true');
        status.classList.add('amd-live-status--visible');
        status.textContent = 'Cerchiamo le proposte per il tuo viaggio…';
        try {
            // A redirect can carry validation or an expired selection: let a native GET handle its flash message.
            const response = await fetch(url, { headers: { Accept: 'text/html' }, credentials: 'same-origin', redirect: 'manual', signal: request.signal });
            if (!response.ok || response.type === 'opaqueredirect') throw new Error('Native navigation required');
            const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
            if (request.signal.aborted) return;
            const next = doc.getElementById('public-search-results');
            if (!next || doc.querySelector('#car-search .amd-errors')) throw new Error('Native navigation required');
            resultRegion.replaceChildren(...next.childNodes);
            const recap = document.getElementById('public-search-recap');
            const nextRecap = doc.getElementById('public-search-recap');
            if (recap && nextRecap) recap.replaceChildren(...nextRecap.childNodes);
            // Keep the editable search consistent with the selected place and browser history.
            search?.querySelectorAll('[name]').forEach(field => {
                const nextField = doc.getElementById(field.id);
                if (!nextField) return;
                if (field.type === 'checkbox') field.checked = nextField.checked;
                else field.value = nextField.value;
                field.dispatchEvent(new Event('change', { bubbles: true }));
            });
            document.title = doc.title;
            if (historyMode === 'push') history.pushState({ amdSearch: true }, '', url);
            setupResults(filterOpen);
            const heading = resultRegion.querySelector('h2');
            const restored = focusId ? document.getElementById(focusId) : null;
            if (restored) restored.focus({ preventScroll: true });
            else if (moveToResults && heading) {
                heading.tabIndex = -1;
                heading.focus({ preventScroll: true });
                resultRegion.scrollIntoView({ behavior: reducedMotion.matches ? 'instant' : 'smooth', block: 'start' });
            }
            status.textContent = heading?.textContent || 'Risultati aggiornati.';
            enter(resultRegion.querySelector('.amd-car-grid, .amd-delivery-suppliers, .amd-place-confirmation'));
        } catch (error) {
            if (!request.signal.aborted) location.assign(url.href);
        } finally {
            if (controller === request) {
                resultRegion.removeAttribute('aria-busy');
                status.classList.remove('amd-live-status--visible');
            }
        }
    }

    resultRegion.addEventListener('submit', event => {
        const form = event.target.closest('form[data-live-search]');
        if (!form || form.method.toLowerCase() !== 'get') return;
        const url = new URL(form.action, location.href);
        if (!isSearchUrl(url)) return;
        event.preventDefault();
        url.search = new URLSearchParams(new FormData(form)).toString();
        const control = document.activeElement;
        const focusId = form.classList.contains('amd-sort') || form.classList.contains('amd-filter-form') ? control?.id : null;
        updateResults(url, { focusId, moveToResults: !focusId });
    });
    resultRegion.addEventListener('change', event => {
        if (event.target.matches('.amd-filter-form select, .amd-sort select')) {
            event.target.focus({ preventScroll: true });
            event.target.form.requestSubmit();
        }
    });
    resultRegion.addEventListener('click', event => {
        const link = event.target.closest('a[href]');
        if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || link.target || link.hasAttribute('download')) return;
        const url = new URL(link.href);
        if (!isSearchUrl(url) || url.hash || !url.search) return;
        event.preventDefault();
        updateResults(url);
    });
    window.addEventListener('popstate', event => {
        if (event.state?.amdSearch && isSearchUrl(new URL(location.href))) updateResults(new URL(location.href), { historyMode: 'none', moveToResults: false });
        else location.reload();
    });
    window.addEventListener('pagehide', () => controller?.abort());
}

const photoButton = document.querySelector('[data-photo-open]');
const photoDialog = document.querySelector('[data-photo-dialog]');
if (photoButton && photoDialog && typeof photoDialog.showModal === 'function') {
    photoButton.hidden = false;
    photoButton.addEventListener('click', () => {
        photoDialog.showModal();
        enter(photoDialog.querySelector('figure'));
    });
    photoDialog.querySelector('[data-photo-close]').addEventListener('click', () => photoDialog.close());
    photoDialog.addEventListener('click', event => { if (event.target === photoDialog) photoDialog.close(); });
    photoDialog.addEventListener('close', () => photoButton.focus({ preventScroll: true }));
}

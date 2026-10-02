import './password-visibility';

const desktop = window.matchMedia('(min-width: 901px)');
const mobileNav = document.querySelector('.amd-mobile-nav');
const mobileButton = mobileNav.querySelector('.amd-mobile-toggle');
const mobilePanel = mobileNav.querySelector('nav');
mobileButton.hidden = false;

function setMobileMenu(open, restoreFocus = false) {
    mobileButton.setAttribute('aria-expanded', String(open));
    mobilePanel.hidden = !open;
    if (restoreFocus) mobileButton.focus();
}

mobileButton.addEventListener('click', () => setMobileMenu(mobilePanel.hidden));
for (const eventName of ['click', 'focusin']) {
    document.addEventListener(eventName, event => {
        if (!mobilePanel.hidden && !mobileNav.contains(event.target)) setMobileMenu(false);
    });
}
document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && !mobilePanel.hidden) setMobileMenu(false, true);
});
desktop.addEventListener('change', () => {
    if (desktop.matches) setMobileMenu(false);
});

document.querySelectorAll('[data-booking-form]').forEach(form => {
    form.addEventListener('submit', () => {
        const button = form.querySelector('button[type="submit"]');
        if (!button) return;
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
    });
});
window.addEventListener('pageshow', () => {
    document.querySelectorAll('[data-booking-form] button[type="submit"]').forEach(button => {
        button.disabled = false;
        button.removeAttribute('aria-busy');
    });
});
document.querySelector('[data-booking-errors]')?.focus();

// The native select remains usable without JavaScript; submit only a selected destination.
document.querySelectorAll('[data-place-select]').forEach(select => {
    const options = [...select.options].filter(option => option.value);
    const input = document.createElement('input');
    input.type = 'text';
    input.id = `${select.id}-search`;
    input.placeholder = 'Città, aeroporto, stazione o zona';
    input.autocomplete = 'off';
    input.required = select.required;
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-describedby', 'place-help');
    const list = document.createElement('ul');
    list.id = `${select.id}-options`;
    list.className = 'amd-place-options';
    list.setAttribute('role', 'listbox');
    list.setAttribute('aria-label', 'Luoghi di ritiro');
    list.hidden = true;
    input.setAttribute('aria-controls', list.id);
    document.querySelector(`label[for="${select.id}"]`).htmlFor = input.id;
    select.before(input);
    select.after(list);
    select.hidden = true;
    select.classList.add('amd-place-select-native');
    select.required = false;
    input.value = select.value ? select.selectedOptions[0].textContent : '';
    const normalize = value => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('it');
    let matches = [];
    let active = -1;
    const close = () => {
        list.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
        active = -1;
    };
    const highlight = index => {
        active = index;
        [...list.querySelectorAll('[role="option"]')].forEach((item, i) => {
            item.setAttribute('aria-selected', String(i === index));
            if (i === index) {
                input.setAttribute('aria-activedescendant', item.id);
                item.scrollIntoView({ block: 'nearest' });
            }
        });
    };
    const choose = option => {
        select.value = option.value;
        input.value = option.textContent;
        input.setCustomValidity('');
        select.dispatchEvent(new Event('change', { bubbles: true }));
        close();
    };
    const show = () => {
        const words = select.value ? [] : normalize(input.value).trim().split(/\s+/).filter(Boolean);
        matches = options.filter(option => words.every(word => normalize(option.textContent).includes(word)));
        list.replaceChildren();
        active = -1;
        input.removeAttribute('aria-activedescendant');
        matches.forEach((option, index) => {
            const item = document.createElement('li');
            item.id = `${list.id}-${index}`;
            item.setAttribute('role', 'option');
            item.setAttribute('aria-selected', 'false');
            item.textContent = option.textContent;
            item.addEventListener('pointerdown', event => { event.preventDefault(); choose(option); });
            item.addEventListener('pointermove', () => highlight(index));
            list.append(item);
        });
        if (!matches.length) {
            const message = document.createElement('li');
            message.className = 'amd-place-none';
            message.setAttribute('role', 'presentation');
            message.textContent = 'Nessun luogo trovato. Prova con il nome della città o un altro punto di ritiro.';
            list.append(message);
        }
        list.hidden = false;
        input.setAttribute('aria-expanded', 'true');
    };
    input.addEventListener('focus', show);
    input.addEventListener('click', show);
    input.addEventListener('input', () => {
        select.value = '';
        input.setCustomValidity(input.value ? 'Scegli un luogo dall’elenco dei risultati.' : '');
        show();
    });
    input.addEventListener('keydown', event => {
        if (event.key === 'Escape') { close(); return; }
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            if (list.hidden) show();
            if (matches.length) highlight((active + (event.key === 'ArrowDown' ? 1 : -1) + matches.length) % matches.length);
        } else if (event.key === 'Enter' && !list.hidden && active >= 0) {
            event.preventDefault();
            choose(matches[active]);
        }
    });
    input.addEventListener('blur', close);
    input.form.addEventListener('reset', () => { input.value = ''; select.value = ''; input.setCustomValidity(''); close(); });
});

const searchDelivery = document.querySelector('[data-search-delivery]');
if (searchDelivery) {
    const field = document.querySelector('[data-search-delivery-field]');
    const address = document.querySelector('[data-search-delivery-address]');
    const placeLabel = document.querySelector('[data-search-place-label]');
    const placeHelp = document.getElementById('place-help');
    const updateSearchDelivery = () => {
        field.hidden = !searchDelivery.checked;
        address.disabled = !searchDelivery.checked;
        address.required = searchDelivery.checked;
        placeLabel.textContent = searchDelivery.checked ? 'Luogo di riconsegna' : 'Luogo di ritiro';
        placeHelp.textContent = searchDelivery.checked
            ? 'Scegli dove riconsegnare l’auto, per esempio un aeroporto servito.'
            : 'Scegli un luogo dall’elenco.';
    };
    searchDelivery.addEventListener('change', updateSearchDelivery);
    window.addEventListener('pageshow', updateSearchDelivery);
    updateSearchDelivery();
}

const compactFilters = window.matchMedia('(max-width: 900px)');
document.querySelectorAll('[data-filter-disclosure]').forEach(panel => {
    panel.open = !compactFilters.matches;
    compactFilters.addEventListener('change', () => { panel.open = !compactFilters.matches; });
});

const pickupDate = document.getElementById('pickup-at');
const returnDate = document.getElementById('return-at');
pickupDate?.addEventListener('change', () => {
    if (returnDate && pickupDate.value) returnDate.min = pickupDate.value;
});

const deliveryChoice = document.querySelector('[data-delivery-choice]');
if (deliveryChoice) {
    const submit = document.querySelector('[data-checkout-submit]');
    const originalLabel = submit?.dataset.standardCheckoutLabel || submit?.textContent;
    const address = document.querySelector('[data-delivery-address]');
    const updateDeliveryChoice = () => {
        if (submit) submit.textContent = deliveryChoice.checked ? 'Invia richiesta di consegna' : originalLabel;
        if (address) address.required = deliveryChoice.checked;
        const standardPickup = document.querySelector('[data-summary-standard-pickup]');
        const customPickup = document.querySelector('[data-summary-custom-pickup]');
        const summaryAddress = document.querySelector('[data-summary-delivery-address]');
        if (standardPickup) standardPickup.hidden = deliveryChoice.checked;
        if (customPickup) customPickup.hidden = !deliveryChoice.checked;
        if (summaryAddress) summaryAddress.textContent = address?.value || 'Indica l’indirizzo nel modulo.';
    };
    deliveryChoice.addEventListener('change', updateDeliveryChoice);
    address?.addEventListener('input', updateDeliveryChoice);
    window.addEventListener('pageshow', updateDeliveryChoice);
    updateDeliveryChoice();
}

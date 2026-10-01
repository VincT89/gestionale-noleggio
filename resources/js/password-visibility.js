function setVisibility(field, visible) {
    const input = field.querySelector('input');
    const button = field.querySelector('[data-password-toggle]');
    if (!input || !button) return;
    input.type = visible ? 'text' : 'password';
    button.setAttribute('aria-pressed', String(visible));
    button.setAttribute('aria-controls', input.id);
    button.setAttribute('aria-label', visible ? 'Nascondi password' : 'Mostra password');
    button.title = visible ? 'Nascondi password' : 'Mostra password';
    button.querySelector('[data-password-eye]').hidden = visible;
    button.querySelector('[data-password-eye-off]').hidden = !visible;
}

function initialize(root) {
    const fields = root.matches?.('[data-password-field]') ? [root] : root.querySelectorAll?.('[data-password-field]') || [];
    for (const field of fields) {
        const button = field.querySelector('[data-password-toggle]');
        if (!button) continue;
        button.hidden = false;
        setVisibility(field, false);
    }
}

initialize(document);
document.addEventListener('click', event => {
    const button = event.target.closest('[data-password-toggle]');
    if (!button) return;
    const field = button.closest('[data-password-field]');
    const input = field?.querySelector('input');
    if (input && !input.disabled) setVisibility(field, input.type === 'password');
});
document.addEventListener('submit', event => {
    for (const field of event.target.querySelectorAll('[data-password-field]')) setVisibility(field, false);
}, true);
window.addEventListener('pageshow', () => initialize(document));

// Livewire and Alpine can insert password forms after the initial page load.
new MutationObserver(records => {
    for (const record of records) {
        if (record.type === 'attributes' && record.target.matches('[data-password-toggle]') && record.target.hidden) {
            const field = record.target.closest('[data-password-field]');
            record.target.hidden = false;
            setVisibility(field, field.querySelector('input').type === 'text');
        }
        for (const node of record.addedNodes) {
            if (node.nodeType === Node.ELEMENT_NODE) initialize(node.closest('[data-password-field]') || node);
        }
    }
}).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['hidden'] });

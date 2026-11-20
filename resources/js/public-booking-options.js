const bookingForm = document.querySelector('[data-options-base]');

if (bookingForm) {
    const choices = [...bookingForm.querySelectorAll('[data-extra-price]')];
    const { optionsBase, optionsDays, optionsDepositBps } = bookingForm.dataset;
    const base = Number(optionsBase);
    const days = Number(optionsDays);
    const depositBps = Number(optionsDepositBps);
    const money = new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' });
    const summary = bookingForm.closest('.amd-booking-layout');
    const amountInput = bookingForm.elements.namedItem('displayed_total_cents');
    const acceptance = bookingForm.elements.namedItem('accept_summary');
    const submit = bookingForm.querySelector('[data-checkout-submit]');
    const isDeliveryRequest = Boolean(bookingForm.querySelector('input[name="request_delivery"][value="1"]'));

    const setPrice = (selector, cents, suffix = '') => {
        summary.querySelectorAll(selector).forEach(node => { node.textContent = money.format(cents / 100) + suffix; });
    };

    const update = () => {
        const selected = choices.filter(choice => choice.checked);
        const extraTotal = selected.reduce((sum, choice) => sum + Number(choice.dataset.extraPrice), 0);
        const depositable = selected.filter(choice => choice.dataset.extraDepositable === '1')
            .reduce((sum, choice) => sum + Number(choice.dataset.extraPrice), 0);
        const total = base + extraTotal;
        const online = Math.floor(((base + depositable) * depositBps + 5000) / 10000);
        amountInput.value = String(total);
        setPrice('[data-options-total]', total);
        setPrice('[data-options-daily]', total / days, ' al giorno in media.');
        setPrice('[data-options-subtotal]', extraTotal);
        setPrice('[data-options-online]', online);
        setPrice('[data-options-balance]', total - online);
        summary.querySelector('[data-extra-summary]').hidden = selected.length === 0;
        summary.querySelectorAll('[data-options-online-summary]').forEach(node => { node.hidden = online === 0; });
        summary.querySelectorAll('[data-options-pickup-summary]').forEach(node => { node.hidden = online > 0; });
        if (!isDeliveryRequest) {
            submit.textContent = online > 0 ? 'Prosegui al pagamento' : 'Conferma prenotazione';
        }
    };

    choices.forEach(choice => choice.addEventListener('change', () => {
        acceptance.checked = false;
        update();
    }));
    window.addEventListener('pageshow', update);
    update();
}

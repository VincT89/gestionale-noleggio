const customerType = document.querySelector('[data-booking-customer-type]');
if (customerType) {
    const form = customerType.closest('form');
    const company = form.querySelector('[data-booking-company]');
    const country = form.elements.namedItem('billing_country');
    const taxCode = form.elements.namedItem('tax_code');
    const update = () => {
        const isCompany = customerType.value === 'company';
        company.hidden = !isCompany;
        company.querySelectorAll('input').forEach(input => {
            input.disabled = !isCompany;
            input.required = isCompany && input.hasAttribute('data-company-required');
        });
        taxCode.required = !isCompany && country.value.trim().toUpperCase() === 'IT';
    };
    customerType.addEventListener('change', update);
    country.addEventListener('input', update);
    window.addEventListener('pageshow', update);
    update();
}

const birthdate = document.querySelector('[data-driver-birthdate]');
if (birthdate) {
    const form = birthdate.closest('form');
    const band = form.elements.namedItem('driver_age_band');
    const pickup = form.elements.namedItem('pickup_at');
    const status = form.querySelector('[data-driver-age-status]');
    const labels = { '18_21': 'Da 18 a 21 anni compresi', 'over_21': 'Oltre 21 anni' };
    const updateAge = () => {
        birthdate.setCustomValidity('');
        status.textContent = '';
        status.classList.remove('amd-field-error');
        if (!birthdate.value || !pickup?.value || !/^\d{4}-\d{2}-\d{2}$/.test(birthdate.value)) return;
        if (birthdate.value > birthdate.max) {
            status.textContent = 'La data di nascita non può essere successiva a oggi.';
            status.classList.add('amd-field-error');
            return;
        }
        const [birthYear, birthMonth, birthDay] = birthdate.value.split('-').map(Number);
        const [pickupYear, pickupMonth, pickupDay] = pickup.value.slice(0, 10).split('-').map(Number);
        const age = pickupYear - birthYear - Number(pickupMonth < birthMonth || (pickupMonth === birthMonth && pickupDay < birthDay));
        const actualBand = age < 18 ? null : (age <= 21 ? '18_21' : 'over_21');
        let error = '';
        if (!actualBand) {
            error = 'Il conducente deve avere almeno 18 anni alla data del ritiro.';
        } else if (band.value && band.value !== actualBand) {
            error = `Al ritiro avrai ${age} anni: fascia «${labels[actualBand]}». Aggiorna la fascia e il preventivo prima di confermare.`;
        }
        status.textContent = error || `Alla data del ritiro avrai ${age} anni. Fascia: ${labels[actualBand]}.`;
        status.classList.toggle('amd-field-error', Boolean(error));
        birthdate.setCustomValidity(error);
    };
    birthdate.addEventListener('input', updateAge);
    birthdate.addEventListener('change', updateAge);
    band.addEventListener('change', updateAge);
    window.addEventListener('pageshow', updateAge);
    updateAge();
}

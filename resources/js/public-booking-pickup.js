const dialog = document.querySelector('[data-booking-pickup-dialog]');
const open = document.querySelector('[data-booking-pickup-open]');
if (dialog && open) {
    const map = dialog.querySelector('[data-pickup-map]');
    open.hidden = false;
    open.addEventListener('click', () => {
        dialog.showModal();
        map.open = true;
        map.dispatchEvent(new Event('map:refresh'));
        dialog.scrollTop = 0;
        dialog.querySelector('[data-booking-pickup-close]').focus({ preventScroll: true });
    });
    dialog.querySelector('[data-booking-pickup-close]').addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => {
        map.dispatchEvent(new Event('map:suspend'));
        open.focus({ preventScroll: true });
    });
}

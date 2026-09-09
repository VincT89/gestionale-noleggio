const desktop = window.matchMedia('(min-width: 769px)');

function disclosure(element, buttonSelector, panelSelector) {
    const button = element.querySelector(buttonSelector);
    const panel = element.querySelector(panelSelector);
    return {
        element, button, panel, openedByHover: false,
        isOpen: () => button.getAttribute('aria-expanded') === 'true',
        set(open, restoreFocus = false) {
            button.setAttribute('aria-expanded', String(open));
            panel.hidden = !open;
            if (!open) this.openedByHover = false;
            if (restoreFocus) button.focus();
        },
    };
}

const mobileMenu = disclosure(document.querySelector('.amd-mobile-nav'), '.amd-mobile-toggle', 'nav');
const submenus = [...document.querySelectorAll('.amd-nav-submenu')]
    .map(element => disclosure(element, '.amd-submenu-toggle', '.amd-submenu-links'));

mobileMenu.button.addEventListener('click', () => mobileMenu.set(!mobileMenu.isOpen()));
submenus.forEach(menu => {
    menu.element.addEventListener('mouseenter', () => {
        if (desktop.matches && !menu.isOpen()) {
            menu.set(true);
            menu.openedByHover = true;
        }
    });
    menu.button.addEventListener('click', () => {
        menu.set(menu.openedByHover || !menu.isOpen());
        menu.openedByHover = false;
    });
    menu.element.addEventListener('mouseleave', () => {
        menu.openedByHover = false;
        if (desktop.matches && !menu.element.contains(document.activeElement)) menu.set(false);
    });
});

function closeOutside(target) {
    submenus.forEach(menu => {
        if (menu.isOpen() && !menu.element.contains(target)) menu.set(false);
    });
    if (mobileMenu.isOpen() && !mobileMenu.element.contains(target)) mobileMenu.set(false);
}
document.addEventListener('click', event => closeOutside(event.target));
document.addEventListener('focusin', event => closeOutside(event.target));

document.addEventListener('keydown', event => {
    if (event.key !== 'Escape') return;
    const submenu = submenus.find(menu => menu.isOpen() && menu.element.contains(document.activeElement));
    if (submenu) {
        submenu.set(false, true);
    } else if (mobileMenu.isOpen()) {
        submenus.forEach(menu => menu.set(false));
        mobileMenu.set(false, true);
    } else {
        submenus.forEach(menu => { if (menu.isOpen()) menu.set(false, true); });
    }
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

desktop.addEventListener('change', () => {
    submenus.forEach(menu => menu.set(false));
    if (desktop.matches) mobileMenu.set(false);
});

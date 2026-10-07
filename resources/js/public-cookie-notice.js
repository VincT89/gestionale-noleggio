// This stores acknowledgement of information, never consent to tracking.
export const noticeStorageKey = 'amd-rent.cookie-notice';

export function noticeIsCurrent(raw, version, days, now = Date.now()) {
    try {
        const record = JSON.parse(raw);
        return typeof record === 'object' && record !== null && record.version === version
            && Number.isFinite(record.dismissedAt) && record.dismissedAt <= now
            && now - record.dismissedAt < days * 86400000;
    } catch { return false; }
}

export function readNotice(storage, version, days, now = Date.now()) {
    try {
        const raw = storage?.getItem(noticeStorageKey);
        if (noticeIsCurrent(raw, version, days, now)) return true;
        if (raw !== null && raw !== undefined) storage.removeItem(noticeStorageKey);
    } catch { /* Blocked browser storage must not block the website. */ }
    return false;
}

const notice = typeof document === 'undefined' ? null : document.querySelector('[data-cookie-notice]');
if (notice) {
    const version = notice.dataset.noticeVersion;
    const days = Number(notice.dataset.noticeDays);
    const dismiss = notice.querySelector('[data-cookie-notice-dismiss]');
    let storage = null, dismissedInPage = false;
    try { storage = window.localStorage; } catch { /* Private/restricted browser. */ }

    const resize = () => document.documentElement.style.setProperty('--amd-cookie-notice-height', notice.hidden ? '0px' : `${notice.getBoundingClientRect().height}px`);
    function show(open) {
        notice.hidden = !open;
        document.documentElement.classList.toggle('amd-cookie-notice-open', open);
        resize();
    }
    dismiss.addEventListener('click', () => {
        try { storage?.setItem(noticeStorageKey, JSON.stringify({ version, dismissedAt: Date.now() })); } catch { /* Keep dismissal for this page even without storage. */ }
        dismissedInPage = true;
        show(false);
        document.getElementById('ricerca-contenuto')?.focus({ preventScroll: true });
    });
    const refresh = () => show(!dismissedInPage && !readNotice(storage, version, days));
    window.addEventListener('pageshow', refresh);
    window.addEventListener('storage', event => {
        if (event.key === noticeStorageKey || event.key === null) {
            dismissedInPage = false;
            refresh();
        }
    });
    if (typeof ResizeObserver !== 'undefined') new ResizeObserver(resize).observe(notice);
    else window.addEventListener('resize', resize);
    refresh();
}

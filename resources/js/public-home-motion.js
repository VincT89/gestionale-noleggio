// One scroll stage keeps the arriving car together with the search form.
const scene = document.querySelector('[data-home-scene]');

if (scene) {
    const preference = window.matchMedia('(prefers-reduced-motion: reduce)');
    const vehicle = scene.querySelector('[data-scene-vehicle]');
    const car = scene.querySelector('[data-scene-car]');
    const background = scene.querySelector('[data-scene-fallback]');
    const glint = scene.querySelector('[data-car-glint]');
    const track = document.querySelector('[data-scene-track]');
    const stage = track.querySelector('.amd-scene-sticky');
    const form = track.querySelector('.amd-home-search-panel');
    const home = document.querySelector('.amd-home-sections');
    const route = home?.querySelector('[data-home-route]');
    const photos = [...document.querySelectorAll('[data-home-photo]')];
    let fallback = false;
    let glintShown = false;
    let frame = 0;
    let arrivalStart = 0;
    let arrivalEnd = 1;
    let routeStart = 0;
    let routeEnd = 1;
    const clamp = value => Math.min(1, Math.max(0, value));

    function layoutBox(element) {
        let x = 0, y = 0;
        for (let node = element; node && node !== home; node = node.offsetParent) {
            x += node.offsetLeft;
            y += node.offsetTop;
        }
        return { x, y, width: element.offsetWidth, height: element.offsetHeight };
    }

    function measure() {
        const inset = document.querySelector('.amd-header').offsetHeight + 10;
        const trackTop = track.getBoundingClientRect().top + window.scrollY;
        arrivalStart = Math.max(0, trackTop - innerHeight * .55);
        arrivalEnd = Math.max(arrivalStart + Math.min(340, Math.max(240, innerHeight * .3)), trackTop + form.offsetTop - innerHeight * .55);
        // Only pin a stage that fits: the search must remain usable on short screens.
        const pin = !preference.matches && !fallback && stage.offsetHeight <= innerHeight - inset - 16;
        track.toggleAttribute('data-scene-hold', pin);
        track.style.setProperty('--amd-stage-top', `${inset}px`);
        track.style.setProperty('--amd-scene-hold', `${pin ? Math.max(0, arrivalEnd - (trackTop - inset)) : 0}px`);
        if (route) {
            const pickup = layoutBox(home.querySelector('.amd-pickup-photos'));
            const planning = layoutBox(home.querySelector('.amd-long-term-photo'));
            const width = home.clientWidth;
            const compact = innerWidth <= 800;
            const startX = compact ? pickup.x + pickup.width - 6 : pickup.x + pickup.width * .72;
            const startY = pickup.y + pickup.height - 12;
            const endX = compact ? planning.x + planning.width - 6 : planning.x + planning.width * .38;
            const endY = planning.y + 20;
            const middleY = startY + (endY - startY) * .62;
            const line = compact
                ? `M ${startX} ${startY} C ${width - 7} ${startY + 36}, ${width - 7} ${endY - 36}, ${endX} ${endY}`
                : `M ${startX} ${startY} C ${startX} ${middleY}, ${endX} ${middleY}, ${endX} ${endY}`;
            route.setAttribute('viewBox', `0 0 ${width} ${home.offsetHeight}`);
            for (const path of route.querySelectorAll('[data-route-path]')) path.setAttribute('d', line);
            routeStart = startY;
            routeEnd = Math.max(startY + 100, endY);
            route.setAttribute('data-route-ready', '');
        }
        schedule();
    }

    function render() {
        frame = 0;
        if (document.hidden || preference.matches) return;
        const bounds = scene.getBoundingClientRect();
        if (!fallback) {
            const progress = clamp((window.scrollY - arrivalStart) / (arrivalEnd - arrivalStart));
            const remaining = Math.pow(1 - progress, 1.35);
            vehicle.style.transform = `translate3d(${150 * remaining}%, ${-6 * remaining}%, 0) scale(${1 - .025 * remaining})`;
            scene.dataset.arrivalProgress = progress.toFixed(3);
            scene.dataset.arrivalStart = arrivalStart.toFixed(1);
            scene.dataset.arrivalEnd = arrivalEnd.toFixed(1);
            if (!glintShown && progress >= .995 && bounds.bottom > 0 && bounds.top < innerHeight && car.complete && car.naturalWidth) {
                glintShown = true;
                vehicle.setAttribute('data-arrival-glint', '');
            }
        }
        for (const photo of photos) {
            const rect = photo.getBoundingClientRect();
            if (rect.bottom <= 0 || rect.top >= innerHeight) continue;
            photo.style.setProperty('--amd-photo-shift', `${(clamp((innerHeight - rect.top) / (innerHeight + rect.height)) - .5) * 12}px`);
        }
        if (route) {
            const progress = clamp((innerHeight * .75 - home.getBoundingClientRect().top - routeStart) / (routeEnd - routeStart));
            route.style.setProperty('--amd-route-offset', String(1 - progress));
        }
    }

    function schedule() {
        if (!frame && !document.hidden) frame = requestAnimationFrame(render);
    }

    function configure() {
        scene.toggleAttribute('data-motion-active', !preference.matches && !fallback);
        vehicle.removeAttribute('data-arrival-glint');
        vehicle.style.removeProperty('transform');
        scene.removeAttribute('data-arrival-progress');
        route?.toggleAttribute('data-route-animated', !preference.matches);
        for (const photo of photos) {
            photo.toggleAttribute('data-photo-active', !preference.matches);
            photo.style.removeProperty('--amd-photo-shift');
        }
        measure();
    }

    function useOriginalPhoto() {
        if (fallback) return;
        fallback = true;
        scene.setAttribute('data-scene-static', '');
        background.removeAttribute('srcset');
        background.src = background.dataset.sceneFallback;
        configure();
    }

    for (const image of [background, car]) {
        image.addEventListener('error', useOriginalPhoto, { once: true });
        if (image.complete && !image.naturalWidth) useOriginalPhoto();
    }
    const loadCar = () => {
        glint.style.setProperty('--amd-car-silhouette', `url("${car.currentSrc}")`);
        schedule();
    };
    car.addEventListener('load', loadCar);
    if (car.complete && car.naturalWidth) loadCar();
    preference.addEventListener('change', configure);
    window.addEventListener('scroll', schedule, { passive: true });
    window.addEventListener('resize', measure, { passive: true });
    window.addEventListener('pageshow', measure);
    document.addEventListener('visibilitychange', schedule);
    if ('ResizeObserver' in window) {
        const observer = new ResizeObserver(measure);
        observer.observe(stage);
        if (home) observer.observe(home);
    }
    configure();
}

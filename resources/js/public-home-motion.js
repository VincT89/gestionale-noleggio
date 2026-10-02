// The photograph is the motion boundary; search controls never move.
const scene = document.querySelector('[data-home-scene]');

if (scene) {
    const preference = window.matchMedia('(prefers-reduced-motion: reduce)');
    const car = scene.querySelector('[data-scene-car]');
    const vehicle = scene.querySelector('[data-scene-vehicle]');
    const glint = scene.querySelector('[data-car-glint]');
    const background = scene.querySelector('[data-scene-fallback]');
    const photos = [...document.querySelectorAll('[data-home-photo]')];
    const reveals = [...document.querySelectorAll('[data-home-reveal]')];
    const revealed = new WeakSet();
    const home = document.querySelector('.amd-home-sections');
    const route = home?.querySelector('[data-home-route]');
    const routePaths = route ? [...route.querySelectorAll('[data-route-path]')] : [];
    let routeStart = 0;
    let routeEnd = 1;
    let controller;
    let observer;
    let frame = 0;
    let fallback = false;
    let glintShown = false;

    function prepareGlint() {
        if (glint && car.complete && car.naturalWidth) {
            // Reuse the loaded cutout as the mask; the reflection stays inside the car.
            glint.style.setProperty('--amd-car-silhouette', `url("${car.currentSrc}")`);
            schedule();
        }
    }

    const clamp = (value, min, max) => Math.min(max, Math.max(min, value));

    // Layout coordinates ignore reveal transforms, so the route stays attached to the photos.
    function layoutBox(element) {
        let x = 0;
        let y = 0;
        for (let node = element; node && node !== home; node = node.offsetParent) {
            x += node.offsetLeft;
            y += node.offsetTop;
        }
        const { width, height } = element.getBoundingClientRect();
        return { x, y, width, height, right: x + width, bottom: y + height };
    }

    function measureRoute() {
        if (!route) return;
        const coast = layoutBox(home.querySelector('.amd-places-photo'));
        const copy = layoutBox(home.querySelector('.amd-places-copy'));
        const places = layoutBox(home.querySelector('.amd-home-places'));
        const hotel = layoutBox(home.querySelector('.amd-delivery-photo'));
        const card = layoutBox(home.querySelector('.amd-delivery-feature'));
        const width = home.clientWidth;
        const height = home.offsetHeight;
        const gutter = width - Math.max(copy.right, card.right);
        const rail = width - gutter / 2;
        const startX = coast.right - 20;
        const startY = coast.y + coast.height * .64;
        const hotelY = hotel.y + hotel.height * .83;
        let line = `M ${startX} ${startY}`;

        if (copy.x > coast.right) {
            const middle = (coast.right + copy.x) / 2;
            const crossing = places.bottom - (places.bottom - Math.max(coast.bottom, copy.bottom)) / 2;
            line += ` C ${middle + 10} ${startY + 15}, ${middle - 20} ${crossing - 18}, ${middle + 48} ${crossing}`;
            line += ` C ${copy.x + copy.width * .45} ${crossing + 12}, ${rail - gutter * .12} ${crossing - 8}, ${rail} ${crossing + 42}`;
        } else {
            line += ` C ${rail} ${startY}, ${rail} ${startY + 16}, ${rail} ${startY + 40}`;
        }

        // A single quiet sweep finishes on the ground in the hotel photograph.
        const shore = `${line} L ${width + 20} ${places.bottom + 80} L ${coast.x + coast.width * .45} ${places.bottom + 80} C ${coast.right - 100} ${places.bottom + 80}, ${coast.right - 36} ${places.bottom - 24}, ${startX} ${startY} Z`;
        line += ` S ${rail + gutter * .12} ${hotelY - 12}, ${hotel.right - 24} ${hotelY}`;

        route.setAttribute('viewBox', `0 0 ${width} ${height}`);
        for (const path of routePaths) path.setAttribute('d', line);
        route.querySelector('[data-route-coast]').setAttribute('d', shore);
        const clip = route.querySelector('[data-route-coast-clip]');
        clip.setAttribute('width', width);
        clip.setAttribute('height', places.bottom);
        routeStart = startY;
        routeEnd = hotelY;
        route.setAttribute('data-route-ready', '');
        schedule();
    }

    function show(element) {
        element.removeAttribute('data-reveal-pending');
        revealed.add(element);
        observer?.unobserve(element);
    }

    function render() {
        frame = 0;
        if (document.hidden || preference.matches) return;

        const viewport = window.innerHeight;
        const bounds = scene.getBoundingClientRect();
        if (!fallback) {
            // Finish the arrival while the photograph is still visible on small screens.
            const distance = Math.min(270, bounds.height * .68);
            const progress = clamp(window.scrollY / distance, 0, 1);
            const remaining = Math.pow(1 - progress, 2);
            vehicle.style.transform = `translate3d(${155 * remaining}%, ${-6 * remaining}%, 0) scale(${1 - .025 * remaining})`;
            if (!glintShown && progress >= .98 && bounds.bottom > 0 && bounds.top < viewport && car.complete && car.naturalWidth) {
                glintShown = true;
                vehicle.setAttribute('data-arrival-glint', '');
            }
        }

        for (const photo of photos) {
            const rect = photo.getBoundingClientRect();
            if (rect.bottom <= 0 || rect.top >= viewport) continue;
            const progress = clamp((viewport - rect.top) / (viewport + rect.height), 0, 1);
            photo.style.setProperty('--amd-photo-shift', `${(progress - .5) * 22}px`);
        }

        if (route) {
            const position = viewport * .45 - home.getBoundingClientRect().top;
            const progress = clamp((position - routeStart) / (routeEnd - routeStart), 0, 1);
            route.style.setProperty('--amd-route-offset', String(1 - progress));
        }
    }

    function schedule() {
        if (!frame && !document.hidden) frame = requestAnimationFrame(render);
    }

    function reset() {
        controller?.abort();
        observer?.disconnect();
        if (frame) cancelAnimationFrame(frame);
        frame = 0;
        scene.removeAttribute('data-motion-active');
        route?.removeAttribute('data-route-animated');
        vehicle.style.removeProperty('transform');
        vehicle.removeAttribute('data-arrival-glint');
        for (const photo of photos) {
            photo.removeAttribute('data-photo-active');
            photo.style.removeProperty('--amd-photo-shift');
        }
        for (const element of reveals) element.removeAttribute('data-reveal-pending');
    }

    function configure() {
        reset();
        measureRoute();
        if (preference.matches) return;

        controller = new AbortController();
        const { signal } = controller;
        if (!fallback) scene.setAttribute('data-motion-active', '');
        route?.setAttribute('data-route-animated', '');
        for (const photo of photos) photo.setAttribute('data-photo-active', '');

        if ('IntersectionObserver' in window) {
            observer = new IntersectionObserver(entries => {
                for (const entry of entries) if (entry.isIntersecting) show(entry.target);
            }, { threshold: .08, rootMargin: '0px 0px -24px 0px' });

            for (const element of reveals) {
                const bounds = element.getBoundingClientRect();
                const inset = element.dataset.homeReveal === 'photo' ? Math.max(24, bounds.height * .12) : 24;
                // Keep content already on screen (including restored scroll positions) visible.
                if (revealed.has(element)) continue;
                if (bounds.top < window.innerHeight - inset) {
                    revealed.add(element);
                    continue;
                }
                element.setAttribute('data-reveal-pending', '');
                observer.observe(element);
            }
        }

        document.addEventListener('focusin', event => {
            const element = event.target.closest('[data-home-reveal]');
            if (element) show(element);
        }, { signal });
        window.addEventListener('scroll', schedule, { passive: true, signal });
        window.addEventListener('resize', schedule, { passive: true, signal });
        window.addEventListener('pageshow', schedule, { signal });
        document.addEventListener('visibilitychange', schedule, { signal });
        render();
    }

    function useOriginalPhoto() {
        if (fallback) return;
        fallback = true;
        scene.setAttribute('data-scene-static', '');
        scene.removeAttribute('data-motion-active');
        background.removeAttribute('srcset');
        background.src = background.dataset.sceneFallback;
    }

    // A failed animation asset must never replace the original scene with a broken image.
    for (const image of [background, car]) {
        image.addEventListener('error', useOriginalPhoto, { once: true });
        if (image.complete && !image.naturalWidth) useOriginalPhoto();
    }

    preference.addEventListener('change', configure);
    car.addEventListener('load', prepareGlint);
    prepareGlint();
    if (route) {
        window.addEventListener('resize', measureRoute, { passive: true });
        home.addEventListener('toggle', measureRoute, true);
        if ('ResizeObserver' in window) {
            const layoutObserver = new ResizeObserver(measureRoute);
            layoutObserver.observe(home);
            for (const section of home.querySelectorAll(':scope > section')) layoutObserver.observe(section);
        }
    }
    configure();
}

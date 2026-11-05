<figure class="amd-home-visual" data-home-scene aria-hidden="true">
    <div class="amd-home-scene-plane">
        <picture>
            <source media="(prefers-reduced-motion: reduce)"
                srcset="{{ asset('images/amd-rent-coastal-drive-branded-640.webp') }} 640w, {{ asset('images/amd-rent-coastal-drive-branded-1200.webp') }} 1200w, {{ asset('images/amd-rent-coastal-drive-branded-1942.webp') }} 1942w">
            <img class="amd-home-scene-background" src="{{ asset('images/amd-rent-coastal-scene-v2-1200.webp') }}"
                srcset="{{ asset('images/amd-rent-coastal-scene-v2-640.webp') }} 640w, {{ asset('images/amd-rent-coastal-scene-v2-1200.webp') }} 1200w, {{ asset('images/amd-rent-coastal-scene-v2-1942.webp') }} 1942w"
                data-scene-fallback="{{ asset('images/amd-rent-coastal-drive-branded-1200.webp') }}"
                sizes="(max-width: 600px) 660px, (max-width: 1000px) 1100px, 1200px"
                width="1942" height="809" alt="" fetchpriority="high" decoding="async">
        </picture>
        <div class="amd-home-scene-vehicle" data-scene-vehicle>
            <img class="amd-home-scene-car" src="{{ asset('images/amd-rent-car-motion-v1-640.webp') }}"
                srcset="{{ asset('images/amd-rent-car-motion-v1-640.webp') }} 640w, {{ asset('images/amd-rent-car-motion-v1-1200.webp') }} 1200w"
                sizes="(max-width: 600px) 212px, 384px"
                width="1774" height="887" alt="" decoding="async" data-scene-car>
            <span class="amd-car-glint" data-car-glint></span>
        </div>
    </div>
</figure>

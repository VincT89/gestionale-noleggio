@php
    $amdContextPhotos = [
        'long-term' => ['small' => 'amd-rent-rental-planning-640.webp', 'large' => 'amd-rent-rental-planning-1200.webp', 'alt' => 'Chiavi dell’auto, un taccuino e un telefono su una scrivania'],
        'pickup' => ['small' => 'amd-rent-pickup-handover-640.webp', 'large' => 'amd-rent-pickup-handover-1200.webp', 'alt' => 'Passaggio delle chiavi accanto a un’auto'],
        'support' => ['small' => 'amd-rent-customer-support-640.webp', 'large' => 'amd-rent-customer-support-1200.webp', 'alt' => 'Cuffie con microfono accanto a un computer per l’assistenza'],
        'confirmation' => ['small' => 'amd-rent-journey-ready-640.webp', 'large' => 'amd-rent-journey-ready-1200.webp', 'alt' => 'Bagagli sistemati nel bagagliaio di un’auto prima della partenza'],
        'long-term-request' => ['small' => 'amd-rent-long-term-dossier-640.webp', 'large' => 'amd-rent-long-term-dossier-1200.webp', 'alt' => 'Una cartellina aperta con fogli e penna per una pratica di noleggio'],
        'delivery-request' => ['small' => 'amd-rent-city-delivery-640.webp', 'large' => 'amd-rent-city-delivery-1200.webp', 'alt' => 'Un’auto parcheggiata davanti a un ingresso in città'],
    ];
    $amdContextPhoto = $amdContextPhotos[$photoScene];
@endphp
<figure class="amd-context-photo">
    <img src="{{ asset('images/'.$amdContextPhoto['small']) }}"
         srcset="{{ asset('images/'.$amdContextPhoto['small']) }} 640w, {{ asset('images/'.$amdContextPhoto['large']) }} 1200w"
         sizes="(max-width: 600px) calc(100vw - 80px), 340px"
         width="1200" height="800" loading="lazy" decoding="async" alt="{{ $amdContextPhoto['alt'] }}">
</figure>

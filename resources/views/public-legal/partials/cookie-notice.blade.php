<aside id="amd-cookie-notice" class="amd-cookie-notice" aria-labelledby="amd-cookie-notice-title" data-cookie-notice data-notice-version="{{ config('public_privacy.notice_version') }}" data-notice-days="{{ config('public_privacy.notice_days') }}" hidden>
    <div class="amd-cookie-notice-inner">
        <div>
            <h2 id="amd-cookie-notice-title" tabindex="-1">La tua privacy, con chiarezza.</h2>
            <p>Usiamo solo cookie tecnici per navigare e accedere alla tua area cliente. Nessun cookie pubblicitario o di analisi. Leggi come trattiamo i tuoi dati.</p>
            <div class="amd-cookie-notice-links">
                <a href="{{ config('public_cars.privacy_url') ?: route('public-site.privacy') }}">Informativa privacy</a>
                <a href="{{ route('public-site.cookies') }}">Informativa cookie</a>
            </div>
        </div>
        <button class="amd-button" type="button" data-cookie-notice-dismiss>Ho capito</button>
    </div>
</aside>

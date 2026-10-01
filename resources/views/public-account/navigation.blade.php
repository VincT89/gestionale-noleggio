<nav class="amd-account-nav" aria-label="Area cliente">
    <a href="{{ route('public-account.bookings') }}" @if(request()->routeIs('public-account.bookings', 'public-account.booking*')) aria-current="page" @endif>Prenotazioni</a>
    <a href="{{ route('public-account.enquiries') }}" @if(request()->routeIs('public-account.enquiries', 'public-account.enquiry')) aria-current="page" @endif>Richieste e preventivi</a>
    <a href="{{ route('public-account.profile') }}" @if(request()->routeIs('public-account.profile*')) aria-current="page" @endif>Il mio profilo</a>
    <form method="post" action="{{ route('public-account.logout') }}">@csrf<button class="amd-account-logout">Esci</button></form>
</nav>

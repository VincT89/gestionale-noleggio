@props(['type' => null])
<nav class="amr-nav" aria-label="Gestione AMD Rent">
    <a href="{{ route('amd-rent.index') }}" @if(request()->routeIs('amd-rent.index')) aria-current="page" @endif>Panoramica</a>
    <a href="{{ route('public-bookings.index') }}" @if(request()->routeIs('public-bookings.index')) aria-current="page" @endif>Prenotazioni</a>
    <a href="{{ route('amd-rent.enquiries.index', ['type' => 'delivery']) }}" @if(($type ?? $case->type ?? request('type')) === 'delivery') aria-current="page" @endif>Richieste di consegna</a>
    <a href="{{ route('amd-rent.enquiries.index', ['type' => 'long_term']) }}" @if(request()->routeIs('amd-rent.enquiries.*') && ($type ?? $case->type ?? request('type', 'long_term')) === 'long_term') aria-current="page" @endif>Lungo termine</a>
    @can('vehicle_pricing.update')<a href="{{ route('public-deliveries.index') }}" @if(request()->routeIs('public-deliveries.*')) aria-current="page" @endif>Luoghi serviti</a>@endcan
    @if(auth()->user()->hasRole('admin'))<a href="{{ route('amd-rent.settings') }}" @if(request()->routeIs('amd-rent.settings*')) aria-current="page" @endif>Impostazioni</a>@endif
</nav>

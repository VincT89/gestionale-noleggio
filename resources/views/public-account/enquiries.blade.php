@extends('layouts.public-cars')
@section('title', 'Le mie richieste e preventivi')
@section('content')
@include('public-account.navigation')@include('public-account.feedback')
<div class="amd-page-heading"><h1>Richieste e preventivi</h1><p>Segui le richieste di consegna personalizzata e le pratiche di lungo termine. Qui puoi consultare i preventivi e i documenti condivisi con te.</p></div>
<div class="amd-account-list">@forelse($cases as $case)<article class="amd-account-record"><div><small>{{ $case->type === 'long_term' ? 'Lungo termine' : 'Consegna personalizzata' }} · {{ $case->reference }}</small><h2>{{ $case->vehicle_request }}</h2><p>Inviata il {{ $case->created_at->format('d/m/Y') }}</p>@if($case->type === 'long_term')<p>{{ $case->duration_months }} mesi · {{ number_format($case->annual_km, 0, ',', '.') }} km/anno</p>@endif</div><div><p><strong>{{ $case->status_label }}</strong></p><p><a class="amd-button" href="{{ route('public-account.enquiry', $case->reference) }}">Apri richiesta</a></p></div></article>
@empty<div class="amd-account-empty"><h2>Non ci sono ancora richieste.</h2><p>Puoi richiedere un preventivo di lungo termine oppure una consegna personalizzata durante la prenotazione.</p><a class="amd-button" href="{{ route('public-site.long-term') }}">Richiedi un preventivo</a></div>@endforelse</div>@include('public-account.pagination', ['items' => $cases])
@endsection

@extends('layouts.public-cars')
@section('title', 'Richiesta '.$case->reference)
@section('content')
@if($customerArea ?? false)@include('public-account.navigation')@endif
@if(session('status'))<div class="amd-account-notice" role="status">{{ session('status') }}</div>@endif
@php $money = fn ($cents) => number_format($cents / 100, 2, ',', '.').' €'; @endphp
<div class="amd-page-heading amd-photo-heading">
    <div><h1>{{ $case->type === 'delivery' ? 'La tua richiesta di consegna' : 'La tua richiesta di lungo termine' }}</h1><p>Riferimento <strong>{{ $case->reference }}</strong> · {{ $case->status_label }}</p></div>
    @include('public-cars.partials.context-photo', ['photoScene' => $case->type === 'delivery' ? 'delivery-request' : 'long-term-request'])
</div>
<section class="amd-detail-info amd-enquiry-summary"><h2>{{ $case->vehicle_request }}</h2><p>Conserva il collegamento a questa pagina per ritrovare la richiesta. Per assistenza, indica il riferimento {{ $case->reference }}.</p>
@if($errors->any())<div class="amd-errors" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@if($case->type === 'delivery')<p>Consegna richiesta: <strong>{{ $case->delivery_address }}</strong>.</p><p>Riconsegna presso {{ $case->booking_context['car']['location'] }} — {{ $case->booking_context['car']['city'] }}.</p>
@if($case->public_booking_id)<p><a class="amd-button" href="{{ $case->booking->confirmationUrl() }}">Apri la prenotazione</a></p>
@if(app(\App\Services\AmdRent\DeliveryBookingRecovery::class)->canReopen($case))
<p>Il pagamento della prenotazione precedente non è stato avviato oppure è scaduto. Puoi riaprire questa richiesta conservando la proposta di consegna e la sua scadenza. Prezzo e disponibilità saranno ricontrollati prima di un nuovo pagamento.</p>
<form method="post" action="{{ \Illuminate\Support\Facades\URL::signedRoute('public-enquiries.reopen', ['reference' => $case->reference]) }}">
    @csrf
    <input type="hidden" name="booking_id" value="{{ $case->public_booking_id }}">
    <input type="hidden" name="revision" value="{{ $case->revision }}">
    <button type="submit" class="amd-button amd-button-secondary">Riapri richiesta</button>
</form>
@endif
@elseif($case->status === 'quoted' && $case->quote_expires_at?->isFuture())<h3>Supplemento di consegna: {{ $money($case->delivery_fee_cents) }}</h3><p>IVA inclusa. Proposta valida fino al {{ $case->quote_expires_at->format('d/m/Y H:i') }}. Il prezzo del noleggio e la disponibilità saranno ricontrollati nel riepilogo, prima del pagamento.</p><form method="post" action="{{ \Illuminate\Support\Facades\URL::signedRoute('public-enquiries.accept', ['reference' => $case->reference]) }}">@csrf<label class="amd-booking-accept"><input type="checkbox" name="accept_quote" value="1" required><span>Ho verificato indirizzo e supplemento di consegna.</span></label><button class="amd-button">Controlla il totale e prosegui</button></form>
@elseif($case->status === 'quoted')<p>La proposta è scaduta. Contatta il noleggiatore per aggiornarla.</p>
@elseif($case->status === 'lost')<p>La richiesta è stata archiviata. Non è stata creata una prenotazione.</p>
@else<p>Il noleggiatore deve confermare la possibilità di consegna e il supplemento. Non è ancora stata riservata un’auto e non è stato richiesto alcun pagamento.</p>@endif
@else<p>{{ $case->duration_months }} mesi · {{ number_format($case->annual_km, 0, ',', '.') }} km/anno.</p><p>La pratica sarà seguita da AMD Rent o dal noleggiatore assegnato. Il contratto si conclude di persona, dopo la verifica della proposta e dei documenti.</p>
@if(in_array($case->status, ['quoted', 'accepted', 'signed']))@foreach($case->quotes as $quote)<article class="amd-long-term-quote"><h3>{{ $quote->supplier }} · {{ $quote->vehicle }}</h3><p><strong>{{ $money($quote->monthly_cents) }}/mese</strong> · Anticipo {{ $money($quote->upfront_cents) }} · {{ $quote->vat === 'included' ? 'IVA inclusa' : 'IVA esclusa' }}</p><p>{{ $quote->months }} mesi · {{ number_format($quote->annual_km, 0, ',', '.') }} km/anno · Valido fino al {{ $quote->valid_until->format('d/m/Y') }}</p><p class="amd-description">{{ $quote->conditions }}</p></article>@endforeach @endif
@endif<p><a href="{{ route('public-site.support') }}">Informazioni e assistenza</a></p></section>
@if($customerArea ?? false)@include('public-account.documents')@endif
@include('public-account.invitation')
@endsection

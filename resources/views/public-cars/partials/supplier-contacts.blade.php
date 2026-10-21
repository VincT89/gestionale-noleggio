@php $supplier = \App\Services\AmdRent\SupplierContacts::forBooking($booking); @endphp
<section class="amd-supplier-contacts" aria-labelledby="supplier-contacts-heading">
    <h2 id="supplier-contacts-heading">Il tuo noleggiatore</h2>
    <p><strong>{{ $supplier['name'] }}</strong>@if($supplier['legal_name'] && $supplier['legal_name'] !== $supplier['name'])<br>{{ $supplier['legal_name'] }}@endif</p>
    <dl class="amd-quote-lines">
        @if($supplier['phone'])<div><dt>Telefono</dt><dd>@if($supplier['phone_url'])<a href="{{ $supplier['phone_url'] }}">{{ $supplier['phone'] }}</a>@else{{ $supplier['phone'] }}@endif</dd></div>@endif
        @if($supplier['email'])<div><dt>Email</dt><dd>@if($supplier['email_url'])<a href="{{ $supplier['email_url'] }}">{{ $supplier['email'] }}</a>@else{{ $supplier['email'] }}@endif</dd></div>@endif
        @if($supplier['address'])<div><dt>Indirizzo aziendale</dt><dd>{{ $supplier['address'] }}</dd></div>@endif
    </dl>
    @if(!$supplier['phone'] && !$supplier['email'])<p>I recapiti diretti non sono ancora disponibili. Contatta <a href="{{ route('public-site.support') }}">Assistenza AMD Rent</a> indicando il riferimento della prenotazione.</p>@endif
    <p>Per il ritiro e la riconsegna segui i luoghi riportati nel riepilogo del noleggio.</p>
</section>

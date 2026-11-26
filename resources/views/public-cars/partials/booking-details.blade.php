@if(!empty($details['birthdate']))
    <section class="amd-agreed-booking-details">
        <h3>Dati del conducente dichiarati</h3>
        <p>Data di nascita: {{ \Carbon\CarbonImmutable::parse($details['birthdate'])->format('d/m/Y') }}.</p>
        @if(isset($details['driver_age_at_pickup']))<p>Età alla data del ritiro: {{ $details['driver_age_at_pickup'] }} anni.</p>@endif
        <p>Il noleggiatore deve verificare i dati sul documento prima della consegna.</p>
    </section>
@endif
@if(!empty($details['booking_customer_type']))
    <section class="amd-agreed-booking-details">
        <h3>Intestazione della prenotazione</h3>
        <p>{{ $details['booking_customer_type'] === 'company' ? 'Azienda' : 'Privato' }}@if(!empty($details['company_name'])) — {{ $details['company_name'] }}@endif</p>
        <p>{{ $details['billing_address'] ?? '' }}<br>{{ $details['billing_postal_code'] ?? '' }} {{ $details['billing_city'] ?? '' }} — {{ $details['billing_country'] ?? '' }}</p>
        @if(!empty($details['tax_code']))<p>Codice fiscale: {{ $details['tax_code'] }}</p>@endif
        @if(!empty($details['vat_number']))<p>Partita IVA / identificativo aziendale: {{ $details['vat_number'] }}</p>@endif
        @if(!empty($details['sdi_code']))<p>Codice destinatario SDI: {{ $details['sdi_code'] }}</p>@endif
        @if(!empty($details['pec']))<p>PEC: {{ $details['pec'] }}</p>@endif
    </section>
@endif

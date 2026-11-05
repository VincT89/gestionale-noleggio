<dl class="amd-legal-facts">
    <div><dt>Titolare del trattamento</dt><dd>{{ $privacy['controller_name'] ?: 'Ragione sociale da completare' }}</dd></div>
    <div><dt>Sede</dt><dd>{{ $privacy['controller_address'] ?: 'Sede legale da completare' }}</dd></div>
    @if($privacy['controller_vat'])<div><dt>Partita IVA</dt><dd>{{ $privacy['controller_vat'] }}</dd></div>@endif
    <div><dt>Contatto privacy</dt><dd>@if(filter_var($privacy['privacy_email'], FILTER_VALIDATE_EMAIL))<a href="mailto:{{ $privacy['privacy_email'] }}">{{ $privacy['privacy_email'] }}</a>@else Email per le richieste privacy da completare @endif</dd></div>
    @if($privacy['dpo_contact'])<div><dt>Responsabile della protezione dei dati</dt><dd>{{ $privacy['dpo_contact'] }}</dd></div>@endif
</dl>
@if($draft && $privacy['controller_source'])
    <p class="amd-legal-source">Dati ripresi dall’<a href="{{ $privacy['controller_source'] }}" target="_blank" rel="noopener noreferrer">informativa di AMD Mobility</a>, da confermare per il portale AMD Rent.</p>
@endif

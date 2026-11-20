@if(!empty($option['provider']))
    <p>Fornita da {{ $option['provider'] }}.</p>
@endif
@if(!empty($option['description']))
    <p class="amd-description amr-preline" style="white-space: pre-line; overflow-wrap: anywhere">{{ $option['description'] }}</p>
@endif
@if(!empty($option['exclusions']))
    <p class="amd-description amr-preline" style="white-space: pre-line; overflow-wrap: anywhere"><strong>{{ ($option['kind'] ?? null) === 'service' ? 'Condizioni del servizio' : 'Esclusioni e limiti' }}</strong><br>{{ $option['exclusions'] }}</p>
@endif
@if(!empty($option['terms_url']) && str_starts_with(strtolower($option['terms_url']), 'https://'))
    <p><a href="{{ $option['terms_url'] }}" target="_blank" rel="noopener noreferrer">Leggi le condizioni (nuova scheda)</a></p>
@endif

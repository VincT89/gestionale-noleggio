@if(!$entry['included'])
    Non inclusa
@elseif($entry['franchise_cents'] !== null)
    {{ $entry['franchise_cents'] === 0 ? 'Franchigia azzerata' : 'Franchigia di '.number_format($entry['franchise_cents'] / 100, 2, ',', '.').' €' }}
@elseif($key === 'assistenza')
    Inclusa
@else
    Inclusa · franchigia da verificare
@endif

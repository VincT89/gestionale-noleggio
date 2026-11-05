@if(($point['source'] ?? null) === 'map' && is_numeric($point['lat'] ?? null) && is_numeric($point['lng'] ?? null))
    @php $mapLat = number_format((float) $point['lat'], 7, '.', ''); $mapLng = number_format((float) $point['lng'], 7, '.', ''); @endphp
    <p><a href="https://www.openstreetmap.org/?mlat={{ $mapLat }}&amp;mlon={{ $mapLng }}#map=18/{{ $mapLat }}/{{ $mapLng }}" target="_blank" rel="noopener noreferrer">Vedi il punto di {{ ($pointType ?? 'pickup') === 'return' ? 'riconsegna' : 'ritiro' }} indicato sulla mappa</a></p>
@endif

@if(isset($car['driver_age']))
    <dl class="amd-quote-lines">
        <div><dt>Età del conducente al ritiro</dt><dd>{{ $car['driver_age']['label'] }}</dd></div>
        <div><dt>Supplemento per età</dt><dd>{{ number_format($car['driver_age']['total_cents'] / 100, 2, ',', '.') }} €</dd></div>
    </dl>
@endif

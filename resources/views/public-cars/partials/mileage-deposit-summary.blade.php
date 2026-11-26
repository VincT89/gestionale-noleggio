<dl class="amd-quote-lines">
    <div><dt>Cauzione separata</dt><dd>{{ number_format($car['deposit_cents'] / 100, 2, ',', '.') }} €<br><span>Paga in sede</span></dd></div>
    <div><dt>Chilometri inclusi</dt><dd>{{ $car['km_per_day'] === null ? 'Illimitati' : $car['km_per_day'].' km/giorno' }}@if($car['km_per_day'] !== null)<br>{{ $car['km_per_day'] * $car['days'] }} km per il periodo @endif</dd></div>
    @if($car['km_per_day'] !== null)
        <div><dt>Chilometri extra</dt><dd>{{ number_format($car['extra_km_cents'] / 100, 2, ',', '.') }} €/km<br><span>Da pagare in sede al rientro</span></dd></div>
    @endif
</dl>

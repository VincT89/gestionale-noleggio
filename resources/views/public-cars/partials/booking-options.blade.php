@php
    $standard = $bookingOptions['standard_protection'];
    $selectedIds = array_column($checkoutQuote['selected_extras'] ?? [], 'id');
    $money = fn (int $cents) => number_format($cents / 100, 2, ',', '.').' €';
@endphp

<section class="amd-booking-options" aria-labelledby="booking-options-title">
    <h2 id="booking-options-title">Protezione ed extra</h2>
    <div class="amd-option-panel">
        <h3>Protezione standard inclusa</h3>
        @include('public-cars.partials.option-coverage', ['entries' => $standard['coverage']])
        @include('public-cars.partials.option-terms', ['option' => $standard])
    </div>

    @if($bookingOptions['extras'] !== [])
        <fieldset class="amd-options-list">
            <legend>Aggiungi i servizi che ti servono</legend>
            @foreach($bookingOptions['extras'] as $extra)
                <div class="amd-option-panel">
                    <label class="amd-option-choice" for="booking-extra-{{ $extra['id'] }}">
                        <input id="booking-extra-{{ $extra['id'] }}" type="checkbox" name="extras[]" value="{{ $extra['id'] }}"
                               data-extra-price="{{ $extra['total_cents'] }}" data-extra-depositable="{{ $extra['depositable'] ? '1' : '0' }}"
                               @checked(in_array($extra['id'], $selectedIds, true))>
                        <span>
                            <strong>{{ $extra['name'] }}</strong>
                            <span class="amd-option-price">
                                @if($extra['price_unit'] === 'multiplier' && $extra['total_cents'] > 0)
                                    +{{ $money($extra['total_cents']) }} per il noleggio
                                @else
                                    @if($extra['price_unit'] === 'day' && $extra['price_cents'] > 0)
                                        {{ $money($extra['price_cents']) }} al giorno ·
                                    @endif
                                    {{ $extra['total_cents'] === 0 ? 'Gratuito' : $money($extra['total_cents']).' per il noleggio' }}
                                @endif
                            </span>
                        </span>
                    </label>
                    @if($extra['price_unit'] === 'multiplier')
                        <p>Tariffa auto con AMD Plus <strong>{{ $money(($car['rental_total_cents'] ?? $car['total_cents']) + $extra['total_cents']) }}</strong> per il periodo.</p>
                    @endif
                    @if($extra['protection_type'] === 'reduction')
                        <table class="amd-coverage-comparison">
                            <thead><tr><th scope="col">Copertura</th><th scope="col">Standard</th><th scope="col">Con AMD Plus</th></tr></thead>
                            <tbody>
                                @foreach($extra['coverage'] as $key => $entry)
                                    <tr>
                                        <th scope="row">
                                            @if($key === 'rca')
                                                <abbr title="{{ $entry['label'] }}">RCA</abbr>
                                            @else
                                                {{ $entry['label'] }}
                                            @endif
                                        </th>
                                        <td>@include('public-cars.partials.coverage-amount', ['entry' => $standard['coverage'][$key]])</td>
                                        <td>@include('public-cars.partials.coverage-amount', ['entry' => $entry])</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @elseif($extra['protection_type'] === 'policy')
                        <p>Polizza assicurativa separata.</p>
                    @endif
                    @include('public-cars.partials.option-terms', ['option' => $extra])
                </div>
            @endforeach
        </fieldset>
        <noscript><p>Se selezioni degli extra, vedrai il totale aggiornato prima di confermare il pagamento o la richiesta.</p></noscript>
    @endif
</section>

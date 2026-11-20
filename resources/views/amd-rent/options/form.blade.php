@php
    $kind = $option->kind;
    $label = \App\Models\AmdRentOption::KINDS[$kind];
    $isStandard = $kind === 'standard';
    $isPlus = $kind === 'plus';
    $currentCoverage = $option->coverage ?? [];
    $franchiseMultipliers = $option->franchise_multipliers ?? [];
    $multiplierScale = \App\Models\AmdRentOption::MULTIPLIER_SCALE;
    $maxMultiplier = \App\Models\AmdRentOption::MAX_MULTIPLIER;
    $value = function (string $field, mixed $fallback = null) {
        $input = old($field, $fallback);
        return is_scalar($input) ? $input : $fallback;
    };
@endphp

<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-white">{{ $label }} — {{ $organization->name }}</h1>
    </x-slot>

    <div class="amr">
        <x-amd-rent-nav />
        @include('amd-rent.feedback')
        <a class="amr-link" href="{{ route('amd-rent.options.index', ['organization_id' => $organization->id]) }}">Torna alla configurazione del noleggiatore</a>

        <form method="post" class="amr-panel app-surface amr-form"
              action="{{ $option->exists ? route('amd-rent.options.update', $option) : route('amd-rent.options.store', [$organization, $kind]) }}">
            @csrf
            @if($option->exists)
                @method('PUT')
                <input type="hidden" name="revision" value="{{ $option->revision }}">
            @endif

            <div class="amr-grid">
                <div>
                    <label for="option-scope">Auto e gruppi</label>
                    <select class="app-field" id="option-scope" name="scope" required>
                        @foreach($targets as $scope => $target)
                            <option value="{{ $scope }}" @selected($value('scope', $option->scopeKey()) === $scope)>{{ $target }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="option-published">Stato</label>
                    <select class="app-field" id="option-published" name="published">
                        <option value="0" @selected(!$value('published', $option->published))>Bozza</option>
                        <option value="1" @selected((bool) $value('published', $option->published))>Pubblicata su AMD Rent</option>
                    </select>
                </div>
            </div>

            @if($kind === 'service')
                <div>
                    <label for="option-name">Nome del servizio</label>
                    <input class="app-field" id="option-name" name="name" maxlength="100" value="{{ $value('name', $option->name) }}" required>
                </div>
            @endif

            @if($isPlus)
                <div class="amr-grid">
                    <div>
                        <label for="option-type">Tipo di protezione</label>
                        <select class="app-field" id="option-type" name="protection_type">
                            <option value="">Da definire</option>
                            <option value="reduction" @selected($value('protection_type', $option->protection_type) === 'reduction')>Riduzione delle franchigie nel contratto di noleggio</option>
                            <option value="policy" @selected($value('protection_type', $option->protection_type) === 'policy')>Polizza assicurativa separata</option>
                        </select>
                    </div>
                    <div>
                        <label for="option-provider">Fornitore della polizza</label>
                        <input class="app-field" id="option-provider" name="provider" maxlength="150" value="{{ $value('provider', $option->provider) }}">
                    </div>
                </div>
                <p class="app-muted">Per pubblicare AMD Plus indica prezzo, coperture ed esclusioni. Per una polizza indica anche il fornitore e il link alle condizioni.</p>
            @endif

            <div>
                <label for="option-description">{{ $kind === 'service' ? 'Descrizione del servizio' : 'Cosa comprende' }}</label>
                <textarea class="app-field" id="option-description" name="description" rows="3" maxlength="2400">{{ $value('description', $option->description) }}</textarea>
            </div>
            <div>
                <label for="option-exclusions">{{ $kind === 'service' ? 'Condizioni del servizio' : 'Esclusioni e limiti' }}</label>
                <textarea class="app-field" id="option-exclusions" name="exclusions" rows="3" maxlength="2400">{{ $value('exclusions', $option->exclusions) }}</textarea>
            </div>
            <div>
                <label for="option-terms">Link alle condizioni (HTTPS)</label>
                <input class="app-field" id="option-terms" type="url" name="terms_url" maxlength="500" value="{{ $value('terms_url', $option->terms_url) }}">
            </div>

            @if(!$isStandard)
                <div class="amr-grid">
                    <div>
                        <label for="option-unit">Modalità di prezzo</label>
                        <select class="app-field" id="option-unit" name="price_unit">
                            <option value="">Da definire</option>
                            <option value="rental" @selected($value('price_unit', $option->price_unit) === 'rental')>Per l’intero noleggio</option>
                            <option value="day" @selected($value('price_unit', $option->price_unit) === 'day')>Per giorno tariffato</option>
                            @if($isPlus)
                                <option value="multiplier" @selected($value('price_unit', $option->price_unit) === 'multiplier')>Moltiplicatore della tariffa auto</option>
                            @endif
                        </select>
                    </div>
                    <div>
                        <label for="option-price">Importo fisso IVA inclusa (€)</label>
                        <input class="app-field" id="option-price" type="number" name="price" min="0" max="100000" step="0.01"
                               value="{{ $value('price', $option->price_cents === null ? '' : $option->price_cents / 100) }}">
                    </div>
                    @if($isPlus)
                        <div>
                            <label for="option-price-multiplier">Moltiplicatore tariffa auto</label>
                            <input class="app-field" id="option-price-multiplier" type="number" name="price_multiplier"
                                   min="1" max="{{ $maxMultiplier }}" step="0.0001" aria-describedby="option-price-multiplier-help"
                                   value="{{ $value('price_multiplier', $option->price_multiplier_bps === null ? '' : $option->price_multiplier_bps / $multiplierScale) }}">
                        </div>
                    @endif
                </div>
                @if($isPlus)
                    <p class="app-muted" id="option-price-multiplier-help">Nella modalità «Moltiplicatore della tariffa auto», 1,88 porta una tariffa di 100 € a 188 €. Il supplemento è la differenza di 88 €. Consegna, cauzione e altri extra hanno voci separate.</p>
                @endif
                @foreach([
                    'depositable' => 'Includi nel calcolo dell’acconto online del 15%',
                    'commissionable' => 'Includi nella base per la commissione AMD Rent',
                ] as $field => $text)
                    <div>
                        <input type="hidden" name="{{ $field }}" value="0">
                        <label class="amr-check"><input name="{{ $field }}" type="checkbox" value="1" @checked($value($field, $option->{$field}))><span>{{ $text }}</span></label>
                    </div>
                @endforeach
                @if($kind === 'service')
                    <div>
                        <input type="hidden" name="covers_second_driver" value="0">
                        <label class="amr-check"><input name="covers_second_driver" type="checkbox" value="1" @checked($value('covers_second_driver', $option->covers_second_driver))><span>Il servizio comprende già il supplemento per la seconda guida.</span></label>
                    </div>
                @endif
            @endif

            @if($isStandard || $isPlus)
                <fieldset class="amr-panel" id="option-coverage">
                    <legend class="px-2 font-semibold">{{ $isPlus ? 'Coperture e franchigie con AMD Plus' : 'Coperture incluse e franchigie' }}</legend>
                    <p class="app-muted">{{ $isPlus
                        ? 'Per la riduzione contrattuale seleziona le voci che cambiano. Per ciascuna indica un importo fisso oppure un moltiplicatore.'
                        : 'Per ogni copertura inclusa puoi indicare un importo fisso oppure un moltiplicatore. Se lasci entrambi vuoti, si usa l’importo della scheda del veicolo.' }}</p>
                    <p class="app-muted">Il moltiplicatore si applica alla franchigia nella scheda del veicolo. Usa 1 per lo stesso importo, 2 per il doppio. Un importo fisso di 0 azzera la franchigia.</p>
                    <div id="option-preview-picker" class="mb-3" hidden>
                        <label for="option-preview-vehicle">Veicolo di anteprima</label>
                        <select class="app-field" id="option-preview-vehicle"></select>
                    </div>
                    <p id="option-preview-status" class="app-muted text-sm" role="status" hidden>Nessuna auto disponibile per l’anteprima.</p>
                    @foreach(\App\Models\AmdRentOption::COVERAGES as $key => $coverageLabel)
                        <div class="amr-grid py-2" @if($key !== 'assistenza') data-franchise-preview="{{ $key }}" @endif>
                            @if($isStandard && $key === 'rca')
                                <p>{{ $coverageLabel }} inclusa</p>
                            @else
                                <div>
                                    <input type="hidden" name="coverage[{{ $key }}][included]" value="0">
                                    <label class="amr-check">
                                        <input name="coverage[{{ $key }}][included]" type="checkbox" value="1"
                                               @checked($value('coverage.'.$key.'.included', array_key_exists($key, $currentCoverage)))>
                                        <span>{{ $coverageLabel }}</span>
                                    </label>
                                </div>
                            @endif
                            @if($key !== 'assistenza')
                                <div>
                                    <div class="amr-grid">
                                        <div>
                                            <label for="option-franchise-{{ $key }}">Franchigia fissa {{ $coverageLabel }} (€)</label>
                                            <input class="app-field" id="option-franchise-{{ $key }}" type="number" min="0" max="100000" step="0.01"
                                                   name="coverage[{{ $key }}][franchise]" data-preview-fixed
                                                   value="{{ $value('coverage.'.$key.'.franchise', isset($currentCoverage[$key]) ? $currentCoverage[$key] / 100 : '') }}">
                                        </div>
                                        <div>
                                            <label for="option-franchise-multiplier-{{ $key }}">Moltiplicatore {{ $coverageLabel }}</label>
                                            <input class="app-field" id="option-franchise-multiplier-{{ $key }}" type="number"
                                                   min="0" max="{{ $maxMultiplier }}" step="0.0001" name="coverage[{{ $key }}][multiplier]" data-preview-factor
                                                   value="{{ $value('coverage.'.$key.'.multiplier', isset($franchiseMultipliers[$key]) ? $franchiseMultipliers[$key] / $multiplierScale : '') }}">
                                        </div>
                                    </div>
                                    <div class="mt-2" data-preview-amounts hidden>
                                        <dl class="amr-grid text-sm">
                                            <div>
                                                <dt class="app-muted">Franchigia da scheda veicolo</dt>
                                                <dd class="font-semibold" data-preview-base></dd>
                                            </div>
                                            <div>
                                                <dt class="app-muted">{{ $isPlus ? 'Risultato con AMD Plus' : 'Risultato con Standard' }}</dt>
                                                <dd class="font-semibold" data-preview-result aria-live="polite"></dd>
                                            </div>
                                        </dl>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </fieldset>
            @endif

            <button class="amr-button" type="submit">Salva configurazione</button>
        </form>
    </div>

    @if($isStandard || $isPlus)
        @include('amd-rent.options.franchise-preview-script')
    @endif
</x-app-layout>

@if(!empty($entries))
    <dl class="amd-quote-lines amd-option-coverage">
        @foreach(\App\Models\AmdRentOption::COVERAGES as $key => $label)
            @isset($entries[$key])
                <div>
                    <dt>{{ $label }}</dt>
                    <dd>@include('public-cars.partials.coverage-amount', ['entry' => $entries[$key]])</dd>
                </div>
            @endisset
        @endforeach
    </dl>
@endif

@if(!empty($terms['standard_protection']) || !empty($terms['selected_extras']))
    <section class="amd-agreed-options">
        <h3>Protezione ed extra concordati</h3>
        @if(!empty($terms['standard_protection']))
            <p><strong>Protezione standard inclusa</strong></p>
            @if($showStandardCoverage ?? true)
                @include('public-cars.partials.option-coverage', ['entries' => $terms['standard_protection']['coverage']])
            @endif
            @include('public-cars.partials.option-terms', ['option' => $terms['standard_protection']])
        @endif
        @foreach($terms['selected_extras'] ?? [] as $extra)
            <div class="amd-agreed-extra">
                <p><strong>{{ $extra['name'] }}</strong>@if($showAmounts ?? true) · {{ number_format($extra['total_cents'] / 100, 2, ',', '.') }} € per il noleggio @endif</p>
                @if($extra['protection_type'] === 'policy')<p>Polizza assicurativa separata.</p>@endif
                @if(!empty($extra['coverage']))
                    <p>Coperture e franchigie con AMD Plus</p>
                    @include('public-cars.partials.option-coverage', ['entries' => $extra['coverage']])
                @endif
                @include('public-cars.partials.option-terms', ['option' => $extra])
            </div>
        @endforeach
    </section>
@endif

<ul class="amd-nav-items">
    @foreach($links as [$label, $url])
        <li>
            @if($label === 'Chi Siamo')
                <div class="amd-nav-submenu">
                    <button class="amd-submenu-toggle" type="button" aria-expanded="false" aria-controls="amd-about-{{ $navigationId }}">Chi Siamo<span class="amd-menu-arrow" aria-hidden="true"></span></button>
                    <div class="amd-submenu-links" id="amd-about-{{ $navigationId }}" hidden>
                        <a href="{{ $url }}">Chi Siamo</a>
                        <a href="{{ $website }}/dicono-di-noi/">Dicono di noi</a>
                    </div>
                </div>
            @else
                <a href="{{ $url }}" @class(['amd-nav-login' => $label === 'Login']) @if($label === 'Cerca auto') aria-current="page" @endif>{{ $label }}</a>
            @endif
        </li>
    @endforeach
</ul>

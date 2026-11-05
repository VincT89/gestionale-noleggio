<ul class="amd-nav-items">
    @foreach($links as [$label, $url, $active])
        <li><a href="{{ $url }}" @if($active) aria-current="page" @endif>{{ $label }}</a></li>
    @endforeach
</ul>

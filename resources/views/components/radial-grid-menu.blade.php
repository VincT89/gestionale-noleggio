{{-- resources/views/components/radial-grid-menu-v16.blade.php --}}
@php
    use Illuminate\Support\Facades\Gate;

    /* ------- 1. Sezioni visibili in base ai permessi ------------------- */
    $visibleSections = collect(config('menu.grid_menu'))
        ->filter(fn($s)=>Gate::any(collect($s['items'])->pluck('permission')->all()))
        ->values();

    /* ------- 2. Offset radiale (max 8) -------------------------------- */
    $offsets = [
        ['x'=> 0,'y'=>-1], ['x'=> 1,'y'=>  0],
        ['x'=> 0,'y'=> 1], ['x'=>-1,'y'=>  0],
        ['x'=> .75,'y'=>-.75],['x'=> .75,'y'=> .75],
        ['x'=>-.75,'y'=> .75],['x'=>-.75,'y'=>-.75],
    ];
@endphp

@once
@push('scripts')
<script>
/* ---------- helper: antenato scrollabile + centratura ----------------- */
function scrollParent(el){
    for(let p=el.parentElement;p&&p!==document.body;p=p.parentElement){
        const s=getComputedStyle(p);
        if(/(auto|scroll)/.test(s.overflow+s.overflowY+s.overflowX)) return p;
    }
    return document.scrollingElement||document.documentElement;
}
function centerTile(el){
    const par   = scrollParent(el);
    const rect  = el.getBoundingClientRect();
    const prect = par.getBoundingClientRect();
    const target = par.scrollTop + rect.top - prect.top - (par.clientHeight/2) + (rect.height/2);
    par.scrollTo({top: target, behavior:'smooth'});
}
</script>
@endpush
@endonce

<div
    x-data="menuGrid()"
    @keydown.escape.stop.prevent="close(true)"
    class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3
           gap-x-10 overflow-visible transition-all duration-100"
    :style="gridStyle()"
    x-cloak
>
@foreach($visibleSections as $i=>$section)
    <div x-ref="tile{{ $i }}" class="relative flex justify-center">

        {{-- pulsante macro-modulo --}}
        <button
            type="button"
            id="dashboard-section-button-{{ $i }}"
            :aria-expanded="openKey === {{ $i }}"
            aria-controls="dashboard-section-{{ $i }}"
            :data-row="Math.floor({{ $i }} / columns)"
            @click.stop="toggle({{ $i }})"
            class="w-28 h-28 bg-white dark:bg-gray-800 rounded-lg shadow
                   flex flex-col items-center justify-center
                   hover:btn-hover-bg-color dark:hover:bg-indigo-900
                   transition-all duration-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-indigo-600">
            <i class="fas {{ $section['icon'] }} text-3xl btn-text-color dark:text-indigo-400" aria-hidden="true"></i>
            <span class="mt-1 text-sm font-medium text-gray-800 dark:text-gray-200">
                {{ $section['section'] }}
            </span>
        </button>

        {{-- menu radiale --}}
        <template x-if="openKey === {{ $i }}">
            <div id="dashboard-section-{{ $i }}" aria-labelledby="dashboard-section-button-{{ $i }}"
                 class="absolute inset-0 flex items-center justify-center pointer-events-none
                        z-30" x-cloak
                 @click.outside="close()">
                @php
                    $items = collect($section['items'])
                             ->filter(fn($it)=>auth()->user()->can($it['permission']))
                             ->values()->take(8);
                @endphp
                @foreach($items as $k=>$item)
                    @php
                        $o = match ($items->count()) {
                            2 => $offsets[$k === 1 ? 2 : 0],
                            3 => [['x' => -.6, 'y' => -1], ['x' => .6, 'y' => -1], ['x' => 0, 'y' => 1]][$k],
                            default => $offsets[$k],
                        };
                    @endphp
                    <a href="{{ route($item['route']) }}"
                       class="absolute w-28 h-28 bg-white dark:bg-gray-800 rounded-full shadow-lg z-40
                              flex flex-col items-center justify-center pointer-events-auto
                              text-gray-800 dark:text-gray-200 hover:scale-105 transition
                              focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                       :style="itemStyle({{ $o['x'] }}, {{ $o['y'] }})">
                        <i class="fas {{ $item['icon'] }} text-lg" aria-hidden="true"></i>
                        <span class="max-w-full px-2 text-xs text-center leading-tight whitespace-normal break-words">{{ $item['label'] }}</span>
                    </a>
                @endforeach
            </div>
        </template>

    </div>
@endforeach
</div>

@push('scripts')
<script>
function getCols(){return window.innerWidth>=768?3:window.innerWidth>=640?2:1}

function menuGrid(){
return{
    openKey:null,columns:getCols(),
    init(){
        this.onResize=()=>this.columns=getCols();
        window.addEventListener('resize',this.onResize);
    },
    destroy(){window.removeEventListener('resize',this.onResize)},

    totalRows(){return Math.ceil({{ $visibleSections->count() }} / this.columns)},

    gridStyle () {
        const hasOpen = this.openKey !== null;
        const openRow = hasOpen ? Math.floor(this.openKey / this.columns) : null;

        const gap = hasOpen ? '10rem' : '2.5rem';
        const padding = this.columns === 1 ? '9rem' : '10rem';
        const top = (hasOpen && openRow === 0) ? padding : '0';
        const bot = (hasOpen && openRow === this.totalRows() - 1) ? padding : '0';

        return `row-gap:${gap}; padding-top:${top}; padding-bottom:${bot}`;
    },

    itemStyle(x,y){
        const radius=this.columns===1?128:144;
        return {left:'50%',top:'50%',transform:`translate(-50%,-50%) translate(${x*radius}px,${y*radius}px)`};
    },

    close(restoreFocus=false){
        const idx=this.openKey;
        this.openKey=null;
        if(restoreFocus && idx!==null) this.$refs['tile'+idx]?.querySelector('button')?.focus();
    },

    toggle(idx){
        this.openKey=this.openKey===idx?null:idx;

        if(this.openKey!==null){
            /* aspetta il repaint + transizione (300ms) → 350ms total */
            this.$nextTick(()=>{
                setTimeout(()=>{
                    const el=this.$refs['tile'+idx];
                    if(el) centerTile(el);
                },150);
            });
        }
    }
}};
</script>
@endpush

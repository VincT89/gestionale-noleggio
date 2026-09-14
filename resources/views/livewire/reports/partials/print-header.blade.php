@if (! empty($printContext))
    <div class="report-print-only mb-4 mt-3" style="color:#111827; overflow-wrap:anywhere;">
        <h1 class="text-xl font-bold">{{ $printContext['title'] }}</h1>

        <div class="mt-1 text-sm font-semibold">
            @if (count($printContext['organization_names']) === 1)
                Noleggiatore: {{ $printContext['organization_names'][0] }}
            @elseif (count($printContext['organization_names']) > 1)
                Noleggiatori: {{ implode('; ', $printContext['organization_names']) }}
            @else
                Nessun noleggiatore nei risultati del periodo selezionato.
            @endif
        </div>

        <div class="mt-1 text-sm">Tipo di analisi: {{ $printContext['report_type_label'] }}</div>
        <div class="mt-1 text-sm">Periodo: {{ $printContext['date_from'] }} - {{ $printContext['date_to'] }}</div>
        <div class="mt-1 text-sm">Generato il: {{ $printContext['generated_at'] }}</div>
    </div>
@endif

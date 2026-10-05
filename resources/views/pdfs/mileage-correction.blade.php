<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>Rettifica del chilometraggio</title>
    <style>
        @page { margin: 42px; }
        body { font: 12px DejaVu Sans, sans-serif; color: #1f2937; line-height: 1.5; }
        h1 { font-size: 21px; margin-bottom: 24px; }
        table { width: 100%; border-collapse: collapse; margin: 24px 0; }
        th, td { border: 1px solid #b8c0ca; text-align: left; padding: 10px; }
        th { background: #f3f4f6; }
        .note { margin-top: 24px; font-size: 10px; color: #374151; }
    </style>
</head>
<body>
    @php
        $data = $correction->properties;
        $km = fn ($value) => $value === null ? 'Non indicato' : number_format((int) $value, 0, ',', '.').' km';
    @endphp
    <h1>Rettifica amministrativa del chilometraggio</h1>
    <p>
        Contratto {{ $data['contract_number'] }} - Noleggio {{ $data['rental_id'] }}<br>
        Veicolo: {{ $data['plate'] }}<br>
        Rettifica {{ $correction->id }} del {{ $correction->created_at->format('d/m/Y H:i') }}<br>
        Amministratore: {{ $data['actor_name'] }}
    </p>
    <p><strong>Motivo:</strong> {{ $data['reason'] }}</p>
    <table>
        <thead><tr><th>Rilevazione</th><th>Prima della rettifica</th><th>Dopo la rettifica</th></tr></thead>
        <tbody>
            <tr><td>Uscita</td><td>{{ $km($data['before']['out']) }}</td><td>{{ $km($data['after']['out']) }}</td></tr>
            <tr><td>Rientro</td><td>{{ $km($data['before']['in']) }}</td><td>{{ $km($data['after']['in']) }}</td></tr>
            <tr><td>Km extra</td><td>{{ $km($data['before']['extra_km']) }}</td><td>{{ $km($data['after']['extra_km']) }}</td></tr>
            <tr><td>Scheda veicolo al momento della rettifica</td><td>{{ $km($data['before']['vehicle']) }}</td><td>{{ $km($data['after']['vehicle']) }}</td></tr>
        </tbody>
    </table>
    @if($data['after']['extra_cents'] !== null)
        <p>Importo dei km extra ricalcolato alle condizioni registrate nel contratto: <strong>{{ number_format($data['after']['extra_cents'] / 100, 2, ',', '.') }} EUR</strong>.</p>
    @else
        <p>Importo dei km extra da verificare: tariffa non disponibile nelle condizioni registrate nel contratto.</p>
    @endif
    @if($data['payment_review'])
        <p>Esistono incassi per km extra registrati prima della rettifica. Occorre verificare gli importi; questo documento non attesta un nuovo pagamento o un rimborso.</p>
    @endif
    <p class="note">Allegare questa rettifica al contratto e alle checklist originali. I documenti firmati originali sono conservati. Questa rettifica registra l'intervento dell'amministratore e non contiene nuove firme del cliente o del noleggiante.</p>
</body>
</html>

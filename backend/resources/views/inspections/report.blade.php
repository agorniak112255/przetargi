<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 12mm 10mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #111; }
        h1 { font-size: 15px; margin: 0 0 4px; }
        h2 { font-size: 11px; margin: 14px 0 3px; }
        .meta { margin-bottom: 8px; color: #444; }
        .customer-meta { color: #444; margin-bottom: 4px; }
        .warn { color: #8a4b00; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ccc; padding: 3px 4px; text-align: left; vertical-align: top; }
        th { background: #f3f4f6; font-size: 8px; }
        tr { page-break-inside: avoid; }
        .right { text-align: right; }
        .muted { color: #555; font-size: 8px; }
        .overdue { color: #b42318; font-weight: bold; }
        .source { margin-top: 12px; color: #555; font-size: 8px; }
    </style>
</head>
<body>
    <h1>Przeglądy u klientów — raport</h1>
    <div class="meta">
        Data raportu: {{ $generatedAt }} · Przygotował: {{ $author }} · Klientów: {{ count($customers) }}
    </div>

    @foreach ($customers as $section)
        @php($c = $section['customer'])
        <h2>{{ $c['acronym'] }}@if ($c['name']) — {{ $c['name'] }}@endif</h2>
        <div class="customer-meta">
            Numer klienta w ERP XL: {{ $c['xl_gid'] }}
            @if ($c['nip']) · NIP: {{ $c['nip'] }}@endif
            @if ($c['city']) · {{ $c['city'] }}@endif
            · E-mail: {{ $c['emails'] !== [] ? implode(', ', $c['emails']) : 'brak danych' }}
            @if (! $c['known'])
                <span class="warn">· klienta nie ma w kopii kartoteki ERP XL — tylko numer</span>
            @endif
            @if ($section['dismissal'])
                <span class="warn">· {{ $section['dismissal']['label'] }}</span>
            @endif
        </div>
        @if ($section['positions'] === [])
            <div class="muted">Brak terminów przeglądów dla tego klienta.</div>
        @else
            <table>
                <thead>
                    <tr>
                        <th>Pozycja</th>
                        <th class="right">Ilość do przeglądu</th>
                        <th>Ostatni przegląd lub zakup</th>
                        <th>Faktura</th>
                        <th class="right">Wartość netto ostatniego</th>
                        <th>Termin przeglądu</th>
                        <th>Stan</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($section['positions'] as $p)
                        <tr>
                            <td>
                                {{ $p['name'] }}
                                <div class="muted">{{ $p['code'] }} · {{ $p['type_label'] }} · {{ \App\Services\Inspections\InspectionQuery::intervalLabel($p['interval_months']) }}@if ($p['location_name']) · {{ $p['location_name'] }}@endif</div>
                                @if ($p['same_nip_newer'])
                                    <div class="muted warn">Inna karta klienta z tym samym NIP-em ma późniejszą sprzedaż tej pozycji.</div>
                                @endif
                            </td>
                            <td class="right">{{ rtrim(rtrim(number_format($p['open_quantity'], 3, ',', ' '), '0'), ',') }} {{ $p['unit'] }}</td>
                            <td>{{ \Illuminate\Support\Carbon::parse($p['last_on'])->format('d.m.Y') }}</td>
                            <td>{{ implode(', ', array_map(static fn (array $d): string => (string) ($d['number'] ?? ''), $p['last_documents'])) }}</td>
                            <td class="right">{{ $p['last_net'] !== null ? number_format($p['last_net'], 2, ',', ' ').' zł' : 'brak danych' }}</td>
                            <td>{{ \Illuminate\Support\Carbon::parse($p['due_on'])->format('d.m.Y') }}</td>
                            <td>
                                <span class="{{ $p['status'] === 'overdue' ? 'overdue' : '' }}">{{ $p['state_label'] }}</span>
                                @if ($p['dismissal_label'])
                                    <div class="muted">{{ $p['dismissal_label'] }}</div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endforeach

    <div class="source">
        Źródło: faktury sprzedaży z ERP XL. Termin wyliczony: ostatni przegląd lub zakup + interwał pozycji ustawiony na liście przeglądów.
        Raport wewnętrzny — wartości netto nie są dla klienta.
    </div>
</body>
</html>

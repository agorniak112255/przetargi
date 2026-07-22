<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111; }
        h1 { font-size: 16px; margin: 0 0 8px; }
        .meta { margin-bottom: 12px; color: #444; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ccc; padding: 4px 5px; text-align: left; vertical-align: top; }
        th { background: #f3f4f6; font-size: 9px; }
        .right { text-align: right; }
        .muted { color: #555; font-size: 8px; }
        .foot { margin-top: 12px; font-size: 11px; }
    </style>
</head>
<body>
    <h1>Oferta SUPON — {{ $tender->number }}</h1>
    <div class="meta">
        <div><strong>Klient:</strong> {{ $tender->client?->name }}</div>
        <div><strong>Tytuł:</strong> {{ $tender->title }}</div>
        <div><strong>Termin:</strong> {{ optional($tender->deadline)->format('Y-m-d') ?? '—' }}</div>
        <div><strong>Opiekun:</strong> {{ $tender->owner?->name }}</div>
        <div><strong>Status:</strong> {{ $tender->status }}</div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Lp</th>
                <th>Wymaganie</th>
                <th>SKU</th>
                <th>Produkt</th>
                <th class="right">Ilość</th>
                <th class="right">Zakup</th>
                <th class="right">Oferta</th>
                <th class="right">Wartość</th>
                <th>Match</th>
                <th>Zamienniki / uwagi</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $r)
                <tr>
                    <td>{{ $r['line_no'] }}</td>
                    <td>{{ $r['requirement'] }}</td>
                    <td>{{ $r['sku'] ?? '—' }}</td>
                    <td>
                        {{ $r['product_name'] }}
                        @if (!empty($r['manufacturer']))
                            <div class="muted">{{ $r['manufacturer'] }}</div>
                        @endif
                    </td>
                    <td class="right">{{ $r['quantity'] }}</td>
                    <td class="right">
                        {{ $r['purchase_price'] !== null ? number_format((float) $r['purchase_price'], 2, ',', ' ') : '—' }}
                    </td>
                    <td class="right">
                        {{ $r['offer_price'] !== null ? number_format((float) $r['offer_price'], 2, ',', ' ') : '—' }}
                    </td>
                    <td class="right">
                        {{ $r['line_value'] !== null ? number_format((float) $r['line_value'], 2, ',', ' ') : '—' }}
                    </td>
                    <td>
                        {{ $r['match_percent'] !== null ? $r['match_percent'].'%' : '—' }}
                        @if ($r['match_reasons'])
                            <div class="muted">{{ $r['match_reasons'] }}</div>
                        @endif
                    </td>
                    <td>
                        {{ $r['substitute_skus'] !== '' ? $r['substitute_skus'] : '—' }}
                        @if ($r['highlights'])
                            <div class="muted">{{ $r['highlights'] }}</div>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="foot">
        <strong>Wartość netto:</strong>
        {{ $tender->offer_value_net !== null ? number_format((float) $tender->offer_value_net, 2, ',', ' ').' zł' : '—' }}
        &nbsp;·&nbsp;
        <strong>Marża:</strong> {{ $tender->margin_percent ?? '—' }}%
    </div>
    <p style="margin-top:18px;color:#666;font-size:9px;">
        Wygenerowano z Przetargi Supon · {{ now()->format('Y-m-d H:i') }} · ceny zakupu = cennik po upuście
    </p>
</body>
</html>

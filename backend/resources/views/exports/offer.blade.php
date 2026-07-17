<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111; }
        h1 { font-size: 18px; margin: 0 0 8px; }
        .meta { margin-bottom: 16px; color: #444; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ccc; padding: 6px; text-align: left; vertical-align: top; }
        th { background: #f3f4f6; }
        .right { text-align: right; }
        .foot { margin-top: 14px; font-size: 12px; }
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
                <th class="right">Cena</th>
                <th class="right">Wartość</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($tender->items as $item)
                @php
                    $line = $item->offer_price !== null
                        ? (float) $item->offer_price * $item->quantity
                        : null;
                @endphp
                <tr>
                    <td>{{ $item->line_no }}</td>
                    <td>{{ $item->requirement }}</td>
                    <td>{{ $item->mainProduct?->sku ?? '—' }}</td>
                    <td>{{ $item->mainProduct?->name ?? '—' }}</td>
                    <td class="right">{{ $item->quantity }}</td>
                    <td class="right">{{ $item->offer_price ?? '—' }}</td>
                    <td class="right">{{ $line !== null ? number_format($line, 2, ',', ' ') : '—' }}</td>
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
    <p style="margin-top:24px;color:#666;font-size:10px;">
        Wygenerowano z SUPON AI · {{ now()->format('Y-m-d H:i') }}
    </p>
</body>
</html>

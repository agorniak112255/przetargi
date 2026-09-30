{{-- Publiczna strona produktu z maila kampanii — bez skryptów; wszystkie treści escapowane. --}}
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>{{ ($missing ?? false) ? 'Nie znaleziono' : $name }} — {{ $company }}</title>
</head>
<body style="margin:0;padding:0;background:#eef1f3;font-family:Arial,Helvetica,sans-serif;color:#1b2328;">
  <div style="max-width:720px;margin:32px auto;padding:0 16px;">
    <div style="background:#ffffff;border-radius:8px;border-top:3px solid #0b7d6a;padding:20px 24px;">
      <div style="font-weight:700;font-size:18px;color:#0b7d6a;">{{ $company }}</div>
@if ($missing ?? false)
      <h1 style="font-size:20px;margin:16px 0 8px;">Nie znaleziono produktu</h1>
      <p style="margin:0;line-height:1.5;">Ten link jest nieprawidłowy albo oferta już wygasła. Odpowiedz na otrzymaną wiadomość, a przygotujemy aktualną ofertę.</p>
@else
      <div style="font-size:12px;color:#5d6970;margin-bottom:16px;">{{ $tagline }}</div>
      <div style="display:flex;flex-wrap:wrap;gap:20px;">
        <div style="flex:1 1 240px;min-width:0;">
@if ($image !== null)
          <img src="{{ $image }}" alt="{{ $name }}" style="display:block;width:100%;max-width:320px;height:auto;border-radius:6px;border:1px solid #e2e7ea;">
@else
          <div style="width:100%;max-width:320px;aspect-ratio:1;background:#f3f5f6;border-radius:6px;"></div>
@endif
        </div>
        <div style="flex:1 1 280px;min-width:0;">
          <h1 style="font-size:21px;line-height:1.3;margin:0 0 6px;">{{ $name }}</h1>
@if ($code !== '')
          <div style="font-size:12px;color:#5d6970;">Kod {{ $code }}</div>
@endif
@if ($price !== null)
          <div style="margin-top:12px;">
@if ($priceBefore !== null)
            <span style="font-size:13px;color:#5d6970;text-decoration:line-through;">{{ $priceBefore }}</span><br>
@endif
            <span style="font-size:24px;font-weight:700;color:#0b7d6a;">{{ $price }}</span>
            <span style="font-size:12px;color:#5d6970;">netto / {{ $unit }}</span>
          </div>
@endif
@if ($stock !== null)
          <div style="font-size:13px;color:#5d6970;margin-top:6px;">Na stanie: {{ $stock }}</div>
@endif
          <div style="font-size:12px;color:#5d6970;margin-top:6px;">
            Ceny netto ważne{{ $validUntil !== null ? ' do '.$validUntil.' lub' : '' }} do wyczerpania zapasów.
          </div>
@if ($note !== null && trim($note) !== '')
          <p style="margin:12px 0 0;line-height:1.5;">{{ $note }}</p>
@endif
@if ($askUrl !== null)
          <a href="{{ $askUrl }}" style="display:inline-block;margin-top:16px;background:#0b7d6a;color:#ffffff;text-decoration:none;border-radius:4px;padding:10px 18px;font-size:15px;font-weight:700;">Zapytaj o ofertę</a>
@endif
        </div>
      </div>
@if ($description !== null)
      <h2 style="font-size:15px;margin:24px 0 6px;">Opis</h2>
      <p style="margin:0;line-height:1.55;font-size:14px;">{{ $description }}</p>
@endif
      <div style="margin-top:24px;padding-top:12px;border-top:1px solid #e2e7ea;font-size:13px;line-height:1.5;">
@if ($signature !== null && trim($signature) !== '')
        {!! nl2br(e($signature), false) !!}
@else
        <b>{{ $fromName }}</b>
@endif
@if ($fromAddress !== null)
        <br><a href="mailto:{{ $fromAddress }}" style="color:#0b7d6a;">{{ $fromAddress }}</a>
@endif
      </div>
@if ($unsubscribeUrl !== null)
      <div style="margin-top:16px;font-size:11px;color:#5d6970;"><a href="{{ $unsubscribeUrl }}" style="color:#5d6970;">Wypisz mnie z mailingu</a></div>
@endif
@endif
    </div>
  </div>
</body>
</html>

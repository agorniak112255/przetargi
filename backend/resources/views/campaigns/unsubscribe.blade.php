{{-- Publiczna strona wypisu z mailingu kampanii — bez skryptów, bez zewnętrznych zasobów. --}}
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Wypis z mailingu — {{ $company }}</title>
</head>
<body style="margin:0;padding:0;background:#eef1f3;font-family:Arial,Helvetica,sans-serif;color:#1b2328;">
  <div style="max-width:480px;margin:48px auto;padding:0 16px;">
    <div style="background:#ffffff;border-radius:8px;border-top:3px solid #0b7d6a;padding:24px;">
      <div style="font-weight:700;font-size:18px;color:#0b7d6a;margin-bottom:16px;">{{ $company }}</div>
@if ($state === 'confirm')
      <h1 style="font-size:20px;margin:0 0 8px;">Wypisać adres z mailingu?</h1>
      <p style="margin:0 0 16px;line-height:1.5;">Adres <b>{{ $masked }}</b> nie będzie już dostawał od nas ofert mailowych.</p>
      <form method="post" action="">
        <button type="submit" style="background:#0b7d6a;color:#ffffff;border:0;border-radius:4px;padding:10px 18px;font-size:15px;font-weight:700;cursor:pointer;">Wypisz mnie</button>
      </form>
@elseif ($state === 'done')
      <h1 style="font-size:20px;margin:0 0 8px;">Wypisano</h1>
      <p style="margin:0;line-height:1.5;">Adres <b>{{ $masked }}</b> nie będzie już dostawał od nas ofert mailowych.</p>
@elseif ($state === 'already')
      <h1 style="font-size:20px;margin:0 0 8px;">Adres jest już wypisany</h1>
      <p style="margin:0;line-height:1.5;">Adres <b>{{ $masked }}</b> nie dostaje od nas ofert mailowych.</p>
@else
      <h1 style="font-size:20px;margin:0 0 8px;">Nie znaleziono linku</h1>
      <p style="margin:0;line-height:1.5;">Ten link jest nieprawidłowy. Aby wypisać się z mailingu, użyj linku z otrzymanej wiadomości albo odpowiedz nadawcy z prośbą o wypisanie.</p>
@endif
    </div>
  </div>
</body>
</html>

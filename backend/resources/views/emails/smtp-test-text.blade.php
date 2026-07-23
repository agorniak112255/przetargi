Test poczty wychodzącej — Przetargi Supon

Co oznacza ta wiadomość?
To automatyczny test z panelu Administracja → SMTP.
Jeśli ją widzisz, serwer poczty przyjął wiadomość i dostarczył ją na adres {{ $recipient }}.

Parametry połączenia
- Host: {{ $host }}
- Port: {{ $port }}
- Login SMTP: {{ $username }}
- TLS / schemat: {{ $scheme }}
- Nadawca: {{ $fromName }} <{{ $fromAddress }}>
- Wysłano: {{ $sentAt }}
- Sterownik: {{ $mailer }}

Ta wiadomość nie wymaga odpowiedzi. Służy wyłącznie do weryfikacji ustawień poczty
używanych m.in. przy zaproszeniach do przetargów.

—
System Przetargi Supon
@if($appUrl)
{{ $appUrl }}
@endif

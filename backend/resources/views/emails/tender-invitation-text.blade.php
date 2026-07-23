Zaproszenie do przetargu — Przetargi Supon

Cześć {{ $inviteeName }},

{{ $inviterName }} zaprasza Cię do przetargu. Masz takie same uprawnienia pracy jak opiekun tego projektu.

Numer: {{ $tenderNumber }}
Tytuł: {{ $tenderTitle }}
@if($clientName)
Klient: {{ $clientName }}
@endif
@if($deadline)
Termin składania: {{ $deadline }}
@endif
@if($note)

Wiadomość:
{{ $note }}
@endif

Otwórz przetarg:
{{ $tenderUrl }}

—
Wiadomość wysłana automatycznie z systemu Przetargi Supon

Dane dostępu do systemu Przetargi Supon

Cześć {{ $userName }},

Poniżej aktualne dane logowania do systemu Przetargi Supon. Hasło zostało ustawione razem z tą wiadomością — poprzednie hasło przestało działać.

Login: {{ $login }}
Hasło: {{ $password }}
Rola: {{ $roleLabel }}

Zaloguj się:
{{ $appUrl }}

@if ($hasAddon)
Dodatek do Thunderbirda w załączniku ({{ $addonFileName }}): zapisz plik na dysku, a potem w Thunderbirdzie Narzędzia → Dodatki i motywy → koło zębate → „Zainstaluj dodatek z pliku…” → wskaż zapisany plik → „Dodaj”. W ustawieniach dodatku podaj adres aplikacji, login i hasło z tej wiadomości. Instrukcja z obrazkami: {{ $helpUrl }}

@endif
Zachowaj tę wiadomość w bezpiecznym miejscu albo usuń ją po zapisaniu hasła. Hasło zmienisz po zalogowaniu w zakładce „Moje konto”.

—
Wiadomość wysłana automatycznie z systemu Przetargi Supon

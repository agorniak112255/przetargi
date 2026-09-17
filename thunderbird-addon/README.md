# Supon Przetargi — dodatek do Thunderbirda

Zakłada zapytanie w aplikacji Przetargi z otwartego maila i wstawia przygotowaną
odpowiedź jako odpowiedź na ten sam mail.

## Jak to działa

1. Otwierasz mail od klienta i klikasz ikonę dodatku nad wiadomością.
2. Dodatek pokazuje całą treść maila — możesz ją poprawić przed wysłaniem.
3. Klikasz **Wyślij do Przetargów**. Analiza trwa nawet ponad minutę i idzie w tle
   dodatku — okienko możesz zamknąć. Gdy zapytanie jest gotowe, samo otwiera się
   w przeglądarce i pojawia się powiadomienie.
4. Wybierasz produkty w aplikacji.
5. Wracasz do Thunderbirda, klikasz ikonę dodatku i **Wstaw odpowiedź do maila**.
   Otwiera się zwykłe okno odpowiedzi — z adresatem, cytatem i podpisem — z gotową treścią na górze.
6. Wysyłasz. Dodatek sam oznacza zapytanie w aplikacji jako obsłużone.

## Gdy ten sam mail ma już ktoś inny

Ten sam mail od klienta trafia czasem do kilku handlowców naraz. Jeśli zapytanie
z tego maila założył już kto inny, aplikacja odpowiada kodem **409** i dodatek:

- **nie zakłada** drugiego zapytania i nie otwiera przeglądarki,
- pokazuje powiadomienie „Tym zapytaniem zajmuje się już Anna Kowalska (od
  17.09.2026 08:15)”; gdy tamta osoba wysłała już odpowiedź do klienta, mówi
  o tym wprost — druga oferta od nas byłaby błędem,
- zapamiętuje to ostrzeżenie przy tym mailu (przeżywa zamknięcie okienka
  i restart Thunderbirda).

Po ponownym kliknięciu ikony nad tym mailem okienko pokazuje, kto prowadzi
zapytanie, od kiedy, czy już odpowiedział, i skąd wiadomo, że to ten sam mail
(identyfikator wiadomości albo sama treść — gdy mail został przekazany ręcznie).
Do wyboru są trzy przyciski:

- **Otwórz zapytanie kolegi** — otwiera tamto zapytanie w przeglądarce,
- **Załóż mimo to** — zakłada własne zapytanie (aplikacja powiąże je z tamtym),
- **Anuluj** — kasuje ostrzeżenie przy tym mailu i wraca do zwykłego ekranu.

Ostrzeżenie znika też samo, gdy zapytanie faktycznie powstanie.

## Instalacja

Dodatek nie jest podpisany przez Mozillę, więc trzeba raz wyłączyć wymóg podpisu.

1. Thunderbird → menu **☰** → **Ustawienia** → **Ogólne** → na dole **Edytor konfiguracji**.
2. Znajdź `xpinstall.signatures.required` i ustaw na **false**.
3. Menu **☰** → **Dodatki i motywy** → koło zębate → **Zainstaluj dodatek z pliku**.
4. Wskaż plik `supon-przetargi.xpi`.
5. Menu **☰** → **Dodatki i motywy** → przy dodatku **Ustawienia** → wpisz adres aplikacji,
   swój e-mail i hasło → **Połącz**.

Hasło nie jest zapisywane — służy tylko do jednorazowego pobrania klucza dostępu.

## Aktualizacja

Nową wersję instaluje się **na wierzch starej** — nie odinstalowuj poprzedniej,
bo razem z nią znikają zapisane dane logowania. Identyfikator dodatku się nie
zmienia, więc Thunderbird podmienia pliki i zostawia ustawienia.

Dodatek pyta serwer o aktualizacje pod adresem
`https://przetargi.supon.rzeszow.pl/dodatek/updates.json`, więc po wgraniu nowej
wersji na serwer Thunderbird sam ją zauważy (sprawdza co kilka godzin; ręcznie:
**Dodatki i motywy → koło zębate → Sprawdź dostępność aktualizacji**).

## Budowanie pliku XPI

XPI to zwykłe ZIP-owe archiwum zawartości tego katalogu (bez katalogu nadrzędnego):

```bash
cd thunderbird-addon && zip -r -FS ../supon-przetargi.xpi . -x '*.git*' 'README.md'
```

Na stanowisku bez polecenia `zip` (Windows) to samo robi PowerShell:

```bash
powershell -Command "Compress-Archive -Path C:\xampp\htdocs\Przetargi\thunderbird-addon\* -DestinationPath C:\xampp\htdocs\Przetargi\supon-przetargi.zip -Force; Move-Item C:\xampp\htdocs\Przetargi\supon-przetargi.zip C:\xampp\htdocs\Przetargi\supon-przetargi.xpi -Force"
```

Przy każdej poprawce podnieś `version` w `manifest.json` — bez tego Thunderbird
nie zaproponuje aktualizacji przy instalacji nowego pliku.

## Adres aplikacji

Dodatek ma w `manifest.json` uprawnienie do domeny
`https://przetargi.supon.rzeszow.pl/*` i tylko z nią może się łączyć — bez tego
Thunderbird blokuje zapytania (komunikat „Brak połączenia z…”). Po zmianie adresu
aplikacji trzeba dopisać nową domenę do `permissions` i zbudować XPI od nowa.

## Wymagania po stronie aplikacji

- konto z uprawnieniem `inquiries.use`,
- API: `POST /api/login`, `POST /api/inquiries`, `GET /api/inquiries/{id}`,
  `POST /api/inquiries/{id}/replied`.
- `POST /api/inquiries` przyjmuje pole `force` (domyślnie false) i przy cudzym
  zapytaniu z tego samego maila odpowiada **409** z polem `duplicate`
  (`id`, `user.name`, `created_at`, `replied_at`, `match`).

## Podział zadań

Dodatek wysyła **całą** treść maila i nic z niej nie usuwa. Cytat poprzedniej
wiadomości, nagłówek przekazania („--- Treść przekazanej wiadomości ---”), podpis
i klauzulę poufności odcina aplikacja (`App\Support\InquiryMailText`) — dopiero
na potrzeby analizy. W bazie zostaje cały mail, a to, co poszło do modelu,
zapisuje się w `analysis.analyzed_body`.

Dzięki temu ta sama zasada działa też wtedy, gdy ktoś wklei maila w przeglądarce.

## Nadawca i data maila

Razem z treścią dodatek przekazuje nagłówek **From** maila (`source_from`, np.
„Jan Kowalski <jan@firma.pl>”) i **datę wysłania** (`source_sent_at`) w formacie
ISO 8601. Aplikacja rozbija nagłówek na nazwę i adres, dzięki czemu na liście
zapytań widać, od kogo i kiedy przyszedł mail. Gdy Thunderbird nie poda którejś
z tych wartości, wysyłane jest `null` — nic nie jest zgadywane.

## Numer wersji

Wersja dodatku jest widoczna na dole okienka nad mailem i na dole strony
ustawień („Supon Przetargi 1.4.0”) — czytana z `manifest.json`, więc zawsze
zgadza się z tym, co faktycznie jest zainstalowane.

## Ograniczenia

- Załączniki (PDF, Excel) nie są wysyłane — do analizy idzie sam tekst maila.
- Maile zaszyfrowane (OpenPGP, S/MIME) nie są odczytywane.
- Jeśli zamkniesz okno odpowiedzi i wyślesz ją później ręcznie, zapytanie trzeba
  oznaczyć w aplikacji przyciskiem „Kopiuj i oznacz wysłane”.

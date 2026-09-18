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

Okienko nad mailem pyta o to aplikację także **bez wysyłania czegokolwiek**:
otwierasz mail, klikasz ikonę i od razu widzisz, czy ktoś już to prowadzi —
również wtedy, gdy zapytanie powstało na innym komputerze. Tak samo wraca
własne zapytanie założone na drugiej maszynie.

Okienko pokazuje, kto prowadzi zapytanie, od kiedy, czy już odpowiedział,
i skąd wiadomo, że to ten sam mail
(identyfikator wiadomości albo sama treść — gdy mail został przekazany ręcznie).
Do wyboru są trzy przyciski:

- **Otwórz zapytanie kolegi** — otwiera tamto zapytanie w przeglądarce,
- **Załóż mimo to** — zakłada własne zapytanie (aplikacja powiąże je z tamtym),
- **Anuluj** — kasuje ostrzeżenie przy tym mailu i wraca do zwykłego ekranu.

Ostrzeżenie znika też samo, gdy zapytanie faktycznie powstanie.

## Oznaczanie maili na liście

Mail, z którego powstało zapytanie, dostaje na liście wiadomości znacznik
z nazwiskiem osoby, która się nim zajmuje:

- pomarańczowy **„Zapytanie: Anna Kowalska”** — zapytanie założone, odpowiedź
  do klienta jeszcze nie poszła,
- zielony **„Wysłane: Anna Kowalska”** — odpowiedź do klienta została wysłana;
  druga oferta od nas byłaby błędem.

Nad jednym mailem mogą siedzieć dwie osoby (ktoś kliknął „Załóż mimo to”) —
wtedy mail ma dwa znaczniki.

### Skąd to wie każdy komputer

Znaczniki są lokalne (siedzą w profilu Thunderbirda), ale to, czym oznaczamy,
pochodzi z aplikacji. Ten sam mail wysłany na kilka adresów ma u wszystkich ten
sam `Message-ID`, więc każdy dodatek dostaje z serwera tę samą odpowiedź
i stawia te same znaczniki.

Kierunek pytania jest odwrotny, niż się wydaje: serwer podaje identyfikatory
maili, wokół których coś się działo (`GET /api/inquiries/message-ids`), a dodatek
szuka ich u siebie. Przeglądanie całej skrzynki co kilka minut byłoby wielokrotnie
droższe — skrzynki handlowców mają dziesiątki tysięcy maili.

Kiedy dodatek sprawdza stan:

- przy otwarciu maila (od razu, bo to jedyna chwila, gdy ktoś naprawdę patrzy),
- co 5 minut — nowe i zmienione zapytania,
- co pół godziny — powtórnie maile już oznaczone; tak znika znacznik po
  usunięciu zapytania w aplikacji,
- natychmiast po założeniu własnego zapytania i po wysłaniu odpowiedzi.

Świeżo zainstalowany dodatek nadgania zaległości z ostatnich 90 dni.

### Zgoda na zmianę znaczników

Zmiana znaczników wiadomości to osobne uprawnienie Thunderbirda, więc trzeba je
raz włączyć: **Dodatki i motywy → przy dodatku Ustawienia → Oznaczanie maili na
liście → Włącz oznaczanie maili**. Bez zgody dodatek działa jak dotąd, tylko bez
kolorów na liście.

Uprawnienie jest **opcjonalne** celowo. Uprawnienie dopisane jako wymagane
zatrzymuje automatyczną aktualizację dodatku do czasu, aż człowiek zatwierdzi je
w Menedżerze dodatków — nowa wersja weszłaby wtedy tylko u tych, którzy sami
by to wypatrzyli.

Ten sam ekran ma przycisk **Wyłącz i usuń znaczniki** — zdejmuje wszystkie
znaczniki dodatku i odbiera zgodę. Dodatek rusza wyłącznie znaczniki z kluczem
zaczynającym się od `supon-`; kolory ustawione ręcznie przez handlowca zostają
nietknięte.

### Czego oznaczanie nie obejmuje

- **Mail przekazany ręcznie** ze skrzynki ogólnej ma nowy `Message-ID`, więc
  znacznika nie dostanie. Taki przypadek łapie dopiero odcisk treści przy
  zakładaniu zapytania (ostrzeżenie o duplikacie, wyżej).
- **Zapytania wklejone w przeglądarce** nie mają `Message-ID` — nie ma czego
  szukać w poczcie.
- Znaczniki nie wędrują między komputerami same z siebie. Na serwerach IMAP,
  które wspierają dowolne słowa kluczowe, przenoszą się między komputerami tej
  samej osoby; niezależnie od tego stan zawsze odtwarza się z serwera aplikacji.

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

Sam dodatek też tego pilnuje, żeby nikt nie pracował na starej wersji, nie
wiedząc o tym:

- **Ustawienia → Aktualizacje** pokazują zainstalowaną wersję i mają przycisk
  **Sprawdź aktualizacje** (czyta ten sam `updates.json`, więc nigdy nie powie
  czegoś innego niż sam Thunderbird),
- w tle sprawdzenie idzie 45 sekund po starcie i potem co 6 godzin; o nowej
  wersji dodatek mówi **raz** powiadomieniem,
- okienko nad mailem wypisuje na dole, że nowa wersja czeka,
- przycisk **Pobierz nową wersję** otwiera plik XPI w przeglądarce — to droga
  awaryjna, gdy automat zawiedzie: pobrany plik instaluje się przez
  **Dodatki i motywy → koło zębate → Zainstaluj dodatek z pliku**.

`updates.json` zawiera `update_hash` (SHA-256 pliku XPI). Dodatek nie jest
podpisany przez Mozillę, więc suma kontrolna jest jedyną weryfikacją, że
Thunderbird pobrał dokładnie ten plik, który zbudowaliśmy.

## Budowanie pliku XPI

Normalnie buduje się to jednym poleceniem — ono składa XPI, kopiuje je do
`backend/public/dodatek/` i przelicza `updates.json` razem z sumą kontrolną:

```bash
python C:/xampp/htdocs/Przetargi/thunderbird-addon/build.py
```

Ręcznie XPI to zwykłe ZIP-owe archiwum zawartości tego katalogu (bez katalogu
nadrzędnego):

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
  `POST /api/inquiries/{id}/replied`, `GET /api/inquiries/queued`,
  `POST /api/inquiries/{id}/queue-reply`.
- oznaczanie maili: `POST /api/inquiries/lookup` (paczka do 200 `message_ids`;
  odpowiedź to mapa `Message-ID → lista zapytań` z `id`, `user`, `mine`,
  `created_at`, `replied_at`; **brak klucza znaczy „sprawdzone, nie ma nic”** —
  dodatek zdejmuje wtedy znacznik) oraz `GET /api/inquiries/message-ids?since=`
  (identyfikatory maili ruszonych po tej chwili, `next_since`, `has_more`).
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
ustawień („Supon Przetargi 1.5.0”) — czytana z `manifest.json`, więc zawsze
zgadza się z tym, co faktycznie jest zainstalowane.

## Ograniczenia

- Załączniki (PDF, Excel) nie są wysyłane — do analizy idzie sam tekst maila.
- Maile zaszyfrowane (OpenPGP, S/MIME) nie są odczytywane.
- Jeśli zamkniesz okno odpowiedzi i wyślesz ją później ręcznie, zapytanie trzeba
  oznaczyć w aplikacji przyciskiem „Kopiuj i oznacz wysłane”.

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

## Na który mail idzie odpowiedź

Zawsze na ten, z którego powstało zapytanie — dodatek szuka go po `Message-ID`
zapisanym w zapytaniu, a nie po tym, co jest w danej chwili zaznaczone na
liście wiadomości. Dotyczy to obu drog:

- „Wstaw odpowiedź do maila” w okienku nad mailem,
- „Zapisz i wyślij w Thunderbirdzie” w aplikacji (dodatek podejmuje prośbę
  z serwera w ciągu kilkunastu sekund).

Gdy tego maila nie ma w tym Thunderbirdzie, dodatek **nie otwiera żadnej
odpowiedzi** — mówi wprost, którego identyfikatora nie znalazł. Wcześniej brał
pierwszą wiadomość z wyszukiwania, a przy pustym identyfikatorze wyszukiwanie
oddawało całą skrzynkę: odpowiedź potrafiła otworzyć się na przypadkowym mailu.

Z kilku kopii tej samej wiadomości (ta sama w skrzynce i w archiwum) wybierana
jest kopia ze zwykłego folderu — nie z Wysłanych, Kosza ani Szkiców.

Zapytanie wklejone w przeglądarce nie ma `Message-ID`; wtedy jedynym wskazaniem
jest mail otwarty w okienku dodatku, a z aplikacji taka odpowiedź nie da się
wysłać przez Thunderbirda (dodatek to mówi).

## Szablony listu

Pod przyciskiem „Wyślij do Przetargów” wybiera się szablon listu do klienta.
Ten sam dobór towarów wygląda w nim inaczej:

| Szablon | Co widzi klient w pozycji |
| --- | --- |
| **Handlowy (pełna specyfikacja)** | nazwa z katalogu, SKU i producent, normy, cena |
| **Bez SKU (proste opisy)** | jedno zdanie opisu bez marki i modelu, normy, cena |
| **Oficjalny (długie opisy)** | nazwa, akapit opisu z karty wyrobu, normy, cena — bez SKU |

Skąd bierze się opis: z karty wyrobu w aplikacji (`products.description`), nie
z modelu językowego przy pisaniu listu. Do szablonu oficjalnego wchodzi proza
z karty (bez przepisanych ze strony dostawcy bloków typu „NORMY I CERTYFIKATY:”),
przycięta na końcu zdania. Do szablonu bez SKU — jej pierwsze zdanie z wyciętą
nazwą producenta i oznaczeniem modelu.

Gdy karta nie ma opisu, szablon bez SKU opisuje pozycję **słowami klienta
z zapytania** — nic nie jest dopisywane od siebie. Zamiennika nigdy nie
opisujemy słowami klienta (pytał o co innego): bez opisu w karcie taka
propozycja po prostu nie wchodzi do listu.

Szablon można zmienić także później, na stronie odpowiedzi w aplikacji
(„Dla całej oferty” → „Szablon listu”) — list przepisuje się od razu.

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

## Kolumna „Prowadzi”

Na liście wiadomości dochodzi kolumna **Prowadzi**: nazwisko osoby, która
prowadzi zapytanie z tego maila, i **✓**, gdy odpowiedź do klienta już poszła.
Gdy nad jednym mailem siedzą dwie osoby, widać obie.

Dlaczego kolumna, a nie sam znacznik: znacznik koloruje **cały wiersz** i zlewa
się z kolorami, których handlowcy używają do własnych spraw. Kolumna nie rusza
ani kolorów, ani etykiet — i **nie wymaga żadnej zgody** na zmianę wiadomości,
bo niczego w mailu nie zapisuje.

Szerokość, kolejność i ukrycie kolumny ustawia się ikoną po prawej stronie
nagłówków listy; Thunderbird pamięta ten układ sam.

### Gdy kolumny nie widać

API eksperymentalne Thunderbird ładuje **przy starcie programu**, więc po
aktualizacji dodatku kolumna nie pojawia się sama — trzeba raz zamknąć i
otworzyć Thunderbirda. Dodatek mówi o tym powiadomieniem (raz na dobę), a
w ustawieniach dodatku widać stan kolumny i jest przycisk **Pokaż kolumnę**.

Gdy po restarcie nadal jej nie ma, sprawdź w edytorze konfiguracji
(Ustawienia → Ogólne → Edytor konfiguracji) ustawienie
`extensions.experiments.enabled` — musi być **true**. Bez niego Thunderbird
pomija całą deklarację kolumny i nie mówi o tym nic.

### Czym to jest okupione

Thunderbird nie ma zwykłego API do dokładania kolumn (zgłoszenie 1615801 jest
otwarte od lat), więc kolumna sięga wprost do wnętrza programu — to jest **API
eksperymentalne** (`experiment/columns`). Skutki, o których trzeba wiedzieć:

- działa od **Thunderbirda 128**; na starszych wydaniach dodatek instaluje się
  i działa normalnie, tylko bez kolumny,
- moduł, z którego korzystamy, należy do wnętrza Thunderbirda i przy większym
  wydaniu może zmienić nazwę albo zniknąć. Wszystkie odwołania są w osłonach:
  gdy modułu zabraknie, kolumna po prostu się nie pokaże, a reszta dodatku
  działa dalej,
- w ustawieniach dodatku widać, czy kolumna działa, i jest przycisk
  **Ukryj kolumnę i wyczyść**.

Znaczniki zostają obok kolumny i można je wyłączyć osobno (**Wyłącz i usuń
znaczniki**) — kolumna działa bez nich. Gdy kolumna działa, dodatek **nie
namawia** już do włączania znaczników: pasek w okienku nad mailem się nie
pokazuje, a w ustawieniach stoi wprost, że kolorowe etykiety nie są potrzebne.

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

- **co 2 minuty** — nowe i zmienione zapytania (jedno krótkie pytanie do
  aplikacji; gdy nic się nie zmieniło, nie robi nic więcej),
- co pół godziny — powtórnie maile już oznaczone; tak znika oznaczenie po
  usunięciu zapytania w aplikacji,
- natychmiast po założeniu własnego zapytania i po wysłaniu odpowiedzi.

**Przy klikaniu w maile dodatek nie pyta serwera o nic.** Wcześniej każde
kliknięcie w inny mail szło własnym zapytaniem do aplikacji, czytało listę
znaczników i przerysowywało listę wiadomości — przy przewijaniu skrzynki
strzałkami poczta wyraźnie zwalniała. Nic na tym nie tracimy: przejście w tle
i tak chodzi co dwie minuty, a okienko nad mailem sprawdza stan na żywo, gdy
sam je otworzysz.

Dodatek pilnuje też, żeby nie robić pracy bez potrzeby: mail, przy którym nic
się nie zmieniło, nie jest w ogóle dotykany, listę znaczników czyta raz na kilka
minut, a maile kolegów, których nie ma w tej skrzynce, pamięta przez dobę i nie
szuka ich w kółko.

Świeżo zainstalowany dodatek nadgania zaległości z ostatnich 90 dni.

### Historia: skąd wiadomo o mailach sprzed kilku dni

Treść kolumny siedzi w pamięci dodatku i **przeżywa zamknięcie Thunderbirda** —
po otwarciu poczty nazajutrz maile sprzed kilku dni nadal mają nazwisko. Zwykłe
przejście pyta tylko o zmiany od ostatniego razu, więc po nocy, weekendzie czy
urlopie dodatek dostaje wszystko, co się w międzyczasie działo, jednym pytaniem.

Do tego raz na dobę idzie **pełne pytanie o ostatnie 14 dni** — siatka
bezpieczeństwa na wypadek, gdyby któreś przejście się nie udało (brak sieci,
komputer wyłączony w złym momencie). Dzięki temu dziura w historii nie zostaje
na zawsze.

### Zgoda na zmianę znaczników

Oznaczanie potrzebuje **trzech** zgód Thunderbirda: na założenie znacznika,
na **odczyt listy znaczników** (osobne uprawnienie od Thunderbirda 122!) i na
zapis znacznika na mailu. Brak środkowej zgody nie daje żadnego komunikatu —
Thunderbird po prostu nie udostępnia funkcji, a wywołanie kończy się błędem
„list is not a function”. Właśnie tego brakowało w wersjach 1.5.0–1.9.0 i dlatego
nic się nie oznaczało. Po aktualizacji do 1.10.0 trzeba **włączyć oznaczanie
jeszcze raz**, bo dochodzi nowa zgoda.

Zgody włącza się raz. Dopóki zgody nie ma, **okienko nad mailem samo o nią prosi**:
u dołu pojawia się „Nie widzisz na liście, kto zajmuje się mailem” z przyciskiem
**Włącz oznaczanie**. To samo da się zrobić w **Dodatki i motywy → przy dodatku
Ustawienia → Oznaczanie maili na liście**. Bez zgody dodatek działa jak dotąd,
tylko bez kolorów na liście.

O zgodę prosi okienko, a nie tło dodatku, bo Thunderbird pyta o uprawnienia
wyłącznie w odpowiedzi na kliknięcie człowieka.

Uprawnienie jest **opcjonalne** celowo. Uprawnienie dopisane jako wymagane
zatrzymuje automatyczną aktualizację dodatku do czasu, aż człowiek zatwierdzi je
w Menedżerze dodatków — nowa wersja weszłaby wtedy tylko u tych, którzy sami
by to wypatrzyli.

Gdy oznaczenie nie wejdzie, dodatek **mówi o tym powiadomieniem** — zaraz po
założeniu zapytania i przy otwarciu maila (najwyżej raz na godzinę, żeby nie
zasypywać). W treści stoi powód: brak zgody, brak połączenia z aplikacją, odmowa
Thunderbirda albo „znacznik nie został na mailu”, gdy serwer poczty nie przyjmuje
własnych etykiet. Wcześniej każda taka awaria kończyła się wpisem w konsoli tła,
której nikt nie czyta, i wyglądała jak „nic się nie dzieje”.

Gdy na liście nic się nie oznacza, ten sam ekran ma dwa przyciski do sprawdzenia:

- **Oznacz wszystko od nowa** — kasuje znacznik czasu i pamięć oznaczonych maili,
  więc najbliższe przejście idzie przez całe okno 90 dni,
- **Sprawdź oznaczanie** — samotest, który wypisuje po kolei: wersję
  Thunderbirda, zgodę, dostępność API znaczników, połączenie z aplikacją, ile
  maili z zapytaniami widzi serwer, ile z nich jest w tym Thunderbirdzie, jakie
  znaczniki stoją na mailu i czy próbny zapis się udał. Raport da się wkleić
  w zgłoszeniu — bez niego każda awaria tej drogi kończyła się wpisem w konsoli
  tła dodatku, do której nikt nie zagląda.

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
- w tle sprawdzenie idzie 45 sekund po starcie i potem co 2 godziny; o nowej
  wersji dodatek przypomina powiadomieniem i powtarza je raz na dobę, dopóki
  stara wersja jest zainstalowana,
- okienko nad mailem pisze wprost „Pracujesz na starej wersji X, na serwerze
  jest Y” i ma przycisk **Pobierz nową wersję**,
- przycisk **Pobierz nową wersję** otwiera plik XPI w przeglądarce — to droga
  awaryjna, gdy automat zawiedzie: pobrany plik instaluje się przez
  **Dodatki i motywy → koło zębate → Zainstaluj dodatek z pliku**.

Czego dodatek **nie potrafi**: sam siebie zainstalować. Aktualizację wgrywa
Thunderbird (domyślnie raz na dobę, ustawienia `extensions.update.enabled`
i `extensions.update.autoUpdateDefault`), a dodatek może tylko o niej
powiedzieć i podać plik. Jedyna rzecz, która wstrzymuje cichą aktualizację, to
nowe **wymagane** uprawnienie w manifeście — dlatego uprawnienia do znaczników
są opcjonalne.

`updates.json` zawiera `update_hash` (SHA-256 pliku XPI). Dodatek nie jest
podpisany przez Mozillę, więc suma kontrolna jest jedyną weryfikacją, że
Thunderbird pobrał dokładnie ten plik, który zbudowaliśmy.

## Budowanie pliku XPI

Przed spakowaniem `build.py` uruchamia `lint.py`: zbiera nazwy zadeklarowane we
wszystkich plikach dodatku i sprawdza (oxlint z regułą `no-undef`), czy coś nie
woła funkcji, której nigdzie nie ma. Pliki dodatku ładują się do jednej
przestrzeni nazw, więc taka literówka wychodziła dopiero u handlowca i to po
cichu — tak przepadły trzy błędy z rzędu. **Gdy sprawdzenie coś znajdzie, XPI
nie powstaje.**


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
ustawień („Supon Przetargi 1.15.0”) — czytana z `manifest.json`, więc zawsze
zgadza się z tym, co faktycznie jest zainstalowane.

## Ograniczenia

- Załączniki (PDF, Excel) nie są wysyłane — do analizy idzie sam tekst maila.
- Maile zaszyfrowane (OpenPGP, S/MIME) nie są odczytywane.
- Jeśli zamkniesz okno odpowiedzi i wyślesz ją później ręcznie, zapytanie trzeba
  oznaczyć w aplikacji przyciskiem „Oznacz, że wysłano”.

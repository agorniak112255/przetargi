# Moduł „Przeglądy” — plan (06.10.2026)

Plan przygotowany razem z agentem-recenzentem (Plan). Kodu jeszcze nie ma.

## 1. Cel

Pracownik widzi listę klientów, u których zbliża się albo minął termin przeglądu (gaśnice, hydranty, legalizacje…).
Robi z niej raport dla wybranych klientów i wysyła ofertę na przegląd. Definicje przeglądów (co, jak często)
wpisuje człowiek, a system podpowiada kolejne na podstawie wzorca. System nigdy nie wymyśla interwałów ani przepisów.

## 2. Fakty z produkcji (sondy tylko do odczytu, 06.10.2026)

**Usługi przeglądów już są w ERP XL.** XL ma 399 aktywnych usług (Twr_Typ = 4). Aplikacja czyta dziś tylko
towary (Twr_Typ = 1). Usługi mają kody „UPR…”, np.:
- PRZEGLĄD GAŚNICY PROSZKOWEJ GP-1, GP-2, GP-4, GP-6, GP-9, GP-12;
- PRZEGLĄD GAŚNICY ŚNIEGOWEJ GS-5X;
- PRZEGLĄD AGREGATU AP-25 i podobne;
- PRZEGLĄD KOCA GAŚNICZEGO;
- PRZEGLĄD I BADANIE CIŚNIENIA HYDRANTU WEWNĘTRZNEGO, PRÓBA CIŚNIENIOWA WĘŻA;
- LEGALIZACJA ZBIORNIKA, REMONT, KOSZT DOJAZDU.

**Skala** (faktury sprzedaży z lat 2022–2025, rocznie):

| Rodzaj | Klientów | Sztuk | Netto |
|---|---|---|---|
| Przegląd gaśnic i agregatów | 560–650 | ~15 tys. | 110–154 tys. zł |
| Hydranty | 200–226 | — | 100–125 tys. zł |
| Legalizacje i remonty | 250–280 | — | 135–228 tys. zł |

Paragony mają jednego kontrahenta (sprzedaż detaliczna), więc w module nie są przydatne.

**Najważniejsza liczba.** W 2024 przegląd gaśnic miało 654 klientów. W 2025 wróciło tylko 388 z nich (59%),
a 266 nie wróciło. Od 2022 przegląd zrobiło łącznie 1280 klientów. Moduł ma przede wszystkim odzyskiwać tych,
którym termin mija.

**Szczegóły danych:**
- **Daty.** Data sprzedaży jest równa dacie faktury w 96% przypadków. W 3,8% różni się o 1–31 dni.
- **Ilość.** Ilość na pozycji to liczba urządzeń (1–153 szt.). Dojazd jest osobną usługą.
- **Faktury na klienta w roku.** 83% klientów ma jedną fakturę za przegląd w roku. Kilkudziesięciu ma ich wiele
  (do 47 — kilka obiektów albo rozliczenie co miesiąc).
- **Ceny.** Cena przeglądu GP-6 w 2025 wynosiła od 4 do 25 zł, średnio 9,14 zł. Każdy klient ma swoją cenę,
  dlatego do oferty bierzemy ostatnią cenę tego klienta.
- **Cennik XL.** Do cennika (CDN.TwrCeny) nie mamy dostępu. Odczyt wymaga GRANT od właściciela.
- **Kilka kart z tym samym NIP-em.** 61 klientów z przeglądem ma inną kartę XL z tym samym NIP-em. Grozi to
  fałszywym „nie wrócił”.
- **Inny odbiorca niż nabywca.** Dotyczy 2–3% pozycji przeglądów.
- **Oddziały.** Magazyny usług to 01G Rzeszów, 13G Stalowa Wola, 14U Kraków, 11G Tarnów. Oddział wyznacza
  ta sama reguła co w Zapasach (WarehouseLocations::of).

## 3. Decyzje po recenzji

1. **Katalog usług w osobnej tabeli `erp_services`, nie w `erp_items`.** Z `erp_items` korzystają łączenie z kartami,
   nocne podpowiedzi wyszukiwarki, Zapasy i wyszukiwarka Ofert. Usługi zaśmieciłyby każde z tych miejsc.
2. **Własna kopia pozycji sprzedaży `inspection_sale_lines`, bez klucza obcego.** Tabela `erp_sale_lines` ma
   obowiązkowe `erp_item_id` z kaskadą, a odczyt kampanii kasuje wiersze po swoich towarach. Nowa metoda
   `inspectionSaleLines()` w bramce XL będzie obok `itemSaleLines`. Zapytania kampanii nie zmieniamy. Kopiujemy:
   - wszystkie faktury z usługami typu 4 (wtedy nowa definicja nie wymaga ponownego czytania historii);
   - towary oznaczone jako „urządzenie”.
3. **Oferta przeglądu w osobnych tabelach.** Pozycje Ofert nie mają ilości, a pozycja bez towaru lub karty jest
   po cichu pomijana. Oferta nie wskazuje też klienta, a wygląd to kafelki produktów. Z modułu Ofert bierzemy:
   - wysyłkę z poczty użytkownika (CampaignSender, UserMailerFactory);
   - listę adresów wypisanych (EmailSuppression);
   - PDF (dompdf, jak OfferPdf).

   Moduł Ofert zostaje bez zmian.
4. **Klienci z `erp_customers`** (45 tys. kart, e-maile), a nie z `clients` (656 firm powyżej 3000 zł rocznie).
   Przegląd 10 gaśnic to ok. 100 zł, więc takiego klienta w `clients` nie ma. Łączymy po numerze XL, bez kluczy obcych.
5. **„Moi klienci” = operator XL ostatniego przeglądu** (`users.erp_operator_ident`), a nie `main_operator`.
   `main_operator` wskazuje osobę, która wystawiła najwięcej faktur, a nie tę, która robi przeglądy.
6. **Wyciszenia.** Klienta można oznaczyć jako „pomiń / robi u innej firmy / zrezygnował”, na zawsze albo do daty.
   Bez tego lista co roku urośnie o ~270 martwych wierszy.
7. **Ochrona przed podwójną ofertą.** Przy wierszu widać „oferta wysłana dd.mm.rrrr przez X”, a przed ponowną
   wysyłką pojawia się ostrzeżenie. Adres wypisany jest widoczny już w szkicu, a nie dopiero jako błąd przy wysyłce.

## 4. Pojęcia i reguły liczenia

- **Definicja przeglądu.** Jedna czynność wykonywana podczas jednej wizyty, z jednym interwałem, np. „Przegląd
  gaśnic” obejmujący usługi GP-1…GP-12 i GS-5X. Interwał wpisuje człowiek, a system zapisuje, kto i kiedy.
  Pola:
  - `interval_months`;
  - `first_interval_months` — liczony od zakupu nowego urządzenia; puste = nie liczymy terminu z zakupów;
  - `basis_note` — podstawa wpisana przez człowieka.
- **Powiązania z XL** dzielą się na dwa rodzaje:
  - usługa: sprzedaż usługi oznacza wykonany przegląd, a ilość to liczba urządzeń;
  - urządzenie: sprzedaż towaru oznacza nowe urządzenie u klienta.

  Jedna usługa może należeć najwyżej do jednej definicji.
- **Wizyta.** Usługi danej definicji u tego samego klienta w odstępie do 31 dni. Data wizyty to najwcześniejsza
  data sprzedaży (a gdy jej brak — data faktury). Ilość to suma po korektach, z podziałem na usługi
  (np. GP-6: 8, GP-2: 2).
- **Termin.** Data wizyty plus interwał (dodawanie miesięcy bez przeskoku końca miesiąca). Wizyta jest odnowiona,
  jeśli później była kolejna po co najmniej 75% interwału. Klient z kilkoma obiektami ma kilka terminów: lista
  pokazuje najbliższy i liczbę pozostałych.
- **Klient bez wizyty u nas, ale z zakupem urządzeń.** Termin to zakup plus `first_interval_months`, oznaczony jako
  „wywnioskowane”. Gdy pole jest puste, wiersz pokazuje „kupił X szt., brak przeglądu u nas”, bez terminu.
- **Zakupy po ostatniej wizycie** są pokazywane osobno jako fakt z XL. W ofercie dodajemy je do ilości tylko jako
  podpowiedź, którą handlowiec może zmienić.
- **Każda liczba ma etykietę:**
  - z XL: data, numer dokumentu, ilość;
  - z definicji: interwał, z autorem;
  - wywnioskowane: wizyta, termin z zakupu, ilość z zakupami;
  - brak: e-mail, cena.
- **Cena w ofercie.** Netto podzielone przez ilość dla tej usługi z ostatniej wizyty klienta (po korektach),
  z numerem i datą faktury. Gdy ceny brak, pole jest puste i trzeba je uzupełnić przed wysyłką.
- **Stan liczony przy odczycie** według polskiej daty: zaległy, w tym miesiącu, w ciągu 30/60/90 dni, później.
  W tabeli zapisujemy same daty. Domyślnie ukrywamy klientów zaległych ponad 3 interwały, a filtr „wszystkie”
  pokazuje i ich.
- **Ostrzeżenie przy NIP-ie.** Przy wierszu zaległym pojawia się „inna karta z tym NIP-em ma przegląd po tej dacie”.
- **Ograniczenie historii.** Historia od 2019 nie pokryje legalizacji co 10 lat. Taki wiersz ma napis „brak danych
  sprzed 2019”.

## 5. Tabele

- `erp_services`: xl_gid (unikalny), xl_type, code, name, unit, archived, synced_at, removed_at.
- `inspection_definitions`: name, activity, interval_months, first_interval_months, basis_note, active,
  created_by, updated_by.
- `inspection_definition_items`: definicja, xl_item_gid, xl_type, role (service / device), decided_by,
  decided_at; para (definicja, xl_item_gid) unikalna.
- `inspection_sale_lines`:
  - document_type, document_id i line — unikalne razem;
  - document_number, issued_on, sold_on, customer_xl_gid, recipient_xl_gid;
  - xl_item_gid, xl_item_type, quantity, net_value;
  - warehouse_code, location, operator_ident, corrects_*, synced_at.
- `inspection_due` (wyliczana w PHP, bez funkcji okna i bez GROUP BY, bo MariaDB i SQLite różnią się w tym zakresie):
  - customer_xl_gid, definicja;
  - last_visit_on, last_visit_quantity (JSON z podziałem na usługi), last_visit_net, last_visit_documents;
  - open_visits, due_on, due_basis (service / purchase);
  - purchased_after_quantity, purchased_after_documents;
  - location, operator_ident, computed_at.
- `inspection_dismissals`: customer_xl_gid, definicja (puste = wszystkie), until_on, reason, user_id.
- `inspection_offers`:
  - user_id, customer_xl_gid, definicja, due_on;
  - subject, intro, valid_until, emails (JSON), status (draft / sent / failed);
  - sent_at, sent_html, sent_text, pdf_path, results.

  Jedna oferta to jeden klient i jedna wysyłka. Ponowna wysyłka tworzy kopię oferty.
- `inspection_offer_lines`: position, xl_item_gid (puste = pozycja wpisana ręcznie), name, unit, quantity,
  quantity_source, price_net, price_source, price_document_number, price_on.

## 6. Odczyt z XL

- **Nocny odczyt `erp:inspections`** w czasie polskim, przed 6:00, poza godziną erp:client-documents (5:40):
  - katalog usług;
  - pozycje faktur sprzedaży i korekt z oknem 60 dni;
  - na koniec przeliczenie `inspection_due`.
- **Pierwszy odczyt od 2019** uruchamia ręcznie użytkownik (`--since=2019-01-01`). Szacunek: ~10 tys. pozycji
  rocznie, łącznie ~80 tys. wierszy.
- **Wzór techniki:** kursor, zapis paczkami po 500, wyłączony dziennik zapytań (pamięć CLI na serwerze to 128 MB),
  `yieldToXl()` (ustępowanie użytkownikom XL).
- **Nowo zatwierdzony towar-urządzenie** dostaje historię przy następnej nocy (lista „do doczytania”). Usług nie
  trzeba doczytywać, bo kopiujemy wszystkie.
- **Paragony pomijamy.** Pomijamy też własne karty SUPON (oddziały) i karty archiwalne.

## 7. Ekrany (teksty bez skrótów)

- **Przeglądy** (`/przeglady`) — lista „klient × przegląd”:
  - filtry: oddział, przegląd, termin (zaległe / ten miesiąc / 30 / 60 / 90 dni), moi klienci, z e-mailem,
    pokaż pominiętych;
  - kolumny: klient, miasto, przegląd, ilość urządzeń, ostatni przegląd (data i faktura), termin, wartość
    ostatniego przeglądu, e-mail, ostatnia oferta;
  - po kliknięciu wiersza panel z wizytami, dokumentami i zakupami urządzeń;
  - zaznaczenie wielu wierszy daje dwa przyciski: „Raport (Excel / PDF)” i „Przygotuj oferty”.
- **Definicje przeglądów:**
  - lista definicji;
  - dodawanie usług i towarów XL przez wyszukiwarkę z zaznaczaniem wielu pozycji naraz; przy każdej pozycji
    liczba klientów i sztuk z 24 miesięcy.
- **Oferta przeglądu:**
  - szkice przygotowane hurtowo dla zaznaczonych klientów, przegląd szkiców i wysyłka „Wyślij i następna”;
  - w ofercie tabela: urządzenie lub usługa, ilość, cena, wartość, termin; opcjonalnie dojazd; PDF w załączniku;
  - wysyłka z poczty użytkownika z zapisem tego, co dostał klient.
- **Uprawnienia** (na start tylko administrator; role nadaje właściciel):
  - inspections.view;
  - inspections.manage (definicje);
  - inspections.offer (wysyłka).
- **Pomoc.** Nowy moduł Pomocy.

## 8. Podpowiedzi definicji z wzorca

Człowiek zatwierdza jedną parę wzorcową, np. towar „GAŚNICA PROSZ.GP-6X ABC” ↔ usługa „PRZEGLĄD GAŚNICY
PROSZKOWEJ GP-6”. System wtedy:

1. Wyznacza rdzeń, czyli słowa wspólne (gaśnica / proszkowa / przegląd), oraz token zmienny: litery, myślnik
   i liczbę z granicą słowa. Dzięki temu GP-6 nie myli się z GP-60, a AP-25 z AP-250.
2. Szuka w katalogu towarów i usług z tym samym rdzeniem i innym tokenem, a potem paruje towar z usługą po tym
   samym tokenie. Przyrostki X/Z traktuje jako równoważne tylko według listy zatwierdzonej przez człowieka.
3. Przy każdej propozycji pokazuje liczbę klientów i sztuk z 24 miesięcy. Każdą parę człowiek zatwierdza albo
   odrzuca, nigdy nie dzieje się to automatycznie. Decyzje są trwałe, jak przy łączeniu towarów XL z kartami.
4. Osobno pokazuje listę usług XL bez definicji, które ci sami klienci kupują cyklicznie. Do każdej podaje medianę
   odstępów w miesiącach, opisaną jako „z historii sprzedaży, nie z przepisu”. Interwał wpisuje człowiek.

## 9. Etapy

0. **Sondy i GRANT-y:**
   - kolumna odbiorcy i data sprzedaży są już czytane;
   - cennik usług (CDN.TwrCeny) — opcjonalny GRANT właściciela;
   - czas pełnego zapytania od 2019.
1. **Dane:**
   - tabele, odczyt nocny, definicje ręczne (gaśnice, hydranty, legalizacje);
   - wyliczanie terminów, lista z filtrami i panelem klienta, wyciszenia, eksport do Excela.
2. **Raport i oferta:**
   - PDF raportu dla zaznaczonych klientów;
   - szkice ofert, wysyłka pojedyncza, ostrzeżenie o podwójnej ofercie.
3. **Podpowiedzi z wzorca** i usługi cykliczne bez definicji.
4. **Później:**
   - wysyłka hurtowa w tle;
   - Thunderbird;
   - sekcja na karcie klienta;
   - wynik „przegląd wykonany po ofercie” (faktura usługi po dacie wysłania oferty).

## 10a. Decyzje właściciela (06.10.2026) — zastępują sekcje 3–5 tam, gdzie się różnią

1. Zgoda na mail z ofertą do klienta, który u nas kupował — niepotrzebna.
2. Oferty przeglądu na liście „Oferty” z numerem OF- → moduł Ofert dostaje rodzaj `inspection`
   (offers.kind, offers.customer_xl_gid, tabela offer_inspection_lines) zamiast osobnych tabel inspection_offers.
3. Każda pozycja XL (towar albo usługa) ma własny interwał z listy 1, 3, 6, 9, 12, 15, 18, 24 mies. → tabela
   `inspection_positions` zamiast definicji z wieloma pozycjami; towar może wskazać usługę, która go odnawia
   (gaśnica GP-6X ↔ przegląd GP-6).
4. Dostęp przez role: inspections.view, inspections.manage, inspections.offer (na start tylko administrator).
5. Oferta zaczepna bez cen — klient widzi, co i kiedy wymaga przeglądu; wartości netto widzi tylko pracownik.
6. Dojazd pomijamy.
7. Domyślny filtr: termin w ciągu 30 dni, do zmiany; zaległe też widoczne.

Kontrakt wykonania: tabele w migracjach 2026_10_06_200000/200100/200200.

## 10. Pytania do właściciela (zmieniają implementację)

1. **Podstawa wysyłki.** Czy mail z ofertą przeglądu do klienta, który wcześniej u nas kupował, wymaga zgody?
   Ocenę daje prawnik, bo system tego nie rozstrzyga. Od odpowiedzi zależą filtr i link wypisu.
2. **Numeracja ofert.** Czy oferty przeglądu mają być też na liście „Oferty” z numerem OF-? Jeśli tak, rozszerzamy
   moduł Ofert zamiast tworzyć osobne tabele.
3. **Zakres definicji.** Czy gaśnice proszkowe, śniegowe i agregaty to jeden przegląd (jedna wizyta)? Jakie są
   interwały dla hydrantów, prób węży, legalizacji i remontów?
4. **Kto widzi i wysyła oferty:** operator ostatniego przeglądu, opiekun klienta czy cały oddział?
5. **Cena.** Ostatnia cena klienta plus ręczna, czy nadać GRANT na cennik XL?
6. **Dojazd.** Czy dodawać go zawsze, według oddziału, czy według ostatniej faktury?
7. **Domyślne okno listy.** Ile dni przed terminem pokazywać klienta (30 / 60 / 90)?

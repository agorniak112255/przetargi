# Sprzedaż przez WZ w modułach ERP XL — plan (06.10.2026)

## Problem

W Comarch ERP XL faktura wystawiona do WZ (FS 2033) nie ma własnych pozycji — towary są tylko na WZ (2001),
a WZ wskazuje fakturę spinaczem (`TrN_SpiTyp = 2033`, `TrN_SpiNumer` = faktura). Wszystkie moduły, które liczą
sprzedaż z pozycji FS/PA, pomijają tę sprzedaż.

## Fakty z pomiarów (produkcja, tylko odczyt, 06.10.2026)

Rok 2025, kontrahent 32, dokumenty zatwierdzone (stan 3–5):

| | dokumentów | netto |
|---|---|---|
| FS z własnymi pozycjami | 28 343 | 49,86 mln zł |
| FS bez pozycji (do WZ) | 9 923 | 36,76 mln zł |
| WZ ze spinaczem FS | 20 872 | 36,76 mln zł (= FS bez pozycji co do grosza) |
| WZ bez faktury (spinacz 0) | 2 | 10 tys. zł |

- **Podwójnego liczenia przy spinaczu 2033 nie ma**: żadna FS wskazana przez WZ nie ma pozycji (sprawdzone od 2019).
  Od 2019 nie ma też FS bez pozycji, której nie da się odtworzyć z WZ.
- 20 869 z 20 872 WZ ma tego samego kontrahenta co faktura; tylko 1 WZ jest w innym miesiącu niż faktura.
- Opóźnienie faktury po WZ: 0 dni 9%, 1–7 dni 33%, 8–14 dni 26%, 15–31 dni 31%, ponad 31 dni 0,4%.
- **Spinacz −2033** (od kwietnia 2026, nie w 2025): 118 WZ z `SpiNumer = 0`, 550 111,17 zł. Ich faktury (7 FS, też
  −2033) **MAJĄ własne pozycje** (np. PRATT & WHITNEY: FS ze 157 pozycjami = 121 637,70 zł = 29 WZ). Te WZ nie mogą
  się liczyć — dublowałyby FS.
- **Korekty**: WZK (2009) ze spinaczem FSK (2041). 100 WZK w 2025 = −50 916,80 zł = dokładnie suma FSK bez pozycji.
  Kilka WZK ma w spinaczu samą FS (15 od 2024, −697,50 zł).
- **Faktury eksportowe**: WZE (2005) ze spinaczem FSE (2037) — 7 od 2024, 148 tys. zł.
- Typ 2044 to korekty wewnętrzne serii 01K u dostawców (Ansell, Ardon) — jak 2036, nie sprzedaż.

## Ile gubi każdy moduł

| moduł | dziś | po zmianie |
|---|---|---|
| Klienci (`erp:clients`, próg 3000 zł), 2025 | 819 firm, 49,7 mln zł | 967 firm, 86,4 mln zł |
| Klienci, 2026 do dziś | 660 firm, 38,2 mln zł | 769 firm, 65,9 mln zł |
| Karta klienta i cele handlowców (`erp_sale_documents`), 2026 | brak 3 525 faktur | +15,9 mln zł |
| Zakupy klientów 24 mies. (`erp_customer_items`, kampanie „kupujący”, Przeglądy-towary) | 43 813 par klient×towar | 56 102 |
| Główny operator klienta (raport Klienci, „mój klient” w kampaniach) | — | 87 firm zmienia operatora, 135 go dostaje |
| Wynik kampanii (`erp_sale_lines`) | tylko 2 kampanie testowe od 1.10 — strata jeszcze niewidoczna | WZ i WZK w wyniku |
| Podpowiedzi zamówień | dziś 0 podpowiedzi | faktury do WZ też podpowiadane |

Przykłady firm, które w Klientach mają dziś 0 zł: MAN TRUCKS (2,3 mln zł w 2025), ANIMEX, TELEFONIKA, SYNTHOS DWORY.

## Reguła

Pozycje sprzedaży = własne pozycje FS / PA / FSE / FSK / PAK (jak dziś) **+** pozycje WZ (2001), WZE (2005) i WZK
(2009), gdy:

1. WZ ma stan 3–5,
2. w spinaczu jest faktura albo korekta (`SpiTyp` 2033, 2037 albo 2041) w stanie 3–5 i od kontrahenta 32,
3. ta faktura **nie ma własnych pozycji** (bezpiecznik przed podwójnym liczeniem; dziś zawsze prawda przy spinaczu 2033).

Spinacz −2033 i 0 się nie liczą (faktura ma już pozycje albo jeszcze jej nie ma).

Kontrahent, numer i (dla kwot) data — z faktury w spinaczu. Pozycja WZ jest „pozycją tej faktury”.

## Co się zmienia w kodzie

- `ErpXlClient` (tylko zapytania; bramka i atrapa bez zmian w kształcie danych):
  - `customerSalesTotals`, `customerDocuments` — nagłówek = faktura; FS do WZ ma wartość z pozycji WZ.
    Tabele `clients` i `erp_sale_documents` bez zmian schematu.
  - `customerDocumentLines` (podpowiedzi) — pozycje WZ pod numerem faktury.
  - `customerSales` (zakupy klientów) — pozycje WZ; liczba dokumentów = liczba faktur.
  - `customerOperators` — faktura do WZ liczy się operatorowi faktury.
  - `itemSaleLines` (kampanie) — wiersz = pozycja WZ / WZK (klucz unikalny), numer faktury do wyświetlania,
    korekta WZK wskazuje WZ (`TrN_ZwrTyp 2001`). Koszt z pozycji WZ (99% ma koszt — lepiej niż FS).
- `CampaignAttribution`: sprzedaż += 2001, 2005; korekty += 2009.
- `ErpSaleLine` / opisy w kodzie — aktualizacja komentarzy.
- Przeglądy (`inspectionSaleLines`) robi inna sesja — przekazane ostrzeżenie o −2033.

## Decyzje właściciela

**D1. Jaka data pozycji WZ?**

- A (polecane): **dokumenty i kwoty miesięczne — data faktury** (Klienci, karta klienta, cele, podpowiedzi zamówień),
  **pozycje towarowe — data WZ** (wynik kampanii, „ostatni zakup” klienta i towaru). Kampania przypisuje zakup do
  ostatniego maila przed zakupem, a faktura bywa do 31 dni po wydaniu towaru: z datą faktury zakup sprzed maila
  zostałby policzony kampanii. Miesiąc WZ i faktury różni się w 1 przypadku na 20 872.
- B: wszędzie data faktury (jedna data w całej aplikacji; wynik kampanii z opóźnieniem i ryzykiem błędnego
  przypisania).

**D2. WZ bez faktury** (zatwierdzone, faktury jeszcze brak: 11 WZ, 60 tys. zł w 2026):
nie liczymy, dopóki nie ma faktury (polecane — „sprzedaż” = sprzedaż zafakturowana) albo liczymy od razu z datą WZ.

**D3. Skutek widoczny od razu:** po pierwszej nocy lista Klienci urośnie o ~110 firm (próg 3000 zł w 2026), kwoty
zakupów klientów wzrosną średnio o ~70%, cele handlowców pokażą wyższą realizację.

## Weryfikacja

- Testy jednostkowe/funkcjonalne modułów (atrapa bramki z wierszami WZ/WZK).
- Nowe zapytania SQL — kopia klasy uruchomiona na produkcji tylko do odczytu; sumy muszą się zgadzać z pomiarem
  powyżej (2025: 86,4 mln zł; dokumenty klientów 2026 +15,9 mln zł; zero FS liczonych dwa razy).
- Po wdrożeniu: nocne `erp:clients`, `erp:client-documents`, `erp:customers`, `erp:campaign-sales` — kontrola
  jakości `erp:client-documents` porównuje sumę roku z `clients.sales_net` (ta sama reguła po obu stronach).

## Przyjęte (06.10.2026)

- D1 = A: kwoty i dokumenty z datą faktury, pozycje towarowe (kampanie, ostatni zakup) z datą WZ.
- D2 = **WZ bez zatwierdzonej faktury liczy się od razu** z datą WZ (na karcie klienta: „WZ (jeszcze bez faktury)”;
  po wystawieniu faktury wpis WZ znika, wartość przechodzi do faktury).
- D3 = wszystkie moduły naraz.
- Po recenzji planu: FSK z własnymi pozycjami do faktury do WZ — od 2024 brak (jedna FSK do FSK, −6 602,85 zł);
  `erp:client-documents` pokazuje co noc kontrolę reguły WZ, a do dziennika ostrzega, gdy faktura w spinaczu ma
  pozycje albo spinacz jest nieznany.
- Symulacja kodu na produkcji (tylko odczyt): Klienci 2025 — 968 firm / 86,43 mln zł, 2026 — 770 / 66,11 mln zł;
  dokumenty klientów 2026 — 18 581 FS (= 15 056 + 3 525), 0 zdublowanych kluczy; zapytania 0,2–5,4 s, 67 MB.

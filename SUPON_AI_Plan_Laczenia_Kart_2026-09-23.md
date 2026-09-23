# Plan: karta producenta + ceny wszystkich dostawców od najtańszej (23.09.2026)

Cel: ten sam wyrób od producenta i od dystrybutora (P4S, Raw-Pol, Ardon, Procera…) to jedna karta — nazwa i opis
producenta, przy niej ceny wszystkich dostawców, pokazane od najtańszej. Łączymy tylko po pewnym kluczu (EAN, kod
producenta w tej samej marce), nigdy po nazwie. Plan po recenzji drugiego agenta.

## Decyzje użytkownika (23.09.2026)

1. Łączenie: propozycje do zatwierdzenia (etap C); automat (etap D) dopiero po zmierzonej trafności.
2. Zatwierdzanie: ekran w panelu „Łączenie kart”, nie polecenie na serwerze.
3. Cena karty w przetargach: zostaje cena producenta (decyzja z 23.09); tańszy dostawca tylko jako informacja.

## Stan dziś (sprawdzony w kodzie i na produkcji, tylko odczyt)

- Synchronizacja B2B szuka karty tylko po powiązaniu (remote_id) i po dokładnym SKU (B2bCatalogSync::syncProduct,
  resolveGroupCard). Tabela identyfikatorów (EAN, kody) nie jest używana — dystrybutor zakłada własną kartę.
- `products:merge-duplicate` łączy parę ręcznie (nazwa, opis, SKU karty, która zostaje; ceny, powiązania, tabelki,
  zdjęcia i identyfikatory przechodzą). Kolejne przebiegi dystrybutora trafiają już w kartę producenta.
- Błąd: przebieg dystrybutora na połączonej karcie nadpisuje `manufacturer`, `variant_summary`, `category_evidence`.
  Od producenta karty zależy wybór ceny (plik producenta), kolejność zdjęć i szukanie karty przy imporcie pliku.
- Identyfikatory na produkcji: tylko Anro (970 kart z kodem) i P4S (3390). Pozostałe 17 kont nie miało przebiegu od
  wdrożenia identyfikatorów, pliki cenników — do ponownego importu. Dziś wspólny kod w marce: 13 grup, wspólny EAN: 0.
- EAN podają: Raw-Pol, Procera, Delta Plus, 3M, Mascot, Protekt, Tegro, JHK, Polstar. Kod producenta: P4S, Delta Plus,
  3M, Mascot, Protekt, JHK, Polstar, Anro, Bolle, JSP, ATG, UVEX. Raw-Pol i Procera — bez kodu producenta (tylko EAN).
- Ceny: slot na każde konto B2B, jeden slot pliku na kartę. Cena karty wg rodzaju źródła (producent B2B > plik
  producenta > dystrybutor > inny plik) — cena nie jest kryterium. Brak jednostki sprzedaży w slotach. Przetargi liczą
  z ceny karty.

## Etap A — karta producenta chroniona przed dystrybutorem (poprawka błędu, pierwsza)

- Właściciel karty = konto B2B producenta tej marki (marki łącznika: `brandsForKey`) albo cennik z pliku producenta tej
  marki (także sugerowany). Marka porównywana kanonicznie: słownik marek (PELTOR → 3M), potem pierwsze słowo.
- Na karcie z właścicielem konto nie-właściciel nie zmienia: producenta, nazwy, listy rozmiarów, dowodu kategorii;
  zdjęcie dokłada tylko do karty bez zdjęć i nie przestawia kolejności. Tabelka sklepu, cena i identyfikatory
  dystrybutora zapisują się jak dotąd. Karta bez właściciela — bez zmian.
- Import pliku producenta dalej aktualizuje swoją kartę, gdy dopiął się do niej dystrybutor.

## Etap B — propozycje łączenia (tylko odczyt) i pomiar

- Reguła dopasowania (jedna, używana w B, C i ewentualnie D):
  - EAN pozycji (rozmiaru): poprawna suma GS1, bez EAN-ów wewnętrznych (20–29) i ISBN, bez EAN-u opakowania;
  - albo kod producenta (min. 5 znaków) w tej samej marce kanonicznej;
  - trafienia liczone tylko w identyfikatorach właściciela karty docelowej (nie dystrybutor do dystrybutora);
  - wszystkie trafione pozycje wskazują jedną kartę; ta sama marka; karta bez wersji (SignProject — nigdy);
  - karta docelowa nie ma już innego kodu tego samego konta (sztuka / karton);
  - cokolwiek niejednoznaczne = konflikt, bez łączenia, z powodem.
- Polecenie `products:match-candidates` (tylko odczyt): lista par z kluczem, liczbą trafionych pozycji, cenami obu
  kart, zmianą ceny karty po połączeniu, konfliktami; plik CSV.
- Przed pomiarem: pełne przebiegi wszystkich kont B2B i ponowny import cenników z plików (robisz Ty).

## Etap C — łączenie z zatwierdzeniem

- Ekran „Łączenie kart” w panelu: propozycje z etapu B, obok siebie karta producenta i karta
  dystrybutora, klucz, ceny; [Połącz] / [Odrzuć], także zbiorczo.
- Połączenie = `mergeDuplicate` z dowodem na powiązaniu (czym, jaka wartość, z którego źródła, kto i kiedy),
  pełną kopią zapasową i strażnikami: odmowa, gdy obie karty mają cenę z pliku (dziś starsza by zginęła), gdy duplikat
  ma wersje, ceny specjalne albo akcesoria; po połączeniu zdjęcie główne karty producenta zostaje główne.
- Odrzucenie zapamiętane (para nie wraca). Cofnięcie połączenia odpina całą grupę rozmiarów i zapisuje odrzucenie.

## Etap D (opcjonalnie, później) — automatyczne dopinanie w synchronizacji

- Dopiero po zmierzonej trafności (np. 50 zatwierdzonych par po EAN bez odrzuconej): włączane per rodzaj klucza.
  Pozycja dystrybutora trafia od razu w kartę producenta, zamiast zakładać nową — to samo co zatwierdzenie w C.

## Etap E — ceny od najtańszej (niezależny od A–D, może iść równolegle)

- Karta wyrobu, tabela „Ceny ze źródeł”: od najtańszej ceny zakupu netto w PLN (kurs NBP), najtańszy wiersz
  wyróżniony, nazwa dostawcy, różnica % do ceny obowiązującej. Znacznik „obowiązuje” zostaje.
- Poza porównaniem (z powodem): cennik sugerowany, źródło bez ceny zakupu, nieznana waluta, cena nieaktualna
  (dostawca nie potwierdził jej od N dni), cena producenta wyłączona w oknie „Producenci”.
- Jednostka: gdy cena jest > 3× niższa od obowiązującej — zamiast „najtaniej” ostrzeżenie „sprawdź jednostkę”.
  Ilość w opakowaniu z pliku NIE wyklucza ceny: to liczba sztuk w kartonie przy cenie za sztukę (produkcja: Ansell
  373 z 682, Bolle 248 z 254 wierszy z kartonem > 1).
- Lista produktów: przy cenie „taniej u P4S: 7,90 zł (−9%)”, gdy najtańsze źródło ≠ obowiązujące.
- Przetarg: przy pozycji ta sama informacja. Cena oferty bez zmian (patrz decyzja 2).
- Kurs zastępczy (NBP nie odpowiada) — widoczna informacja.

## Kolejność i praca

1. A i E (backend) równolegle; potem E (ekran). 2. B. 3. Ty: przebiegi B2B i importy plików, potem raport B → decyzja.
4. C. 5. D tylko po decyzji. Każdy etap osobnym commitem z testami.

## Poza zakresem

- Jeden slot ceny z pliku na kartę (dwa cenniki z plików tego samego wyrobu nadpisują się) — osobny etap.
- Jednostka sprzedaży w danych cen.
- Karty dystrybutora założone z pliku (nie z B2B) — łączenie po etapie z wieloma slotami plików.

---

# Etap C2 — „Rozdziel rozmiarami” (plan 24.09.2026, do akceptacji)

## Przypadek z produkcji
Po pierwszym „Odśwież propozycje”: 273 do decyzji, 42 niepewne — 24 z nich „klucze wskazują kilka kart producenta”.
Przykład: P4S „6X00 Półmaska 3M 6000” (#56362) = jedna karta z 3 rozmiarami, każdy rozmiar to osobna pozycja P4S
z własnym kodem 3M: 6100 S → 7000146845 → karta 3M #40819; 6200 M → 7000146847 → #40815; 6300 L → 7000146849 → #40814.
Producent ma kartę na rozmiar, dystrybutor kartę na model — połączyć w jedną się nie da, trzeba rozdzielić.

## Ustalenia z kodu
- P4S dzieli wyrób na karty według ceny (rozmiary w innej cenie = osobna karta P4S), więc wszystkie rozmiary jednej
  karty P4S mają tę samą cenę — cena rozmiaru = cena karty P4S.
- Synchronizacja grupy rozmiarów (B2bCatalogSync::resolveGroupCard, saveLink) przepina powiązania wszystkich rozmiarów
  na jedną kartę — rozdział bez zmian w synchronizacji zostałby cofnięty przy najbliższym przebiegu.
- Slot ceny, tabelka sklepu i identyfikatory zapisują się dziś tylko na karcie grupy.
- Błąd danych: kod producenta z karty wyrobu P4S (tu 7000146847 = kod rozmiaru M) ląduje pod pozycją pierwszego
  rozmiaru (S) — rozmiar S wskazuje dwie karty 3M. Źródło: P4sB2bConnector::identifiers (kod karty bez remoteId)
  + ProductIdentifierStore (pozycja bez remoteId = pozycja karty).

## Kroki (po recenzji agenta, 24.09.2026)
0. Znacznik „przypięte” na powiązaniu (`b2b_product_links.pinned_at`, `pinned_by_candidate_id`) — ustawia go rozdział
   ORAZ zwykłe łączenie z ekranu (etap C). Recenzja znalazła istniejący błąd: po połączeniu kilku kart P4S jednego
   modelu z różnymi kartami producenta zmiana grup cenowych u P4S zebrałaby rozmiary z powrotem na jedną kartę
   producenta. Uzupełnienie znacznika wstecz dla połączonych już par (z kopii zapasowych).
1. Kod karty P4S (liczony w grupie cenowej): równy kodowi któregoś rozmiaru → nie zapisujemy (duplikat, 6X00); grupa,
   w której rozmiary mają własne kody → kod karty jako kod modelu (nie bierze udziału w dopasowaniu); w pozostałych
   przypadkach jak dziś (JALAS/TEGERA „PS18”, FR360). Ta sama reguła dla „Kod producenta” w tabelce sklepu.
   Pomiar przed i po: CSV propozycji przed zmianą i po pełnym przebiegu P4S.
2. Synchronizacja: jeśli KTÓREKOLWIEK powiązanie pozycji grupy jest przypięte — każda pozycja grupy idzie ścieżką
   pojedynczą (swoja karta, swój slot ceny z dostępnością tej pozycji, swoje identyfikatory, bez listy rozmiarów
   grupy); pozycja bez powiązania → osobna karta dystrybutora (później propozycja), bez zgadywania. Dodatkowo grupa
   nie przepina powiązania z karty, która ma innego właściciela.
3. Propozycja rozdziału: każde powiązanie karty dystrybutora (wszystkie konta) ma klucz rozmiaru wskazujący dokładnie
   jedną kartę producenta, ≥ 2 różne karty; karty producenta jak przy łączeniu (chronione, marka, bez wersji, bez
   innej pozycji tego konta); pozycje widziane w ostatnim pełnym przebiegu; karta dystrybutora bez cennika z pliku.
   Częściowe trafienie → „niepewne” z planem pokazującym, którego rozmiaru brakuje.
4. Rozdzielenie: transakcja, blokada, ponowna ocena całego planu, pełna kopia JSON przed zmianą; na każdą kartę
   producenta: slot ceny dystrybutora (z datą sprawdzenia), wpis historii ceny, tabelka sklepu, odrzucone zdjęcia;
   powiązania i identyfikatory rozmiarów przepięte i przypięte; karta dystrybutora usunięta na końcu (z wektorem).
   Historia cen, zdjęcia i dokumenty karty dystrybutora tylko w kopii. Odmowa, gdy karta dystrybutora jest w
   przetargu (także jako produkt dodatkowy), w zamiennikach, akcesoriach (także jako powiązane), Preście, ma ceny
   specjalne, wersje, albo trwa synchronizacja jej konta.
5. Poprawki etapu C przy okazji: łączenie przepina też produkt dodatkowy w przetargach i akcesoria wskazujące kartę,
   usuwa wektor karty-duplikatu, ustawia znacznik przypięcia.
6. Ekran: w „Do decyzji” propozycje rozdziału z tabelką rozmiar → karta producenta i przyciskiem „Rozdziel rozmiarami”
   (bez zbiorczego); po rozdziale w „Połączone” jako „Rozdzielono”; w „Niepewne” przycisk „Odrzuć” (odrzucenie
   zapamiętane dla tego zestawu kart).
7. Kolejność wdrożenia: 0+1+2+5 → pełny przebieg P4S → „Odśwież propozycje” → 3+4+6.

## Testy
Rozdział 6X00 na trzy karty i dwa kolejne przebiegi P4S bez scalenia; zmiana grup cenowych w obie strony; nowy rozmiar
→ osobna karta; usunięta karta producenta; drugie konto; kod karty P4S po poprawce (6X00, 6X00P, JALAS, FR360); odmowy;
łączenie z etapu C odporne na zmianę grup cenowych; odrzucenie niepewnej.

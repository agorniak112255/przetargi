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

---

# Etap C3 — „Łączenie rozmiarów” (decyzja użytkownika 24.09.2026, plan do recenzji)

Decyzja: gdy dystrybutor ma jedną kartę modelu z rozmiarami, a producent osobną kartę na każdy rozmiar w tej samej
cenie — karty producenta łączymy w jedną kartę modelu (jak reszta katalogu: pliki i UVEX łączą rozmiary), a kartę
dystrybutora dołączamy do niej zwykłym połączeniem. Osobna zakładka na ekranie „Łączenie kart”. Różne ceny
(rozmiary albo kolory) → rozdzielanie z etapu C2.

Dane z produkcji (24 niepewne „kilka kart producenta”): 23 w tej samej cenie, 1 w różnych (UVEX 9762 X40).
Wśród 23: rozmiary (3M 6000/6000S/7500/6500QL, kombinezony 3M 4515/4520/4530/4540/4565, szelki DBI-SALA) i kolory
(kłódki lockout czerwona/zielona/żółta, hełmy UVEX Airwing/Pheos, 3M SecureFit, czapki JSP?).

## Reguła propozycji „połącz rozmiary”
- Karta dystrybutora (źródło) z ≥ 2 pozycjami; każda pozycja wskazuje dokładnie jedną kartę producenta (klucz
  rozmiaru, jak w C2), ≥ 2 różne karty producenta, wszystkie pozycje trafione.
- Karty producenta: ten sam właściciel (to samo konto producenta albo ten sam cennik), ta sama marka, bez wersji,
  identyczna cena zakupu i waluta w KAŻDYM wspólnym źródle (sloty o tym samym source_key muszą mieć tę samą cenę —
  scalenie zostawia jeden slot na źródło).
- Tylko rozmiary, nie kolory: etykiety pozycji u dystrybutora (P4S sizeName: „rozmiar S (mały)”, „kolor biały,
  rozmiar L”) i nazwy kart producenta; kolor albo etykieta niejednoznaczna → nie ta zakładka (rozdzielanie albo
  niepewne). Kolory mają osobne karty (zasada katalogu: karta = kolor — Mascot, JHK, MAVIBO; przetargi wymagają koloru).
- Odmowa, gdy któraś karta producenta jest w przetargu (pozycja albo produkt dodatkowy — połączenie zgubiłoby wybrany
  rozmiar), w Preście (osobne produkty sklepu), ma ceny specjalne albo akcesoria.

## Połączenie (jedno kliknięcie, dwa kroki w jednej transakcji)
1. Karty rozmiarów producenta → jedna karta modelu (istniejący absorb z ProductSizeMergeService): zostaje karta
   z najlepszym zdjęciem i opisem (pickWinner) albo wskazana na ekranie; nazwa karty modelu — pole na ekranie
   z podpowiedzią (nazwa producenta bez rozmiaru; przy 3M „…, 6300” numer rozmiaru trzeba usunąć ręcznie), zatwierdza
   człowiek; SKU karty modelu — kod producenta karty, która zostaje (kody pozostałych w merged_size_skus); lista
   rozmiarów (variant_summary) złożona z etykiet dystrybutora i kodów producenta („S 7000146845; M …; L …”).
2. Karta dystrybutora → dołączona do karty modelu (CardMatchMerger, z przypięciem powiązań).
Kopia zapasowa wszystkich kart przed zmianą. Opis karty modelu: opis karty, która zostaje — do sprawdzenia, czy nie
mówi o jednym rozmiarze (wtedy ponowne „Pobierz” albo ostrzeżenie na ekranie).

## Synchronizacja po połączeniu
Konto producenta (3M) podaje dalej każdy rozmiar osobno — wszystkie trafiają po powiązaniu w kartę modelu; drugi
i kolejny kod konta na tej samej karcie odświeża tylko powiązanie i identyfikatory (refreshSharedCardLink), różna
cena → ostrzeżenie w dzienniku przebiegu. Karta modelu jest chroniona (etap A) — nazwy nie nadpisze nikt poza nowym
założeniem karty. Do sprawdzenia w recenzji: czy konto producenta z pozycjami bez grup nie zbierze się inaczej.

## Ekran — zakładki
Do decyzji (połącz) · Łączenie rozmiarów · Rozdzielanie · Niepewne · Odrzucone · Połączone.
W „Łączenie rozmiarów”: karta dystrybutora, tabelka rozmiar → karta producenta (zdjęcie, SKU, cena), pole nazwy karty
modelu, wybór karty, która zostaje, przycisk „Połącz rozmiary i dołącz kartę dystrybutora”, „Odrzuć”.

## Pytania do recenzji
1. Rozróżnienie rozmiar/kolor — skąd pewny sygnał (etykiety P4S, nazwy 3M/UVEX, kody UVEX 9762.020/.420 = kolory?).
2. Kolory w tej samej cenie — rozdzielać (C2) czy zostawić osobne karty dystrybutora?
3. Czy łączenie kart producenta (właściciela) za zgodą człowieka nie psuje synchronizacji konta producenta, cen
   (sloty), historii, identyfikatorów, zdjęć (galeria z trzech kart), opisu i tabelek.
4. Kolejność etapów C2/C3 i co wspólne (przypięcie, poprawka kodu P4S, plan rozmiar → karta w CardMatchFinder).

---

# Wersja uzgodniona C2 + C3 (po dyskusji z agentem, 24.09.2026) — zastępuje kroki C2 i C3 wyżej

## Co wyszło w dyskusji
- Po połączeniu kart rozmiarów producenta następny przebieg konta producenta (3M) bierze cenę, OPIS, zdjęcie główne
  i tabelkę sklepu z rozmiaru, który akurat przyjdzie pierwszy na liście; różnica cen innych rozmiarów zostaje tylko
  w dzienniku. Potrzebna „pozycja wiodąca” karty modelu i cena zapisywana przy każdej pozycji.
- Import cennika z pliku po połączeniu zakłada karty od nowa (nie zna połączeń) — dotyczy to już dziś połączeń
  z zakładki „Do decyzji”, jeśli dostawca ma też cennik z pliku. Trzy wiersze trafiające w jedną kartę dodatkowo
  przepisywałyby jej SKU i nazwę.
- Rozmiar vs kolor: pewny sygnał tylko przy spełnieniu trzech warunków (etykieta dystrybutora typu „rozmiar S (mały)”
  bez koloru; części nazw kart producenta, którymi się różnią, to rozmiary, bez słów koloru; łącznik producenta sam
  nie grupuje tych kodów — UVEX 9762.020/.420/.520 to warianty, nie rozmiary). Inaczej „niepewne”.
- Kolory w tej samej cenie → rozdzielanie (karta = kolor; inaczej ceny dystrybutora nie trafią do kart producenta).

## Mapa połączeń (pomysł użytkownika) — tabela `card_redirects`
Wiersz = kod ze źródła (konto B2B albo cennik z pliku + kod pozycji) → karta, powód (połączenie / łączenie rozmiarów /
rozdzielenie), numer propozycji, karta z chwili decyzji, pozycja wiodąca, kto i kiedy. Każda aktualizacja
(synchronizacja B2B i import pliku) najpierw sprawdza mapę i aktualizuje dane na wskazanej karcie — nie pomija pozycji.
Mapa ma pierwszeństwo przed powiązaniem (synchronizacja nie przepnie pozycji wbrew decyzji człowieka). Usunięta karta
docelowa → wiersz zostaje jako „decyzja bez karty” z ostrzeżeniem. Uzupełnienie wstecz z połączeń zrobionych na ekranie.
Zastępuje znacznik „przypięcia” z C2.

## Kolejność (każdy punkt osobnym commitem z testami)
1. Kod karty P4S (kod karty równy kodowi rozmiaru → pomijany; przy rozmiarach z własnymi kodami → kod modelu).
   Pełny przebieg P4S, propozycje przed/po.
2. Mapa połączeń: tabela, zapis przy „Połącz”, przepinanie przy scaleniach, uzupełnienie wstecz (najpierw podgląd).
3. Synchronizacja B2B respektuje mapę; cena i data przy każdej pozycji (b2b_product_links) — rozjazd cen rozmiarów
   widoczny, nie po cichu.
4. Import pliku respektuje mapę (wiersze jednej karty razem, bez przepisywania SKU i nazwy, rozjazd cen w raporcie).
   Punkty 2–4 naprawiają też istniejące połączenia.
5. Propozycje z planem „pozycja → karta” i rodzajem (połącz / połącz rozmiary / rozdziel) — pomiar: ile rozmiarów,
   ile kolorów, ile niepewnych.
6. „Łączenie rozmiarów” (C3): pozycja wiodąca w synchronizacji, nowe scalenie kart producenta z nazwą i listą rozmiarów
   zatwierdzonymi przez człowieka (podpowiedź: wspólna część nazw; SKU karty, która zostaje; opis i zdjęcie główne
   z pozycji wiodącej), potem dołączenie karty dystrybutora. Osobna zakładka (decyzja użytkownika).
7. „Rozdzielanie” (C2) dla kolorów i różnych cen.
Odłożone: cofanie z ekranu, ręczne „to są rozmiary” dla niepewnych, zbiorcze decyzje dla 6 i 7.

## Ekran
Zakładki: Do decyzji · Łączenie rozmiarów · Rozdzielanie · Niepewne · Odrzucone · Zrobione. (Agent proponował mniej
zakładek z filtrem rodzaju — zostaje osobna zakładka, bo tak zdecydował użytkownik.) Każdy wiersz zaczyna się zdaniem
po ludzku, np. „P4S ma jeden wyrób w 3 rozmiarach, 3M ma osobną kartę na każdy rozmiar w tej samej cenie. Po
połączeniu: jedna karta 3M z rozmiarami S, M, L i ceną P4S obok.” Przy łączeniu rozmiarów potwierdzenie „pozycje
różnią się tylko rozmiarem”.

## Stan 24.09.2026 (rano)
Zrobione: 1 (0acdf91), 2 (2449f04), 3 (d173764), 4 (36b3621), 5 (8b11654, 7a86f0f, build 8a513ed).
Następne: wdrożenie → „Odśwież propozycje” → pomiar (rozmiary / kolory / niepewne) → decyzja → 6 (akcja łączenia
rozmiarów z nazwą karty modelu zatwierdzaną przez człowieka, pozycja wiodąca) → 7 (akcja rozdzielania).


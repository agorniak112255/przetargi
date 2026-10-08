# UVEX po audycie 08.10.2026 — przyczyny, co zrobione, plan reszty

Cennik #22 = konto B2B UVEX (b2b_accounts.id 5), 1258 kart. Audyt: `SUPON_AI_Audyt_UVEX_2026-10-08.csv`.

## A. Zrobione — commit 0fa6bb8 (na origin)

| Problem z audytu | Przyczyna w kodzie | Poprawka |
|---|---|---|
| Tabele laserowe sklejone w wiersz („3950 - 4700 - 4765 - 5200 - 14500 (OD10+)”), P1D01 23116–23121, FS1, F18/F42 | Sklep ma poprawną tabelę („3950 - <4700 (OD4+) DIM LB4”). libxml **2.9.10 na produkcji** bierze „<” przed cyfrą za znacznik i połyka tekst do najbliższego „>”. Lokalny libxml 2.10.3 tego nie robi — testy nie mogły tego wykryć. | `App\Support\HtmlBareLessThan::escape` przed `loadHTML` w `JspB2bClient::dom` |
| 20 opisów obuwia uciętych na „rezystancja skrośna” | ten sam błąd: „<35 megaomów” | jw. |
| P1H09 23122 z tabelą P1D01 | błąd sklepu: tekst i odnośnik „pełny opis” z karty P1D01 | `UvexB2bConnector::foreignShopText` — odnośnik do strony producenta z numerem innego wyrobu = tekst sklepu cudzy |
| P1M03 25405 z opisem P1M02 | błąd sklepu: tekst podaje „F22.P1M02.1001” | jw. — tekst podaje tylko oznaczenie innego filtra |
| pronamic E-WR/E-S-WR (8 kart) z kartą B-WR 9731; 6937/8 z kartą 6936.8 | błąd sklepu (plik innego modelu w „Pliki do pobrania”) | `UvexB2bConnector::foreignModelFile` — PDF z kodem tylko innego modelu = „inny dokument”, nie karta techniczna; wypada z embeddingu |
| — | opis kasowany przez `ownDescriptionIsGone` znikał bez śladu | zostaje w `enrichment_payload.replaced_description` |

Uzupełnienie po recenzji: data wydania w nazwie pliku („2019.05”) nie odrzuca pliku jako „inny model” (UVEX ma model 2000 — przy własnej karcie dalej zgodny). Po pierwszym przebiegu UVEX sprawdzić w podsumowaniu linię „Opis w sklepie opisuje inny wyrób”: spodziewane tylko P1H09 i P1M03 — więcej kart = fałszywe odrzucenia do przejrzenia.

Sprawdzone na produkcji (kopia klasy w tinkerze, tylko odczyt): 113 kart laserowych i obuwia — 69 bez zmian, 42 zmienione wyłącznie przez przywrócone „<”, 2 celowo puste (P1H09, P1M03); wszystkie 44 mają odcisk synchronizacji, więc **naprawi je zwykły przebieg UVEX po wdrożeniu**. Reguła PDF: 9 z 867 plików konta, wszystkie trafne.

## B. Plan — pole norm z kart SST (wymaga decyzji właściciela)

Stan: 1056 z 1258 kart ma puste `products.norms`; 642 z nich mają przy sobie kartę SST z wierszem „Normy …”. `products.norms` jest skrótem opisu (kontrakt `ProductNormsColumn`) i pisze go tylko wzbogacanie — opis z B2B go nie wypełnia. Dopasowanie czyta jako nadrzędne `products.manufacturer_norms` (BhpAttributeNormalizer, CardSources, RequirementCheck); dla UVEX zasila je dziś tylko wiersz „Protection Class / Norm” ze stron laservision (60 kart), a `B2bCatalogSync::storeNormFacts` przy `B2bShopFieldNormSource` kończy, zanim dojdzie do źródeł z PDF.

Proponowana zmiana (tylko pliki B2b, bez zablokowanych):
1. Interfejs `B2bDatasheetNormSource` (`static datasheetNormLabels(): list<string>`; UVEX: „Normy”, „Norma”).
2. Klasa `DatasheetNormFacts` (wzór `ShopCardNormFacts`): czyta własne dokumenty konta `kind=datasheet` z tytułem „SST…”, blok od wiersza „Normy/Norma” plus kolejne wiersze zaczynające się od EN/ISO/PN-/DIN EN; **bramka tożsamości**: kod z tytułu SST musi pasować do kodu karty (z łączeniem „CF 33”→„CF33”); dwie SST o różnych blokach = brak zapisu; zapis przez `ManufacturerNormFacts::build` z pochodzeniem (document_id, nazwa, blok, sha256 bloku), pierwszeństwo jak w ShopCardNormFacts.
3. `storeNormFacts`: najpierw `ShopCardNormFacts`; przy wyniku NONE i łączniku `B2bDatasheetNormSource` — `DatasheetNormFacts`. Uzupełnienie istniejących kart = zwykły przebieg.
4. Parser par: dzielić też przed „ANSI” i „DIN EN”, „+” jako separator; wartość tylko z nawiasu / klasy obuwia / „TYP …”, w pozostałych przypadkach sama norma (całość bloku w pochodzeniu) — inaczej „–30°C, MM” trafi do EN 50365.

Symulacja agenta (bez zapisu): 529 kart w zakresie, 426 przechodzi bramkę tytułu, 422 daje pary; rękawice 81 z 111.

Po recenzji (agent Plan, 08.10.2026) — zmiany w planie B przed wdrożeniem:
- Sama zgodność tytułu nie wystarcza (tytuł nadaje sklep: „8534.pdf” z treścią 8543, zamiana xenova, FOCUS S1P z „S3”). Kod karty (model z wariantem) musi być w treści PDF; dla obuwia klasa z bloku musi zgadzać się z klasą z nazwy/kodu — przy sprzeczności brak zapisu i ostrzeżenie w podsumowaniu. Te przypadki z audytu = testy, które bramka musi odrzucić.
- `source.identity` (od niego zależy „zweryfikowane” w ManufacturerNormFacts::verified i RequirementCheck) tylko przy kodzie w treści PDF; przy samym tytule — `title_match`. `source.url` = adres PDF; bez pól zmiennych (data), bo sameFacts porównuje całe source.
- Wartość przy normie tylko po walidacji (EN 388 — En388Code, EN 407 — 6 znaków [0-4X], EN ISO 374-1 — typ i litery, EN ISO 20345/20347 — klasa) i tylko z tej samej linii PDF; inaczej sama etykieta, blok w `source.block`.
- Parser wspólny z ShopCardNormFacts::items() w trybie ścisłym (bez zmiany wyników Protekt/Polstar/ARTRA/3M/Canis/BIG); „+” dzieli tylko przed kolejnym oznaczeniem (nie „EN 388:2016 + A1:2018”); „DIN EN” rozpoznawane.
- Wycofanie: gdy plik źródłowy zniknął, zmienił rodzaj (np. foreignModelFile → inny dokument) albo blok przestał przechodzić bramkę — pary z tego pliku kasowane (status RETRACTED, ostrzeżenie). Kontrakt „pusty odczyt niczego nie kasuje” nie pasuje do danych z konkretnego pliku.
- Pierwszeństwo: tabelka laservision > SST > niezweryfikowana strona producenta; SST nie nadpisuje zweryfikowanej strony producenta (różnica = ostrzeżenie); pary innych łączników nietknięte; klucz `connector=uvex` + `source.kind=datasheet`.
- storeNormFacts: SST tylko przy wyniku NONE z tabelki (nie przy SAME/OTHER_SOURCE); DatasheetNormFacts sam sprawdza markę (NONE przychodzi też dla HexArmor).
- Istniejące karty: polecenie z podglądem i `--apply` (wzór norms:from-shop-cards, kopia przed zapisem) zamiast cichego zapisu przy przebiegu — zmiana dotyka ok. 420 kart, embeddingów i Presty.

Decyzje właściciela:
- D1. Czy normy z karty SST sklepu UVEX mogą zasilać `manufacturer_norms` (źródło nadrzędne nad opisem)? Ryzyko: sklep przypina SST innego modelu — chroni bramka tytułu (pronamic E odpada).
- D2. HexArmor i HECKEL: `B2bManufacturerSiteBrands` dla UVEX zna tylko „uvex” — ok. 22 rękawice HexArmor zostaną bez norm. Dopisać marki (zmienia też pierwszeństwo opisu i zdjęć) czy zostawić?
- D3. Dwa wydania EN 388 w jednej SST („EN 388:2003 (4342), EN 388:2016 (4X42C)”) — `ManufacturerNormFacts::readEn388` bierze pierwsze. Brać najnowsze (zmiana dla wszystkich łączników)?

## C. Naprawy danych, których automat nie rozstrzygnie (ręcznie albo decyzją)

- Kopie tekstu sklepu bez kodu: FS1 P1P22 25420 (tekst P1P18), P1P23 25424 (tekst P1P22) — poprawić ręcznie; zgłosić UVEX.
- Opisy „Z karty technicznej (…)” z pliku innego modelu — 8 × pronamic E (24734, 24736–24743), 6937/8 (23372), K2 23228 (instrukcja zamiast SST 2600.012). Po wdrożeniu plik zmieni rodzaj, ale opis zostaje (zapis sprzed 20.09 nie jest kasowany automatem). D4: wyczyścić opis i opisać od nowa ze strony producenta (karta wypadnie z propozycji do czasu nowego opisu) czy poprawić ręcznie?
- Błędy w samym sklepie/PDF — zgłosić UVEX: zamiana PDF xenova S1 24693 ↔ S1P 24692, „8534.pdf” z treścią sandała 8543, FOCUS S1P 25548/25549 „S3 SRC” w PDF.
- Wzbogacanie z sieci (status done; pliki etapu 3, przekazane sesji „Analiza cenniki z pluku”): K10H/K30H liczby K20H bez źródła, 24790 ze strony 9760014, filtry laserowe z kupbhp „UX-GOG-LASER”, EN 166 zamiast EN 207/208, HexArmor 25479 z izolacją z bloku „polecane”.

## D. Poza kontem UVEX — propozycje, nie wdrożone

- P1. `HtmlBareLessThan::escape` w pozostałych parserach HTML: 14 łączników B2B z własnym `loadHTML` (Atlas, Demar, MSA, BIG, Mactronic, Ardon, Polstar, Tegro, Protekt, Deltaplus, Honeywell, ATG, Canis, Shopify) i `ProductPageFetcher` (wzbogacanie, plik zablokowany). Ten sam błąd libxml 2.9 dotyczy ich na produkcji. Każdy łącznik: test tekstu po escape + sprawdzenie na żywej stronie.
- P2. Wycinanie bloków „polecane/podobne” ze stron sklepów (HexArmor Chrome SLT 25479) — decyzja właściciela (etap 3).

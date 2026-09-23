# Plan naprawy norm na kartach wyrobów — wszystkie cenniki (23.09.2026)

Wersja po recenzji agenta Plan (23.09.2026). Punkt wyjścia: zgłoszenie MAPA Butoflex 650 (#112) — opis z cas-technik.eu podawał
EN 388 „1.1.2.2” i EN 374 „A.B.C.I.K.L”, producent podaje EN 388 „1121X” i EN 374-1 „Typ A ABCILMNOS”. Naprawa
dla MAPA jest wdrożona (commit 240d40a): ramka norm ze strony producenta → `products.manufacturer_norms`
(connector `strona-producenta`), pierwszeństwo producenta w poleceniu modelu, na liście norm i w kodzie EN 388 opisu,
piktogramy norm na karcie. Ten plan rozszerza to na wszystkie źródła cen.

## 1. Stan na produkcji (audyt tylko do odczytu, 23.09.2026)

48 403 karty, z czego 30 442 w kategorii ŚOI (rękawice, obuwie, ochrona oczu/twarzy/głowy/słuchu/dróg
oddechowych, odzież, asekuracja — wg `BhpAttributeNormalizer::forProduct`).

Skąd opis:

| Źródło karty | Kart | Opis ze sklepu B2B | Opis z WWW (producent / sklep) | Bez opisu |
|---|---|---|---|---|
| Łączniki B2B (18 kont) | ~44 000 | ~42 300 | ~70 | ~1 500 |
| Pliki cenników (Canis, Coba, Ansell, AJ GROUP, MAPA, SECURA, CEDERROTH) | ~3 150 | 0 | 476 / 190 | ~130 |

Wniosek: **poprawka z MAPA dotyczy tylko ok. 670 kart opisywanych z WWW.** Reszta ma opis i normy z tekstu
sklepu dostawcy — tam model nie pisze opisu, a normy czyta normalizator z opisu i z tabelki sklepu
(`shop_fields_summary`).

Normy (odczyt taki, jakim liczy się dopasowanie do przetargu):

| Miara | Kart |
|---|---|
| Karta z jakąkolwiek normą | 16 577 |
| Karta wspomina EN 388 | 2 539 |
| EN 388 bez kodu poziomów („EN 388 (ochrona mechaniczna)”) | **821** (32%) |
| Różne kody EN 388 z różnych miejsc karty | 54 |
| Kod inny niż u producenta (tam, gdzie producent jest znany) | 12 |
| Karty z normami producenta (`manufacturer_norms`) | 1 051 (Delta Plus 851, MAPA 145, ATG 53) |

Najwięcej EN 388 bez kodu: P4S 198 (Ansell 70, Tegera 70), Raw-Pol 177 (Reis 155), Ansell z pliku 126,
UVEX 113, Canis 84, Procera 68, Polstar 45.

Próbki „różnych kodów” pokazują trzy różne zjawiska, których nie wolno wrzucić do jednego worka:
- **dwa wydania normy** — UVEX C500: EN 388:2003 „4542” i EN 388:2016 „4X42C”; Ansell przez P4S: 2003 „3331”
  i 2016 „3X31B”. To nie błąd, to prawda o wyrobie (przetarg bywa na konkretne wydanie);
- **prawdziwa sprzeczność** — RS (Tegro): tabelka sklepu „2122X”, lista norm z modelu „2132X”;
- **szczątkowy odczyt** — ATG: tabelka „EN 388: poziom 3”, „EN 388: ISO C” obok pełnego „4342C”;
  Canis: opis „z poziomami … 211” (ucięty kod).

Łączniki: **producenci** (B2bManufacturerSite) — ARTRA, ATG, Bolle, Delta Plus, JHK, JSP, Mascot, 3M, Polstar,
Protekt, UVEX; **dystrybutorzy wielu marek** — P4S, Raw-Pol, Ardon (też własna marka), Procera, Tegro, MAVIBO,
SignProject, Anro (własne wyroby). Pary norm producenta podają dziś tylko ATG i Delta Plus (B2bNormFactSource).

Strony WWW producentów: ramkę norm w układzie „etykieta + wartość” rozpoznajemy dziś tylko u MAPA; sprawdzone
strony ARTRA, ATG, Canis, Coba, JSP, Portwest, UVEX nie dają par (każda zapisuje normy inaczej: tekst, JSON,
tabela).

## 2. Co wykazała recenzja planu (agent Plan, 23.09.2026) — błędy we własnych czytnikach

Recenzja z odczytem kodu pokazała, że dużą część szkody robią nasze czytniki norm, a nie brak źródeł.
Dokładanie źródeł przed ich naprawą karmiłoby te same błędy.

1. **Dwa czytniki EN 388.** `poziomy_en388` przy braku producenta bierze kod ze słabego `detectEn388`
   (BhpAttributeNormalizer ~:1290), a nie z `En388Code`. Sprawdzone lokalnie: „EN 388: 4121X”, „EN 388 (4121X)”,
   „EN 388 4 1 2 1 X” → brak kodu; „EN 388 2016”, „EN 388 - 2003”, „EN 388 211” → fałszywe kody „2016”, „2003”,
   „211”. Dopasowanie porównuje kod EN 388 przez `str_contains` (ProductMatchService ~:936), więc rok albo
   ucięty kod dokłada punkty wymaganiu, które go przypadkiem zawiera.
2. **Wydania normy nie są rozróżniane nigdzie.** `En388Code` gubi rok, `NormCode::key` celowo go pomija,
   `LevelChecker::codesConflict` porównuje pozycjami — 2003 „4542” i 2016 „4X42C” wychodzą jako sprzeczność
   (fałszywy znacznik „Sprzeczności” w przetargach, status „niejasne”).
3. **Dwa rozstrzygacze norm producenta przeczą sobie.** `normalize` i `ManufacturerNormFacts::preferOver`
   różnie traktują „EN 374” wobec „EN ISO 374-1” i wiersz producenta bez poziomu. Karta pokazuje wynik
   `normalize` — sprzeczność EN 374 z Butoflex może wracać na ekranie.
4. **Moja poprawka z 23.09 (240d40a) ma dwie słabości:**
   - `alignEn388WithManufacturer` podmienia w opisie *każdy* inny kod EN 388 na kod producenta — także kod innego
     wydania („EN 388:2003 4542” stałby się „4X42C”, czyli fakt zmyślony);
   - `manufacturerNormFactsFromPages` bierze pierwszą stronę z domeny producenta z ramką norm, a nie stronę
     wybraną jako źródło — przy stronie rodziny/katalogu normy mogą dotyczyć innego wariantu.
   Dla MAPA ryzyko jest małe (karty mają wydania bez roku, strona producenta jest kartą wyrobu), ale obie
   rzeczy idą do naprawy jako pierwsze w etapie 1.
5. **Czego hierarchia nie uwzględniała:** parametry wpisane ręcznie i kolumny cennika (w `CardSources` stoją
   nad tabelką dostawcy), `products.norms` o mieszanym pochodzeniu; źródło producenta może być częściowe
   („poziom 3”) — wygrywa tylko w pozycjach, które faktycznie podaje, a odczyt częściowy nigdy nie bije pełnego.

## 3. Zasady (niezmienne w całym planie)

1. **Hierarchia źródeł** (od najwyższego): parametry wpisane ręcznie → pary z łącznika B2B producenta → karta
   WWW producenta → dokument producenta (PDF tego wyrobu) → kolumny cennika → tabelka sklepu dostawcy → opis.
   Wyższe źródło wygrywa tylko w tym, co faktycznie podaje.
2. **Pochodzenie przy każdej wartości** (źródło, adres/dokument, data odczytu, zapis dosłowny). Brak = brak.
3. **Wydania normy — trzy przypadki:**
   - rok podany po obu stronach i różny → dwie osobne wartości, nie sprzeczność;
   - ten sam rok albo brak roku po obu stronach → sprzeczność;
   - rok tylko po jednej stronie → rozstrzyga wyższe źródło; niższe zostaje jako zacytowany wynik niższej rangi,
     nie jako drugi fakt (tak zostaje naprawa #112: MAPA „EN 388” vs sklep „EN 388 (1.1.2.2)”).
   2016 i 2016+A1:2018 to jedno wydanie. Wydanie zgadnięte z formatu kodu (4 cyfry bez litery) jest
   oznaczane jako *wywnioskowane*, nigdy jako fakt. Nie przeliczamy wartości między wydaniami.
4. **Tekstu dostawcy nie przepisujemy.** Opis z B2B zostaje dosłowny; sprzeczność z producentem jest widoczna
   na karcie i rozstrzygana w dopasowaniu. Opis pisany przez model — wyrównanie tylko w obrębie tego samego
   wydania.
5. **Model językowy nie wyciąga wartości norm.** Pary czytamy deterministycznie ze źródła.
6. **Na produkcji nic bez podglądu i kopii**; naprawę danych uruchamia użytkownik (`--apply` / `--restore`),
   najpierw jedna karta na próbę. Publikacja opisów do PrestaShop tylko po potwierdzeniu użytkownika.

## 4. Etapy (kolejność po recenzji)

### Etap 0 — miara, tylko odczyt
Polecenie `norms:audit` (z obecnego skryptu): per źródło cen × producent; `--samples`, `--csv`. Mierzy:
- zgodność dwóch czytników EN 388 (`detectEn388` vs `En388Code`) na wszystkich kartach,
- 821 „EN 388 bez kodu” rozbite na: kod jest w tekście, a czytnik go nie widzi / zapis słowny lub częściowy /
  poziomów naprawdę brak w tekście (tylko ta grupa potrzebuje stron producenta albo PDF),
- 54 „różne kody” rozbite na: inne wydanie / prawdziwa sprzeczność / odczyt szczątkowy,
- ile kart dystrybutorów ma EAN wspólny z kartą z normami producenta,
- próbka 10 marek × 10 kart: kod u nas vs kod na karcie producenta (skrypt lokalny, bez zapisu).
Poza EN 388 w tym planie tylko liczymy obecność norm (EN 374 litery, EN 166 oznaczenia — bez nowych parserów).

### Etap 1 — czytniki i jeden rozstrzygacz, bez nowych danych
- `detectEn388` zastąpiony przez `En388Code`; odczyt częściowy/słowny nigdy nie wypełnia `poziomy_en388`.
- `En388Code` z wydaniem (dosłownie z tekstu; wywnioskowane oznaczone); dopisane separatory „4-1-2-1-X”, „4/1/2/1/X”.
- `LevelChecker::codesConflict` świadomy wydań (zasada 3).
- `normalize` i `preferOver` → jedna funkcja rozstrzygająca, używana przez listę norm, kartę, dopasowanie i Prestę.
- Poprawki 240d40a: wyrównanie kodu w opisie tylko w obrębie wydania; normy ze strony producenta tylko ze strony
  wybranej jako źródło i potwierdzonej kodem/EAN.
- Pierwszeństwo producenta także dla EN 407 i klasy obuwia (tam, gdzie mamy parser).
- Dopasowanie EN 388: porównanie pozycjami (LevelChecker) zamiast `str_contains` — albo świadomie poza zakresem
  (decyzja w tym etapie, z pomiarem na zestawie przetargów `tenders:eval --replay`).
- Po etapie: `norms:audit` przed/po. Naprawa danych ograniczona do pól zapisanych (`products.norms`, payload);
  dopasowanie i karta liczą się na bieżąco, więc same poprawki czytników działają od wdrożenia.

### Etap 2 — pary norm z łączników producentów
Ta sama kolumna `manufacturer_norms` + `source.kind` (`b2b_manufacturer` / `manufacturer_page` /
`manufacturer_document`). Kolejność wg etapu 0; kandydaci: 3M (1 940 kart ŚOI), UVEX (1 161, normy w JSON),
Protekt (1 407, asekuracja), JSP (582), Polstar (566), Mascot (odzież), Bolle (EN 166), ARTRA (klasy obuwia,
z sitem wariantu). Każdy odczyt sprawdzony na żywej karcie. Łącznik bez norm w danych — pomijamy.

### Etap 3 — karta WWW i PDF producenta, tylko normy (dawne etapy 4 i 5)
Osobne, deterministyczne zadanie „normy od producenta” — bez modelu, bez zmiany opisu i innych pól karty.
Bramka ostrzejsza niż przy opisach:
- na stronie/w dokumencie musi być dokładny kod wyrobu albo EAN (sama nazwa + producent nie wystarcza),
- odrzucamy stronę rodziny, której warianty mają różne klasy (odcień, klasa obuwia, FFP),
- pary tylko ze strony wybranej jako źródło, nie z pierwszej z ramką norm,
- zapis surowego bloku (lub skrótu) i daty odczytu; zasady odświeżania.
Odczyt ramki per witryna dla marek z największą liczbą braków wg etapu 0 (kandydaci: Ansell, Reis, Canis,
Portwest, Ejendals/Tegera, Showa, Lebon, Honeywell), każdy z testem na zapisanej prawdziwej stronie.

### Etap 4 — decyzja o modelu danych
Dopiero teraz: jeśli etap 3 wymaga kilku źródeł producenta na kartę (strona + PDF + łącznik), nowa tabela faktów
norm na wzór `product_identifiers` (bez kasowania — `removed_at`), a kolumna zostaje rozstrzygnięciem
w pamięci podręcznej, przebudowywanym w jednej transakcji przez jedną usługę; do tego kopia/przywrócenie
(ProductEnrichmentResetter) i przenoszenie przy łączeniu rozmiarów (ProductSizeMergeService). Inaczej zostajemy
przy kolumnie.

### Etap 5 — karty dystrybutorów przez identyfikatory
Po zakończeniu prac nad `product_identifiers` (inna sesja). Tylko EAN albo jawny kod producenta tej samej marki
(nie `model_code` — obejmuje rodzinę; kod dystrybutora ≠ kod producenta), dopasowanie jeden do jednego,
odrzucenie przy różnicy klasy (odcień, klasa obuwia, FFP). Normy liczone przy odczycie przez powiązanie
(bez kopiowania), z opisem „z karty #N przez EAN X”.

### Etap 6 — widok
Rozbudowa istniejących `CardConflict` / `ConflictSummary`: „dostawca podaje X, producent Y” z adresami, wartość
innego wydania oznaczona jako „inne wydanie”; piktogramy z rozstrzygnięcia.

Każdy etap kończy się: testy, `norms:audit` przed/po, próba na jednej karcie na produkcji, polecenie
z podglądem i `--apply` / `--restore` dla użytkownika.

## 5. Poza zakresem
- Dopasowanie kart po nazwie; przeliczanie wartości między wydaniami normy.
- Wartości norm wyciągane przez model językowy.
- Automatyczna publikacja opisów do PrestaShop.
- Nowe parsery liter EN 374 i oznaczeń EN 166 (tylko liczenie).
- Karty spoza ŚOI (tabliczki SignProject, tekstylia reklamowe Anro) — tylko w audycie.

## 6. Najważniejsze ryzyka
1. Zła zasada wydań przywróciłaby błąd #112 (kod sklepu bez roku obok kodu producenta z rokiem).
2. Słaby `detectEn388` wprowadza do dopasowania lata i ucięte kody — więcej źródeł tego nie naprawi.
3. Bramka opisów (nazwa + producent, strony rodzin, katalogi) użyta do norm dałaby normy innego wariantu
   z rangą „producent”.
4. Przenoszenie norm po kodzie modelu albo kodzie dystrybutora na warianty rodzeństwa.
5. Druga kopia danych (tabela faktów) za wcześnie — rozjazd z kolumną, indeksem, kopią i łączeniem rozmiarów.

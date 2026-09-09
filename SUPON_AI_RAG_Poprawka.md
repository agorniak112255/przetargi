# RAG / wektory — stan obecny i poprawka

Dokument do wdrożenia. Nie zmienia kodu — opisuje, jak retrieval wektorowy
działa dziś i co chcemy zmienić, żeby SIWZ w stylu „rękawice do amoniaku”
trafiało w karty z nitrylem / EN 374, a nie polegało na zgadywaniu modelu
embeddings.

Powiązane: `deploy/README-qdrant.md` (uruchomienie Qdrant),
`SUPON_AI_Plan_Wyszukiwarki.md` (K4: filtr w Qdrant, wagi RRF),
`backend/resources/search-eval/README.md` (pomiar).

---

## 1. Cel

RAG u nas **nie jest encyklopedią chemii**. To indeks kart katalogu w Qdrant:
jedna karta = jeden wektor. Ma wrzucić do puli AI produkt, którego **słowa
nie pokrywają się z SIWZ**.

FULLTEXT zostaje. Wektor ma uzupełniać dziury semantyczne (zastosowanie,
materiał, norma), a nie zastępować kod modelu ani żargon.

**Sukces:** zapytanie z zagrożeniem / zastosowaniem (amoniak, oleje, spawanie)
wciąga do puli karty, na których tego słowa nie ma, ale jest materiał / norma /
`use_cases` — i `search:eval` na takich przypadkach nie spada na reszcie golden
setu.

---

## 2. Jak działa dziś

### 2.1 Pipeline (skrót)

1. LLM rozumie wymaganie → `needed`, `search_phrases`, `constraints`,
   `search_steps`.
2. Żargon SIWZ (`CatalogSlangDictionary`) dokłada frazy cennika do
   `search_phrases` i appendix do promptu. **Nie buduje tekstu do Qdrant.**
3. Retrieval składa pulę z kilku źródeł i scala je RRF
   (`RrfFusion`, waga wektora = 1.0, waga tekstu = 1.0, priorytet kodu/marki = 3.0):
   - kod modelu / SNR / filtr / marka (priorytet),
   - kaskada katalogu (nazwa, rodzaj),
   - FULLTEXT po `search_phrases` (`ProductTextSearch`),
   - wektor: top 150 z Qdrant (`VECTOR_POOL`),
   - recall nieklasyfikowanych (tylko tekst).
4. Sitko zgodności (`keepCompatible` + żargon).
5. LLM rankuje ~24 karty. Do modelu idzie karta produktu, nie fragment
   wiedzy o amoniaku.

To samo wejście obsługuje **Szukaj AI** i **dopasowanie SIWZ**
(`ProductMatchService` woła `ProductAiSearchService`).

### 2.2 Co ląduje w Qdrant (dokument karty)

`ProductEmbeddingIndexer::documentText()` skleja jedną płaską linię, max 8000 znaków,
w tej kolejności:

`sku | nazwa | producent | kategoria | normy | opis | materials | features | use_cases | norms z payload | atrybuty BHP`

Hash = `dostawca:model | tekst`. Zapis w MySQL: `embedding_hash`,
`embedding_synced_at`. Panel „Jakość katalogu” liczy tylko `embedding_synced_at`.

Payload w Qdrant: `sku`, `name`, `manufacturer`. **Brak** rodziny PPE, materiału,
norm — `QdrantClient::search()` nie filtruje, bierze 150 najbliższych niezależnie
od score.

Karta bez opisu też dostaje wektor (SKU + nazwa). Taki wektor nie niesie
zastosowania.

### 2.3 Jakie zapytanie idzie do Qdrant

`retrieveCandidates()` ustawia:

`$searchText = needed !== '' ? needed : surowe wymaganie`

Potem `retrieveVectorIds($searchText)`. Prefetch liczy też surowe zapytanie,
ale retrieval **szuka po `needed`**.

Przykład: SIWZ „Rękawice do pracy z amoniakiem” → `needed` bywa „rękawice”
albo „rękawice ochronne”. Wektor szuka **rękawic w ogóle**, nie amoniaku.
`constraints` (EN 374) i frazy żargonu idą do FULLTEXT / rankingu, nie do
embeddingu zapytania.

### 2.4 Kiedy wektor w ogóle nie leci

Hybryda RRF z wektorem jest na końcu `retrieveCandidates()`. Wcześniejszy
`return` omija Qdrant:

- kaskada zapełni limit (`cascadeKept >= limit`) — typowe przy gołym rodzaju
  („rękawice”, „spodnie”);
- żargon z twardym dowodem (`requiresTightEvidence`) — dokładany jest tylko
  FULLTEXT;
- kotwica marka+model z nazwą — zwrot po priorytecie, bez fuzji wektorowej.

Dlatego „mamy RAG” w ustawieniach, a duża część zapytań SIWZ **nie korzysta
z Qdrant**.

### 2.5 Operacyjnie

- Kolejka `embeddings` (job `ReindexProductEmbeddingJob`), worker
  `enrich,embeddings` — najpierw opisy.
- Błąd API/Qdrant jest łapany w indexerze: job schodzi z kolejki, karta bez
  `embedding_synced_at`.
- Po zmianie modelu/dostawcy hashe są czyszczone; trzeba
  `products:reindex-embeddings`.
- Indeks bywał niekompletny (ok. 19k / 41k). Karta bez wektora nie wejdzie
  ścieżką Qdrant.

Żargon **nie ma** wpisu „amoniak” (`backend/config/catalog_slang.php` /
Administracja → Żargon SIWZ).

---

## 3. Co jest nie tak (nie „zły Qdrant”)

| Problem | Skutek |
| --- | --- |
| Dokument karty zaczyna się od SKU i nazwy handlowej | Szum tożsamości; zastosowanie na końcu albo go nie ma |
| Zapytanie wektorowe = samo `needed` | Gubi zagrożenie z SIWZ (amoniak, oleje) |
| Żargon zasila FULLTEXT, nie embedding zapytania | „wampirki” działają tekstowo; chemia bez słowa na karcie — nie |
| Wektor często wycinany przez kaskadę / tight evidence | RAG nie bierze udziału w typowym SIWZ |
| Payload bez rodziny / materiału | 150 sąsiadów, w tym obcy asortyment |
| Słaby / brakujący enrichment | Wektor = SKU + nazwa; model embeddings nie „wie” o amoniaku |

Wektor **nie tworzy** faktu „to jest do amoniaku”. Może tylko zbliżyć teksty,
które już mówią o nitrylu, EN 374, chemii, `use_cases`. Bez tego w dokumencie
albo w zapytaniu zostaje loteria treningu modelu.

---

## 4. Co zmieniamy

Trzy warstwy. Bez drugiej kolekcji „Wikipedii chemicznej” i bez zmiany
dostawcy embeddingów na starcie.

### A. Dokument karty (indeks)

Zbudować **tekst do embeddingu** osobno od sklejki magazynowej.

Kolejność (ważne na początku):

1. rodzaj / `kategoria_bhp` / `typ_wyrobu`
2. materiał, normy EN, klasa / poziomy
3. `use_cases`, `features`, `przeznaczenie`
4. opis (przycięty)
5. nazwa handlowa
6. producent, SKU (na końcu, krótko)

Zasady:

- Puste sekcje pomijamy; nie wstawiamy sztucznego „amoniak” na kartę, jeśli
  tego nie ma w enrichmentcie / normach.
- Po zmianie layoutu dokumentu: reindex **bez** `--fresh` (nowy hash sam
  unieważni stare). `--fresh` tylko przy zmianie wymiaru modelu.
- Nadal jedna karta = jeden punkt w Qdrant.

### B. Zapytanie do Qdrant (retrieval)

Embedding zapytania = jeden kanoniczny string, nie samo `needed`:

`needed | frazy żargonu | constraints | skrót surowego SIWZ (zagrożenia, nie cała powieść)`

Przykład: „Rękawice do pracy z amoniakiem”
→ `rękawice | nitryl | EN 374 | chemia | amoniak`.

Ten sam string: prefetch i `similar()`. `search_phrases` zostają przy FULLTEXT.

Kaskada / tight evidence **nie mogą** omijać wektora: najpierw RRF
(tekst + wektor + priorytet), potem sitko. Limit kaskady nie wycina Qdrant.

### C. Żargon SIWZ (wiedza domenowa)

Tu jest miejsce na „amoniak → nitryl, EN 374, chemia”, nie w Qdrant.

- Nowe / uzupełnione wpisy kategorii `chemia` (i analogicznie oleje, kwasy,
  rozpuszczalniki) w Administracja → Żargon SIWZ.
- Frazy cennika z wpisu wchodzą do **FULLTEXT i do stringu wektorowego** (B).
- Nie cytować żargonu w `search_steps` (jak dziś w prompcie).

Enrichment (`use_cases`, materiał, normy) nadal karmi dokument A. Im więcej
kart z zastosowaniem, tym mniej zależy od samego żargonu.

### D. Payload Qdrant (z planu K4, tu tylko to co RAG)

Dopisać do payloadu i do filtra zapytania: `ppe_family`, ewentualnie
producent. Minimalny score — osobna decyzja po `search:eval`, nie w pierwszym
PR razem z A–C jeśli nie zmierzymy.

---

## 5. Czego nie ruszamy w tej poprawce

- Osobna kolekcja fragmentów norm / kart charakterystyki substancji.
- Wymiana modelu embeddings „bo będzie mądrzejszy”.
- Prompt rankingu i progi SIWZ (chyba że eval pokaże regresję).
- Wyłączanie FULLTEXT.

---

## 6. Przykład „amoniak” — dziś vs po zmianie

**Dziś**

- Karta: `RTELA-9 | Rękawice nitrylowe RTELA | … | EN 374 | …`
- Qdrant dostaje zapytanie: `rękawice`
- Kaskada „rękawice” zapełnia pulę → wektor często nie startuje
- Trafienie zależy od FULLTEXT „nitrylowe” / nazwy, nie od amoniaku

**Po zmianie**

- Karta (indeks): `rękawice | nitryl | EN 374 | odporność chemiczna | … | RTELA | SKU`
- Zapytanie (indeks): `rękawice | nitryl | EN 374 | chemia | amoniak`
- Oba wektory bliżej siebie, bo dzielą materiał i normę
- FULLTEXT szuka tych samych fraz równolegle
- LLM nadal decyduje na karcie; RAG tylko dobiera kandydatów

---

## 7. Kolejność wdrożenia

1. Dokończyć indeks obecny (`products:reindex-embeddings`) — bez tego B nic
   nie znajdzie na połowie katalogu.
2. Wpisy żargonu chemia (C) — da się w panelu, bez deployu kodu.
3. String zapytania wektorowego + nie wycinanie Qdrant przez kaskadę (B).
4. Nowy `documentText` + reindex (A).
5. Payload / filtr rodziny (D) po pomiarze.
6. `search:eval` przed i po (min. przypadki chemia + istniejący golden,
   np. `--filter=`).

---

## 8. Mapa kodu

| Element | Plik |
| --- | --- |
| Dokument i zapis wektora | `backend/app/Services/Vector/ProductEmbeddingIndexer.php` |
| Szukanie w Qdrant | `backend/app/Services/Vector/ProductVectorSearch.php`, `QdrantClient.php` |
| `$searchText` / pominięcie wektora | `ProductAiSearchService::retrieveCandidates()` |
| Prefetch | `ProductAiSearchService::prefetchVectorQueries()` |
| Żargon | `backend/app/Support/CatalogSlangDictionary.php`, `/admin/zargon` |
| Job / kolejka | `ReindexProductEmbeddingJob` (`embeddings`) |
| Licznik w UI | `ProductCatalogHealthService::vectorProgress()` |
| Reindex | `products:reindex-embeddings` |

---

## 9. Kryterium zamknięcia

- Indeks ≈ liczba produktów (kafelek wektorów bez „kolejka”).
- Co najmniej kilka SIWZ z zagrożeniem chemicznym: oczekiwany SKU w puli
  retrievalu (`search:eval`, recall), nie tylko w rankingu gdy karta już jest
  w topce FULLTEXT po nazwie.
- Brak regresji na istniejącym golden (wampirki, nitrylki, marka+model).

Handlowiec nie akceptuje „model sam wie, że nitryl jest do amoniaku”.
Akceptuje: żargon + dokument karty mówią to samym językiem co zapytanie.

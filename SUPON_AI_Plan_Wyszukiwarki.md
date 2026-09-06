# Wyszukiwarka AI — stan i plan kolejnych kroków

Dokument przekazania dla nowej sesji. Stan na commit `b50697c` (main).
Kontekst: audyt wyszukiwania AI → wdrożenie P0 → naprawa czerwonych testów →
progi dopasowania w panelu.

---

## 1. Gdzie jesteśmy

**Wdrożone (na `main`, wypchnięte):**

| commit | zakres |
| --- | --- |
| `5f768af` | 9 heurystyk tożsamości karty w enrichmencie |
| `eb8f6b8` | wyszukiwanie AI + dopasowanie SIWZ — koniec podstawiania przypadkowego towaru |
| `a3b48dc` | P0: `search:eval`, telemetria `search_events`, świeżość embeddingów |
| `c64abd6` | progi dopasowania SIWZ w panelu `/admin/strojenie-ai` |
| `b50697c` | build produkcyjny frontendu |

**Testy: 945 passed / 0 failed.** Przed tą pracą było 25 czerwonych — wszystkie
naprawione przez zmianę kodu, żaden test nie został osłabiony.

**Pomiar jakości istnieje po raz pierwszy:** `php artisan search:eval` liczy
recall retrievalu osobno od jakości rankingu. Wcześniej każda zmiana w
wyszukiwarce była zgadywanką.

### Czego NIE ma

- Golden set to 10 przypadków wypisanych z katalogu przez model, **bez akceptacji
  handlowca**. Metryki bezwzględne nic jeszcze nie znaczą — służą do porównań
  „przed/po”.
- Telemetria nie zebrała jeszcze danych (wdrożona dopiero co).
- P1 i P2 z audytu nietknięte (cache, szersza pula do modelu, filtr w Qdrant,
  wyniki progresywne, reranker, rozbicie serwisu).

---

## 2. Do zrobienia na serwerze (blokuje resztę)

```bash
php artisan migrate --force
```

Czekają dwie migracje: `create_search_events_table` (telemetria) i
`add_match_thresholds_to_ai_settings` (progi). Bez nich panel progów zapisuje
w próżnię, a wyszukiwanie loguje ostrzeżenie zamiast zdarzenia — samo szukanie
działa normalnie.

---

## 3. Kolejne kroki, w kolejności

### K1. Obserwacja zaostrzonego dopasowania SIWZ (dni, nie godziny)

Dopasowanie jest teraz ostrzejsze: zniknął najsłabszy poziom („ten sam rodzaj
z katalogu”), a komplet („bluza + spodnie”) nie może być zaspokojony jedną
sztuką. Część pozycji zostanie pusta zamiast wypełniona przypadkowym towarem.

**Co obserwować:** ile pozycji zostaje bez produktu po `POST /tenders/{id}/match`.
Punkt odniesienia sprzed zmian: 286 dopasowanych pozycji, z tego **61 poniżej
70% score**, źródła: heuristic 140 (śr. 91), vector 98 (73), ai_substitute 30
(69), ai 16 (75), catalog 2 (62).

**Jeśli za dużo pustych** — panel `/admin/strojenie-ai`, w kolejności od
najłagodniejszego:
1. obniż „Zapis zamiennika” (55 → 45),
2. dopiero potem zaznacz „Wstawiaj też karty z zapasowej listy katalogowej”
   (przywraca stare zachowanie; to on odpowiada za większość pustych pozycji,
   ale wraca z nim ryzyko przypadkowego towaru w ofercie).

**Kryterium zamknięcia:** handlowiec mówi, czy puste pozycje są akceptowalne.
Bez tej odpowiedzi nie ruszaj progów w kodzie.

### K2. Rozbudowa golden setu z telemetrii — **najważniejszy krok**

Wszystko dalsze wymaga wiarygodnego pomiaru. Cel: **100–200 przypadków**
z prawdziwych SIWZ, z oczekiwanymi SKU zaakceptowanymi przez handlowca.

Źródło: `backend/resources/search-eval/README.md` ma gotowe zapytania SQL —
zapytania, po których ktoś faktycznie wziął produkt (`search_event_actions`
z akcją `pick`/`add_to_offer`) oraz zapytania bez żadnej akcji (najcenniejsze:
wynik, który nikomu nie pomógł).

Plik: `backend/resources/search-eval/golden.json`. Format i zasady w README.
Warto dopisywać `forbidden_skus` — fałszywy pozytyw w przetargu kosztuje więcej
niż brak trafienia.

**Kryterium zamknięcia:** `php artisan search:eval --save` na ≥100 przypadkach
daje raport, który handlowiec uznaje za odzwierciedlenie rzeczywistości.

### K3. Dziura w retrievalu marka+model (`peltor-x2-naglowne`)

Otwarte, powtarzalne, z gotowym testem: zapytanie
„Nauszniki przeciwhałasowe 3M Peltor X2 wersja nagłowna” **nie wciąga
`X2A-EU` do puli 80 kandydatów** — `recall retrievalu = 0.00`.

```bash
php artisan search:eval --filter=peltor-x2
```

Trop: `ProductAiSearchService::retrieveCandidates()` — ścieżka priorytetowa
(`retrieveByModelCode`, `retrieveByFuzzyModel`, `retrieveByManufacturer`) ma wagę
RRF 3.0, więc jeśli trafienie tam nie wchodzi, wina jest w rozpoznaniu modelu
(`ProductModelFuzzy`) albo w tym, że kaskada wcześniej zwraca coś innego.
Diagnostyka: `lastTrace()['candidate_ids']` + wywołanie prywatnych metod przez
refleksję w tymczasowym teście (wzorzec używany w tej sesji wielokrotnie).

**Uwaga:** testy chodzą na SQLite (LIKE), produkcja na MySQL (FULLTEXT) — ten
przypadek może być niewidoczny w testach. Weryfikuj `search:eval` na żywej bazie.

### K4. P1 z audytu — koszt i czas odpowiedzi

Kolejność wg stosunku zysku do ryzyka:

1. **Cache** — embeddingi zapytań w Redis (TTL dni) + wynik całego wyszukiwania
   na kluczu `hash(query, limit, wersja_katalogu, wersja_promptu)` na ~15 min.
   Dziś identyczne zapytanie = pełny pipeline i 1–3 wywołania modelu.
2. **Szersza pula do modelu** — dziś 24 karty z puli 80 (`RANK_CARDS`), o odsiewie
   decyduje heurystyka `constraintNeedles`. Albo dwa równoległe wywołania po 24
   karty, albo tani model jako pre-filtr. **Najpierw zmierz** na golden secie:
   jeśli `recall retrievalu` jest wysoki, a `nDCG` niski — problem jest tutaj.
3. **Qdrant: filtr i próg** — `ppe_family`/`manufacturer` do payloadu i do filtra
   zapytania + minimalny score. Dziś 150 najbliższych wchodzi do fuzji niezależnie
   od podobieństwa. Plus przeskalowanie wag RRF, gdy źródło zwróci pustkę.
4. **Wyniki progresywne** — trafienia po kodzie modelu i FULLTEXT są znane po
   ~200 ms, a użytkownik czeka na całość do 180 s.

### K5. P2 — utrzymywalność

- Prompt rankingu (~40 linii stringa w `rankMessages()`) do wersjonowanego
  zasobu; `RANK_PROMPT_VERSION` już leci do `search_events`, ale sam prompt
  siedzi w kodzie.
- Rozbicie `ProductAiSearchService` (3800+ linii) na Retriever / Ranker /
  MatchGates / ResultBuilder.
- Reranker (bge-reranker/cohere) między RRF a modelem.
- Wagi RRF do `AiSettings`, strojone na golden secie.

---

## 4. Mapa kodu

**Wyszukiwanie**
- `backend/app/Services/ProductAiSearchService.php` — cały pipeline; wejście:
  `search()`, `searchMany()`; ślad: `lastTrace()`; stała `RANK_PROMPT_VERSION`.
- `backend/app/Services/Search/ProductTextSearch.php` — FULLTEXT (MySQL) / LIKE (SQLite).
- `backend/app/Services/Vector/` — Qdrant + embeddingi.
- `backend/app/Support/CatalogCascadeRecall.php`, `CatalogRequirementRecall.php`,
  `CatalogSlangDictionary.php`, `PpeAssortment.php`, `PpeFilterType.php`,
  `ProductModelFuzzy.php`, `RrfFusion.php` — warstwa reguł domenowych.
- Słownik żargonu: `backend/config/catalog_slang.php` (+ nadpisanie w ustawieniach AI).

**Dopasowanie SIWZ**
- `backend/app/Services/ProductMatchService.php` — `resolveBestPick()` → `pickAuto()`
  → `persistableScore()`. Progi: `minMatchScore()`, `applyMatchScore()`,
  `substituteMatchScore()` (czytają ustawienia; stałe = wartości domyślne).

**Pomiar i telemetria**
- `php artisan search:eval [--filter=] [--k=10] [--save] [--baseline=plik]`
- `backend/app/Services/Search/SearchEvalRunner.php`, `app/Support/SearchEvalMetrics.php`
- `backend/resources/search-eval/golden.json` + `README.md`
- `backend/app/Services/Search/SearchEventRecorder.php`, modele `SearchEvent`,
  `SearchEventAction`; front: `logAiSearchAction()` w `frontend/src/lib/productAiSearch.ts`.

**Panel**
- `/admin/strojenie-ai` → `AiTuningController` + `UpdateAiTuningRequest` +
  `frontend/src/pages/AdminAiTuning.tsx`.

---

## 5. Jak pracować w tym repo

- **Uruchamiaj `php artisan test` przed commitem.** Historia pokazuje, że czerwone
  testy wchodziły razem z kolejnymi funkcjami i uzbierało się ich 25.
- `./vendor/bin/pint --dirty` przed commitem; front: `npx tsc -b --noEmit` i `npm run lint`.
- Frontend wdraża się osobnym commitem z buildem: `npm run build:prod`, potem
  `git add` nowego `backend/public/assets/index-*.js` + `index.html` i
  `git rm --cached` poprzedniego bundla (stare pliki zostają na dysku nieśledzone).
- Diagnostyka pipeline'u: tymczasowy test w `tests/`, refleksja na prywatne metody,
  `fwrite(STDERR, ...)`. Kasuj plik po diagnozie.

### Pułapki, które już raz ugryzły

- **SQLite w testach nie ma FULLTEXT** — część zachowań produkcyjnych jest
  niewidoczna w suite. Sprawdzaj `search:eval` na żywej bazie.
- **`static fn` nie widzi `$this`** — przy zamianie stałych na metody trzeba
  wyciągnąć wartość przed domknięciem.
- **Nowa kolumna w `ai_settings` musi trafić do `$fillable` i `casts()`** w modelu
  `AiSetting`, inaczej zapis cicho przepada.
- **`AiSettingsService::resolve()` bije do bazy** przy każdym wywołaniu —
  wyniki pamiętaj w polu obiektu, jeśli czytasz je w pętli.
- Kaskada odzyskiwania (`CatalogCascadeRecall`) potrafi zwrócić wąską listę i
  zakończyć retrieval; zmiany tam wymagają przebiegu `ProductAiSearchSlangTest`
  i `ProductAiSearchApiTest` (to one łapią regresje recallu).

---

## 6. Decyzje czekające na człowieka

1. Czy puste pozycje w SIWZ po zaostrzeniu są akceptowalne, czy wracamy do
   wypełniania kartami z listy katalogowej (przełącznik w panelu).
2. Kto akceptuje golden set — bez tego metryki są tylko względne.
3. Czy wchodzimy w cache wyników wyszukiwania (K4.1) przed poprawą recallu (K3),
   czy odwrotnie. Rekomendacja: **najpierw K2 i K3** (jakość), cache potem —
   cache złych wyników utrwala złe wyniki.

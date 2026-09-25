# Golden set wyszukiwania AI

Zbiór referencyjny do `php artisan search:eval`. Odpowiada na jedno pytanie:
**czy zmiana w wyszukiwarce poprawiła jakość, czy tylko przesunęła problem.**

## Uruchomienie

```bash
# pełny przebieg, zapis raportu
php artisan search:eval --save

# szybka iteracja na jednym przypadku (każdy przypadek = prawdziwe wywołanie modelu)
php artisan search:eval --filter=trzewiki

# porównanie po zmianie promptu / wag RRF / retrievalu
php artisan search:eval --save --baseline=storage/app/search-eval/reports/20260906_101500.json

# ablacja: ten sam golden bez Qdranta (nie rusza Ustawień AI)
php artisan search:eval --no-vector --save --baseline=storage/app/search-eval/reports/OSTATNI.json
```

## Metryki

| metryka | co mierzy | jak czytać |
| --- | --- | --- |
| `recall retrievalu` | ile oczekiwanych SKU weszło do puli kandydatów (przed modelem) | **sufit całego pipeline'u** — czego tu nie ma, tego model nie zobaczy |
| `recall@k` | ile z nich przetrwało ranking i bramki | różnica względem powyższego = strata na rankingu |
| `precision@k` | trafienia / k | dzielone przez k, więc przy 1 oczekiwanym SKU max = 1/k; tylko do porównań przebiegów |
| `nDCG@k` | jakość kolejności | 1.00 = trafienia na samej górze |
| `MRR` | pozycja pierwszego trafienia | co user widzi bez scrollowania |
| `naruszenia` | zwrócone SKU z `forbidden_skus` w top‑k (ścisłe) | fałszywy pozytyw w przetargu kosztuje więcej niż brak trafienia |
| `naruszenia blokujące` | naruszenia bez „zakazanych pod właściwą kartą” | **bramka porównań** — takie SKU automat przetargu mógłby wybrać |
| `zakazane pod właściwą kartą` | zakazane SKU z oceną poniżej progu zapisu (`match_min_score`, Ustawienia AI), nad którymi stoi karta wzorcowa albo równoważna z oceną ≥ progu | ostrzeżenie, nie błąd (np. ARMEN 6660 — inny kolor — z 60% pod 1010 z 99%) |
| `równoważniki w top‑k` | SKU z `acceptable_skus` w top‑k | trafienia innego producenta/modelu |

Trafienie w `precision@k`, `nDCG@k` i `MRR` to karta z `expected_skus` **albo** z
`acceptable_skus`. Idealny DCG liczy się z samych `expected_skus`: równoważnik w wyniku
zajmuje miejsce brakującej wzorcowej, a trafień liczy się najwyżej tyle, ile jest kart
wzorcowych — dopisanie równoważnika do golden setu nie obniża nDCG przy tym samym
wyniku, a karta spoza list na 1. miejscu dalej go obniża. `recall` i `recall retrievalu`
liczą wyłącznie `expected_skus`. Bez `acceptable_skus` wszystkie metryki są takie jak
przed 25.09.2026; zmiana list golden setu to zmiana miary — raport zapisuje ich podpis
(`golden_signature`), a komenda ostrzega przy `--baseline` z innym podpisem.

Diagnoza w jednym zdaniu: **niski recall retrievalu → pracuj nad retrievalem
(frazy, RRF, pula kandydatów). Wysoki recall retrievalu i niski nDCG → pracuj
nad rankingiem (prompt, karty, liczba kart).** Komenda podpowiada to w sekcji
„Najsłabsze przypadki” etykietą `[retrieval]` / `[ranking]`.

## Format przypadku

```json
{
  "id": "krótki-slug",
  "query": "wymaganie dokładnie tak, jak wpisałby je handlowiec",
  "expected_skus": ["SKU-1", "SKU-2"],
  "acceptable_skus": ["SKU-4"],
  "acceptable_note": "SKU-4: inny producent, warunki sprawdzone na karcie",
  "forbidden_skus": ["SKU-3"],
  "note": "dlaczego akurat te SKU"
}
```

- `expected_skus` — karty, które **muszą** znaleźć się w wyniku. SKU, nie id
  (id różnią się między środowiskami). Komenda ostrzega, gdy SKU nie ma w katalogu.
- `acceptable_skus` (opcjonalne) — równoważniki: karty innego producenta albo
  modelu, które spełniają **wszystkie** warunki wymagania, sprawdzone warunek po
  warunku na karcie (decyzja z 25.09.2026). Tylko karty producenta — bez kart
  dystrybutora z tym samym EAN (te łączy się w „Łączenie kart”, zostaje karta
  producenta) i bez osobnych kart rozmiarów. SKU nie może być jednocześnie na
  liście wzorcowych ani zakazanych — `loadCases` odrzuca taki plik.
- `acceptable_note` — skąd wiadomo, że równoważniki spełniają warunki: przy każdej
  karcie producent i dowody warunek po warunku (cytat z karty, a przy odczycie
  normy dopisek „wniosek”). Czego karta nie potwierdza, tego nie dopisujemy.
- `forbidden_skus` — karty, które przy tym wymaganiu są błędem (inna klasa
  ochrony, brak ESD, inny wariant mocowania). Opcjonalne, ale to one wyłapują
  najdroższe pomyłki. Komenda ostrzega także o zakazanych SKU spoza katalogu
  (np. karta dystrybutora po łączeniu kart) — taki zakaz niczego już nie pilnuje.
- `catalog_note` — tylko przy przypadkach `opisowy15-*`, gdy lista wzorcowa
  różni się od karty z fixture: dlaczego (np. inny SKU tego samego wyrobu na
  produkcji).

Przypadki `opisowy15-*` są lustrem `tests/Fixtures/catalog/opisowy15/items.json`
(pilnuje tego `SearchEvalRunnerTest`). Pola pomiaru w pozycji fixture:
`eval_extra_expected_skus` i `eval_extra_forbidden_skus` dopisują karty do list
z migawki, `eval_catalog_expected_skus` (z obowiązkowym `eval_catalog_note`)
zastępuje listę wzorcową, gdy karta migawki ma na produkcji inny SKU albo nie
spełnia wymagania, a `eval_acceptable_skus` + `eval_acceptable_note` to
równoważniki. `expected_sku` i `forbidden_skus` pozycji zostają bez zmian, bo
sprawdzają bramki na kartach migawki.

## Raport (`--save`)

Nagłówek zapisuje `match_min_score` — próg, względem którego liczono
„zakazane pod właściwą kartą”. Każdy przypadek oprócz metryk ma:

- `returned_top` — pierwsze k wierszy wyniku: `sku`, `percent`
  (`ai_match_percent`), `source` (`ai_match_source`: `catalog` = lista
  zapasowa, `rule` = skrót reguły, brak = ocena modelu albo skrót nazwanego
  modelu) i `tag` (`expected` / `acceptable` / `forbidden` / `other`). Z tej listy
  skutek zmiany golden setu dla metryk top‑k i naruszeń przelicza się bez nowego
  przebiegu modelu (recall retrievalu i MRR spoza top‑k — nie, tych lista nie niesie);
- `unrated_rows` — ile wierszy całego wyniku pochodzi z `catalog` albo `rule`;
- `violations`, `violations_blocking`, `forbidden_shown`, `acceptable_hits`;
- `unknown_skus`, `unknown_forbidden_skus`, `unknown_acceptable_skus` — SKU
  z golden setu, których nie ma w katalogu.

## Jak rozbudować zbiór

Docelowo 100–200 przypadków z prawdziwych SIWZ. Źródłem jest telemetria —
tabela `search_events` wraz z `search_event_actions`:

```sql
-- zapytania, po których handlowiec faktycznie wziął produkt do oferty
SELECT e.id, e.query, p.sku, a.action, a.position
FROM search_event_actions a
JOIN search_events e ON e.id = a.search_event_id
JOIN products p ON p.id = a.product_id
WHERE a.action IN ('pick', 'add_to_offer')
ORDER BY e.created_at DESC
LIMIT 200;
```

Wybrany produkt to gotowy kandydat na `expected_skus`. Odwrotnie:

```sql
-- zapytania bez żadnej akcji = wynik, który nikomu nie pomógł
SELECT e.id, e.query, e.result_count, e.created_at
FROM search_events e
LEFT JOIN search_event_actions a ON a.search_event_id = e.id
WHERE a.id IS NULL AND e.task = 'product_search'
ORDER BY e.created_at DESC
LIMIT 100;
```

To najcenniejsza pula — te przypadki dopisz do golden setu z ręcznie ustalonym
poprawnym SKU (albo pustą listą, jeśli katalog naprawdę nie ma odpowiednika;
takiego przypadku nie dodawaj, komenda wymaga niepustego `expected_skus`).

Zasady: jeden przypadek = jedno wymaganie; mieszaj łatwe (marka + model) z
trudnymi (sam warunek techniczny bez marki); przy każdym wpisie zostaw `note`,
żeby za pół roku dało się odtworzyć, dlaczego akurat te SKU są poprawne.

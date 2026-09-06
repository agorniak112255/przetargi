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
```

## Metryki

| metryka | co mierzy | jak czytać |
| --- | --- | --- |
| `recall retrievalu` | ile oczekiwanych SKU weszło do puli kandydatów (przed modelem) | **sufit całego pipeline'u** — czego tu nie ma, tego model nie zobaczy |
| `recall@k` | ile z nich przetrwało ranking i bramki | różnica względem powyższego = strata na rankingu |
| `precision@k` | trafienia / k | dzielone przez k, więc przy 1 oczekiwanym SKU max = 1/k; tylko do porównań przebiegów |
| `nDCG@k` | jakość kolejności | 1.00 = trafienia na samej górze |
| `MRR` | pozycja pierwszego trafienia | co user widzi bez scrollowania |
| `naruszenia` | zwrócone SKU z `forbidden_skus` | fałszywy pozytyw w przetargu kosztuje więcej niż brak trafienia |

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
  "forbidden_skus": ["SKU-3"],
  "note": "dlaczego akurat te SKU"
}
```

- `expected_skus` — karty, które **muszą** znaleźć się w wyniku. SKU, nie id
  (id różnią się między środowiskami). Komenda ostrzega, gdy SKU nie ma w katalogu.
- `forbidden_skus` — karty, które przy tym wymaganiu są błędem (inna klasa
  ochrony, brak ESD, inny wariant mocowania). Opcjonalne, ale to one wyłapują
  najdroższe pomyłki.

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

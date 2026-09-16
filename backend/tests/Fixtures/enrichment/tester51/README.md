# Zestaw odniesienia „tester 51”

Ręcznie przejrzany zestaw pozycji z trzech cenników (MAPA, SECURA, AJ GROUP) razem
z prawdziwym wynikiem wzbogacania i prawdziwymi migawkami stron, z których ten
wynik powstał. Służy do mierzenia, czy poprawki w pipelinie wzbogacania idą
w dobrą stronę.

Data zbudowania zestawu: **2026-09-16**.

## Skąd pochodzą dane

- **`items.json` — 43 pozycje.** Arkusz testerki ma 51 wierszy, ale po odjęciu
  trzech wierszy nagłówkowych (MAPA / SECURA / AJ GROUP) i wierszy pustych zostają
  42 wiersze z produktami. Wiersz „Chodnik elektroizolacyjny 20 KV” zawierał dwa
  kody w jednej komórce (`T5921002 / T5921003`) i został rozbity na dwie pozycje —
  stąd 43 wpisy. Pola `verdict` (`+`, `-`, `+/-`, `null`) i `note` są przepisane
  z arkusza bez zmian; ogólne uwagi z dodatkowej kolumny arkusza doklejono do
  `note` po separatorze `||` z prefiksem `UWAGA OGÓLNA`.
- **`products.json` — stan „PRZED”** wyciągnięty z lokalnej bazy MySQL (`products`,
  `product_images`, `product_documents`). To migawka produkcji: opis, `source_urls`,
  normy, certyfikaty, materiały, specyfikacje, zastosowania, atrybuty, `confidence`,
  adresy zdjęć i dokumentów. Niczego tu nie poprawiamy ani nie upiększamy — test ma
  sprawdzać to, co system naprawdę wyprodukował.
- **`pages/*.json` — 58 migawek** wszystkich unikalnych adresów z `produced.source_urls`
  (57 otwartych, 1 z błędem). Tekst obcięty do 20 000 znaków, czyli znacznie powyżej
  budżetu pipeline'u.

Nazwa pliku migawki: `{host-bez-kropek}-{pierwsze-10-znaków-sha1(url)}.json`.

## Jak pobrano migawki

Strony pobrano **tą samą drogą, którą widzi pipeline** — przez
`App\Services\Enrichment\ProductPageFetcher::fetch()` z `$product = null`, czyli bez
filtra tożsamości (inaczej fetcher odrzuciłby część stron i nie dałoby się zmierzyć,
co bramka przepuszcza). Po jednym adresie na wywołanie, `maxPages = 1`, odstęp około
1,6 s między żądaniami, bez ponawiania w nieskończoność:

```php
$out = app(ProductPageFetcher::class)
    ->fetch([['url' => $url, 'title' => '', 'snippet' => '']], '', 1, [], null);
```

Skrypt uruchamiano z katalogu `backend/` przez
`php artisan tinker --execute="require '<ścieżka do skryptu>';"`.

Strona, która się nie otworzyła, ma `"ok": false` i wypełnione `error` — to też jest
informacja. W zestawie jest jedna taka: `https://icd.pl/wodery-pros-wrm` (HTTP 404).
Osobny przypadek to `https://www.manutan.pl/pl/mpl/rekawiczki-lateksowe-mapa-jersette-300-...`,
które odpowiedziało stroną CAPTCHA (Radware Bot Manager) — formalnie `ok: true`, ale
treść nie opisuje produktu.

## Jak etykietowano źródła (`items.json` → `sources[].verdict`)

Każdy adres z `produced.source_urls` dostał etykietę po **przeczytaniu tytułu i tekstu
pobranej migawki** i zestawieniu go z nazwą oraz kodem produktu z cennika i z uwagą
testerki:

- `expected` — strona NA PEWNO opisuje dokładnie ten wyrób (ten sam model, ten sam typ
  wyrobu). Przykład: `mapa-pro.pl/.../alto-260` przy pozycji `34260038 ALTO 260`,
  `domtechniczny24.pl/płatek-zaworu-wydechowego-secura-3000-...` przy `S56212-10`
  (strona podaje wprost kod `S5621210`).
- `wrong` — strona NA PEWNO opisuje inny wyrób. Przykład: karta półmaski SECURA 3000
  przy pierścieniu z zaczepami `S56212-50`, karta zestawu `SECURA 3100-LAK` przy
  nagłowiu `S5621300`, karta półbutów 20 kV przy kaloszach 5 kV `T5911400`,
  karta *dywanika* 0,75 × 0,75 m przy *chodniku* `T5921003`.
- `unknown` — brak pewności. Tak oznaczono m.in.: warianty wymiarowe chodnika
  elektroizolacyjnego (nie wiadomo, który wymiar odpowiada któremu kodowi z cennika),
  półmaskę SECURA 3000 przy rozmiarach `S56T0SL0`/`S56T0SS0` (strona przypisuje sobie
  kod rozmiaru M), stronę z CAPTCHA, stronę 404, karty `BEMOREGREEN 906`/`903`
  (nie potwierdzono, że to ten sam wyrób co `906`/`903` z cennika AJ GROUP), fartuch
  `model 110` przy pozycji `110/75` oraz ocieplany `104/1 OC` przy nieocieplanym `104/1`.

Zasada nadrzędna: **przy jakiejkolwiek wątpliwości `unknown`**. Fałszywe `expected`
albo `wrong` zatruwa wszystkie przyszłe pomiary, `unknown` tylko zmniejsza próbkę.

Bilans etykiet: **39 `expected`, 14 `wrong`, 13 `unknown`** (łącznie 66 par
produkt–adres; ten sam adres bywa źródłem kilku pozycji).

Uwaga: etykiety źródeł są niezależne od oceny testerki. Bywa, że pozycja ma ocenę `+`,
a źródło jest obiektywnie obce — tak jest przy `T5911400` (testerka zauważyła tylko
zdublowane normy, a opis powstał z karty zupełnie innego obuwia). I odwrotnie:
`T5912200` ma ocenę `-`, ale źródło jest właściwe — błędne są dopiero wartości
wyciągnięte z tej strony.

## Jak podnosić progi

`tests/Feature/EnrichmentTester51Test.php` liczy dwie rzeczy, podając każdą parę
(produkt, migawka) do prywatnej metody `ProductEnrichmentService::keepConfirmedCardPages()`:

| Stała | Znaczenie | Pomiar 2026-09-16 |
| --- | --- | --- |
| `MIN_WRONG_REJECTED` | ile źródeł `wrong` bramka odrzuciła | **0** z 14 |
| `MIN_EXPECTED_KEPT` | ile źródeł `expected` bramka zachowała | **39** z 39 |

Po każdej poprawce bramki tożsamości:

1. uruchom `php artisan test --filter=EnrichmentTester51`,
2. odczytaj z komunikatu nowe liczby (albo tymczasowo podbij próg, żeby test wypisał
   raport),
3. ustaw stałe na nowo zmierzone wartości i zacommituj je razem z poprawką.

**Progów nigdy nie obniżamy, żeby test zzieleniał.** Spadek `MIN_WRONG_REJECTED`
oznacza, że znowu wpuszczamy karty obcych produktów; spadek `MIN_EXPECTED_KEPT`, że
zaczęliśmy odrzucać karty własne i produkt zostanie bez opisu.

Dzisiejszy próg `0` mówi wprost: **bramka tożsamości nie odrzuca w tym zestawie ani
jednej obcej karty.** Lista 14 przepuszczonych adresów jest w komunikacie błędu testu
i to jest kolejka roboty.

## Czego ten zestaw NIE mierzy

- **Jakości prozy generowanej przez model.** Nie sprawdzamy, czy opis jest ładny,
  spójny ani czy nie powtarza akapitów — do tego potrzebny byłby model językowy,
  a test ma działać bez sieci.
- Poprawności wyciągniętych wartości (grubość, napięcie, kategoria, rozmiary). Uwagi
  testerki o takich błędach są w `items.json` jako `wrong_values` / `size_wrong`, ale
  test ich jeszcze nie asertuje.
- Obecności i trafności zdjęć oraz plików PDF. Adresy są zapisane w `products.json`,
  kody `image_missing` / `image_wrong` / `pdf_unrelated` czekają na osobną zapadkę.
- Kolejności i rankingu wyników wyszukiwarki — zestaw zna tylko adresy, które
  faktycznie trafiły do opisu, nie całą listę kandydatów.
- Deduplikacji wariantów w cenniku (`variant_duplicate`) — to problem katalogu,
  nie bramki stron.

## Słownik kodów `issues`

| Kod | Znaczenie |
| --- | --- |
| `mixed_sources` | opis sklejony z kilku linków o różnych produktach |
| `wrong_product` | opis/zdjęcie dotyczy innego produktu (np. zestawu nadrzędnego zamiast części) |
| `not_manufacturer` | dane spoza strony producenta, choć producent ma kartę tego produktu |
| `wrong_values` | parametry sprzeczne ze stroną producenta |
| `missing_norms` | opis twierdzi „brak norm/certyfikatów”, a producent je podaje |
| `dup_norms` | normy wypisane dwa razy |
| `dup_text` | ten sam fragment opisu/powłoka opisana dwa razy |
| `desc_missing` | brak opisu |
| `image_missing` | brak zdjęcia |
| `image_wrong` | zdjęcie nie tego produktu (logo, budynek, inny wyrób) |
| `pdf_unrelated` | w plikach PDF dokument niedotyczący produktu |
| `variant_duplicate` | ta sama pozycja powielona (warianty rozmiarowe) |
| `size_wrong` | błędne rozmiary / nazwa modelu potraktowana jako rozmiar |

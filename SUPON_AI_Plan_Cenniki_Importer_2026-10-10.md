# Cenniki z plików jak B2B: cennik → plik → importer per cennik → mapa kart (10.10.2026)

Kontrakt zamrożony po recenzji agentem Plan. Szkic v1 i pełna recenzja: scratchpad sesji 94697342 (plan-cenniki-importer-v1.md).

## 0. Prośba i decyzje właściciela
Prośba: najpierw zakładamy cennik (producent, rabat, strony długich opisów), strona producenta z „Strony wyszukiwarka”, potem plik,
potem programista przygotowuje IMPORTER dla tego cennika, który mapuje produkt do strony producenta (albo dostawcy po lepszy opis,
albo — gdy u producenta brak towaru — do strony ze zdjęciem i opisem). „Raz na zawsze naprawić opisy cenników z plików.”

Decyzje 10.10.2026:
1. Karta, której importer NIE przypiął strony: **bez opisu z internetu**, trafia do „Do przeglądu” (review_reason `source_unmapped`),
   handlowiec „Wskaż adres”. Istniejące opisy zostają. Dotyczy cenników nowym sposobem (source_policy = map_only); zmienia decyzję
   z 07.10 („automat zapisuje zawsze”) tylko dla nich.
2. Pilotaż: **MAPA #1** (punkt odniesienia z etapu 1: 147/147 hard).

## 1. Zasada
Tożsamość karty i jej źródło ustala RAZ, deterministycznie, importer napisany i przetestowany przez programistę na prawdziwym pliku
i prawdziwych stronach. Wynik jest zapisany (`product_source_pins`) i widoczny. Pobieranie opisu karty z mapą nie szuka niczego —
czyta wskazaną stronę (uogólnione przypięcie Coby). Człowiek nadal decyduje przez `shop_source_url` („Wskaż adres”), który wygrywa.

## 2. Dane (migracje 2026_10_10_100000/100100/100200, products bez ALTER)
- `price_lists`: `importer_key` string(60) null idx, `source_policy` string(16) null (`map_only`; null = dawny sposób), `importer_notes` text null.
- `price_list_files`: id, price_list_id FK cascade, sha256, disk, path (`price-list-files/{sha256}.{ext}`), original_name, size, mime,
  uploaded_by, status `new|imported|failed|superseded`, error, importer_key, importer_version, price_list_import_id, imported_at;
  unique(price_list_id, sha256).
- `product_source_pins`: product_id UNIQUE FK cascade, price_list_id FK cascade, importer_key, importer_version, url null (null =
  nierozwiązana), source_kind `manufacturer|supplier|shop`, page_title, image_url, match_kind `exact_code|short_code|ean|model|parts_table`,
  match_key, spec json, evidence json, unresolved_reason, candidates json, checked_at.
- `Product::REVIEW_SOURCE_UNMAPPED = 'source_unmapped'` (review_reason string(32) — bez migracji).
- Bez nowych kolumn na rabat/walutę/rodzaj ceny: rabat = istniejący „Upust na cały cennik” (AssortmentGroup GLOBAL przez
  PriceListDiscountService); waluta i rodzaj ceny są cechą formatu pliku → zna je importer. Strona producenta = `manufacturer_sites`
  source=manual (ManufacturerSite::remember), nie kolumna.

## 3. Kod zamrożony (napisany przed agentami)
- Modele: `PriceListFile`, `ProductSourcePin` (KINDS, MATCH_KINDS, isResolved), `PriceList` (POLICY_MAP_ONLY, INTAKE_*, files(),
  usesIntake(), intakeStatus()).
- `app/Services/PriceLists/Importers/`: `PriceListImporter` (key/label/version/manufacturerKeys/read/mapSource), `ReadContext`,
  `ReadResult`, `ImportedRow` (toPayload, identifiers), `SourceDecision` (pinned/unresolved/toPinAttributes), `MapContext` (interfejs),
  `PriceListFormatChanged`, `PriceListImporterRegistry` (IMPORTERS, classFor, make, options).
- `app/Services/Enrichment/Sources/SourcePin` (interfejs wspólny PartsTablePin i MappedSourcePin).

## 4. Statusy przyjęcia (liczone, PriceList::intakeStatus)
`legacy` (source_policy null) · `awaiting_file` · `awaiting_importer` (plik jest, brak importer_key) · `importer_missing` (klucza nie ma
w rejestrze — np. wdrożenie bez klasy) · `ready` (najnowszy plik `new`) · `imported` · `failed` (najnowszy plik `failed`, error).

## 5. API (uprawnienia: odczyt `price_lists.view`, zapis jak dzisiejszy import cenników)
- `POST /price-lists/intake` `{manufacturer, version, manufacturer_hosts:{"<brand_key>":["host"]}|[] , enrichment_sites:[], enrichment_sites_mode,
  suggested_prices, importer_notes, discount_percent?:number|null}` → 201 `{price_list: IntakeView}`; istniejący manufacturer_key → 409
  `{message, price_list_id}` (cennik istnieje — otwórz go; dla wiersza B2B komunikat, że pisze do niego konto B2B). Zakłada wiersz
  price_lists z source_policy=map_only, zapisuje hosty producenta (ManufacturerSite::remember manual), rabat (PriceListDiscountService).
- `PATCH /price-lists/{priceList}/intake` — te same pola; ustawienie na starym cenniku włącza nowy sposób (source_policy=map_only).
- `PATCH /price-lists/{priceList}/importer` `{importer_key|null}` — tylko administrator (uprawnienie admin), 422 przy nieznanym kluczu.
- `GET /price-lists/importers` → `{importers: registry.options()}`.
- `POST /price-lists/{priceList}/files` multipart `file` (xlsx/xls/csv/pdf, ≤ 100 MB) → 201 `{file: FileView, intake: IntakeView}`;
  ten sam sha256 → 200 z istniejącym. Poprzedni plik `new` → `superseded`.
- `GET /price-lists/{priceList}/files` → `{files: FileView[]}`; `GET .../files/{file}/download` (stream).
- `POST /price-lists/{priceList}/files/{file}/preview` `{limit?:200}` → 200 PreviewView; 409 gdy brak importera; 422 `{message}` przy
  PriceListFormatChanged. ZERO zapisów (ReadOnlyGuard).
- `POST /price-lists/{priceList}/files/{file}/import` `{describe: bool}` → 201 `{created, updated, skipped, errors, price_changes,
  map_job: "queued"}`; plik → imported/failed; potem MapPriceListSourcesJob(priceListId, describe).
- `GET /price-lists/{priceList}/source-pins?state=unresolved|pinned&page=` → paginacja `{data:[{product_id, sku, name, url, source_kind,
  match_kind, match_key, unresolved_reason, candidates, human_url}], meta}`.
- Blokada: `POST /price-lists/import` (stary import) → 422 „Ten cennik przyjmuje się nowym sposobem — dodaj plik w Cenniki → Z pliku”,
  gdy cennik o tym manufacturer_key ma source_policy albo importer_key.
- `GET /price-lists/files` (zakładka „Z pliku”) dołącza cenniki z source_policy NOT NULL także bez kart i zwraca w wierszu `intake`.

IntakeView: `{id, manufacturer, manufacturer_key, version, source_policy, importer_key, importer_label, importer_notes, status,
manufacturer_hosts:{brand_key:[host]}, enrichment_sites, enrichment_sites_mode, suggested_prices, discount_percent, latest_file: FileView|null,
pins:{pinned, unresolved, total}}`.
FileView: `{id, original_name, sha256, size, status, error, importer_key, importer_version, imported_at, created_at, uploaded_by_name}`.
PreviewView: `{importer:{key, version}, rows_total, rows:{create, update, skip, blocked}, skipped:[{ref, sku, reason}],
price_changes:[{sku, name, old, new}], sources:{manufacturer, supplier, shop, unresolved, human_url, b2b_description, not_checked},
unresolved:[{sku, name, reason, candidates}], samples:[{sku, name, action, url, source_kind, match_kind}], notes:[], not_in_preview:[…]}`.

## 6. Import i mapa (agent B)
- `PriceListImportService::importCollected(PriceList $list, PriceListFile $file, User $u, array $collected, ?array $groupOptions): array`
  → applyGroupOptions + persistImport(target: $list) — bez collapseSamePriceVariants, bez AI. Plik owijany w UploadedFile (test mode).
- `persistImport(..., ?PriceList $target = null)` — gdy target podany: używa go (asercja zgodności manufacturer_key), nie find-or-create.
- `planImport(PriceList $list, array $collected, ?array $groupOptions): array` — tylko odczyt, wspólna funkcja decyzji wiersza wydzielona
  z pętli persistImport (redirect, wykluczenia, findExistingProduct, foreignManufacturer, detectPriceChange) + wariant grup bez upsertGroup.
  Test zgodności planImport ↔ persistImport.
- `ReadOnlyGuard::run(callable)`: cache.default=array, queue.default=null (sync bez wykonania), MySQL `SET SESSION TRANSACTION READ ONLY`
  (finally READ WRITE), DB::listen → wyjątek ReadOnlyViolation na insert/update/delete/replace/create/alter/drop/truncate.
- `DefaultMapContext` (implementacja MapContext; liveFetch=false w podglądzie).
- `MapPriceListSourcesJob(int $priceListId, bool $describe, ?array $productIds = null)`: karty z PriceListCards::fileSlotIds, porcje 200,
  wiersze z product_identifiers (source_key file:{id}), importer->mapSource z liveFetch=true, zapis tylko zmienionych pinów
  (updateOrCreate po product_id), describe → enqueueProductIds(force, scope price_list) dla nowych kart, zmienionych pinów i kart bez opisu.
- Polecenia (argument `{lista}` = id albo manufacturer_key): `price-lists:pending`, `price-lists:bind {lista} {klucz} {--unbind}`,
  `price-lists:preview {lista} {--file=} {--live-fetch} {--json} {--limit=}` (w ReadOnlyGuard — programista uruchamia na produkcji),
  `price-lists:map {lista} {--id=*} {--apply} {--queue}`, `price-lists:import {lista} {--file=} {--apply} {--describe}`.

## 7. Pobieranie opisu (agent C)
- `SourcePins` (#[Scoped]): kolejność trustedShopUrl → null (stara ścieżka z adresem człowieka); PartsTables::pinFor (Coba) →
  PartsTablePin; product_source_pins z url, gdy cennik karty (slot file) ma source_policy → MappedSourcePin. `blockedReason(Product)`:
  cennik map_only, brak adresu człowieka, pin bez url albo brak pinu → powód (string), inaczej null.
- `PartsTablePin implements SourcePin` (właściwości zostają), `MappedSourcePin` (payloadKey 'source_map', groupKey 'map:'.sha1,
  publishReason MAPPED_SOURCE_REASON = 'strona z mapy importera cennika', handlesImages = image_url !== null).
- `MappedSourceImages::apply(Product, SourcePin, bool $dryRun=false)`: pobiera image_url, ustawia główne, NIC nie odrzuca (nie używać
  PartsTableImages — ta odrzuca zdjęcia spoza hostów producenta).
- ProductEnrichmentService: wszystkie miejsca `$this->pin` (partsTablePin :315 → SourcePins; :1183 log → logLabel; payload :2216/:3165 →
  payloadKey; pinned_page + pinned_reason :2229/:3175; bramki zdjęć `$this->pin === null` → `! $this->pinOwnsImages()`; członek modelu
  :2867). `SourceUnmappedException` (osobna, nie dziedziczy po ManufacturerPageMissingException): w enrichProduct po :1183 i w prefetch
  :641 wcześniejsze wyjście; catch: review_reason=source_unmapped, opis zostaje (status bez zmian przy opisie, inaczej manual), bez
  cofania, bez modelu, bez wyszukiwarki.
- DescriptionVersionStore::decide: `pinned_reason` z kandydata, domyślnie PARTS_TABLE_REASON. ModelGroupPlanner: groupKey() tylko przy
  profilu z modelGroup (jak dziś).

## 8. Front (agent D)
- „Z pliku” (PriceListsFiles.tsx): przycisk „+ Dodaj cennik z pliku” → PriceListIntakeForm (Producent ze słownika marek, Wersja,
  Strona producenta per marka z „Strony wyszukiwarka” z liczbą zaindeksowanych stron i ostrzeżeniem przy słabym indeksie, Strony
  dostawców z opisami + tryb, Rabat na cały cennik %, Ceny sugerowane, Uwagi dla programisty). 409 → link do istniejącego.
- Wiersz cennika nowym sposobem: plakietka stanu (Czeka na plik / Czeka na importer — przygotuje programista / Importer brak we
  wdrożeniu / Gotowy do importu / Zaimportowany / Błąd: …), „Dodaj plik”, lista plików, „Podgląd importu” (modal PreviewView),
  „Importuj” (z checkboxem „Pobierz opisy po imporcie”), „Karty bez strony (N)” → lista source-pins unresolved, „Edytuj ustawienia”.
  Administrator: wybór importera (GET /price-lists/importers).
- Do przeglądu: powód `source_unmapped` = „Brak strony z importera — wskaż adres”.
- PriceLists.tsx: komunikat 422 starego importu z linkiem do „Z pliku”.
- Pomoc: wpis o nowym sposobie (help-must-cover-all-modules).

## 9. Pilot MAPA (agent E)
`app/Services/PriceLists/Importers/Mapa/MapaPriceListImporter.php` (key `mapa-2025`), read() pliku „MAPA SUPON - cennik bazowy & ceny
specjalne_2025.xlsx” (wynik zgodny z obecnymi kartami #1: kody, nazwy, ceny), mapSource() → strona mapa-pro (pl/com) z kodem,
fixture'y w tests/Fixtures/price-lists/mapa/. Porównanie mapy z obecnym primary_source_url kart #1 (odczyt produkcji).

## 10. Poza zakresem (osobne zadania, jak łączniki B2B)
Importery SECURA, AJ GROUP, CEDERROTH, Ansell, Coba (delegacja do PartsTables) i cenniki z folderu !Wojtek; kilka stron na kartę;
grupowanie modeli po adresie mapy bez profilu; wykrywanie stron 404/przekierowań; automatyczne wiązanie; rabat z nazwy pliku.

## 11. Testy
Migracje up/down/up; intake 201/409/uprawnienia; plik ten sam sha → 200; blokada starego importu + dotychczasowe PriceList*Test bez zmian;
rejestr; podgląd = zero zapisów (DB::listen, Queue::fake, Storage::fake, cache); planImport ↔ persistImport zgodne (redirect, wykluczenia,
Canis, sklejone kody); importCollected bez scalania; job mapy (tylko sloty cennika, niezmieniony pin bez zapisu, opisy tylko nowe/zmienione,
B2B pominięte); pin z mapy (wyszukiwarka niewołana, jedno pobranie, MAPPED_SOURCE_REASON, payload.source_map, image_url główne bez
odrzuceń); trustedShopUrl wygrywa; map_only bez mapy (bez modelu, opis zostaje, source_unmapped, force nic nie cofa); Coba bez regresji;
stare cenniki bez zmian; pilot MAPA (odczyt fikstury, zmiana nagłówka → PriceListFormatChanged, mapa na zapisanych stronach); front tsc+eslint.

## 12. Stan
- 10.10: kontrakt + fundament (migracje, modele, interfejsy). Agenci A–E w toku.

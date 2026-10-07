# Etap 1 opisów z cenników — projekt techniczny i zamrożony kontrakt (08.10.2026)

Do planu `SUPON_AI_Plan_Opisy_z_cennikow_2026-10-07.md` (sekcje 2.1–2.8, decyzje w sekcji 4). Projekt agenta Plan,
przegląd: kolejność zmieniona — najpierw części B i C, pomiar werdyktu tożsamości na Coba/MAPA, dopiero potem A i D.

## 1. Decyzje architektoniczne
1. **Profil producenta = warstwa odczytu nad istniejącym configiem.** `enrichment.manufacturer_domains`,
   `manufacturer_only_sources`, `manufacturer_catalogs` zostają (czyta je ~15 miejsc). Nowy `config/manufacturer_profiles.php`
   dodaje tylko: `identity_in`, `code`, `model_alias_is_key`, `resolver`. `ManufacturerProfiles` scala w locie. Fizyczne
   scalenie — etap 5.
2. **`identity_in`: `url | title | markup | text`.** Domyślnie `['url','title','markup']`; Coba, CEDERROTH, MAPA także `text`
   (tabela wariantów trafia do tekstu strony — kod jako osobny token).
3. **Werdykt tożsamości to sygnał, obecne bramki bez zmian** (`keepConfirmedCardPages`, `orderPagesForDescription`,
   `primarySource` — testy wołają je refleksją).
   - twardy: klucz (SKU = kod producenta, EAN, manufacturer_code, model_code, opcjonalnie model_alias) w adresie, tytule,
     mikrodanych albo w tekście (gdy profil pozwala) — każdy host, także sklep (decyzja 2); twardy też ręczny adres
     (`trustedShopUrl`) i blok katalogu PDF dopasowany po kodzie;
   - miękki: brak klucza, ale `pageHasSkuOrNameAndManufacturer` = true;
   - brak: stronę przepuściła tylko heurystyka.
   Werdykt karty = werdykt strony `primary_source_url`.
4. **ProductPageFetcher oddaje kody i final_url.** `markupSkus` (:1357) czyta itemprop sku/mpn, JSON-LD (:1434–1460) tylko
   sku/name. Dochodzi `page['markup_codes']` (sku/mpn/gtin z itemprop i JSON-LD Product/ProductGroup.hasVariant),
   `page['final_url']`, dziennik stron przebiegu `startRunLog/runLog/stopRunLog` (strony przychodzą z ~8 wywołań fetch
   w PES). Tekst surowy = `page['text']` przed `sanitizePagesWithLlm`; po filtrze = `$pageSnippets` przy zapisie.
5. **Dowody (`evidence`) liczy kod, prompt bez zmian** (`jsonContract` wspólny z B2B). `EvidenceExtractor` szuka dosłownie:
   kody norm i poziomy (logika `sourceClaims`/`normDesignations`), liczby z jednostką, numer jednostki notyfikowanej, klasa;
   materiał i klasa przez mały słownik PL→EN/DE. Cytat = okno ±100 znaków. `explicit` = w źródle, `inferred` = w payloadzie
   bez pokrycia. Braki per kategoria — etap 3.
6. **`describeFromStoredSources` w etapie 1 tylko w trybie cienia** (komenda, wersje `shadow`, bez zapisu `products`).
   Główna ścieżka zostaje na `extractWithLlm`; przełączenie per marka w etapie 2/5 po porównaniu.
7. **`review_reason` kolumną w `products`** (poza `ProductSearchBlob::SOURCE_COLUMNS` — bez reindeksu), historia decyzji
   w `product_description_versions`.
8. **Porównanie wersji leksykograficzne: najpierw tożsamość, potem liczba dowodów.** Baza = wersja `published`, której
   `description_sha1` = sha1 bieżącego opisu (zapis przez B2B / reset / edycję zmienia sha → baza wygasa sama). Stare opisy
   dostają bazę komendą `products:baseline-versions`; bez niej nie są chronione.

## 2. Zamrożony kontrakt

### Migracje (dodają, `down()` odwraca)
- `2026_10_08_100000_create_product_description_versions_table.php` (C): id; product_id FK cascadeOnDelete; status
  string(16) `published|proposed|superseded|rejected|shadow`; origin string(24)
  `enrichment|sku_cache|restore|review_approve|legacy_baseline|stored_sources`; description TEXT null; enrichment_payload
  JSON null; enrichment_trace JSON null; packaging string null; description_sha1 char(40) null; primary_source_url
  string(2000) null; identity_verdict string(8) null; identity_reason string(255) null; evidence_count unsignedSmallInteger
  null; completeness decimal(4,3) null; review_reason string(32) null; reason string(255) null; batch_id
  unsignedBigInteger null (bez FK); created_by FK users null nullOnDelete; decision string(16) null
  `approved|rejected|url_given|restored`; decided_by FK users null nullOnDelete; decided_at timestamp null; timestamps.
  Indeksy `(product_id, status)`, `(product_id, id)`. Retencja w serwisie: wszystkie published i proposed + 5 ostatnich
  pozostałych na kartę.
- `2026_10_08_100100_create_product_source_documents_table.php` (B): id; product_id FK cascadeOnDelete;
  description_version_id unsignedBigInteger null (indeks, bez FK); url string(2000); url_hash char(40) =
  sha1(Product::normalizeShopUrl(url)); final_url string(2000) null; host string(255); sha256 char(64) (tekst surowy);
  filtered_sha256 char(64) null; chars unsignedInteger; fetched_at timestamp; identity_verdict string(8) null;
  identity_reason string(255) null; identity_key string(80) null (np. `sku:CCLIP25`); roles string(40)
  (`description,image,norms`); markup_codes JSON null; norm_facts JSON null; timestamps. Unique `(product_id, url_hash,
  sha256)` jako `psd_unique`; index sha256; index `(product_id, fetched_at)`.
- `2026_10_08_100200_add_review_reason_to_products.php` (C): review_reason string(32) null z indeksem, review_since
  timestamp null; bez `->after()`.
- `2026_10_08_100300_add_products_review_permission.php` (C): `products.review` dla admin, handlowiec, przetargi, kierownik
  i każdej roli z `price_lists.import` (wzór `add_notices_view_permission`); wpis w `PermissionCatalog`.

### Konfiguracja
- `config/filesystems.php`: dysk `sources` (local, `storage_path('app/sources')`, throw false).
- `config/enrichment.php`: `'store_sources' => (bool) env('ENRICHMENT_STORE_SOURCES', true)`.
- `phpunit.xml`: `ENRICHMENT_STORE_SOURCES=false` (stare testy bez plików; nowe włączają i używają `Storage::fake('sources')`).
- `config/manufacturer_profiles.php`:
```php
return [
  'default' => ['identity_in' => ['url','title','markup'], 'code' => ['normalize' => 'upper_alnum', 'min_length' => 4], 'model_alias_is_key' => false, 'resolver' => null],
  'profiles' => [
    'coba'      => ['brand_keys' => ['coba','coba-europe'], 'identity_in' => ['url','title','markup','text'], 'code' => ['normalize'=>'upper_alnum','model_regex'=>'/^([A-Z]+)\d/']],
    'cederroth' => ['brand_keys' => ['cederroth'], 'identity_in' => ['url','title','markup','text']],
    'mapa'      => ['brand_keys' => ['mapa'], 'identity_in' => ['url','title','markup','text'], 'model_alias_is_key' => true],
  ],
];
```

### Nowe klasy (publiczne sygnatury)
```php
// B: App\Services\Enrichment\ManufacturerProfiles
public function for(Product $p): ?ManufacturerProfile; // readonly: brandKey, hosts, onlyManufacturer, catalogs, identityIn, codeNormalize, minLength, modelRegex, modelAliasIsKey, resolver
// B: App\Support\ProductCodeMatch (algorytm z PES::textCarriesCode / supplementCodeKey)
public static function key(string $code): string;  public static function textCarries(string $text, string $key): bool;
// B: App\Services\Enrichment\SourceClaims (1:1 z PES::sourceClaims, normDesignations, normDesignationSupported, claimKey)
public static function claims(string $t): array; public static function designations(string $t): array;
public static function designationSupported(string $c, array $n): bool; public static function key(string $t): string;
// B: App\Services\Enrichment\SourceIdentity
public function keysFor(Product $p, ?ManufacturerProfile $prof): array; // list<array{type:string,value:string}>
public function judgePage(Product $p, array $page, ?ManufacturerProfile $prof): array; // {verdict:'hard'|'soft'|'none', reason, key_type:?string, key:?string, where:?string}
public function judgeCard(Product $p, array $pages, ?string $primaryUrl, ?string $primaryKind, array $catalogUrls): array; // + source_url, profile
// B: App\Services\Enrichment\EvidenceExtractor
public function extract(array $lists, array $docs): array; // $docs list<{sha256,text}> → {entries: list<{field,value,quote:?string,source_sha256:?string,status:'explicit'|'inferred'}>, explicit:int, inferred:int, completeness:?float}
// B: App\Services\Enrichment\SourceDocumentStore + readonly SourceDoc(url, finalUrl, host, sha256, filteredSha256, verdict, verdictReason, roles, normFacts, markupCodes, fetchedAt)
public function put(string $text): string;  public function get(string $sha256): ?string;
public function record(Product $p, array $docs, ?int $versionId): void; // upsert + retencja na (karta, adres)
public function forProduct(Product $p, string $role = 'description'): array; // list<SourceDoc>, bez tekstu
// B: App\Models\ProductSourceDocument
// C: App\Models\ProductDescriptionVersion; Product: $fillable += review_reason, review_since; cast review_since; descriptionVersions();
//    stałe REVIEW_IDENTITY_SOFT='identity_soft' | REVIEW_IDENTITY_NONE='identity_none' | REVIEW_WORSE_VERSION='worse_version' | REVIEW_REJECTED_SOURCE='rejected_source'
// C: App\Services\Enrichment\DescriptionVersionStore
public function snapshot(Product $p): array; // status, error, norms, manufacturer_norms, packaging, web_files{images,documents}
public function decide(Product $p, array $candidate): array; // {identity, evidence_count, primary_source_url, manual_url:bool} → {action:'publish'|'propose', review_reason:?string, reason:string}
public function record(Product $p, string $status, string $origin, array $data, ?User $by = null): ProductDescriptionVersion; // published → poprzednia published = superseded
public function publish(ProductDescriptionVersion $v, ?User $by, string $origin): Product; // przez model: description, payload(+description_version_id), packaging, norms=ProductNormsColumn::fromList, status done, review_reason null
public function hasProtectedPublished(Product $p): bool;  public function rejectedUrls(Product $p): array;
// C: App\Services\ProductReviewService
public function list(array $filters, int $page, int $perPage): array;
public function approve(Product $p, ?int $versionId, User $u, ?string $note): array;
public function reject(Product $p, ?int $versionId, User $u, ?string $note): array;
public function giveUrl(Product $p, string $url, User $u): array;
```

### Reguła `decide`
1. Ręczny adres → publikuj.
2. `primary_source_url` w `rejectedUrls` → propozycja (`rejected_source`).
3. Karta bez opisu albo bez bieżącej bazy → publikuj.
4. Ranga: hard 3, soft 2, none 1, null = nieznana. Obie znane i nowa niższa → propozycja (`worse_version`); wyższa → publikuj.
5. Równa albo nieznana ranga: `published.evidence_count` niepuste i nowa ma mniej → propozycja; inaczej publikuj.
6. `review_reason` przy publikacji: soft → identity_soft, none → identity_none, hard → null.
7. `force` nic nie zmienia; karta z opisem B2B obchodzona jak dotąd.

### Zmiany w ProductEnrichmentService.php (wyłącznie część A)
Nowe zależności przez `app()` (testy budują serwis `new ProductEnrichmentService(...)` z 11 argumentami).
- przed `update(RUNNING)`: `$before = versions()->snapshot($product)`; `pages->startRunLog()`, w finally `stopRunLog()`;
- `copyPageMeta`: + `final_url`, `markup_codes`;
- po `primarySource`: `$identity = sourceIdentity()->judgeCard(...)` na stronach z `runLog()` (dopasowanie po URL,
  także `preferredLocaleUrl`), zapasowo `$pageSnippets`;
- po `payloadFromExtraction`: `$evidence = evidence()->extract($fields['lists'], $docs)` — teksty surowe i po filtrze stron
  opisu, bloki katalogu, manufacturer_norms, price_list_attributes;
- `$productPayload` + `identity`, `evidence`, `evidence_summary`, `completeness`, `description_version_id` (nie do
  `storeSkuCache`);
- przed zapisem `decide()`: publish → `record(published)` + review_reason/review_since, dalej jak dziś, na końcu
  `sources()->record(...)`; propose → `record(proposed)`, przywrócenie norms i manufacturer_norms ze snapshotu,
  `dropWebFilesAddedSince($before['web_files'])`, status/error sprzed przebiegu + review_reason worse_version, zapis
  źródeł, `return` (bez refine, accessories, reindeksu, pamięci SKU);
- `applyFromSkuCache`: `if (versions()->hasProtectedPublished($product)) return false;` na wejściu; po zapisie
  `record(published, 'sku_cache')`;
- nowe `describeFromStoredSources(Product, array $docs): array` (uogólnienie describeFromB2bSources, niczego nie zapisuje,
  wyjątek `StoredSourcesDescriptionRejected`); `describeFromB2bSources` bez zmian;
- ciała sourceClaims/normDesignations/normDesignationSupported/claimKey/textCarriesCode → wywołania SourceClaims /
  ProductCodeMatch (te same prywatne sygnatury).

### ProductPageFetcher.php (część B)
- strona: `final_url` (effectiveUri ?? url), `markup_codes` = `markupIdentifiers($html)`;
- `public function markupIdentifiers(string $html): array` → `list<{type: sku|mpn|gtin, value}>`;
- `startRunLog()`, `runLog(): array`, `stopRunLog()`; `fetch()` dopisuje `$out['pages']` przy włączonym dzienniku (≤40).

### API (część C, routes/api.php)
- `GET /product-reviews?price_list_id=&reason=&page=1&per_page=50` (`products.review|price_lists.import`):
```json
{"data":[{"product_id":1,"sku":"CCLIP25","name":"…","manufacturer":"Coba","price_list_id":14,"review_reason":"identity_soft","review_since":"…","enrichment_status":"done","primary_source_url":"https://…","primary_source_kind":"manufacturer","identity":{"verdict":"soft","reason":"…"},"evidence_summary":{"explicit":2,"inferred":1},"image_url":"/storage/…|null","published":{"version_id":10,"identity_verdict":"hard","evidence_count":4}|null,"proposal":{"version_id":12,"identity_verdict":"soft","evidence_count":1,"created_at":"…"}|null}],
 "meta":{"total":37,"page":1,"per_page":50},
 "counts":{"by_reason":{"identity_soft":20,"identity_none":5,"worse_version":12},"by_price_list":[{"id":14,"manufacturer":"Coba","count":30}]}}
```
  Karty z `PriceListCards` (sloty file), porcje po 1000, ścieżki JSON bez `description`, bez GROUP BY po złączeniach.
- `GET /products/{product}/description-versions` → `{"data":[{id,status,origin,identity_verdict,identity_reason,evidence_count,completeness,review_reason,reason,created_at,decision,decided_at,decided_by:{id,name}|null,description,primary_source_url,source_urls,evidence}],"current_version_id":10}`.
- `POST /products/{product}/review` `{"action":"approve|reject|url","version_id":12,"url":"https://…","note":"…"}`:
  approve — przy worse_version publikuje propozycję, przy identity_* zeruje powód i zapisuje decyzję; reject — propozycja →
  rejected, opublikowany zły opis → poprzednia published/superseded albo `ProductEnrichmentResetter::reset` (tekst zostaje
  w wersji), odrzucony adres nie wraca automatem; url — http(s), 422 dla `B2bConnectorRegistry::isConnectorUrl`, zapis
  `shop_source_url`, decision url_given, `enqueueProduct($p, $u, force: true)`. Odpowiedź
  `{"review_reason":null,"current_version_id":…,"batch_id":…|null,"shop_source_url":…}`.
- `POST /products/{product}/description-versions/{version}/restore` `{"note"}` — 409 dla opisu z B2B i wersji shadow;
  `{"current_version_id","description"}`.
- `GET /products/{product}/source-documents/{document}/text` — text/plain (`products.review|products.view`).
- `GET /price-lists/files` (D): + `"identity":{"hard","soft","none","unknown"}`, `"to_review"`, `"with_image"`.

### Komendy
- B `products:source-identity-probe {--csv=} {--price-list=} {--manufacturer=} {--limit=0} {--out=}` — tylko odczyt;
  `--csv` w formacie pomiaru Coby; rozkład werdyktów per grupa.
- C `products:baseline-versions {--price-list=} {--manufacturer=} {--limit=0} {--no-fetch} {--apply}` — baza
  `published/legacy_baseline` dla kart z opisem bez wersji; nie zapisuje `products`; chunkById(100).
- A `products:redescribe-from-sources {--price-list=} {--product=*} {--limit=20} {--apply}` — cień, zapis tylko `shadow`.

### Klucze enrichment_payload (tylko dochodzą)
`identity{verdict, reason, key_type, key, where, source_url, profile}`, `evidence[]`, `evidence_summary{explicit,
inferred}`, `completeness`, `description_version_id`.

## 3. Części
- **B — źródła i tożsamość:** ProductPageFetcher.php, nowe klasy B, model, migracja 100100, configi, phpunit.xml, komenda
  probe; testy SourceIdentityTest, EvidenceExtractorTest, SourceClaimsParityTest, SourceDocumentStoreTest,
  ProductPageFetcherMarkupCodesTest, SourceIdentityProbeCommandTest.
- **C — wersje i przegląd (backend):** migracje 100000/100200/100300, Product.php, ProductDescriptionVersion,
  DescriptionVersionStore, ProductReviewService, ProductReviewController, routes/api.php, PermissionCatalog.php, komenda
  baseline; testy DescriptionVersionStoreTest, ProductReviewApiTest, BaselineVersionsCommandTest, migracja uprawnienia.
- **A — rdzeń przebiegu (jedyny właściciel ProductEnrichmentService.php):** po B i C.
- **D — miary i frontend:** PriceListDescriptionSources, PriceListFileSources, lib/priceListSources.ts, PriceListsFiles.tsx,
  lib/productReview.ts, pages/PriceListsReview.tsx, PriceListsTabs.tsx, App.tsx, Help.tsx; po C.

Muszą przejść bez zmian: EnrichmentTester51Test, ProductEnrichmentApiTest, EnrichmentManufacturerOnlySourcesTest,
EnrichmentStageZeroTest, EnrichmentPriceListSitesTest, EnrichmentImageRetryTest, EnrichmentB2bShopLinkTest,
CatalogHostPriorityTest, PriceListFilesApiTest, PriceListEnrichFiltersTest, B2b*, SupplementB2bDescription* i cały pakiet.

## 4. Wdrożenie
1. kod → migrate → config:clear → queue:restart; 2. `products:source-identity-probe --price-list=14` (potem 12, 1);
3. `products:baseline-versions --price-list=14` (podgląd → --apply), potem 12, 1, 3, 2; Ansell z `--no-fetch` albo po
etapie 4; 4. build; 5. cień `products:redescribe-from-sources --price-list=12 --limit=20`; 6. pełne ponowne pobranie
dopiero po kroku 3 dla danego cennika i po etapie 2.

## 5. Ryzyka i pomiar
1. Kalibracja werdyktu: probe na `SUPON_AI_Pomiar_Coba_przed_2026-10-07.csv` — grupa 1 (23) soft/none, najwyżej 2 hard;
   dobre karty Coby ≥ 95% hard (każdy soft obciąża przegląd).
2. MAPA bez kodu na stronie — probe z model_alias i bez.
3. Obciążenie przeglądu = odsetek soft × liczba kart w przebiegu wobec „kilkunastu dziennie”.
4. Stare testy z dwoma przebiegami tej samej karty — drugi może skończyć propozycją; testów nie zmieniamy.
5. Częściowe skutki propozycji (manufacturer_norms, norms, zdjęcia, PDF zapisywane przed decyzją) — przywracane, test A.
6. Pamięć: dziennik ≤40 stron; komendy chunkById; dysk Coba ≈ 13 MB.
7. Baza ze stanu strony z dnia pomiaru; nieudane pobranie = werdykt null, bez ochrony.
8. Reguła 5 (mniej dowodów → propozycja) do sprawdzenia w cieniu — przy pełnym pobraniu może dawać za dużo propozycji;
   ewentualnie próg różnicy ≥ 2.

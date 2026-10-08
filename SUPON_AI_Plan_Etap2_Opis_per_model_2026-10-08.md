# Etap 2 opisów z cenników — opis wspólny dla modelu: projekt i zamrożony kontrakt (08.10.2026)

Do planu `SUPON_AI_Plan_Opisy_z_cennikow_2026-10-07.md` (sekcja 2.5, decyzje w sekcji 4) po etapie 1
(`SUPON_AI_Plan_Etap1_Opisy_2026-10-08.md`, wdrożony; baseline na produkcji dla Coba/CEDERROTH/MAPA/AJ GROUP/SECURA).
Projekt agenta Plan po przeglądzie; dodatek przeglądu: zabezpieczenie członków modelu przed zabitym liderem (§2, C).

## 0. Fakty z produkcji (08.10.2026, odczyt)
Karty cenników z plików nie mają wierszy `product_variants` — każdy wymiar/rozmiar to osobna karta. Modele po nazwie bez
wymiarów i kolorów: **Coba 869 kart → ~258 modeli** (76 pojedynczych; „Orthomat Standard” 11 kart), Ansell 683 → ~253
(etap 4), CEDERROTH 84 → 74, AJ GROUP 163 → 154, MAPA 147 → 147 (numer w nazwie = model), SECURA 42 → 27. `model_name`
puste wszędzie. Strona modelu coba.com zwykle pokazuje jeden kolor (czarny), w nazwach plików bywa kod karty
(`AF010003C_OrthomatStd_09xLinear_Black.jpg`). **Zysk etapu 2 = Coba (i Ansell w etapie 4); grupowanie tylko dla marek
z profilem `model.group`.**

## 1. Decyzje
1. **Klucz modelu** = `brand_key | rodzina SKU | rdzeń nazwy`, liczony **w locie** (bez kolumny w `products`, bez tabeli),
   tylko dla profilu z `model.group = name_stem` (etap 2: Coba). Rodzina = przechwyt istniejącego `model_regex`
   (`ManufacturerProfiles.php:45`; Coba `/^([A-Z]+)\d/`). Rdzeń = nazwa po zdjęciu rozmiarów odzieżowych
   (`ProductSizeVariant::stripSizeFromName`), wyrażeń wymiarowych (`0.9m x 18.3m (9.5mm)`, `x mb.`, `- maks. 10m`,
   `2000mm x 1000mm x 25mm`), słów koloru (`App\Support\ColourWords`, nadzbiór `ProductSearchIdentity::isColorWord`).
   **Nic więcej nie jest zdejmowane** — każde inne słowo rozdziela („Deckplate Czarny/Żółte krawędzie” osobno od
   „Deckplate Czarny”, „Otwarta” ≠ „Pełna”, „Senso Runner” ≠ „Senso Runner ESD”, GRP rozpada się na kratę / Light /
   nakładkę / osłonę). Sam prefiks SKU (198 grup) skleiłby różne wyroby — jest tylko składnikiem. Klucz zamraża się
   w pozycji partii (`model_key`, `model_leader_id`) i w `enrichment_payload.model_group` (pochodzenie). Marki bez
   profilu: karta = model, zero zmian. Scalanie fizyczne (`ProductSizeMergeService`, `collapseSamePriceVariants`) bez zmian.
2. **Przebieg per model.** Jednostką w kolejce zostaje karta, ale zadania idą **tylko do lidera** grupy; członkowie mają
   pozycje partii `queued` bez zadań. Lider = ręczny adres > SKU w postaci bazowej (bez `C`, bez `-N`) > twarda baza >
   najniższy id; karta z ręcznym adresem innym niż lidera = osobna grupa. Przebieg lidera = dzisiejszy `enrichProduct`
   + nota modelu w prompcie (tylko gdy ≥ `min_members` w partii) + sito `isUsableProductDescription` na klonie karty
   z nazwą = rdzeń (sito odrzucało poprawne opisy Coby przez wymiary w nazwie). Po wersji lidera `EnrichProductJob` zleca
   `ApplyModelDescriptionJob`: dla każdego członka bez modelu językowego — werdykt per karta (`judgePage` na zapisanych
   tekstach źródeł lidera z kluczami członka; tabela części Coby daje twardy werdykt wymiarom), listy wspólne +
   `PriceListCardFacts` (specs i evidence `explicit`/`price_list` z nazwy, `price_list_attributes`, EAN; **bez ceny**),
   atrybuty per karta, dowody przeliczone, **opis prozą identyczny**, nazwa karty bez zmian, normy jak u lidera,
   zdjęcie w kolorze karty (adres z `page_image_urls` wersji lidera z kolorem w nazwie pliku) albo kopia pliku lidera
   bez sieci; przy kolorze sprzecznym — żadnego nowego; potem `publishRun` z decyzją per karta (twarda baza i słabszy
   wynik → propozycja; CCLIP25 z cudzej strony → publikacja). Członek z już zapisanym opisem tego lidera → `skipped`.
3. **Pamięć SKU** (`applyFromSkuCache`/`storeSkuCache`/`hasSkuCacheRow`) nie jest czytana ani pisana dla marki
   z grupowaniem; pochodzenie niesie wersja (`origin model_shared`, `model_group.leader_version_id`). Dublowanie nie
   występuje z konstrukcji; idempotencja członka = przejęcie ze statusu `queued`. **Sztafeta:** lider bez wersji (brak
   źródeł, wyjątek, `failed()`) → `nextLeader()` promuje kolejnego członka i zleca mu prefetch.
4. **Partie i panel.** Limit kart z Ustawień AI, cięcie całymi modelami (model nie trafia do dwóch partii; pierwszy
   model większy niż limit wchodzi cały); kolejność z force = najstarszy `enriched_at` w modelu. `total/done` po kartach
   jak dotąd + `models_total/models_done`. Lista „Do przeglądu”: decyzja **per karta** (ochrona, wersje i blokada adresu
   są per karta); wiersz dostaje `model{key, stem, in_review, shared_from}`, front grupuje sąsiednie wiersze i daje
   „Zastosuj do N kart modelu” = sekwencja istniejących wywołań per karta (bez nowego endpointu). „Z pliku”: `models`.
5. **Pełne ponowne pobranie** (Coba pierwsza) — §4; **czego nie robić** — §5.

## 2. Zamrożony kontrakt

### Migracja (C)
`2026_10_09_100000_add_model_key_to_product_enrichment_batch_items.php`: `model_key` string(160) null,
`model_leader_id` unsignedBigInteger null, `model_leader_version_id` unsignedBigInteger null (wersja lidera po jego
przebiegu — podstawa dla członków także po restarcie workera), indeks `(batch_id, model_key)` `pebi_batch_model`;
`down()` zdejmuje. Lider ma `model_leader_id` = własne id. **Bez zmian w `products`, bez nowych tabel.**

### Konfiguracja (B)
`config/manufacturer_profiles.php`: `default` += `'model' => ['group' => null, 'min_members' => 2]`; `coba` +=
`'model' => ['group' => 'name_stem', 'min_members' => 2]`. `ManufacturerProfile` += `?string $modelGroup = null`,
`int $modelMinMembers = 2`; `ManufacturerProfiles::for` mapuje. `ProductDescriptionVersion::ORIGIN_MODEL_SHARED =
'model_shared'` (+ `ORIGINS`). `DescriptionVersionStore::cleanMeta` przepuszcza `page_image_urls` (≤ 40 adresów,
tylko w kopii payloadu wersji). `ProductEnrichmentBatchItem::$fillable` += `model_key`, `model_leader_id`,
`model_leader_version_id`.

### Nowe klasy (B)
```php
App\Support\ColourWords:
  static is(string $word): bool; static canonical(string $word): ?string /* 'szary'→'grey' */;
  static inUrl(string $url): ?string; static inName(string $name): ?string
App\Services\Enrichment\ModelKey (readonly): string $key, $brandKey, $family, $stem, $source = 'profile_name'
App\Services\Enrichment\ProductModelKey:
  __construct(ManufacturerProfiles $profiles, ProductSizeVariant $sizes)
  for(Product $p, ?ManufacturerProfile $prof = null): ?ModelKey   // null = marka bez grupowania albo pusty rdzeń
  static stem(string $name, ProductSizeVariant $sizes): string      // bez wymiarów, kolorów, „x mb.”, „maks.”
  static family(string $sku, ?string $modelRegex): string
App\Services\Enrichment\ModelGroup (readonly): string $key, $stem; int $leaderId; array $memberIds /* z liderem */
App\Services\Enrichment\ModelGroupPlanner:
  groups(array $productIds): array /* list<ModelGroup>; karta bez klucza = grupa 1; ręczny adres inny niż lidera = osobna grupa */
  sliceByLimit(array $groups, int $limit, bool $oldestFirst): array{groups: list<ModelGroup>, product_ids: list<int>}
  chooseLeader(array $products): Product
  contextFor(Product $p, int $batchId): ?array /* {key, stem, members: list<{id,sku,name}>}; null gdy < min_members albo brak klucza w pozycji */
  membersOf(int $batchId, int $leaderId): array /* list<int> członkowie queued */
  nextLeader(int $batchId, int $failedLeaderId): ?int /* przepina model_leader_id pozostałych */
App\Services\Enrichment\PriceListCardFacts:
  for(Product $p): array{specs: list<string>, evidence: list<array{field,value,quote,source_sha256:null,status:'explicit',source:'price_list'}>, colour: ?string}
App\Services\Enrichment\ProductWebFileCopier:
  copyImage(Product $from, ProductImage $img, Product $to): ?ProductImage /* ta sama suma na $to → istniejący wiersz */
  copyDocuments(Product $from, array $documentIds, Product $to): array /* list<ProductDocument> */
App\Services\Enrichment\ModelImagePicker:
  pickFor(Product $member, ?string $memberColour, array $pageImageUrls, array $leaderImages): array{url: ?string, copy_of: ?ProductImage, reason: string}
```

### Zadania (C)
`App\Jobs\ApplyModelDescriptionJob(int $batchId, int $leaderId, int $leaderVersionId)` — kolejka `enrich`, bez slotu
LLM, tries 3, timeout 600; członek po członku: `applyModelDescription` + `markBatchItem` + komunikat partii (trait
`App\Jobs\Concerns\RefreshesBatchProgress` — kopia `refreshBatchProgress` z `EnrichProductJob:321`).
`EnrichProductJob::handle` po zwolnieniu slotu: `$v = $enrichment->lastRunVersion(); $members = planner->membersOf(...)`;
`$v !== null && $members !== []` → zapis `model_leader_version_id` w pozycjach + `ApplyModelDescriptionJob::dispatch`;
`$v === null && $members !== []` → `nextLeader` + `PrefetchProductSourcesJob::dispatch(next, batch, force)`. To samo
w `failed()` i w gałęzi „Produkt miał już opis”. **Zabity lider (przegląd):** `releaseStaleRunningProducts`
(ProductEnrichmentService, harmonogram) dla członków `queued` bez zadania, których lider nie jest już `queued/running`
i nie ma zadania: gdy pozycja ma `model_leader_version_id` → `ApplyModelDescriptionJob::dispatch`; inaczej → `nextLeader`
+ prefetch; gdy członków już nie ma → nic. `PrefetchProductSourcesJob` bez zmian.

### ProductEnrichmentService.php (wyłącznie A; zależności przez `app()` — konstruktor bez zmian)
- `enqueueProductIds` (~:295): po filtrze B2B `$plan = planner->groups($productIds)`; `sliceByLimit` zamiast
  `array_slice`; `seedBatchItems` z `model_key/model_leader_id`; dispatch tylko liderów; `message` += „(N modeli)”;
  zwrot += `'models' => int`.
- `prefetchProductSources` i `enrichProduct`: pamięć SKU pomijana (odczyt i zapis), gdy `profiles()->for($p)?->modelGroup !== null`.
- `enrichProduct`: `$this->modelContext = $batchId !== null ? planner->contextFor($product, $batchId) : null` (zerowane
  w `finally`); `extractWithLlm` nowy parametr `?array $modelContext = null` → `modelContextNote()` dopisana do treści
  użytkownika; sito `isUsableProductDescription` z `modelIdentityCard(Product)` (klon z nazwą = rdzeń);
  `pickPrimaryImageUrls`: +30 za kolor karty w adresie, −50 za inny kolor (tylko gdy nazwa karty ma kolor);
  `$productPayload['model_group']`; `$versionData['_version']['page_image_urls']` (adresy zdjęć `$descPages`, zaufane
  pierwsze); `private ?ProductDescriptionVersion $lastRunVersion` + `public lastRunVersion()`.
- `keepAsProposal`: parametr `string $origin = ORIGIN_ENRICHMENT`.
- nowa `public applyModelDescription(Product $member, ProductDescriptionVersion $leaderVersion, ?int $batchId): void`
  — kroki z §1.2: snapshot, running, strony = `storedSourcePage()` dla `sources()->forProduct($leader)`, `judgePage`
  per strona (werdykt karty = strona `primary_source_url` lidera), listy z payloadu lidera + `PriceListCardFacts`,
  `bhpAttributes->normalize` + `applyExtractedSizes` per członek, `evidence()->extract`, normy (`writeNormsColumn`,
  `manufacturer_norms` z ochroną `replaceableFromWebPage`, `runWrites`), zdjęcie (`ModelImagePicker` →
  `downloadMany` albo `copier->copyImage`), dokumenty kopią, `publishDescription($member, ORIGIN_MODEL_SHARED,
  candidate{identity, evidence_count, primary_source_url lidera, manual_url}, versionData{description lidera, payload
  członka, trace, packaging, primary_source_url, batch_id}, cardUpdates, $before, runWrites)` → null →
  `keepAsProposal(..., origin: ORIGIN_MODEL_SHARED)`; `recordSourceDocuments` per karta; `ReindexProductEmbeddingJob`;
  pomija członka z bazą o tym samym `description_sha1` i `leader_version_id` (`skipped`, „opis modelu już na karcie”).

### API (C)
- `batchPayload` += `models_total: int|null, models_done: int|null` (z pozycji partii, PHP; null bez kluczy).
- `POST /price-lists/{id}/enrich` += `models_queued`; podgląd += `will_queue_models`. `POST /products/enrich` += `models_queued`.
- `GET /product-reviews` wiersz += `model: {key, stem, in_review, shared_from: int|null} | null` (klucz liczony
  w `ProductReviewService::list` z `sku,name,manufacturer`; licznik po pełnym zbiorze przed stronicowaniem;
  `shared_from` z `enrichment_payload->model_group->leader_product_id` ścieżką JSON).
- `GET /price-lists/files` wiersz += `models: int`.

### Komendy (C)
- `products:model-groups {--price-list=} {--manufacturer=} {--min-size=1} {--suspicious} {--judge} {--images} {--limit=0} {--out=}`
  — tylko odczyt; `chunkById(200)`, w pamięci tylko id/sku/nazwa/klucz. Tabela grup + podsumowanie (karty, modele,
  pojedyncze, największa grupa, szacunek wywołań modelu). `--suspicious`: ten sam rdzeń w kilku rodzinach SKU; grupy
  > 20 kart; grupy z **różnymi `primary_source_url`** członków; mieszanka końcówek `C`/`-N`; zdjęte słowa spoza
  słownika. `--judge`: najczęstsza strona źródła z twardym werdyktem w grupie (pamięć stron 24 h, wzorzec
  `SourceIdentityProbeCommand::judge`) i `judgePage` każdego członka — przewidywane obciążenie przeglądu. `--images`:
  adresy zdjęć tej strony z rozpoznanym kolorem (`ColourWords::inUrl`).
- `products:compare-cards {--csv=} {--out=}` — tylko odczyt; dla kart z CSV pomiaru „przed”: `primary_source_url`
  (ten sam / inny), werdykt, `review_reason`, pochodzenie i numer wersji, zdjęcie i zgodność koloru z nazwą, liczba
  źródeł; podsumowanie per grupa CSV.
- `products:rollback-batch {batch} {--apply} {--reject-proposals}` — karty z wersją `published` z `batch_id` partii
  (origin `enrichment|model_shared`) → poprzednia `superseded` przez `DescriptionVersionStore::publish` (origin
  `restore`); podgląd bez `--apply`.
- `products:queue-enrichment` += `{--force}` (pilotaż po `--id`).

### Klucze `enrichment_payload` (tylko dochodzą)
`model_group{key, stem, family, leader_product_id, leader_version_id, members, shared}`; `evidence[].source`
(`'price_list'`, opcjonalny); `specs` dostaje wiersze z pliku. W `_version`: `page_image_urls`.

## 3. Części
| Część | Pliki | Zależności |
|---|---|---|
| **B — klucz, fakty z pliku, pliki** | `app/Support/ColourWords.php`, `app/Services/Enrichment/{ModelKey,ProductModelKey,ModelGroup,ModelGroupPlanner,PriceListCardFacts,ProductWebFileCopier,ModelImagePicker}.php`, `ManufacturerProfile.php`, `ManufacturerProfiles.php`, `config/manufacturer_profiles.php`, `ProductDescriptionVersion.php`; testy `ProductModelKeyTest` (karty z `SUPON_AI_Pomiar_Coba_przed_2026-10-07.csv` i `scratchpad/coba_pages.json` jako przypadki), `ModelGroupPlannerTest`, `PriceListCardFactsTest`, `ColourWordsTest`, `ProductWebFileCopierTest`, `ModelImagePickerTest` | brak |
| **C — kolejka, partie, API, komendy** | migracja, `ProductEnrichmentBatchItem.php`, `EnrichProductJob.php`, `ApplyModelDescriptionJob.php`, `Concerns/RefreshesBatchProgress.php`, `DescriptionVersionStore.php` (tylko `cleanMeta`), `ProductEnrichmentController.php`, `ProductReviewService.php`, `PriceListFileSources.php`, `QueueEnrichmentCommand.php`, 3 nowe komendy; testy `ModelGroupsCommandTest`, `CompareCardsCommandTest`, `RollbackBatchCommandTest`, `ApplyModelDescriptionJobTest`, rozszerzenia `ProductReviewApiTest`, `PriceListFilesApiTest`, `ProductEnrichmentApiTest` | sygnatury B |
| **A — rdzeń (jedyny właściciel `ProductEnrichmentService.php`)** | ten plik + `releaseStaleRunningProducts` (zabity lider); test `EnrichmentModelSharedFlowTest` (lider + 3 członków: hard/soft/propozycja z twardą bazą; sztafeta po `ProductSourcesNotFoundException`; bez pamięci SKU; kolor zdjęcia; `model_group`; `page_image_urls` tylko w wersji; zabity lider → członkowie obsłużeni) | po B i C |
| **D — front** | `lib/api.ts` (`EnrichmentBatch`), `EnrichmentProgressBanner.tsx`, `PriceListsFiles.tsx`, `PriceListsReview.tsx`, `lib/productReview.ts`, `Help.tsx` | po C |

Bez zmian muszą przejść: `EnrichmentStageOneFlowTest`, `EnrichmentStageZeroTest`, `EnrichmentTester51Test`,
`ProductEnrichmentApiTest`, `PriceListFilesApiTest`, `ProductReviewApiTest`, `QueueEnrichmentCommandTest`,
`ProductSizeMergeTest`, `BaselineVersionsCommandTest`, `RedescribeFromSourcesCommandTest`, `SourceIdentityTest`, `B2b*`,
`SupplementB2bDescription*`, cały pakiet.

## 4. Wdrożenie i pełne pobranie Coby
1. Kod → `migrate --force` → `config:clear` → `queue:restart` → build frontu.
2. **Odczyt:** `products:model-groups --price-list=14 --suspicious --out=storage/app/reports/coba_model_groups.csv` —
   właściciel przegląda podejrzane (≈ 258 grup; każde sklejenie dwóch wyrobów = poprawka słownika/profilu w gicie przed
   krokiem 4). `--judge --images --limit=40`: odsetek członków z twardym werdyktem na stronie modelu (próg ≥ 80%) i czy
   galerie mają kolory kart.
3. `products:baseline-versions --price-list=14` (podgląd: karty z opisem „już z wersją”).
4. **Pilotaż** 4 modeli: `products:queue-enrichment --price-list=14 --force --id=…` (Orthomat Standard szary, CCLIP25,
   LCLIP, krata GRP) → baner (modele), „Do przeglądu”, historia wersji (origin `model_shared`),
   `products:compare-cards --csv=SUPON_AI_Pomiar_Coba_przed_2026-10-07.csv`.
5. **Pełne pobranie:** Cenniki → „Z pliku” → Coba → „Pobierz opisy ponownie” z force i `include_manufacturer`
   (inaczej modele rozpadłyby się na karty wzięte i pominięte). Partia = limit kart w całych modelach; powtarzać.
   Czas ≈ 258 liderów × ~2,5 min przy 4 zapytaniach ≈ 3 h + członkowie ≈ 20 min (dziś 869 przebiegów ≈ 9–10 h).
6. **Pomiar „po”:** `products:compare-cards`, `products:source-identity-probe --price-list=14`, liczniki „Z pliku”.
   Kryteria: wywołań modelu ≤ 300; grupa 1 (23): ≥ 20 kart z właściwą stroną (twardy) albo z `review_reason`, 0 opisów
   z cudzej strony opublikowanych bez powodu przeglądu; grupa 2 (57): zdjęć w sprzecznym kolorze ≤ 20 (próg wg
   `--images`); grupa 3 (14): 14/14 ze źródłami; całość: twardy ≥ 85% opisanych, do przeglądu ≤ 15%, żadna karta
   z twardą bazą nie straciła opisu po cichu.
7. **Wycofanie:** `products:rollback-batch <id>` (podgląd → `--apply`); pojedyncze karty — „Przywróć wersję”.

## 5. Ryzyka i czego nie robić
Ryzyka: fałszywe sklejenie (rdzeń zdejmuje tylko wymiary i kolory; lista podejrzanych; werdykt i decyzja per karta;
wycofanie partią); przegląd zapchany miękkimi członkami (`--judge` przed przebiegiem); lider skończył propozycją →
członkowie bez plików (ślad); nota modelu tylko przy ≥ `min_members`; zdjęcie w złym kolorze nie jest kasowane bez
zamiennika (etap 3); pamięć 128 MB (`chunkById`, teksty z dysku po jednym, liczniki w PHP); stare wiersze pamięci SKU
Coby zostają nieczytane; brak tytułu w `product_source_documents` — werdykt członka z adresu, mikrodanych i tekstu.

Nie robić: przełączania `describeFromStoredSources` do głównej ścieżki; grupowania dla MAPA/SECURA/AJ GROUP/CEDERROTH;
profilu Ansella (etap 4); scalania kart fizycznie, zmian w `ProductSizeMergeService`/`collapseSamePriceVariants`/tabeli
wariantów; zmian nazw i SKU; zmian bramek, reguł `decide`, ścieżek B2B, słownictwa `SourceClaimGuard`; kolumn
w `products`; zbiorczego endpointu przeglądu; nowej infrastruktury zdjęć; edytora profili w panelu.

## 6. Stan po wdrożeniu kodu i dwóch przeglądach (08.10.2026)
Części A–D zrobione; dwa niezależne przeglądy kodu (rdzeń + zadania; narzędzia + komendy + panel) i drugi przegląd
samych poprawek. Odstępstwa od zamrożonego kontraktu (wszystkie z testami):
- `ModelImagePicker::pickFor(Product, array $memberColours, array $pageImageUrls, array $leaderImages, ?string $modelStem)`
  — zbiory kolorów (`ColourWords::allInName/allInUrl/sameSet`), karta dwubarwna ≠ jednobarwna; adres z galerii lidera
  tylko ze słowem rdzenia modelu w nazwie pliku (galeria to wszystkie `<img>` ze stron lidera, także cudzych wyrobów).
  `PriceListCardFacts::for()` zwraca dodatkowo `colours`.
- Rodzina z SKU z literowym przyrostkiem po cyfrach („ST/B1”): ST010001 ≠ ST010001B1 (nitryl), SS070002MN ≠ SS070002B1M,
  SS070002FN ≠ SS070002B1F; „C” po cyfrze i „-N” nie liczą się. Strażnik rdzenia: litera typu i numer modelu na końcu
  nazwy nie są rozmiarem („Uchwyt typu L” ≠ „typu M”, „Model 5” ≠ „Model 10”).
- Członek: strony tylko z adresów WERSJI lidera (nie z całej historii `product_source_documents`); lider z propozycją →
  członek bez plików i bez kopii `manufacturer_norms`; z atrybutów lidera odpadają `rozmiar` i `kod_producenta`; linie
  `specs` lidera z etykietą obecną w faktach cennika członka odpadają; `evidence_count` tylko z dowodów ze stron, fakty
  z cennika osobno w `evidence_summary.price_list`; werdykt bez stron = `none` (nie null); kopia norm producenta bez
  `source.identity` lidera (`copied_from_product_id`; przy twardym werdykcie klucz członka).
- Reguła koloru w `pickPrimaryImageUrls` tylko dla marek z `model.group` i zbiorami (inaczej regresja dla odzieży
  dwubarwnej Portwest/Mascot/JHK i plików „white-background”).
- `ApplyModelDescriptionJob`: `timeout` 420 (< `retry_after` 480), atomowe przejęcie pozycji `queued` (ponowienie
  przejmuje tylko porzucone `running`); `EnrichProductJob::handOverModel` i sweeper zabitego lidera biorą najpierw
  `ProductDescriptionVersion::latestOfRun(lider, partia)` — sztafeta dopiero bez wersji; lider z pozycją „running”
  i kartą poza przebiegiem = zabity.
- `products:queue-enrichment` tnie porcje całymi modelami; podgląd „zostanie zleconych” liczy tak jak kolejka;
  kolejność z force = NAJNOWSZY `enriched_at` w modelu (model tknięty idzie na koniec).
- „Do przeglądu”: lista sortowana grupami modelu (po najnowszym `review_since` grupy); „Zastosuj do N kart modelu”
  tylko gdy wszystkie karty grupy mają opis z tej samej karty lidera (inaczej uwaga „opisy z różnych stron — decyzje
  per karta”).
- `products:rollback-batch --apply`: wspólna `RunEffectsReverter` (ta sama co „Odrzuć”) usuwa pliki z `_version` wersji
  i cofa normy producenta, powód przeglądu z wersji źródłowej; odmowa dla partii w toku; plików skasowanych przez
  przebieg z force wycofanie nie przywraca (uwaga w podglądzie). `--out` komend tworzy katalog.

Zostawione na później (z przeglądów): `handOverOrphanedModelMembers` skanuje `jobs` per członek przy każdym odpytaniu
panelu; `contextFor`/`models_total` po kluczu, a `models_queued` po grupach (rozjazd tylko przy podgrupach z ręcznym
adresem); normy `runWrites` członka nie są cofane w `catch`/anulowaniu; podwójne propozycje przy ponowieniu; lider bez
faktów z cennika a członkowie z nimi (niespójne specs w modelu); „Uchwyt T5” vs „T8” jeden rdzeń (T5 = znacznik
rozmiaru); „stalowy” w słowniku kolorów (też materiał); „Ciemny Szary” → „Kolor: Szary”; „5 L”/„1 kg” w „Wymiary”;
CCLIP25/LCLIP25/MCLIP25 w innej rodzinie niż -38/-50; podgrupy z ręcznym adresem mają wspólny `model_key`; klucz
ucięty do 160 znaków; członek bez koloru w nazwie kopiuje zdjęcie lidera mimo innego koloru w SKU (COBAGRiP pasy);
`previousPublished` przy tej samej treści cofa się dalej; `CompareCardsCommand` tylko średnik i dopasowanie po numerze
karty; `ProductWebFileCopier` zostawia plik bez wiersza przy wyjątku `create()`; pominięty członek nie ponawia
nieudanego pobrania zdjęcia; `PriceListFileSources::modelCount` bez skrótu dla marek bez grupowania.

## 7. Etap 2b — poprawki po pilotażu (08.10.2026, partia #499: 52 karty, 5 modeli, 0 błędów)
Wynik pilotażu: 49/50 opisanych kart z twardym werdyktem, 0 w przeglądzie, opis identyczny w modelu, CCLIP25 ze strony
uchwytu, zielone i szare kraty ze zdjęciem w kolorze. Wady: (1) CCLIP-38/-50 „ręcznie” — strona pisze „CCLIP38”,
program szuka „CCLIP-38”; (2) lider zielonej kraty bez zielonego zdjęcia (weryfikator zdjęć zostawił tylko og:image
Gray.jpg; `verified_count: 1`), lider szarego Orthomatu pobrał czarne (−50 nie odrzuca); (3) żółte kraty bez
„Yellow-1.jpg” (reguła słowa rdzenia w nazwie pliku za ostra dla coba.com). Decyzje właściciela (08.10): zakres A+B+C,
stare zdjęcie w sprzecznym kolorze bez zamiennika USUWAĆ przy pełnym pobraniu (z force).

- **A — kod z myślnikiem.** `SourceIdentity::skuForms`: dodatkowa postać bez separatorów między literami a cyframi
  („CCLIP-38” → „CCLIP38”, także spacja/podkreślenie); `ProductModelKey::family()` liczony także na tej postaci
  (uchwyty 25/38/50 = jeden model „CCLIP”). Testy na tekście prawdziwej strony c-type-cobagrip-grating-accessory.
- **B — zdjęcie lidera jak u członków.** W `enrichProduct` dla marki z `model.group` i karty z kolorem: najpierw
  `ModelImagePicker::pickFor` na całej galerii stron opisu (zaufane pierwsze; ta sama lista co `page_image_urls`
  wersji), PRZED weryfikatorem; adres w kolorze → pierwszy kandydat; „inny kolor” → bez nowego zdjęcia; brak koloru
  w galerii → dotychczasowa ścieżka, ale kandydat o kolorze rozłącznym z kartą ODPADA (nie −50). Picker: nazwa pliku
  z samych słów koloru i cyfr („Yellow-1.jpg”, „Gray.jpg”) liczy się jak nazwa ze słowem rdzenia.
- **C — bramka zdjęć.** `ImageUrlBlocklist` (wzorce ogólne: placeholder/no-image/coming-soon/logo/banner/captcha/
  cloudflare + `image_url_blocklist` profilu; coba: StandUpforHealth, Modal_Elephant) stosowana przy wyciąganiu zdjęć
  ze strony (`ProductPageFetcher`) i w `ProductImageDownloader`; downloader odrzuca treść HTML niezależnie od nagłówka
  (`<!DOCTYPE`, `<html`) i obrazki mniejsze niż 180×180 px.
- **D — usuwanie złego zdjęcia.** Lider i członek: gdy karta ma kolor, przebieg jest z force i nie znaleziono nowego
  zdjęcia (picker „inny kolor” / brak kandydata), zdjęcia z internetu karty (bez b2b_account_id, z source_url), których
  nazwa pliku ma kolory rozłączne z kartą, są usuwane (`clearProductImages`) z notą w śladzie i komunikacie karty
  („zdjęcie w innym kolorze usunięte — nowego brak”). Zdjęć ręcznych i B2B nie dotyczy. Wycofanie partią ich nie
  przywraca (uwaga w podglądzie rollback-batch już jest).
Po wdrożeniu: ponowne zlecenie 6 uchwytów (11054–11062 bez 11055), potem pełne pobranie Coby (§4 krok 5).

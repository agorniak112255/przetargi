# Cenniki → „Z pliku”: źródła opisów per cennik z pliku (05.10.2026)

Prośba użytkownika: cenniki z plików dostosowywać indywidualnie jak konta B2B — przy każdym cenniku wpisać strony
z opisami albo wybrać je z listy Administracja → „Strony wyszukiwarka”. Decyzje użytkownika (05.10): zakres = strony
opisów (bez zapamiętanego mapowania kolumn); tryb do wyboru przy cenniku.
Plan po recenzji agenta Plan (zmiany względem projektu: bez flagi „przed producentem”, bez przypisania z zakresu
partii, wyszukiwanie po hostach tylko w enrichProduct, bramka wariantu jako warunek awansu, limit 4 hostów dla site:).

## Reguły
1. Karta producenta w puli (manufacturerOnlyPages, gałąź z kartą) = tylko producent, bez zmian. Strony cennika
   działają WYŁĄCZNIE w gałęzi bez karty producenta (tam, gdzie dziś listedSitePagesFirst). Dotyczy też
   manufacturer_only_sources (MAPA, AJ GROUP) — test AJ GROUP 906 bez zmian.
2. Tryb 'first': strona cennika (z treścią ≥ MFR_CARD_MIN_CHARS i z kodem karty — bramka wariantu
   supplementPageNamesCardVariant) → pula = strony cennika + katalog PDF + adres zaufany; brak takiej strony →
   dzisiejsze listedSitePagesFirst (lista „Strony wyszukiwarka”, reszta internetu).
3. Tryb 'only': jak wyżej, ale brak strony cennika → pula = tylko katalog PDF + adres zaufany; pusta pula →
   ProductSourcesNotFoundException („brak strony wyrobu na stronach cennika X”), a przy awarii wyszukiwarki komunikat
   awarii (status failed, nie manual). Ścieżki zapasowe (fetchMoreCatalogCards, fetchMappedRetailerCards,
   fetchCardsFromOpenWeb, uzupełnienie) nie pobierają stron spoza: host cennika, isOfficialCatalogUrl, adres zaufany,
   katalog PDF. Zdjęcia z innych kart (tryImagesFromOtherCards) bez zmian.
4. Przypisanie karty: tylko slot 'file' (product_source_prices.price_list_id) → PriceListCards::sourceSettingsFor.
   Ścieżka uzupełniania B2B (supplementB2bDescription/supplementWebPages) NIE widzi ustawień cennika.
5. Przy ustawieniach cennika pamięć SKU (applyFromSkuCache) jest pomijana — inaczej opis spoza stron cennika.
6. Ranga: host cennika w descriptionSourceScore = 90 − min(pozycja, 15) (po gałęzi producenta; nad rangą globalną
   21–70, pod ręcznym adresem 100). orderPagesForDescription: producent → strony cennika (po pozycji) → ranga → reszta.

## Kontrakt (zamrożony)
- Migracja `2026_10_06_110000_add_enrichment_sites_to_price_lists`: `price_lists.enrichment_sites` json null,
  `enrichment_sites_mode` string(10) default 'first', `enrichment_sites_updated_at` timestamp null. (ZROBIONE)
- `App\Support\EnrichmentSiteList::MAX = 20`, `normalize(array $sites, string $field, string $label): ?array`
  (kolejność zachowana), `hosts(mixed $stored): list<string>`. B2B woła z ('enrichment_sites', 'Strony z opisami'). (ZROBIONE)
- `PriceList::MODE_FIRST='first'`, `MODE_ONLY='only'`, `MODES`; `enrichmentHosts()`, `enrichmentSitesMode()`,
  `enrichmentHostsSha1()` = sha1(mode."\n".implode("\n", hosts)). (ZROBIONE)
- `App\Services\Enrichment\PriceListSourceSettings` (final readonly): priceListId, manufacturer, hosts, mode,
  hostsSha1; `onlyMode()`, `position(string $url): ?int`, `covers(string $url): bool`. (ZROBIONE)
- `PriceListCards::sourceSettingsFor(Product): ?PriceListSourceSettings`. (ZROBIONE)
- HybridWebSearchService: `catalogHitsOnHosts(Product, array $hosts, string $label = 'strony konta')`,
  `searchOnHosts(Product, array $hosts, string $label = 'strony konta')`, `lastHostSearchErrors(): list<string>`.
- `enrichment_payload.price_list_sources` = `{price_list_id, mode, hosts_sha1}` — tylko przy ustawieniach.
- `PATCH /price-lists/{id}` (price_lists.import): `{enrichment_sites?: string[]|null (≤20), enrichment_sites_mode?:
  'first'|'only'}`; 422 dla cennika bez kart z pliku; 422 dla hostów z `enrichment.blocked_source_hosts` i hosta
  `prestashop.shop_url`; `enrichment_sites_updated_at` = now() tylko przy realnej zmianie hostów lub trybu.
  Odpowiedź (jak dziś) + `enrichment_sites`, `enrichment_sites_mode`, `enrichment_sites_updated_at`.
- `GET /price-lists/files` (price_lists.view; trasa PRZED `/price-lists/{priceList}`):
  `{lists: [{id, manufacturer, version, enrichment_sites: string[], enrichment_sites_mode, enrichment_sites_updated_at,
  has_b2b_account: bool, cards, described, sources: {price_list_sites, manufacturer, other, b2b, none},
  stale (opis sprzed enrichment_sites_updated_at), queued, running, failed, manual,
  batch: {id, status, total, done, failed}|null,
  hosts: [{host, position, on_search_sites: bool, indexed_pages: int, described_cards: int}]}]}`
  Cenniki = DISTINCT price_list_id ze slotów 'file'. sources liczone z enrichment_payload->primary_source_url
  i ->primary_source_kind (ścieżka JSON, porcje po 1000, bez całego payloadu); b2b = opis z B2B (B2bDescriptionSource).
- `GET /price-lists/search-sites` (price_lists.import): `{sites: [{host, links, manufacturers: string[],
  priority: int|null, sources: string[]}]}` z CatalogSearchHostService::list(), cache 600 s.
- `POST /price-lists/{id}/site-check` (price_lists.import, throttle:20,1): `{product_id}` → `{product: {id, sku,
  name}, hits: [{url, title, host, position, coded: bool}]}` — tylko indeks lokalny (catalogHitsOnHosts), bez
  site: i bez modelu; karta musi należeć do cennika (PriceListCards::ids) — inaczej 422.
- `POST /price-lists/{id}/enrich` (price_lists.import): dotychczasowe `force` + `only_not_from_sites?: bool`,
  `skip_manufacturer?: bool = true` (gdy filtry podane: pomija karty z primary_source_kind manufacturer/catalog),
  `enriched_before?: date`, `apply?: bool = true`. apply=false → 200 `{preview: true, matched, will_queue,
  skipped_b2b, limit}`; apply=true → dotychczasowa odpowiedź 202. Bez nowych pól zachowanie identyczne jak dziś.

## Frontend
- Zakładka `{to: '/price-lists/files', label: 'Z pliku', permission: 'price_lists.view'}` + trasa w App.tsx.
- `pages/PriceListsFiles.tsx`: tabela cenników z pliku (producent → `/products?price_list={id}`, karty, pasek źródeł
  opisów, stare, stan pobierania), panel per cennik: textarea hostów (kolejność = ważność), okno „Wybierz z »Strony
  wyszukiwarka«” (filtr host/producent, liczba stron w indeksie), tryb (radio), ostrzeżenie indexed_pages = 0,
  informacja „strony cennika działają, gdy wyrób nie ma karty producenta”, „Sprawdź na karcie”, „Pobierz opisy
  ponownie” (filtry → podgląd → potwierdzenie → postęp przez EnrichmentProgressBanner/parseActiveEnrichment).
  Edycja tylko z price_lists.import.
- Pomoc: sekcja Cenniki w Help.tsx.

## Testy, które muszą przejść bez zmian
EnrichmentManufacturerOnlySourcesTest, B2bAccountEnrichmentSitesTest, SupplementB2bDescriptionTest,
SupplementB2bDescriptionJobTest, B2bDescriptionSupplementTest, CatalogHostPriorityTest, Unit/EnrichmentSourceRankingTest,
EnrichmentTester51Test, PriceListCardsTest, PriceListUpdateApiTest, ProductEnrichmentApiTest.

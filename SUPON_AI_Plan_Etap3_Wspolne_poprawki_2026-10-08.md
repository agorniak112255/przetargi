# Etap 3 opisów z cenników — wspólne poprawki: projekt i zamrożony kontrakt (08.10.2026)

Do planu `SUPON_AI_Plan_Opisy_z_cennikow_2026-10-07.md`. Etapy 0–2b są wdrożone; reguły z §6–§7 planu etapu 2 (kolor, model, bramka zdjęć) działają bez zmian. Dowody: sześć audytów z 08.10 (CSV w repo, niezacommitowane: Coba, Ansell, MAPA, CEDERROTH, AJ GROUP, SECURA), siódmy audyt (Bolle) oraz `SUPON_AI_Pilne_bezpieczenstwo_2026-10-08.md`. Ansell (etap 4) jest poza planem, poza regułami ogólnymi z §4. Decyzja właściciela (08.10): „Etap 3 teraz, potem pobieranie” — SECURA → AJ GROUP → CEDERROTH → karty MAPA.

## 0. Przyczyny w kodzie (sprawdzone)

| # | Objaw z audytu | Przyczyna (plik:linia) |
|---|---|---|
| 1 | MAPA SOLO 987 (404 u producenta) z bpbhp; AJ GROUP 264 CP, SBA01B, WRA02B, 1044, 071, 002, 045, 001 ze sklepów; SECURA prawie cała ze sklepów | `ProductEnrichmentService.php:5105-5121` `manufacturerOnlyPages`: bez karty producenta w puli sklepy zostają źródłem (opis :5067); to samo w `describeFromPages` (:822) i przy ponowieniu (:1365-1370). SECURA nie ma na liście `enrichment.manufacturer_only_sources` (`config/enrichment.php:214`). |
| 1 | PDF-y MAPA z cas-technik.eu | PDF-y z wyników wyszukiwania z kodem w adresie (:1714-1725) przechodzą bez względu na „tylko producent”. |
| 2 | 1011 ↔ 1011 R, 104/1 ↔ 104/1 OC, SB04 AIR ↔ SB04 AIR CARP — twardy werdykt na stronie innego wariantu | `SourceIdentity.php:234` + `pathCarries` :738 + `ProductCodeMatch::textCarries`: kod 4-znakowy to pełny klucz, myślnik w „model-1011-r” liczy się jako granica; ochrona wariantu (`labelledCodeIn` :480, `markupNamesLongerVariant` :613) tylko dla kodów krótkich. O puli decyduje `keepConfirmedCardPages` (:6425 → `ProductSearchIdentity::pageHasSkuOrNameAndManufacturer` :4033) — przepuszcza stronę wariantu po samym kodzie albo nazwie. |
| 2 | PDF-y wariantów i rodziny (AJ id_product=241 przy 240; CEDERROTH 51011003 przy 51011013, 51000008 przy 390103) | `ProductEnrichmentService.php:1689-1713`: każdy plik ze strony opisu przyjmowany bez sprawdzenia (`$descriptionPageDocs`); kod szukany w surowym adresie. |
| 2 | Zdjęcie wariantu („…model-1011-r.jpg”, „…105-spryz.jpg”, „…108wz.jpg”) | `provenProductImages` (:1559) uznaje zdjęcie, gdy adres niesie nasz kod jako osobny ciąg — „1011-r” też. |
| 3 | 102 z „/102-plaszcz-model-1102.html”; pdf.php?id_product=103 przy modelu 103 | `ProductSearchIdentity.php:4038-4053`: zbitka „adres + tytuł + tekst” i `tokenInHay(sku)` łapią „102” z numeru wpisu PrestaShop; pliki: `hayHasProductCode` na surowym adresie (:1700, :1718). Numer wpisu sklepu zdejmuje dziś tylko `SourceIdentity::pathCarries`. To samo „-p-1893” robartbhp (CEDERROTH 1893). |
| 4a | Harpon 330: „Produkt należy do kategorii PPE (obuwie, rękawice, odzież), a tekst opisuje…” | Model powtarza sprawdzenie warunku z polecenia: `EnrichmentDescriptionTemplates.php:150`, `:231` i polecenie filtra stron `ProductEnrichmentService.php:7425`; przed zapisem nic tego nie wycina. Ten sam rodzaj: SECURA 151, Ansell (tok rozumowania w polu norm). |
| 4b | 6× COBAtape „…Pozostałość rozpuszczalnika:”, Exonit 852 „(średnia siła uderzenia” | `ProductDescriptionText.php:21`: `strip_tags` bierze „<0,5%” / „<9 kN” za znacznik i usuwa resztę (COBAtape odtworzone `php -r`; Exonit — przypuszczenie). |
| 4b | CEDERROTH 26589 „…Station. o symbolu 51011011…”, Coba CM0500-6, Ansell 55110 | Hipoteza H2: `ProductDescriptionText::stripSpecTableRows` → `isSpacedSpecRow` (:300) usuwa wiersz „nazwa + wymiar”, gdy model złamał po nim linię. Do potwierdzenia pomiarem M1. |
| 4b | Opis ucięty limitem odpowiedzi | `OpenAiCompatibleClient.php:1278-1291` + `JsonResponseParser::closeTruncatedJson` domyka ucięty JSON (`max_tokens` 4500, :7691). |
| 4c | „EN ISO 13999” zamiast TDM, gauge jako poziom EN 388, ANSI pod EN 388, EN 388 przy EN 420/21420, typ EN ISO 374-1 vs litery, „EN ISO 388”, rozumowanie modelu, „Kategoria 2/3”, „ATEX HAZARDOUS AREA…” (Bolle 23053), „SCS” (AJ 908) | `payloadFromExtraction` (:7897-7900) tylko usuwa powtórzenia (`NormCode::dedupe`); `withoutUnsupportedNormClaims` (:8786) sprawdza obecność oznaczenia w źródle, nie zgodność poziomu z normą; poza normą nic nie odpada. |
| 5 | Półbuty 30 kV jako 20 kV; ELSEC 5/10 kV ze zdjęciem 2,5 kV; E2 jako A; P2 ze zdjęciem P1 | Brak porównania kV, klasy izolacji, klasy filtra i liter gazu; strony 20 kV, A2, P1 przechodzą po marce i nazwie. |
| Bolle b | specshop SILEXPSF, RUSHPSPSIS: przekierowanie na kategorię wzięte za kartę | `ProductPageFetcher.php:1033-1036`: potwierdzenie na adresie z zapytania, `final_url` (:1084) tylko zapisywany; `isListingWithoutProduct` działa przed pobraniem. |

## 1. Decyzje

1. **„Tylko producent” bez sklepu** (marki z `manufacturer_only_sources` + `secura`). Bez karty producenta w puli → **druga próba** na hostach producenta z innymi zapisami kodu (`ManufacturerCodeForms`, reguły z profilu; `catalogHitsOnHosts` + `searchOnHosts` na kopii karty z innym SKU). Nic → **model nie jest wołany**: `ManufacturerPageMissingException` → status `manual` + nowy powód przeglądu **`manufacturer_missing`** („brak strony producenta”; akcja „Wskaż adres” — giveUrl). **Przy force** opis spoza hostów producenta (nie ręczny adres, nie B2B, nie chroniony) cofany przez `DescriptionVersionStore::withdrawCurrent` (wersja → `superseded` z powodem; karta `description`/`norms` = null; tekst w historii, „Przywróć wersję”); **zdjęć i plików nie usuwamy**. Opis ze strony producenta albo z ręcznego adresu nigdy nie jest cofany; awaria wyszukiwarki = `failed` bez cofania. Tylko zwykłe wzbogacanie (`$sourceHierarchy = true`); uzupełnianie B2B bez zmian. Strona znaleziona innym zapisem kodu = najwyżej `soft` (`identity_soft`); inny zapis będący SKU innej karty marki (SB01-J → SB01) = strona tamtej karty, odpada.
2. **Najdłuższy kod z katalogu marki decyduje (`CardCodeArbiter`)** — strony, zdjęcia, pliki marek z `code.longest_code_wins` (aj-group, cederroth, secura, bolle; Coba, MAPA, Ansell wyłączone). Wyrób strony z: ścieżki adresu bez numerów wpisów sklepu, tytułu, `sku/mpn` głównego wyrobu (bez końcówki kombinacji), na hoście producenta także etykiety „model/REF/Indeks” z tytułu i adresu oraz pierwszego „Indeks: X” w tekście (reszty tekstu nie czytamy). Kod innej karty tej marki, który wydłuża nasz (1011 → 1011R) albo naszego kodu na stronie nie ma (102 przy 1102) → `foreign`: strona, jej zdjęcia i pliki odpadają, `judgePage` = `none`. Etykietowany kod producenta inny niż nasz (REF 6943 przy 310366, Indeks S565A202 przy S565E202) też `foreign`, nawet bez takiej karty w katalogu.
3. **Numer wpisu sklepu to nie kod wyrobu (`App\Support\ShopEntryId`)**: PrestaShop `/{id}[-{id}]-slug`, `{id}-*_default/`, parametry `id_product|product_id|id`, osCommerce `-p-{id}`, IdoSell `product-xxx-{id}-`, bhp-gabi `p{id},` — zdejmowane przy każdym dopasowaniu kodu w adresie. Plik z `id_product=N` tylko gdy N = numer wpisu którejś strony opisu na tym samym hoście.
4. **Sprawdzenie przed zapisem** (wszystkie cenniki; `enrichProduct`/`describeFromPages`; B2B tylko poprawka `plain()`):
   - **poprawiamy automatycznie** (ślad): „<” bez znacznika nie zjada tekstu; wiersz „nazwa + wymiar” z następną linią od małej litery nie jest tabelą; urwany koniec obcinany do ostatniego pełnego zdania; „EN ISO 388” → „EN 388”; z pozycji normy zdejmujemy poziom, który do niej nie należy (gauge, ANSI A1–A9 przy EN 388; kod EN 388 przy EN 420/21420); „Kategoria/Kat. I–III” z norm do certyfikatów;
   - **tylko odrzucamy i zapisujemy** (`dropped_norm_claims`; nowe `dropped_meta_sentences`): zdania i pozycje powtarzające polecenie; „EN ISO 13999 + litera A–F”; EN ISO 374-1 z typem sprzecznym z liczbą liter (A ≥ 6, B 3–5, C 1–2); pozycja bez oznaczenia normy (ATEX…, SCS, „Klasa 2 (…)”); szablon „EN166 Lens Marking = PrB420”; sama etykieta atrybutu sklepu bez oznaczenia nie potwierdza normy;
   - nowego powodu przeglądu nie ma; opis za krótki po wycięciu → wyjątek jak dziś (:8829).
5. **Cechy bezpieczeństwa (`App\Support\SafetyFeatures`)** z nazwy karty: kV, klasa izolacji (00–4 z etykietą), P1–P3, FFP1–3, gaz (A/B/E/K/AX/ABE/ABEK… + klasa 1/2). Po stronie źródła tylko ścieżka adresu, tytuł, nazwa pliku zdjęcia. Sprzeczność = cecha po obu stronach bez części wspólnej → strona/zdjęcie odpada, `judgePage` = `none`; brak cechy w źródle to nie sprzeczność (strona rodziny „2,5/5/10 kV” przechodzi). Opis: tylko jawne kody P/FFP/gaz sprzeczne z nazwą (karta E2, w opisie tylko „A2”/„ABEK”) → opis odrzucony, `manual`; kV w opisie nie porównujemy.
6. **Bolle (dodatek):** (a) reguły 4c obejmują „ATEX…” i „EN166 Lens Marking = PrB420”; (b) przekierowanie na kategorię = odrzucenie strony; (c) arbiter dla profilu `bolle` (B9V na stronie FLASHV → `foreign`). Opisów B2B Bolle i EN 379 z dokumentów nie ruszamy.

## 2. Odrzucone / przesunięte

- **Sens treści bez modelu nie do sprawdzenia:** odwrócone zaprzeczenie „nie zawiera silikonu”, objaśnianie kodów norm słowami, rękaw jako rękawica, dopisane cechy (rozmiar S, „wielokrotne pranie”, „sterylne”, Burn Gel „chemiczne”), zestaw jak jeden składnik, część jak całe urządzenie, normy materiału jako normy wyrobu, EN 136/142 przy półmasce → osobny etap „polecenie + weryfikator treści” z pomiarem.
- **Grupy gazu po opisie słownym** — przesunięte (źródło A2 przy E2 odpada na poziomie strony).
- **Kody 2-znakowe** (AJ „CP”) — klucz od 3 znaków; CP → `manufacturer_missing`, handlowiec poda adres.
- **Ansell** — etap 4; reguły 4a–4c obejmą go przy jego pobraniu.
- **p4s JPEG jako .pdf, opisy B2B Bolle, kategorie-śmieci, błędy cennika, sklep 390100** — poza etapem 3.
- **ISO 12312-1 / ProBlu jako wyrób spoza ŚOI** — tylko reguła „Lens Marking”.

## 3. Zamrożony kontrakt

### Konfiguracja (B)
- `config/enrichment.php`: `manufacturer_only_sources` += `'secura'`.
- `config/manufacturer_profiles.php`: `default.code` += `alt_forms => []`, `longest_code_wins => false`, `index_label => null`; `aj-group.code` += `alt_forms => ['letter_suffix','dash_suffix','trailing_words']`, `longest_code_wins => true`; `cederroth.code` += `longest_code_wins => true`; nowy `secura` (`brand_keys ['secura']`, `identity_in ['url','title','markup']`, `code ['longest_code_wins' => true, 'index_label' => 'Indeks']`); nowy `bolle` (`brand_keys ['bolle','bolle-safety']`, `code ['longest_code_wins' => true]`).
- `ManufacturerProfile` += `public readonly array $altForms = []`, `public readonly bool $longestCodeWins = false`, `public readonly ?string $indexLabel = null`; `ManufacturerProfiles::for` mapuje.

### Nowe klasy i metody (sygnatury zamrożone)

```php
// B — App\Support\ShopEntryId
public static function strip(string $url): string;      // adres bez numerów wpisów sklepu (§1.3)
public static function entryId(string $url): ?int;      // id_product z 1. segmentu slugu albo z parametru

// B — App\Support\SafetyFeatures
/** @return array{kv: list<string>, insulation: list<string>, particle: list<string>, ffp: list<string>, gas: list<string>} */
public static function in(string $text): array;         // bez „+A1:2018”, „B2B”, „A4” bez kontekstu filtra
public static function inUrl(string $url): array;       // ścieżka po ShopEntryId::strip + nazwa pliku; „2-5-kv” = 2.5
public static function ofCard(Product $p): array;       // tylko nazwa karty
public static function conflict(array $card, array $found): ?string;    // null albo np. „30 kV ≠ 20 kV”

// B — App\Services\Enrichment\ManufacturerCodeForms
public const RULES = ['letter_suffix', 'dash_suffix', 'trailing_words', 'leading_zeros'];
/** @return list<array{code: string, rule: string}> — reguły z profilu; ≥3 znaki z cyfrą; bez samego SKU */
public function alternatives(Product $p, ?ManufacturerProfile $prof): array;

// B — App\Services\Enrichment\CardCodeArbiter (kody kart marki z bazy, pamięć per marka w przebiegu)
public const OWN = 'own'; public const FOREIGN = 'foreign'; public const NONE = 'none';
/** @param list<string> $ownKeys  @param list<string> $hays  @return array{verdict: string, code: ?string} */
public function judge(Product $p, array $ownKeys, array $hays, ManufacturerProfile $prof): array;
/** @return list<string> */
public function pageHays(array $page, ManufacturerProfile $prof): array;
public function forget(): void;

// B — SourceIdentity (nowe publiczne)
public function ownKeys(Product $p, ?ManufacturerProfile $prof): array;  // klucze bez EAN
/** @return array{verdict: 'own'|'foreign'|'none', code: ?string} — 'none' także przy profilu bez longest_code_wins */
public function pageCodeRelation(Product $p, array $page, ?ManufacturerProfile $prof): array;
public function fileCodeRelation(Product $p, string $urlAndLabel, ?ManufacturerProfile $prof): array;
// judgePage — kolejność: nasz sklep → ręczny adres → SafetyFeatures (none) → arbiter foreign (none)
// → twarde klucze (+ 'field': „{index_label}: SKU” na hoście producenta) → krótkie → rodzina Coby
// → inne zapisy kodu (alt_forms): twarde dla innego zapisu = SOFT „strona modelu X (kod karty w innym zapisie: reguła)”
// → soft → none

// D — App\Support\PromptEcho
public static function isEcho(string $sentenceOrItem): bool;  // „(obuwie, rękawice, odzież”, „kategorii PPE”,
// „tekst|źródło|strona opisuje”, „zgodnie z różnymi źródłami”, „dla tego samego SKU”, „implied”, „or similar”, „source says”

// D — App\Services\Enrichment\NormListSanity
/** @param list<string> $norms @return array{norms: list<string>, certificates: list<string>, dropped: list<string>, fixed: list<string>} */
public static function clean(array $norms): array;
/** @return list<string> powody (13999+litera, 374-1 typ≠litery) — do SourceClaimGuard::filterSentences */
public static function sentenceProblems(string $sentence): array;
public static function withoutTemplateAttributeRows(string $sourceText): string;  // „EN166 Lens Marking PrB420” bez oznaczenia → wycięte

// D — App\Support\ProductDescriptionText
// plain(): przed strip_tags „<” bez litery, „/”, „!”, „?” za nim → „&lt;”
// stripSpecTableRows(): wiersz, po którym linia zaczyna się małą literą, zostaje
/** @return array{text: string, cut: string} */
public static function withoutUnfinishedTail(string $text): array;  // ostatni akapit kończy się na „:”, „,”, „(”, „-”,
// ma niezamknięty nawias albo kończy się literą bez kropki → cięcie do ostatniego [.!?…] (nic nie zostaje — bez zmian, cut='')

// E — Product::REVIEW_MANUFACTURER_MISSING = 'manufacturer_missing' (+ REVIEW_REASONS)
// E — DescriptionVersionStore
public function withdrawCurrent(Product $p, string $reason): ?ProductDescriptionVersion;
// published → superseded (reason); karta: description=null, norms=null, review_reason=manufacturer_missing, review_since=now;
// null i bez zmian, gdy wersja chroniona (hasProtectedPublished / review_approve / manual) albo karta ma opis B2B

// A — App\Exceptions\ManufacturerPageMissingException extends ProductSourcesNotFoundException
//     (ProductSourcesNotFoundException przestaje być final)
```

### `ProductEnrichmentService.php` (wyłącznie A; zależności przez `app()`, konstruktor bez zmian)
- **W1 — pula.** `private function withoutForeignOrConflictingPages(Product, array $pages): array`: `pageCodeRelation` = foreign albo `SafetyFeatures::conflict(ofCard, inUrl(url) + in(title))` → strona odpada (wpis `page` z powodem); adres ręczny zwolniony. Po `withCatalogPages` (:1192), na początku `describeFromPages` (:822) i po uzupełnieniu (:1301).
- **W2 — ścisłe „tylko producent”.** `manufacturerOnlyPages`: `$sourceHierarchy && usesManufacturerSourcesOnly && ! $hasCard` → zostają tylko katalog PDF i adres ręczny; `cut=true`, nowy klucz `missing=true`. Strony cennika z pliku nie otwierają sklepów dla tych marek.
- **W3 — druga próba.** `private function retryManufacturerHosts(Product): list<page>` przed :1193 i :1224 (marka ścisła, brak karty producenta): dla każdego `alternatives()` klon karty z `sku = code` → `catalogHitsOnHosts` → pusto: `searchOnHosts` → `pages->fetch(..., $profile->hosts, $clone)` → zostają strony na hoście producenta, twarde dla klonu i bez `foreign` dla karty. Ślad: `druga próba: kod X (reguła)`.
- **W4 — brak strony producenta.** `ManufacturerPageMissingException` („Strony producenta nie znaleziono (hosty: …) — marka tylko od producenta, sklepy pominięte. Wskaż adres w Do przeglądu.”); w `catch`: `manual` + `review_reason`; przy force i źródle obecnego opisu spoza hostów producenta (`profile->ownsUrl`), nie ręcznym → `versions()->withdrawCurrent()`. Awaria wyszukiwarki (`engineOutageDetail`) — nic z tego.
- **W5 — zdjęcia.** Przed `provenProductImages` (:1559) i w `tryImagesFromOtherCards` odpada adres z `fileCodeRelation` = foreign albo sprzecznością `SafetyFeatures::inUrl`.
- **W6 — pliki.** (a) `fileCodeRelation(url.' '.label)` = foreign → odpada, także ze strony opisu; (b) `ShopEntryId::entryId(doc)` ≠ null i host = host strony opisu → tylko gdy równy `entryId` którejś strony opisu; (c) `hayHasProductCode` na `ShopEntryId::strip(url)` (:1700, :1718); (d) przy `$manufacturerOnly` tylko pliki ze stron opisu, hostów producenta albo katalogu. Ta sama reguła przed `manufacturerPdfCardPages` (:1190).
- **W7 — tekst.** Po `plain()` (:1271, :841): `withoutUnfinishedTail` (ślad `desc`). Przed `withoutUnsupportedNormClaims`: `filterSentences` z `PromptEcho::isEcho` i `NormListSanity::sentenceProblems`, „EN ISO 388” → „EN 388” w opisie, sprzeczność P/FFP/gaz → `$description = ''`. W `withoutUnsupportedNormClaims`: `sourceText` przez `withoutTemplateAttributeRows`. Po nim: `NormListSanity::clean` na `norms` i `attributes.normy_en`; `certificates` += przeniesione; `dropped/fixed` → `dropped_norm_claims` (przedrostek „pole norm: ”); echa z list → `dropped_meta_sentences`. Polecenie filtra stron (:7405) += linia „Nie pisz w tekście, czy źródło pasuje do produktu ani jak sprawdzałeś warunki”.
- **W8.** `finally`: `app(CardCodeArbiter::class)->forget()`.

### Pozostałe pliki
- **C: `ProductSearchIdentity.php`** — każde miejsce, gdzie adres trafia do zbitki dopasowania kodu (`pageHasSkuOrNameAndManufacturer`, `isConfirmedProductCard`, `imageUrlMentionsProduct`, `hayMentionsProduct`, `urlOrTitleCarriesCodeFamily`, `resultsCarryProductCode`/`cardsCarryProductCode`), adres przez `ShopEntryId::strip`. Nic więcej; `pageAgreesWithBrandAndName` bez zmian.
- **C: `ProductPageFetcher.php`** — gdy znormalizowany `final_url` ≠ adres zapytania, potwierdzenie (`pageConfirmsMatchingProduct`) na `final_url`; `identity->looksLikeNonProductCardUrl(final)` → odrzucenie `CandidateRejection::LISTING` z notą „przekierowanie na listę”; `documentNamesProduct`: `ShopEntryId::strip`.
- **D:** `EnrichmentDescriptionTemplates::jsonContract()` += „Nie pisz w tekście, czy źródło pasuje do produktu ani jak sprawdzałeś warunki”; komenda tylko do odczytu `products:audit-description-text {--price-list=} {--manufacturer=} {--all} {--out=}` (`chunkById(200)`; kolumny id, sku, producent, flagi `prompt_echo|unfinished_tail|paragraph_lowercase_start|norms_invalid`, fragment).
- **E:** `Product.php` (stała); `DescriptionVersionStore::withdrawCurrent`; `ProductReviewService` (`approve` przy `manufacturer_missing` bez wersji zdejmuje powód; `giveUrl` bez zmian; liczniki `by_reason`); `CompareCardsCommand` czyta też format audytu (`id;sku;nazwa;problem;waga;szczegóły;link`, grupa = problem); front `lib/productReview.ts` (etykieta „Brak strony producenta” + podpowiedź „Wskaż adres strony producenta albo opisz ręcznie”), `Help.tsx`, build.

## 4. Agenci, pliki, testy

| Agent | Pliki (jedyny właściciel) |
|---|---|
| **A — rdzeń** | `ProductEnrichmentService.php`, `Exceptions/ProductSourcesNotFoundException.php`, `Exceptions/ManufacturerPageMissingException.php`, `tests/Feature/EnrichmentStageThreeFlowTest.php` (+ przepisanie asercji „sklepy zostają” w `EnrichmentManufacturerOnlySourcesTest` :144-151 dla marek z listy ścisłej) |
| **B — tożsamość i profile** | `SourceIdentity.php`, `ManufacturerProfile.php`, `ManufacturerProfiles.php`, `config/manufacturer_profiles.php`, `config/enrichment.php` (tylko lista), nowe `Support/ShopEntryId.php`, `Support/SafetyFeatures.php`, `Enrichment/ManufacturerCodeForms.php`, `Enrichment/CardCodeArbiter.php` |
| **C — bramka stron** | `ProductSearchIdentity.php`, `ProductPageFetcher.php` |
| **D — tekst i normy** | `Support/ProductDescriptionText.php`, `Support/EnrichmentDescriptionTemplates.php`, nowe `Support/PromptEcho.php`, `Enrichment/NormListSanity.php`, `Console/Commands/AuditDescriptionTextCommand.php` |
| **E — przegląd i pomiar** | `Models/Product.php`, `DescriptionVersionStore.php`, `Services/ProductReviewService.php`, `Console/Commands/CompareCardsCommand.php`, `frontend/src/lib/productReview.ts`, `PriceListsReview.tsx` (tylko etykieta), `Help.tsx`, build |

**Testy A** (`EnrichmentStageThreeFlowTest`, atrapy Http i modelu): (1) SBA01B: w wynikach tylko roboczystyl/behapownia, druga próba daje `141-spodniobuty-antystatyczne-sba01` → model dostaje tylko pros.pl, soft, `identity_soft`; (2) SOLO 987: mapa-pro 404, bpbhp jest → zero wywołań modelu, `manual` + `manufacturer_missing`; z force i starym opisem z bpbhp → cofnięty, zdjęcia zostają; ze starym opisem z mapa-pro.pl → nietknięty; (3) awaria wyszukiwarki → `failed`, bez cofania; (4) 1011: strony 104 i 105 → do modelu tylko 104; zdjęcie `…-model-1011-r.jpg` odpada, `…-model-1011.jpg` zostaje; `pdf.php?id_product=105` odpada, `104` zostaje; (5) 103: `pdf.php?id_product=103` przy stronie 64-… odpada; (6) CEDERROTH 51011013: `51011003-v03.pdf` odpada; (7) T5912200: mistralbhp „…20-KV…T5912100” i securabc z „Indeks: T5912200” → zostaje securabc, zdjęcie „…-20kv.jpg” odpada; (8) tekst: „<0,5%” w całości; zdanie Harpon 330 wycięte; „…uderzenia” obcięte; pole norm [„EN ISO 13999 D”, „EN 388 15 gauge”, „Kategoria 2”, „EN ISO 374-1 Type B JKLOPT”] → [„EN 388”], „Kategoria 2” w certyfikatach, reszta w `dropped_norm_claims`; „Pochłaniacz 3033 E2” z opisem „…klasa A2…” bez E → `manual`.

**Testy B:** `ShopEntryIdTest` (pros 102/241/x13producttopdf, securabc `20-41-secura-3000.html`, robartbhp `-p-1893`, bhp-gabi `p4240,`); `SafetyFeaturesTest` (30 kV ↔ „…20-KV…”; ELSEC 5 kV ↔ „2,5 kV”/„2-5-kv”; E2 ↔ „pochłaniacz-a2-3031”; P2 ↔ „secair-3000-01-p1-1_1.jpg”; A2P3 ↔ „47-pochlaniacz-a2-filtr-przeciwpylowy-p3” → brak sprzeczności; „EN 388:2016+A1:2018” i „B2B” to nie gaz; „5 kV / 10 kV” → brak sprzeczności); `ManufacturerCodeFormsTest` (SBA01B→SBA01, WRA02B→WRA02, SB01-J→SB01, „071 STRAŻ”→071, CP→[], Coba/MAPA→[]); `CardCodeArbiterTest` (AJ: 1011/1011 R, 104/1 / 104/1 OC, SB04 AIR / SB04 AIR CARP, 105 / 105 S/PRYZ, 108/WZ, 102/1102, 1101/1011 / 1101 R/1011 R, 1044/1044/W; CEDERROTH REF 6943 w tytule przy 310366; SECURA „Indeks: S565A202” przy S565E202; „103-00033-48/XS” = own; Coba → none); `SourceIdentityTest` += 1011 na „…model-1011-r.html” → none; T5912200 z Indeksem → hard; SBA01B na stronie SBA01 → soft; SB01-J na stronie SB01 (karta SB01 w katalogu) → none.

**Testy C:** 102 + „/102-plaszcz-model-1102.html”, tytuł „Płaszcz model 1102” → `pageHasSkuOrNameAndManufacturer` false (na starym kodzie true); „/61-kurtka-kangurka-model-102.html” → true; `imageUrlMentionsProduct(102, …/3879-thickbox_default/plaszcz-model-1102.jpg)` false; CEDERROTH 1893 + robartbhp „…-p-1893.html” false; fetcher: SILEXPSF → kategoria specshop → LISTING; przekierowanie na adres z kodem → przyjęta; mapa-pro.pl /pl/ → /pl/… z tytułem modelu → przyjęta.

**Testy D:** `ProductDescriptionTextTest` += „<0,5%”, „<9 kN”, prawdziwy `<p>`; „Soft Foam Bandage Blue 6 cm x 200 cm” + „o symbolu 51011011…” zostaje; „Overall Length (Metric)54.9 m” dalej znika; `withoutUnfinishedTail` na COBAtape, Exonit 852, Coba PL010001; `PromptEchoTest` (Harpon 330, SECURA 151, Ansell 11772, AJ 217 → echo; „zgodne z wymaganiami normy EN 388” i „Kategoria III ŚOI” → nie); `NormListSanityTest` (przypadki §1.4 + DIN 13157, ASTM F2675, PN-EN 50321-1:2018, „EN 343 (4. klasa)”, „EN 166 2C-1.2 1 FT” zostają); `AuditDescriptionTextCommandTest`.

**Testy E:** `ProductReviewApiTest` (+ `manufacturer_missing`: lista, licznik, approve bez wersji, giveUrl); `withdrawCurrent` (published → superseded, tekst w historii, zdjęcia nietknięte, odmowa przy review_approve i B2B, „Przywróć wersję” wraca tekst); `CompareCardsCommandTest` z wycinkiem `SUPON_AI_Audyt_SECURA_2026-10-08.csv`.

**Kolejność:** sygnatury §3 zamrożone; B, C, D, E równolegle; A pisze test i podłączenie od razu na sygnaturach, łączy po B, D, E. Potem pełny pakiet (≤ 12 procesów), dwa niezależne przeglądy (rdzeń; tożsamość + tekst), symulacja na produkcji (§5) przed prośbą o wdrożenie — jedno wdrożenie.

**Muszą dalej przechodzić (bez obniżania progów):** `EnrichmentTester51Test` („własne zachowane 39/39” nie spada, „obce odrzucone” może tylko wzrosnąć), `SourceIdentityTest`, `SourceIdentityProbeCommandTest`, `SourceClaimsParityTest`, `SourceClaimGuardTest`, `EnrichmentStageOneFlowTest`, `EnrichmentStageZeroTest`, `EnrichmentModelSharedFlowTest`, `EnrichProductJobModelHandoverTest`, `ModelGroupPlannerTest`, `ModelGroupsCommandTest`, `CompareCardsCommandTest`, `EnrichmentPriceListSitesTest`, `EnrichmentSingleCardDescriptionTest`, `EnrichmentDescriptionGuardTest`, `EnrichmentMissingDataListItemsTest`, `EnrichmentPageBudgetNormsTest`, `ManufacturerPageNormFactsTest`, `ManufacturerPdfCardSourceTest`, `PreferManufacturerDocumentsTest`, `ManufacturerCatalogPdfTest`, `ProductSearchIdentity*Test`, `AnsellGloveIdentityTest`, `AccessoryCardIdentityTest`, `CatalogIndexTest`, `ProductPageFetcher*Test`, `ProductDescriptionTextTest`, `NormCodeTest`, `BhpAttributeNormalizerTest`, `ProductReviewApiTest`, `ProductEnrichmentApiTest`, `QueueEnrichmentCommandTest`, `B2b*`, `SupplementB2bDescription*`, `B2bDescriptionSupplementTest`, cały pakiet. Jedyna zamierzona zmiana: `EnrichmentManufacturerOnlySourcesTest` :144-151 (marki ścisłe) — z komentarzem o decyzji; marki spoza listy i B2B (:185) bez zmian.

## 5. Pomiar na produkcji przed pobraniem (tylko odczyt)
Wdrożenie kodu niczego nie zmienia w danych — reguły działają przy pobraniu. Każde ssh do dziennika poleceń.
- **M1 — tekst:** `products:audit-description-text --all --out=storage/app/reports/etap3_text_before.csv`. Oczekiwane: Harpon 330; 6 COBAtape; Exonit 852; 26589; ~23 HyFlex z 13999; 23053. H2: odsetek akapitów od małej litery. Próg: próbka 20 kart na flagę, fałszywe `unfinished_tail` ≤ 2/20.
- **M2 — kogo cofnie W4:** karty AJ GROUP, MAPA, SECURA ze źródłem opisu spoza hostów profilu i nie ręcznym → lista id (oczekiwane SECURA ~30, AJ ~10, MAPA 1); właściciel zatwierdza.
- **M3 — inne zapisy kodu:** `alternatives()` dla 163 kart AJ + czy inny zapis jest SKU innej karty → „soft / odpadnie / brak”.
- **M4 — arbiter na zapisanych danych:** dla #3, #12, #2 `pageCodeRelation`/`fileCodeRelation` na `source_urls`, `product_images.source_url`, `product_documents.source_url` → lista foreign. Próg: wszystkie przypadki z audytu oznaczone, 0 fałszywych wśród kart bez uwag.
- **M5 — cechy bezpieczeństwa** dla 42 kart SECURA → oczekiwane 182, 178, 179, 157, 159.
- **M6 — pokrycie indeksu:** `catalog_pages` dla securabc.com, pros.pl, mapa-pro.pl, cederroth.com + `products:source-identity-probe --price-list=2/3/12` → CSV „przed”.

## 6. Ponowne pobranie i kryteria „po”
Kolejność: **SECURA #2 (42) → AJ GROUP #3 (163) → CEDERROTH #12 (84) → MAPA #1** (140, 22, 134 i karty z flagą M1). Force + `include_manufacturer`. Po każdym: `products:compare-cards --csv=SUPON_AI_Audyt_<X>_2026-10-08.csv`, `products:source-identity-probe --price-list=N`, `audit-description-text --price-list=N`.
- **SECURA:** 0 opisów ze źródłem spoza securabc.com, katalogu PDF albo ręcznego adresu; 182, 190, 187, 157, 165 twardo z securabc albo `manufacturer_missing`; 0 zdjęć ze sprzecznym kV/P/gazem w nazwie pliku; części spoza katalogu (170, 173, 176, 171) i 187, 188, 190 → `manufacturer_missing`; twardy ≥ 80% kart z opisem.
- **AJ GROUP:** każda z 10 kart „tylko producent omijane” ma stronę z pros.pl (twardo albo soft) albo `manufacturer_missing`; 102, 1011, 104/1, SB04 AIR, 108, 105 ze strony własnego modelu; 0 plików z `id_product` innej strony; 0 zdjęć z kodem innej karty; twarde karty nie tracą opisu po cichu.
- **CEDERROTH:** 310366 bez strony i zdjęcia 6943; 510110414 bez stron 7200 i 190400; 0 plików z kodem innej karty marki; 26589 bez urwanego zdania.
- **MAPA:** 140 → strona producenta albo `manufacturer_missing`; 22 bez zdania kontrolnego; 134 pełny; pozostałe bez zmiany werdyktu (hard 147/147).
- **Wszystkie:** `dropped_norm_claims` przejrzane na próbce; „do przeglądu” w możliwościach handlowców (kilkanaście kart dziennie) — inaczej stop przed kolejnym cennikiem.
**Wycofanie:** `products:rollback-batch <id>`; opisy cofnięte przez W4 wracają pojedynczo („Przywróć wersję”, lista z M2, filtr `manufacturer_missing`).

## 7. Ryzyka
Ścisła reguła zostawi bez opisu karty, których strony producenta wyszukiwarka nie zna (druga próba, „Wskaż adres”, M2/M6); inny zapis kodu może wskazać wyrób bazowy (tylko soft, SKU innej karty odpada); arbiter na krótkich kodach w nazwach plików (tylko 4 profile, bez tekstu strony, M4); `ShopEntryId` w `ProductSearchIdentity` zmienia bramkę wszystkich marek (`EnrichmentTester51Test` + sonda #3, #12); `final_url` może odrzucić skrócone adresy producentów bez kodu (tytuł i treść też potwierdzają; testy mapa-pro i coba); obcinanie urwanego końca (próg M1); `withdrawCurrent` zmienia kartę bez nowej wersji opublikowanej (tekst w historii, pliki nietknięte); 128 MB (`chunkById(200)`, arbiter trzyma tylko klucze SKU marki).

## 8. Czego nie robić
Nie ruszać: profilu i kodu Ansella; reguł Coby z etapu 2/2b (arbiter wyłączony dla `coba`); `pageAgreesWithBrandAndName`; `decide()` w `DescriptionVersionStore`; ścieżek B2B; słownika `SourceClaimGuard` i `SourceClaims`. Nie dodawać: kolumn w `products`; weryfikatora treści z modelem; usuwania zdjęć i plików przy W4.

## 9. Do potwierdzenia przez właściciela przed kodem
1. W4: cofanie tekstu i norm sklepowych opisów marek „tylko producent” przy force (bez usuwania zdjęć i plików) — tak/nie.
2. SECURA na liście „tylko producent” i profil `bolle` tylko z regułą najdłuższego kodu — tak/nie.
3. Zdjęcia ze sklepu przy markach ścisłych dozwolone, gdy zdjęcie producenta się nie pobierze (dzisiejsza decyzja, :1660), ale po filtrach W5 — tak/nie.

**Decyzje właściciela (08.10.2026):** 1 — TAK (cofać tekst i normy sklepowych opisów marek „tylko producent” przy force; zdjęcia i pliki zostają; karta do przeglądu z `manufacturer_missing`); 2 — TAK, oba (SECURA „tylko producent” z polem Indeks; profil `bolle` z regułą najdłuższego kodu); 3 — TAK (zdjęcie ze sklepu przy markach ścisłych dozwolone po filtrach W5).

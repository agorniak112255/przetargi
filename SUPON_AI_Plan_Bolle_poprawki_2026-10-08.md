# Karty Bolle po audycie 08.10.2026 — przyczyny, poprawki, naprawa danych

Cennik #21 = konto B2B Bolle #4 (415 kart). Dowody: `SUPON_AI_Audyt_Bolle_2026-10-08.csv`, `SUPON_AI_Pilne_bezpieczenstwo_2026-10-08.md` §8, sondy tinkera READ ONLY z 08.10 (wpisy w `przetargi-serwer.log`). Wspólne poprawki opisów robi etap 3 (`SUPON_AI_Plan_Etap3_Wspolne_poprawki_2026-10-08.md`, kontrakt 4d17081) — tu tylko to, czego etap 3 nie obejmuje.

## 0. Skąd są opisy i normy kart Bolle (pomiar 08.10)

- 413 kart ma stan wzbogacania `none`: opis to tekst z B2B przetłumaczony przez `TranslateB2bProductTextJob`, a **185 z nich ma opis z uzupełniania ze stron sklepów** (`SupplementB2bDescriptionJob` → `ProductEnrichmentService::supplementB2bDescription`, ślad `b2b_supplement`). Zwykłe wzbogacanie (`enrichProduct`) Bolle prawie nie dotyczy.
- Normy producenta: `products.manufacturer_norms` z Technical Sheet (BolleDatasheetTable). Kolumnę `products.norms` kart uzupełnionych wpisała 07.10 komenda `products:restore-norms-column` z listy `enrichment_payload.norms` uzupełnienia — stąd „EN 166” ProBlu i „ATEX HAZARDOUS AREA…” w kolumnie.
- Etap 3 zostawia ścieżkę uzupełniania bez zmian (kontrakt: „uzupełnianie B2B bez zmian”); jego arbiter kodu (W1), `NormListSanity` i `PromptEcho` (W7) działają tylko w `enrichProduct`/`describeFromPages`. Obejmie ją jedynie `ProductPageFetcher` (final_url → przekierowanie na kategorię odrzucone), bo uzupełnianie woła `$this->pages->fetch`.

## 1. Przyczyny w kodzie

| # | Objaw (karty) | Przyczyna | Kto |
|---|---|---|---|
| 1 | ProBlu Screeners z EN 166 (13 kart), PrB420 „oznaczeniem EN 166” | sklepy (chipdip, rsdelivers) mają pole szablonu „EN166 Lens Marking: PrB420”; `supplementB2bDescriptionInner` sprawdza kody norm opisu i listy (`sourceClaims` vs `claimKey(supplier+web)`) — „EN166” stoi w tekście strony jako etykieta, więc przechodzi | uzupełnianie — **ja, po etapie 3** (`NormListSanity::withoutTemplateAttributeRows`) |
| 2 | 23053 OSLO: pole norm „ATEX HAZARDOUS AREA / ATMOSPHERE GROUP” | `$supported` w uzupełnianiu odrzuca tylko pozycję z kodem spoza źródeł; pozycja bez oznaczenia normy przechodzi | uzupełnianie — **ja** (`NormListSanity::clean`) |
| 3 | Strony innego wariantu/wyrobu: B9V←FLASHV, TRYON←TRYONN20E/TRYOPSF/PSSTRYO443B, BAXN←BAXPSI/BAXPSF, PACCASR-4←PACCASR-2, IRIDPSI2←IRIDPSI2.5 | `supplementPageNamesCardVariant`: kod < 5 znaków (B9V, BAXN, BESM) = bez sprawdzenia; kod w adresie/tytule wystarcza nawet obok dłuższego kodu innej karty (TRYON w „…Tryon-EN-166…TRYONN20E.html”); cudzy kod sprawdzany tylko, gdy nasz jest wyłącznie w treści | uzupełnianie — **ja** (`SourceIdentity::pageCodeRelation`, profil `bolle` z `longest_code_wins` dodaje etap 3) |
| 4 | specshop SILEXPSF, RUSHPSPSIS → kategoria; tekst kategorii jako cechy | `ProductPageFetcher` potwierdza adres z zapytania, `final_url` tylko zapisuje | **etap 3 C** (obejmie też uzupełnianie) |
| 5 | Ucięty dopisek na końcu opisu: „FLASH WELDING WELDING HEA”, „CASES ACCESSORIES CASES N”, przy zestawie pianki NESS+ „RUSH+ - KIT SAFETY SPARE” | `BolleB2bConnector::description` dokleja `featureddescription`, które u tych pozycji jest uciętym znacznikiem kategorii (same wersaliki, 24–25 znaków); tłumacz zostawia akapit wersalikami dosłownie | **łącznik — zrobione** (pomijamy opis wyróżniony bez małych liter) |
| 6 | 10356 B7VP, 10357 B6V „puste pole norm” | nie błąd: normy producenta są w `manufacturer_norms` (EN166, EN175, EN379 + oznaczenie filtra) i w `attributes.normy_en`; kolumnę `norms` pisze tylko opis | — |
| 7 | 22958 B9V z EN 175 | normy z wiersza B9V Technical Sheet FLASH (wiersz kodu + EAN) — dane producenta, zgodnie z decyzją „najpierw producent”; błędny jest opis (strona FLASHV, #3) | — |
| 8 | Nazwy „FILTERS – Osłona ekranu”, „SCREENS – osłona ekranu”, SUPERBLAST „okulary” przy wizjerze | nazwa u Bolle: „FILTERS – Screen guard”, „SUPERBLAST – Clear safety goggle” — tłumaczenie wierne, błąd źródła | ręczna zmiana nazwy |
| 9 | BL150 w opisie BL15 (10306, 10307), RUSH+ w RUSH+ 2.0 | tekst B2B Bolle | zgłoszenie do Bolle / ręcznie |
| 10 | Nazwy po angielsku (10240, 10243, 10254, 10373 — tłumaczenie odrzucone; 10251, 10255–10257 „size S”) | odrzucone przed 28.09 (TRANSLATABLE_UPPERCASE: ECO/PACK/SIZE dodane później); „size S” model zostawił | `b2b:translate 4 --retry-rejected` / `--redo-names` |
| 11 | Liczby i cechy wymyślone przez model (VLT 43–88%, 3,0%, 2 mm, Curve, kolor, korekcja wzroku, „Copper Selenide Photochromic”) | treść uzupełnienia ze sklepów; sensu bez modelu nie sprawdzimy (etap 3 §2 „odrzucone/przesunięte”) | naprawa danych: cofnięcie uzupełnienia z pozostawieniem tekstu B2B |
| 12 | PDF iri-s-ft-gb.pdf przy 10226, 10227, 10283; zdjęcie procery COBPSI przy COBPSF (10183) | dokumenty i zdjęcia spoza łącznika Bolle (thesafetysupplycompany, b2b.procera.pl) | ręcznie w karcie |
| 13 | 10147 NESS+ „certyfikat ATEX 21ES1011” | lista `certificates` z uzupełnienia nie jest sprawdzana względem źródeł dostawcy (normy tak, przy `manufacturer_norms` tylko dostawca) | niepewne (audyt) — ręczna weryfikacja; bez zmiany kodu |

## 2. Zmiany kodu

### A. Teraz (pliki wolne od etapu 3)
1. `BolleB2bConnector::description` — `featureddescription` bez żadnej małej litery pomijany (`isCategoryTag`). Skutek przy synchronizacji: 25 kart dostaje nowy tekst źródła → `B2bCatalogSync` (witryna producenta) zastępuje opis tekstem B2B bez dopisku, tłumaczenie od nowa; 15 z nich ma dziś opis z uzupełnienia → wraca tekst B2B i karta znów czeka na uzupełnienie (opis uzupełniony zostaje w `replaced_description`). Dlatego wdrożenie razem z B (jedno wdrożenie).
2. `B2bDescriptionSupplement::undoCards` + `b2b:supplement-descriptions --account= --undo=ID… --reason= [--keep-b2b] [--apply]` — cofnięcie uzupełnienia wskazanych kart (jak `--undo-ungated`, bez warunku bramki); kolumna `norms` wraca do listy sprzed uzupełnienia tylko wtedy, gdy wciąż równa się liście uzupełnienia (stara wartość w śladzie `b2b_supplement_undone.norms`). Bez `--keep-b2b` próba `failed` i ponowne zlecenie (także przy wyczerpanym limicie prób); z `--keep-b2b` próba `kept_b2b` — synchronizacja nie zleca ponownie.

### B. Po commicie etapu 3 (ProductEnrichmentService, tylko obszar uzupełniania — uzgodnione z sesją etapu 3)
1. `fetchSupplementPages`: po `keepConfirmedCardPages` — `SourceIdentity::pageCodeRelation($product, $page, ManufacturerProfiles::for…)` = `foreign` → strona odpada (ślad `page`), bez względu na długość kodu; tylko profil z `longest_code_wins` (bolle; inne marki: `none`, zachowanie bez zmian). Kolejność: arbiter przed `supplementPageNamesCardVariant`.
2. `supplementB2bDescriptionInner`: tekst stron z internetu przez `NormListSanity::withoutTemplateAttributeRows` **przed modelem** (model nie widzi „EN166 Lens Marking PrB420”) — ten sam tekst idzie do `claimKey` i `SourceClaimGuard`.
3. Listy: `NormListSanity::clean($lists['norms'])` → `norms`; `certificates` += przeniesione; `dropped` += `pole norm: …` (te same przedrostki co w enrichProduct); pozycje list i zdania opisu z `PromptEcho::isEcho` wypadają (`dropped_claims`).
4. Bez nowych klas; `SupplementB2bDescriptionJob` bez zmian.

### Testy
- A1: `BolleConnectorTest::test_featured_description_without_lowercase_is_a_category_tag_and_is_skipped` (trzy prawdziwe znaczniki; zwykła lista cech z „FLASH” zostaje).
- A2: `SupplementB2bDescriptionJobTest` — cofnięcie z kolumną norm (EN 166 → null, kolumna zmieniona przez człowieka zostaje), `--keep-b2b` (wynik ostateczny, synchronizacja nie zleca), komenda (konto i powód wymagane, ponowne zlecenie mimo limitu prób, karta nieistniejąca pominięta).
- B: test przepływu uzupełniania z atrapą Http/modelu na prawdziwych kodach Bolle: odpadają TRYON←„…Tryon-EN-166…TRYONN20E.html”, BAXN←BAXPSI, B9V←FLASHV, IRIDPSI2←IRIDPSI2.5, PACCASR-4←PACCASR-2; **zostają** B9V←„…-B9V.html”, IRIDPSI2.5←strona IRIDPSI2.5 (wymienia też IRIDPSI2), ELATPR←strona ELATPR, COBPSI←strona COBPSI, NESPSN10E←„NESPSN10E S”; chipdip z „EN166 Lens Marking: PrB420” → opis bez EN 166 albo `kept_b2b`, lista norm pusta; „ATEX HAZARDOUS AREA / ATMOSPHERE GROUP” → poza `norms`, w `dropped`.

## 3. Pomiar na produkcji przed wdrożeniem (READ ONLY)
- M1 (zrobione): akapity wersalikami — w opisach 10 kart, w tekście B2B śladu uzupełnienia 15 kart (lista w logu).
- M2 (po etapie 3, eval kopii klas): `pageCodeRelation` na `b2b_supplement.web_source_urls` 185 kart (adres; tytułu nie mamy) → lista `foreign`. Próg: wszystkie przypadki #3 oznaczone, 0 wśród kart bez uwag w audycie.
- M3: `NormListSanity::clean` na `enrichment_payload.norms` 185 kart → co odpada/przenosi się. Oczekiwane: 23053 ATEX, opisowe dopiski ProBlu („EN 166 (oznaczenie PrB420…)”).

## 4. Naprawa danych (robi użytkownik; podgląd, potem --apply)
Kolejność: wdrożenie A+B → synchronizacja konta Bolle (#4) → cofnięcia → po kilku godzinach sprawdzenie wyników uzupełniania.
1. Cofnięcie z ponownym uzupełnieniem (przyczyny #1–#4 naprawione kodem): ProBlu 23025, 23026, 23030, 23032, 23033, 23045, 23046, 23056, 23057, 23058, 23059, 23065, 23069; 23053; strony obcych wariantów 23102, 23111, 22961, 10195, 22938; kategoria specshop 10270, 22932.
2. Cofnięcie z pozostawieniem tekstu B2B (`--keep-b2b`, treść wymyślona — #11): 10204, 10158, 10286, 10200, 10346, 23024, 23047, 23025 (jeśli nie w p.1 — jest w p.1), 10211, 10209, 10210, 10164, 10188, 10187, 22925, 22935, 23006, 23007, 22933, 22945, 10147 i pozostałe „opis_wymyślone_dane” z uzupełnieniem.
3. Ręcznie w panelu: nazwy 22956, 22957 („Filtr spawalniczy mineralny…”), 22965, 22966 („Szybka ochronna…”), 22954 („Wizjer zapasowy SUPERBLAST”), 10352 kategoria; PDF iri-s-ft-gb.pdf z 10226, 10227, 10283; dodatkowe zdjęcie procery z 10183.
4. `b2b:translate 4 --retry-rejected --id=10240 --id=10243 --id=10254 --id=10373`, `--redo-names --id=10251 --id=10255 --id=10256 --id=10257`.

## 5. Ryzyka
- Synchronizacja po A zmienia 25 kart naraz (15 traci opis uzupełniony do czasu ponownego uzupełnienia) — świadomie, opisy uzupełnione tych kart są w audycie błędne w 5 z 15.
- Arbiter na kodach krótkich i prefiksach (TRYON/TRYONN…, ELATPR/ELATPR2, IRIDPSI2/IRIDPSI2.5): tylko adres i tytuł, tylko profil bolle; M2 przed wdrożeniem.
- ProBlu: strona sklepu może podawać „EN166” zwykłym tekstem — wtedy EN 166 przejdzie; decyzja etapu 3 §2: tylko reguła „Lens Marking”, bez reguły „Screeners to nie ŚOI”.
- `--keep-b2b` zostawia kartę z krótkim opisem B2B (mniej treści, ale bez faktów spoza źródeł).

## 6. Recenzja planu (agent Plan, 08.10 wieczór) — do wprowadzenia przed kodem B

**Wysokie**
1. Arbiter etapu 3 nie widzi kodów Bolle: `SourceIdentity.php:103,:142` — klucz tylko ≥ `min_length` (domyślnie 4) i z cyfrą. TRYON, BAXN, BESM, FLASHV, BAXPSI, TRYOPSF bez cyfry, B9V 3 znaki → `pageCodeRelation` = none. Do wyboru: (a) uzgodnić z etapem 3 `bolle.code` z `min_length => 3` i kodami bez cyfry; (b) w `supplementPageNamesCardVariant` (:8636-8719) reguła „najdłuższy kod” na adresie i tytule, próg 3 zamiast `SUPPLEMENT_MIN_CODE_CHARS=5`. M2 (pomiar na `web_source_urls`) zrobić PRZED wyborem.
2. Kolejność §4 kłóci się z A1: 22945 (i pewnie 22965, 22958, 10353) jest w zbiorze A1 i na listach cofnięć — po synchronizacji opis przestaje być wynikiem uzupełnienia, `undoCards` go pominie, `kept_b2b` przepada (nowe `source_sha1`), a synchronizacja zostawia `norms`, `payload.norms`, `attributes` z uzupełnienia (`withReplacedDescription` B2bCatalogSync :4190 tylko dopisuje `replaced_*`). Poprawka: cofnięcia PRZED synchronizacją i/lub w `B2bCatalogSync::applyCardDetails` (:3388) — gdy zastępowany opis jest wynikiem uzupełnienia, zdjąć `SUPPLEMENT_PAYLOAD_KEYS`, przywrócić `previous_payload` i kolumnę norm jak w `undoOne` (plik wolny od etapu 3). Wstrzymać harmonogram synchronizacji #4 między wdrożeniem a cofnięciami.
3. Po A1 zalegające listy uzupełnienia trafią przy ponownym uzupełnieniu do `previous_payload` (Job :377) — późniejsze cofnięcie przywróci złe normy. Rozwiązuje to poprawka z p.2.
4. B9V 22958: brak w §4.1; porównać `norms` / `manufacturer_norms` / `payload.norms` (sonda p1: mn = EN166, EN175, EN379 z wiersza B9V Technical Sheet FLASH, kolumna = to samo z payload).

**Średnie**
5. A1: pozycja z samym znacznikiem (bez storedescription/storedetaileddescription) → pusty opis → `ownDescriptionIsGone` kasuje opis karty. Sprawdzić na 25 kartach (sonda p2: wszystkie 25 mają inne sekcje? — policzyć).
6. Reguła A1 za szeroka (np. „EN166 FT K N” bez małych liter) — zawęzić: jedna linia ≤ ~30 znaków (znaczniki mają 24–25) albo powtórzone słowo.
7. Ponowne uzupełnienie nie przyjdzie samo: po synchronizacji `source_description_hash=null` (B2bCatalogSync :1286), Bolle = B2bForeignLanguageSource → `consistentHashes` null; tłumaczenie nie zleca uzupełnienia. Dopisać ręczne `b2b:supplement-descriptions --account=4 --apply` po tłumaczeniach. Do uzupełnienia wraca 25 kart, nie 15.
8. `undoOne` przy `kept_b2b` nie ustawia `hosts_sha1`/`source_sha1` próby → przy zmianie hostów synchronizacja zleci ponownie. Ustawić `hosts_sha1 = $account->enrichmentHostsSha1()`, `source_sha1 = trace.source_sha1`.
9. `undoOne` zastępuje payload całym `previous_payload` — klucze dopisane później przez inne procesy giną; scalić: `previous_payload` + bieżące klucze spoza `SUPPLEMENT_PAYLOAD_KEYS`.
10. B2: `withoutTemplateAttributeRows` przed `sanitizePagesWithLlm` (:8336) i na `$pages` dla `enrichStructuredFieldsFromPages` (:8407, regex norm :5806) i `payloadFromExtraction` (:8446).
11. B3: czyścić też `attributes.normy_en`; `NormListSanity::clean` przed filtrem `$supported` (:8466); `PromptEcho` przed kontrolą długości (:8440).
12. B1: `CardCodeArbiter::forget()` w `finally` `supplementB2bDescription` (:8300); sprawdzić, czy brandKey „Bollé” trafia w `brand_keys`; M2 z `variant_gate` (PACCASR-4 może być sprzed bramki).
13. Kolumna `norms` po ponownym uzupełnieniu pusta (job jej nie pisze) — ewentualnie `products:restore-norms-column --price-list=21` po przeglądzie, z podglądem.
14. Listy §4: 23025 w p1 i p2 — wybrać p1; zamiast „pozostałe” jawnie 22937, 23068, 10216, 10331, 23048, 10353; dopisać 22997, 23063, 10363, 10272, 10275; nieprzypisane 10311, niepewne 10339, 10223. ProBlu rozważyć `--keep-b2b` zamiast ponownego uzupełnienia.
15. Pominięte (tłumacz/łącznik): 23019, 22983 pierwsza linia po angielsku; 10281 PL/EN; SPECTRUM „goggles→okulary” 10316, 10317, 10319, 10321, 10325; 10379 „carton→kartusz”; brak zdjęć 10185, 10327 (galeria Bolle — niezbadane); 10352 kategoria „Odzież” (presta_rewrite); 22925 — adres niesie nasz kod, strona PSSRUSP0862 (arbiter nie złapie).

**Niskie:** nie cofać w trakcie synchronizacji #4; brakujące testy A2 (dwa konta, `kept_b2b` przy zmienionych hostach, karta A1 z samym featureddescription).

**Kolejność naprawy danych wg recenzji:** wdrożenie → wstrzymanie synchronizacji #4 → cofnięcia → synchronizacja → tłumaczenia → `kept_b2b` dla kart A1 (z `--keep-b2b`) → ręczne `--apply` uzupełnienia → przegląd → ewentualnie `restore-norms-column` z podglądem.

## 7. Stan po poprawkach z recenzji (08.10 wieczór)

Wprowadzone (testy zielone, niezacommitowane):
- §6.6 — znacznik kategorii = jedna linia, bez małych liter, ≤ 30 znaków (`CATEGORY_TAG_MAX_CHARS`). §6.5 sprawdzone na produkcji (sonda p4): wszystkie 25 kart mają poza znacznikiem dwie zwykłe sekcje — żadna nie zostanie bez opisu.
- §6.8 — cofnięcie zapisuje w próbie `source_sha1` i `hosts_sha1` (obecna lista stron konta).
- §6.9 — cofnięcie scala: klucze wyniku uzupełnienia wracają z `previous_payload`, pozostałe klucze zostają w obecnej postaci.
- §6.2/§6.3 — `B2bDescriptionSupplement::withoutResult` (wspólne dla cofnięcia i synchronizacji): `B2bCatalogSync::withReplacedDescription` przy zastępowaniu uzupełnionego opisu zdejmuje listy, źródła, atrybuty i ślad (→ `b2b_supplement_undone`, powód „nowy tekst u dostawcy (synchronizacja B2B)”), a gałąź `$replaces` zeruje kolumnę norm, gdy pochodzi z listy uzupełnienia. Kolejność §4 nie musi już wyprzedzać synchronizacji.
- §6.1 — arbiter etapu 3 (niezacommitowany) obejmuje TRYON, FLASHV (litery ≥ 5) i B9V (z cyfrą ≥ 3); poza zasięgiem tylko BAXN (23111) → naprawa danymi. Bez zmiany kontraktu etapu 3.

Decyzja użytkownika 08.10: 13 kart ProBlu — cofnięcie z `--keep-b2b` (zostaje opis z B2B, bez ponownego uzupełnienia).

Czeka: commit etapu 3 → kod B (§2 B z poprawkami §6.10–6.12) → pomiar M2/M3 → commit + push → polecenia dla użytkownika.

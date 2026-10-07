# Opisy i zdjęcia kart z cenników z plików — przebudowa (07.10.2026)

Analiza: mapa kodu (4 agentów), pomiar produkcji tylko do odczytu (9 cenników ze slotem `file`), recenzja projektu
przez agenta Plan z weryfikacją w kodzie i na produkcji. Projekt po recenzji: usunięto przerosty (tabela faktów
z cytatem dla każdej wartości, sześć statusów etapów, edytor profili w panelu), dodano błędy znalezione w recenzji.

## 1. Co jest naprawdę źle

### 1.1. Budowa, nie pojedyncze reguły
- `app/Services/Enrichment`: 45 plików, 35,6 tys. linii. `ProductSearchIdentity` ma 8148 linii, 282 metody, ~540
  wyrażeń regularnych, 46 metod z nazwą marki (Ansell 33) i ~78 domen wpisanych w kod.
- `ProductEnrichmentService::enrichProduct` (~800 linii, :723–1530) robi wszystko w jednym przebiegu: szukanie
  (~17 ścieżek zapasowych × łańcuch 7–8 silników × 2 fazy), pobranie stron, bramki, filtr modelu, opis,
  uzupełnienie, drugą rundę, zdjęcia, PDF-y, zapis, pamięć SKU.
- Ostatnie 30 dni: 203 commity w tym katalogu, ok. 45% naprawia jeden przypadek (marka, model, sklep). Tożsamość
  karty 54, indeks 46, zapytania 30, silniki i zapory 29, normy i PDF 24, zdjęcia 22, treść 20, statusy 11.

Pięć przyczyn strukturalnych:
1. **Tożsamość zgadywana z tekstu.** Klucze z pliku (kod producenta, EAN, kod modelu) są zapisywane przy imporcie
   w `product_identifiers` z pochodzeniem (`PriceListImportService.php:743`), ale wzbogacanie ich nie czyta. EAN
   trafia tylko jako tekst do promptu (`ProductEnrichmentService.php:5698`).
2. **Model skleja kilka stron w prozę.** Dostaje do 5 stron naraz (`:5679`) i pisze opis bez źródła przy wartości;
   `confidence` ocenia sam. Dosłowne sprawdzenie kodów i poziomów norm (`sourceClaims`, `:6602`) działa tylko
   w ścieżkach B2B.
3. **Nic nie zostaje między etapami.** Tekstu źródeł nie zapisujemy (ślad = kroki i adresy). Poprawka opisu = nowe
   wyszukiwanie, często z innym wynikiem, kosztem silników i zapór.
4. **Nowy wynik zawsze nadpisuje stary.** Brak wersji, brak porównania; wymuszenie kasuje stare zdjęcia nawet wtedy,
   gdy nowe się nie pobrało.
5. **Brak miary.** Jakość ocenia się, gdy pracownik zauważy błąd. `EnrichmentTester51Test` mierzy tylko bramkę stron
   (43 pozycje z 3 cenników); nie ma miary opisu, wartości, zdjęć ani odsetków per cennik.

### 1.2. Błędy, które po cichu psują dane (potwierdzone w kodzie)
| # | Błąd | Miejsce | Skutek |
|---|---|---|---|
| B1 | Ponowny import cennika czyści `products.norms` | `PriceListImportService.php:243`, `:2479` (`'norms' => null`), `:601` → `update` (też mapa połączeń `:1893`) | normy znikają z karty, wyszukiwarki i wektorów; każdy import przelicza blob i embeddingi |
| B2 | Wymuszony przebieg bez nowego zdjęcia kasuje stare | `ProductEnrichmentService.php:1314` (`[]`) → `:1469` → `dropPreviousWebFiles :1650` | dobre zdjęcie znika (Ansell: zapora → pusta lista → kasowanie) |
| B3 | Dodanie do kolejki kasuje `enrichment_error` i `enrichment_trace` | `:180–184`, `:302–306` | ślad pochodzenia obecnego opisu ginie, zanim cokolwiek się stanie |
| B4 | Anulowanie / „zatrzymaj wszystko” zapisuje na karcie `failed` | `:5216–5230`, `EnrichProductJob.php:331`, `:~5288` | Coba: 137 z 155 kart „bez opisu” to przerwana partia, nie brak źródła; poprzedniego stanu nie ma gdzie przywrócić |
| B5 | Klasyfikator źródeł liczy tylko `primary_source_kind` | `PriceListDescriptionSources` | Coba: 559 opisów z coba.com pokazanych jako „bez źródła”; CEDERROTH 59 i ATG 23 z ręcznym linkiem do producenta jako „inne” |
| B6 | Pamięć SKU kopiuje opis bez śladu (`enrichment_trace = null`) | `:1731–1857` | błędny opis rozchodzi się na karty z tym samym kodem bez możliwości sprawdzenia |

### 1.3. Produkcja (odczyt 07.10.2026)
| Cennik | Karty | Bez opisu | Opis sprzed 16.09 | Opis od producenta* | Bez zdjęcia | Przebiegi na kartę (30 dni) |
|---|---|---|---|---|---|---|
| MAPA #1 | 147 | 0 | 0 | 140 | 1 | 7,1 — każda ≥4 udane |
| SECURA #2 | 42 | 4 | 0 | 25 | 9 | 6,2 |
| AJ GROUP #3 | 165 | 0 | 2 | 152 | 0 | 8,4 |
| Ansell #5 | 683 | 17 | 44 | 559 | 71 | 9,1 (rekord 43) |
| CEDERROTH #12 | 84 | 0 | 13 | 69 (z ręcznym linkiem) | 0 | 1,7 (77 partii po jednej karcie) |
| Coba #14 | 869 | 155 | 604 | ~654 (559 po poprawce B5) | 153 (140 bez opisu) | 2,6 |

\* po uwzględnieniu B5. ARTRA #8, ATG #9 i Bolle #21 mają opisy z B2B — poza zakresem.

Wnioski:
- **Powtarzanie nie naprawia.** Ansell: 6192 pozycje partii w 30 dniach, 1713 anulowanych w 14 dniach, partia #487
  szła 20,7 h. Karty opisywane 30–43 razy nadal są „ręcznie/błąd”: brakuje dla nich źródła, a nie kolejnej próby.
- **Dobre cenniki opisuje się w kółko.** MAPA i AJ GROUP mają 92–95% opisów od producenta, a każdą kartę opisano
  ≥4 razy w miesiąc — czas modelu i ryzyko podmiany dobrego opisu gorszym.
- **Coba wygląda gorzej, niż jest.** Główna luka to 155 kart po przerwanej partii (B4) i 604 opisy sprzed poprawek
  jakości (ale z coba.com). Przeliczenie źródeł (B5) nie wymaga modelu.
- **EAN w pliku ma tylko Ansell** (678/683). W MAPA, Coba, CEDERROTH, SECURA i AJ GROUP SKU = kod producenta — to
  wystarczający klucz do dokładnego dopasowania strony.
- „Bez normy” nie jest miarą jakości dla mat Coba i apteczek CEDERROTH — tam brak normy EN bywa naturalny.
- Funkcja „strony cennika” z 05.10 nie jest używana przy żadnym cenniku.

## 2. Rozwiązanie

Zasada: **tożsamość z kluczy → zapisane źródła → opis z zapisanych źródeł z dowodem dla wartości krytycznych →
porównanie ze starym → publikacja albo przegląd.** Zakres: karty ze slotem `file`. Ścieżki B2B bez zmian.
Kontrakt `enrichment_payload` (czyta go 53 plików: Presta, wyszukiwarka, wektory) zostaje — nowe dane dochodzą
jako nowe klucze.

### 2.1. Profil producenta (konfiguracja w gicie)
Scalenie tego, co już jest w `config/enrichment.php` (`manufacturer_domains`, `manufacturer_only_sources`,
`manufacturer_catalogs`, `blocked_source_hosts`), `manufacturer_sites` i indeksie `catalog_pages`, w jeden wpis na
markę. Plik w gicie, nie edytor w panelu — wersjonowany razem z kodem, powtarzalny przy porównaniach.

```php
'coba' => [
    'brand_keys'        => ['coba', 'coba-europe'],
    'hosts'             => ['coba.com'],
    'only_manufacturer' => false,
    'code'              => ['normalize' => 'upper_alnum', 'model_regex' => '/^([A-Z]+)\d/'], // grupa modelu
    'identity_in'       => ['url', 'title', 'jsonld', 'table'], // gdzie kod daje werdykt „twardy”
    'reader'            => false,      // r.jina.ai za zaporą
    'catalog_pdf'       => [],
    'image_portal'      => null,       // np. Ansell Asset Bank
    'resolver'          => null,       // klasa PHP dla reguł, których nie da się opisać danymi
],
```
- MAPA: mapa-pro.pl/.com, tylko producent, kod 8 cyfr. AJ GROUP: pros.pl, sportpros.pl, bemoregreen.eu, tylko
  producent, outlet.pros.pl zablokowany. SECURA: katalog PDF 2026. CEDERROTH: cederroth.com, kod w tytule i tabeli.
- Ansell: `resolver = AnsellCodes` — metody Ansella przeniesione z `ProductSearchIdentity` (dekodowanie serii,
  modelu, koloru, wersje językowe), `reader = true`, `image_portal = assetbank`. Ansell zawsze będzie potrzebował
  kodu; ważne, żeby ten kod był w jednym miejscu, a nie rozsiany po 8 tys. linii.

### 2.2. Tożsamość z kluczy
- Przed jakąkolwiek wyszukiwarką: dokładny kod / EAN / kod modelu z `product_identifiers` (i SKU) szukany w
  `catalog_pages` hostów z profilu, potem `identity_in` na pobranej stronie (kod w adresie, tytule, JSON-LD
  `sku/mpn/gtin`, tabeli wariantów).
- Werdykt: **twardy** (kod/EAN na stronie z profilu), **miękki** (marka + nazwa), **brak**. Opis publikuje się
  automatycznie tylko przy twardym. Miękki → przegląd.
- Kolejność źródeł: profil producenta → strony cennika → katalog PDF z profilu → otwarta sieć wyłącznie jako
  kandydat do przeglądu (decyzja właściciela, p. 4).

### 2.3. Zapisane źródła
- Tekst użytych stron i PDF-ów na dysku: `storage/app/sources/{sha256}.txt.gz` (deduplikacja — jedna strona Coba
  obsługuje kilka kart), w bazie mała tabela `product_source_documents`: product_id, url, final_url, host, sha256,
  fetched_at, identity_verdict + powód, rola (opis/zdjęcie/normy). Wyciągnięte tabele i ramka norm obok tekstu.
- Zapisywane najpierw jako skutek uboczny zwykłego przebiegu (bez zmiany zachowania). Daje: opis bez ponownego
  szukania, audyt „skąd to zdanie”, porównanie zmian kodu na prawdziwych danych bez sieci.
- Retencja: ostatnia wersja na (karta, adres) + wersje użyte w opublikowanym opisie.

### 2.4. Opis z zapisanych źródeł
- Uogólnić istniejące `describeFromB2bSources` (`:5998`) do `describeFromStoredSources(Product, list<SourceDoc>)`:
  teksty na wejściu, bez wyszukiwania, dosłowne kody norm, filtr list, powód odrzucenia. To gotowy, sprawdzony na
  B2B wzorzec.
- Jedno wywołanie modelu na model wyrobu (jak dziś), źródło główne = strona z twardą tożsamością. Drugie źródło
  tylko do uzupełnienia brakujących wartości, nigdy do sprzecznych.
- **Dowód tylko dla wartości krytycznych** w `enrichment_payload.evidence`: norma z poziomami, klasa (S3, kat. III),
  materiał główny, zakres rozmiarów, certyfikat z numerem jednostki, wymiary i grubość (maty). Wpis
  `{value, quote, source_sha256, status: explicit|inferred}`; kod sprawdza dosłowną obecność kodu lub liczby
  w zapisanym tekście (jak `sourceClaims`). Brak = brak wpisu i luka widoczna na karcie (wymóg explicit / inferred /
  missing z CLAUDE.md). Cytat dla każdego zdania — świadomie nie: 3–5× więcej wywołań, a cytatu w obcym języku
  wobec polskiej wartości i tak nie da się sprawdzić automatem.
- Atrybuty (`BhpAttributeNormalizer`) i listy norm/materiałów brane z wartości z dowodem, nie z prozy.
- `SourceClaimGuard` w ścieżce cenników z plików dopiero po dopisaniu angielskiego (i niemieckiego, francuskiego)
  słownictwa — dziś ma tylko polskie, więc wycinałby poprawne zdania ze stron coba.com czy cederroth.com.

### 2.5. Opis per model
Klucz grupy z profilu (`model_regex`) albo z istniejącego łączenia rozmiarów. Opis i dowody wspólne dla modelu,
rozmiar, kolor i EAN dopisuje kod z pliku. Coba: 869 kart → ok. 120–375 modeli — wielokrotnie mniej wywołań
i spójne opisy rozmiarów. Zastępuje pamięć SKU (B6) współdzieleniem z pochodzeniem.

### 2.6. Zdjęcia osobno
- Kandydaci tylko ze stron z twardą tożsamością (JSON-LD, og:image, galeria) albo z portalu z profilu. Obecny
  weryfikator wizyjny zostaje jako sito, bez rozbudowy.
- `product_images` + `page_url` i `verdict` (kod w adresie / obraz strony z twardą tożsamością / wizja z pewnością).
- Zdjęcie zastępowane tylko lepszym; nowy opis nie kasuje zdjęć (B2). Akcja „odśwież zdjęcia” bez opisu.

### 2.7. Wersje i ochrona dobrych danych
- `product_description_versions`: opis, payload, ślad, powód, wynik (tożsamość, liczba wartości krytycznych
  z dowodem). Nowa wersja gorsza w którejkolwiek miarze → przegląd zamiast podmiany. Przywracanie w panelu.
- Partie „Pobierz ponownie” domyślnie pomijają karty z opisem od producenta; pojedyncze wymuszenie wymaga powodu.
  Bez twardej blokady — zła strona producenta albo nowa wersja reguł muszą dać się poprawić.

### 2.8. Przegląd i miara
- Status: zostaje `enrichment_status`; dochodzi `review_reason` (null albo powód: tożsamość miękka, wersja gorsza,
  sprzeczne wartości, brak wartości krytycznej) i `completeness` w payloadzie. Pozycja partii dostaje
  `previous_status` — anulowanie przywraca stan sprzed partii (B4).
- Kategorie kontraktu (`EnrichmentDescriptionTemplates.php:208`) uzupełnione o maty i pierwszą pomoc, żeby wymagane
  wartości per kategoria miały sens dla Coba i CEDERROTH.
- Zakładka „Z pliku”, per cennik: filtr „do przeglądu”, link do zapisanego źródła, lista wartości bez dowodu, akcje
  zatwierdź / wskaż adres / odrzuć. Liczniki: % twardej tożsamości, % kompletnych (wg kategorii), % ze zdjęciem,
  do przeglądu, wiek opisów, przebiegi na kartę.
- Zestaw odniesienia: 15–20 kart zatwierdzonych na cennik pilotażowy, porównanie na zapisanych źródłach bez sieci,
  zapadka jak `EnrichmentTester51Test` (progi tylko w górę). Każda zmiana łańcucha: porównanie → wdrożenie.

## 3. Kolejność

**Etap 0 — naprawy bez przebudowy (jedno wdrożenie, kilka dni):**
1. B1: import nie czyści `norms` + test ponownego importu; komenda odtwarzająca `products.norms` z
   `enrichment_payload.norms` (logika `writeNormsColumn`, bez modelu, podgląd → `--apply`).
2. B2: pusta lista nowych zdjęć nie kasuje starych.
3. B3 + B4: kolejkowanie nie kasuje śladu; `previous_status` w pozycji partii, anulowanie i „zatrzymaj wszystko”
   przywracają poprzedni stan (Coba: 137 kart wraca do „brak opisu”, nie „błąd”).
4. B5: klasyfikacja źródła z `source_urls` i z ręcznego linku do domeny producenta (bez modelu).
5. `sourceClaims` (dosłowne kody i poziomy norm) w głównej ścieżce; `SourceClaimGuard` tylko jako zapis do śladu.
6. „Pobierz ponownie” domyślnie pomija karty z opisem od producenta, wymuszenie z powodem.

**Etap 1 — fundament:** zapisane źródła (2.3) jako skutek uboczny, profil producenta (2.1) dla MAPA i CEDERROTH,
tożsamość z kluczy (2.2), `describeFromStoredSources` + `evidence` (2.4), wersje (2.7), a po decyzjach z 07.10 także
`review_reason` i lista „do przeglądu” dla handlowców (2.8, wersja minimalna). Tryb cienia na dwóch
pilotach: **CEDERROTH** (tożsamość i źródła, 84 karty) i **MAPA** (rękawice z poziomami EN 388, 140/147 od
producenta — gotowy punkt odniesienia). Cień korzysta z zapisanych źródeł, pisze tylko do nowych tabel i plików
(zapis do `products` uruchomiłby reindeks).

**Etap 2 — Coba, AJ GROUP, SECURA:** profile, opis per model (2.5), ponowny opis tylko kart naprawdę bez źródła
(155 po B4) i 604 starych po porównaniu próbki.

**Etap 3 — przegląd i miary** w „Z pliku” (2.8), zestaw odniesienia z pierwszych przeglądów, zdjęcia osobno (2.6).

**Etap 4 — Ansell:** `resolver` z metodami Ansella przeniesionymi z `ProductSearchIdentity`, czytnik, Asset Bank.
Karty z 30+ przebiegami → przegląd z kandydatami, nie kolejne próby.

**Etap 5 — przełączenie** cenników z plików na nowy łańcuch po przejściu progów. Stara ścieżka zostaje dla B2B;
reguły markowe usuwane z `ProductSearchIdentity` dopiero po przełączeniu danej marki.

Ograniczenia techniczne: CLI na serwerze 128 MB — porównania i audyty po `chunkById`, bez kolumn tekstowych
w zapytaniach, teksty z dysku po jednym. Model lokalny: najwyżej 4 zapytania naraz.

## 4. Decyzje właściciela (07.10.2026)
1. **Automat zapisuje zawsze, przegląd po fakcie.** Tożsamość miękka / brak kodu nie blokuje zapisu — ustawia
   `review_reason` i karta trafia na listę „do przeglądu”. Wyjątek z zasady „nie nadpisuj dobrych danych gorszymi”
   (CLAUDE.md): nowa wersja gorsza od opublikowanej (tożsamość, liczba wartości krytycznych z dowodem) nie zastępuje
   jej, tylko czeka jako propozycja w przeglądzie.
2. **Sklep z kodem wyrobu zapisuje się automatycznie;** sklep bez kodu też (pkt 1), ale z `review_reason`.
3. Plik danych od producentów — otwarte (rozmowa z Coba/Ansell/SECURA po stronie właściciela).
4. **Opis wspólny dla modelu** (wszystkie rozmiary/wymiary); rozmiar, kolor, EAN dopisuje kod z cennika.
5. **Przegląd: handlowcy, kilkanaście kart dziennie** — lista „do przeglądu” potrzebna od etapu 1, nie od 3.
6. Kolejność: najpierw naprawy (etapy 1–2), potem pełne ponowne pobranie cenników z wieloma błędami w całości.
   Pomiar „przed” dla Coby: `Coba_karty_z_cudzych_stron_2026-10-07.csv` (23 karty z cudzej strony, 57 ze zdjęciem
   innego koloru, 14 bez źródła) — po pełnym pobraniu porównanie na tych samych kartach.

## 5. Stan
- Etap 0: wdrożony 07.10.2026 (30d73e8, build c1a0501); `products:repair-cancelled-status` (151 kart) i
  `products:restore-norms-column` (146 kart) wykonane na produkcji.

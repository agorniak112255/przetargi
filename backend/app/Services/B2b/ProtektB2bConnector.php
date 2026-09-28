<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bAccount;
use App\Models\ProductDocument;
use App\Models\ProductIdentifier;
use App\Support\WithdrawnProductNote;
use DOMNode;
use DOMXPath;
use RuntimeException;

/**
 * protekt.pl — witryna producenta bez logowania i bez API. Karta w katalogu = numer katalogowy („Nr kat.”,
 * w mikrodanych itemprop="gtin"); Protekt powtarza ten numer na kilku adresach (podstronach).
 *
 * Podstrony jednego numeru (sprawdzone na całej witrynie 28.09.2026): kolory (zwykle w tej samej cenie), rozmiary
 * („Rozmiar”, „Rozmiar (Szelki bezpieczeństwa)”: szelki P-61C M-XL 289 zł, XXL 320 zł) i długości („Długość [m]”,
 * „Długość z zatrzaśnikami”: linka LB 101 1.4 / 1.6 / 1.9 / 2 m za 72 / 73 / 84 / 85 zł). Decyzja użytkownika
 * 28.09.2026 (zastępuje zasadę z 15.09.2026): wyrób, którego rozmiary mają różne ceny, to JEDNA karta. Dlatego
 * łącznik najpierw czyta całą mapę strony (B2bListProgressAware), grupuje podstrony po numerze katalogowym i podaje
 * jeden wyrób z pozycjami (members) = rozmiary/długości, każda z własną ceną; cena karty = najniższa cena rozmiaru,
 * ceny pozostałych — wiersze rozmiarów karty. Rozmiar to wybór podstrony z siatki „Dostępne warianty” o nagłówku
 * „Rozmiar…” albo „Długość…”, dosłownie ze strony. Inne różnice (wersja, średnica, zatrzaśniki) nie są rozmiarami:
 * taka podstrona w innej cenie zostaje przy pierwszej cenie i trafia do podsumowania rozbieżności — jak dotąd.
 *
 * Kolor zmieniający cenę (drabina DS 242: szara 649 zł, czarna 659 zł) to inny wyrób — osobna karta z kodem
 * „numer / kolor” (dopisek nasz, kolor dosłownie ze specyfikacji), jak dotąd. Kolory w tych samych cenach są jedną
 * kartą z listą kolorów. Do 28.09.2026 różna cena bez innego koloru niż „biały” (długości linek) też tworzyła kartę
 * „numer / kolor” — takie karty zostają (synchronizacja ich nie przepina ani nie kasuje).
 *
 * Ceny: strona podaje wyłącznie cenę katalogową i pisze wprost „Cena netto”. Cena zakupu powstaje z niej
 * przez rabat z konfiguracji konta (b2b_discount_rules). Karta, dla której żadna reguła nie pasuje, jest
 * pomijana z powodem — cena katalogowa zapisana jako cena zakupu zawyżyłaby każdą wycenę, a po cichu
 * przyjęty rabat 0% byłby zmyśleniem danych handlowych.
 *
 * Karta bez numeru katalogowego (np. seria BW100SCF) też jest pomijana z powodem: bez numeru nie ma czym
 * jej powiązać z katalogiem, a sklejanie identyfikatora z nazwy byłoby wymyślaniem kodu, którego producent
 * na karcie nie podał.
 */
final class ProtektB2bConnector implements B2bConnector, B2bDocumentSource, B2bListProgressAware, B2bManufacturerSite, B2bPublicSite, B2bRunSummaryAware, B2bShopFieldNormSource, B2bShopFieldSource, B2bSizePriceSource
{
    /** Co tyle stron komunikat postępu (sygnał życia przebiegu przed pierwszym zapisem). */
    private const PROGRESS_EVERY_PAGES = 250;

    /** Nagłówki siatki wariantów, których wybór jest rozmiarem wyrobu (a nie innym wyrobem). */
    private const SIZE_HEADING = '/^(?:rozmiar|długość)/iu';

    private int $total = 0;

    /** @var array<string, string> numer katalogowy => opis rozbieżności (jeden wpis na numer) */
    private array $priceConflicts = [];

    /** wyroby z rozmiarami w różnych cenach (jedna karta, cena od najniższej) */
    private int $multiPriceProducts = 0;

    /** @var list<string> numery z rozmiarami bez ceny (te rozmiary poza kartą) */
    private array $sizesWithoutPrice = [];

    /** @var list<string> podstrony numeru w innej walucie niż karta (poza kartą) */
    private array $otherCurrency = [];

    /** @var (callable(string): void)|null */
    private $listProgress = null;

    public function __construct(
        private readonly ProtektB2bClient $client,
        private readonly B2bDiscountRuleResolver $discounts,
    ) {}

    public static function key(): string
    {
        return 'protekt';
    }

    public static function label(): string
    {
        return 'PROTEKT';
    }

    public static function host(): string
    {
        return ProtektB2bClient::HOST;
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new self(new ProtektB2bClient($delayMs), new B2bDiscountRuleResolver($account->id));
    }

    /** Witryna jest publiczna — nie ma się gdzie logować. */
    public function login(): void {}

    public function onListProgress(callable $callback): void
    {
        $this->listProgress = $callback;
    }

    /**
     * Cała mapa strony przed pierwszym produktem: podstrony jednego numeru bywają w różnych miejscach mapy, a wyrób
     * z rozmiarami musi przyjść jako jeden produkt ze wszystkimi rozmiarami (members).
     */
    public function products(): iterable
    {
        $this->priceConflicts = [];
        $this->multiPriceProducts = 0;
        $this->sizesWithoutPrice = [];
        $this->otherCurrency = [];

        $urls = $this->client->sitemapProductUrls();
        $this->total = count($urls);
        $categories = $this->client->categorySlugs();

        // Cała witryna w pamięci przebiegu: 6412 adresów (28.09.2026) to ok. 66 MB razem z gotowymi produktami — przebieg
        // podnosi limit do 512 MB (B2bAccountSyncRunner::raiseMemoryLimit).
        $pages = [];
        foreach ($urls as $index => $url) {
            $pages[] = $this->pageFor($url, $index, $categories);
            $read = $index + 1;
            if ($read % self::PROGRESS_EVERY_PAGES === 0 || $read === count($urls)) {
                $this->progress('Strony produktów Protekt: '.$read.'/'.count($urls));
            }
        }

        $products = $this->grouped($pages);
        unset($pages);
        $this->total = count($products);

        foreach ($products as $product) {
            yield $product;
        }
    }

    public function totalProducts(): int
    {
        return $this->total;
    }

    /** Witryna nalezy do tej marki — tylko jej karty wolno nadpisac opisem stad. */
    public static function ownBrand(): string
    {
        return 'PROTEKT';
    }

    /** Normy / Norma = „EN 355” — jeden wiersz na normę (23.09.2026: 1760 kart). */
    public static function normShopFieldNames(): array
    {
        return ['Norma'];
    }

    public function manufacturer(B2bRemoteProduct $product): string
    {
        return 'PROTEKT';
    }

    /**
     * Nazwa karty bez rozmiaru z nagłówka strony; null, gdy nagłówek rozmiaru nie podaje.
     *
     * Każdy rozmiar ma u Protektu osobny adres z tym samym numerem katalogowym (P-50mX, AB 150 21: „rozmiar S”,
     * „rozmiar M - XL”, „rozmiar XXL”), a karta jest jedna na numer — nazwa z rozmiarem pierwszego adresu mówiła
     * o jednym rozmiarze karty obejmującej wszystkie (24.09.2026: 231 kart; zapytanie „P-50mX rozmiar M-XL” trafiało
     * na kartę „… rozmiar S”). Zapisy ze strony: „- rozmiar S”, „, rozmiar M”, „- roz. M-XL”, „- rozmiar szelek M-XL”
     * (zestawy); rozmiar szelek „M - XL” to jeden rozmiar, nie zakres. Samo „- rozmiar” na końcu (P-51E: strona
     * bez wartości) też znika. Tylko rozmiary literowe — liczba („rozmiar 52-63” hełmu) to cecha wyrobu, a nie
     * podstrona. Reszta nagłówka zostaje dosłownie.
     */
    public static function cardNameWithoutSize(string $name): ?string
    {
        $size = '(?:[2-7]XL|X{0,4}L|X{0,3}S|M)';
        $stripped = preg_replace(
            '/(?:\s*[-,])?\s*\b(?:rozmiar(?:\s+szelek)?|roz\.)(?:\s*'.$size.'(?:\s*-\s*'.$size.')?(?![\p{L}\p{N}])|\s*$)/iu',
            '',
            $name,
            1,
            $count,
        );
        if ($stripped === null || $count === 0) {
            return null;
        }
        $stripped = trim(preg_replace('/\s{2,}/u', ' ', $stripped) ?? $stripped, " \t\n\r\0\x0B-,");

        return $stripped !== '' ? $stripped : null;
    }

    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        if (($product->raw['status'] ?? null) !== 'ok') {
            throw new RuntimeException((string) ($product->raw['reason'] ?? 'strona produktu nieodczytana'));
        }

        $priceText = (string) ($product->raw['price_text'] ?? '');
        if ($priceText === '') {
            // Karty „zapytaj o dostępność” nie mają ceny — to normalny stan, nie błąd.
            return null;
        }
        $amount = self::money($priceText);
        if ($amount === null) {
            throw new RuntimeException('nie udało się odczytać ceny „'.$priceText.'”');
        }
        if ($amount <= 0) {
            return null;
        }

        $currency = strtoupper(trim((string) ($product->raw['currency'] ?? '')));
        if ($currency === '') {
            throw new RuntimeException('strona nie podała waluty ceny („'.$priceText.'”)');
        }

        // Rabat rozstrzygnięty raz na wyrób przy grupowaniu (products()) — ceny rozmiarów potrzebują go przed zapisem.
        // Cena z raw to najtańszy rozmiar (members[].price niesie ceny wszystkich).
        $discount = $product->raw['discount_percent'] ?? null;
        if (! is_float($discount) && ! is_int($discount)) {
            throw new RuntimeException(
                'brak reguły rabatowej (nr kat. '.(string) ($product->raw['catalog_no'] ?? $product->sku)
                .($product->category !== null ? ', kategoria '.$product->category : '').')'
            );
        }

        return self::discounted($amount, (float) $discount, $currency);
    }

    private static function discounted(float $amount, float $discountPercent, string $currency): B2bRemotePrice
    {
        return new B2bRemotePrice(
            net: round($amount * (1 - $discountPercent / 100), 2),
            base: $amount,
            discountPercent: $discountPercent,
            currency: $currency,
        );
    }

    /**
     * Opis dosłownie z karty producenta: ostrzeżenie o wycofaniu i cechy szczególne — czyli proza, której nie da
     * się rozpisać na pary „nazwa: wartość”. Norm i specyfikacji technicznej tu nie ma: te same dane podaje
     * shopFields() jako wiersze tabelki, gdzie pojedyncze oznaczenie („EN 361”) da się odczytać osobno.
     * Niczego nie dopisujemy — karta Protektu nie ma pola opisu ciągłego, więc opis bywa pusty. Pusty opis
     * niczego nie nadpisuje (B2bCatalogSync::applyCardDetails), więc karta zostaje z tym, co już ma.
     */
    public function description(B2bRemoteProduct $product): string
    {
        $raw = $product->raw;
        $sections = [];

        $withdrawn = (string) ($raw['withdrawn'] ?? '');
        if ($withdrawn !== '') {
            // Jedyny trwały ślad wycofania — panel zapytania czyta go stąd (WithdrawnProductNote::parse).
            $sections[] = WithdrawnProductNote::forDescription($withdrawn);
        }

        $features = $raw['features'] ?? [];
        if ($features !== []) {
            $sections[] = "Cechy szczególne:\n".implode("\n", array_map(
                static fn (string $item): string => '- '.$item,
                $features,
            ));
        }

        return implode("\n\n", $sections);
    }

    /**
     * Tabelka z karty producenta w układzie ze strony: dane handlowe („Nr kat.”, „Indeks”, EAN, stan
     * magazynowy), normy i specyfikacja techniczna. Wszystko pochodzi ze strony pobranej przy liście
     * produktów ($raw), więc karta nie wysyła do Protektu żadnego dodatkowego zapytania.
     *
     * @return list<B2bRemoteShopField>
     */
    public function shopFields(B2bRemoteProduct $product): array
    {
        $raw = $product->raw;
        if (($raw['status'] ?? null) !== 'ok') {
            return [];
        }

        $fields = [];
        $commercial = [
            'Nr katalogowy' => (string) ($raw['catalog_no'] ?? $product->sku),
            'Indeks producenta' => (string) ($raw['supplier_index'] ?? ''),
            'EAN' => (string) ($raw['ean'] ?? ''),
            'Dostępność' => (string) ($product->availability ?? ''),
        ];
        foreach ($commercial as $label => $value) {
            if (trim($value) !== '') {
                $fields[] = new B2bRemoteShopField('Informacje handlowe', $label, $value);
            }
        }

        // Każda norma osobnym wierszem, a nie jedną sklejoną wartością: przy dopasowaniu do wymagania
        // przetargu liczy się pojedyncze oznaczenie („EN 361”), więc musi dać się je odczytać osobno.
        // Powtórzona etykieta jest w karcie dozwolona (B2bRemoteShopField).
        foreach ($raw['norms'] ?? [] as $norm) {
            $fields[] = new B2bRemoteShopField('Normy', 'Norma', (string) $norm);
        }

        // specRows() skleja pary w „etykieta: wartość” i wstawia gołe nazwy podzespołów — rozkładamy to
        // z powrotem, a nazwa podzespołu staje się sekcją kolejnych wierszy, żeby „Materiał” z dwóch
        // podzespołów nie wyglądał w tabelce na jedną cechę. Kolejność ze strony zostaje bez zmian.
        $section = 'Specyfikacja techniczna';
        foreach ($raw['spec'] ?? [] as $row) {
            $parts = explode(': ', (string) $row, 2);
            if (count($parts) < 2) {
                $section = trim((string) $row);

                continue;
            }
            $fields[] = new B2bRemoteShopField($section, trim($parts[0]), trim($parts[1]));
        }

        return $fields;
    }

    public function image(B2bRemoteProduct $product): ?B2bRemoteImage
    {
        $url = (string) ($product->raw['image_url'] ?? '');
        if ($url === '') {
            return null;
        }
        if (str_starts_with($url, '/')) {
            $url = ProtektB2bClient::BASE.$url;
        }

        $file = $this->client->fileBytes($url);
        if ($file['bytes'] === '' || ! str_starts_with($file['mime'], 'image/')) {
            return null;
        }

        return new B2bRemoteImage(bytes: $file['bytes'], mime: $file['mime'], sourceUrl: $url);
    }

    /**
     * @return list<B2bRemoteDocument>
     */
    public function documents(B2bRemoteProduct $product): array
    {
        $documents = [];
        foreach ($product->raw['documents'] ?? [] as $file) {
            $documents[] = new B2bRemoteDocument(
                title: (string) $file['title'],
                sourceUrl: (string) $file['url'],
                kind: (string) $file['kind'],
            );
        }

        return $documents;
    }

    /**
     * @return array{bytes: string, mime: string}
     */
    public function documentBytes(B2bRemoteDocument $document): array
    {
        return $this->client->fileBytes($document->sourceUrl);
    }

    /**
     * @return list<string>
     */
    public function runSummary(): array
    {
        $this->discounts->flushCounters();

        if (! $this->discounts->hasRules()) {
            return ['Konto nie ma reguł rabatowych — wszystkie karty pominięte. Uzupełnij rabaty w konfiguracji konta.'];
        }

        $lines = ['Reguły rabatowe: '.$this->discounts->matchedCount().' kart z rabatem'];
        if ($this->discounts->missedCount() > 0) {
            $lines[] = 'Kart bez pasującej reguły: '.$this->discounts->missedCount().' — sprawdź listę pominiętych.';
        }
        if ($this->multiPriceProducts > 0) {
            $lines[] = $this->multiPriceProducts.' wyrobów z rozmiarami w różnych cenach — jedna karta, cena karty = najniższa '
                .'cena rozmiaru, ceny rozmiarów w tabeli rozmiarów karty';
        }
        if ($this->sizesWithoutPrice !== []) {
            $lines[] = 'Rozmiary bez ceny (poza kartą): '.self::listing($this->sizesWithoutPrice);
        }
        if ($this->otherCurrency !== []) {
            $lines[] = 'Podstrony numeru w innej walucie niż karta (pominięte): '.self::listing($this->otherCurrency);
        }
        if ($this->priceConflicts !== []) {
            $lines[] = 'Ten sam numer katalogowy z różnymi cenami na różnych adresach (zapisano pierwszą): '
                .implode('; ', array_slice(array_values($this->priceConflicts), 0, 20))
                .(count($this->priceConflicts) > 20 ? ' i '.(count($this->priceConflicts) - 20).' więcej' : '');
        }

        return $lines;
    }

    /**
     * Jedna podstrona z mapy: pominięta (produkt z powodem) albo odczytane pola. raw — pola karty, z których korzysta
     * tylko podstrona wiodąca wyrobu (opis, tabelka, pliki, zdjęcie).
     *
     * @param  array<string, string>  $categories
     * @return array<string, mixed>
     */
    private function pageFor(string $url, int $order, array $categories): array
    {
        $category = self::categoryOf($url, $categories);

        try {
            $page = $this->client->productPage($url);
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return ['order' => $order, 'skipped' => self::skipped($url, $category, 'nie udało się pobrać strony produktu: '.$e->getMessage())];
        }

        if ($page['status'] !== 'ok' || ! isset($page['html'])) {
            return ['order' => $order, 'skipped' => self::skipped($url, $category, 'karta nie istnieje (martwy wpis w mapie strony)')];
        }

        $xpath = self::dom($page['html']);
        $name = self::text($xpath->query('//h1['.self::classPredicate('product-desc__name').']')->item(0));
        $catalogNo = self::text($xpath->query('//*[@itemprop="gtin"]')->item(0));

        if ($catalogNo === '') {
            return ['order' => $order, 'skipped' => self::skipped($url, $category, 'karta bez numeru katalogowego'.($name !== '' ? ' („'.$name.'”)' : ''))];
        }
        if ($name === '') {
            return ['order' => $order, 'skipped' => self::skipped($url, $category, 'karta bez nazwy produktu')];
        }

        $priceText = self::text($xpath->query('//*[@itemprop="price"]')->item(0));
        $currency = strtoupper(self::text($xpath->query('//*[@itemprop="priceCurrency"]')->item(0)));
        $amount = self::money($priceText);

        return [
            'order' => $order,
            'url' => $url,
            'category' => $category,
            'catalog_no' => $catalogNo,
            'name' => $name,
            'price_text' => $priceText,
            // podstrona z ceną do karty: kwota > 0 i waluta; pozostałe price() rozlicza jak dotąd (brak ceny / błąd)
            'amount' => $amount !== null && $amount > 0 && $currency !== '' ? $amount : null,
            'currency' => $currency,
            'colour' => self::ownColour($xpath),
            'colours' => self::colourSummary($xpath),
            'variants' => self::ownVariants($xpath, $url),
            'ean' => self::text($xpath->query('//*[@itemprop="gtin13"]')->item(0)),
            // „Indeks” producenta — trzymamy do wglądu, kartę identyfikuje numer katalogowy.
            'supplier_index' => self::text($xpath->query('//*[@itemprop="sku"]')->item(0)),
            'availability' => self::availability($xpath),
            'raw' => [
                'norms' => self::texts($xpath, '//*['.self::classPredicate('product-desc__norms--bold').']'),
                'spec' => self::specRows($xpath),
                'withdrawn' => self::withdrawnNote($xpath),
                'documents' => self::documentList($xpath),
                'features' => self::texts($xpath, '//*['.self::classPredicate('product-desc__specific--warn').']'),
                'image_url' => self::imageUrl($xpath),
            ],
        ];
    }

    /**
     * Podstrony zgrupowane w wyroby, w kolejności mapy strony (miejsce wyrobu = jego pierwsza podstrona).
     *
     * Numer katalogowy (+ waluta ceny) to jeden wyrób. Podstrony bez ceny nie wchodzą do karty numeru,
     * który ma ceny (liczone w podsumowaniu); numer bez żadnej ceny daje jeden produkt z pierwszej podstrony — price()
     * rozlicza go jak dotąd (brak ceny, nieczytelna cena, brak waluty).
     *
     * @param  list<array<string, mixed>>  $pages
     * @return list<B2bRemoteProduct>
     */
    private function grouped(array $pages): array
    {
        /** @var array<int, B2bRemoteProduct> $out */
        $out = [];
        /** @var array<string, list<array<string, mixed>>> $byNumber */
        $byNumber = [];
        foreach ($pages as $page) {
            if (isset($page['skipped'])) {
                $out[$page['order']] = $page['skipped'];

                continue;
            }
            $byNumber[$page['catalog_no']][] = $page;
        }

        foreach ($byNumber as $catalogNo => $numberPages) {
            $catalogNo = (string) $catalogNo;
            $numberPages = self::withLegacyIdentity($catalogNo, $numberPages);
            $priced = array_values(array_filter($numberPages, static fn (array $p): bool => $p['amount'] !== null));
            if ($priced === []) {
                $fallback = null;
                foreach ($numberPages as $page) {
                    if ($page['price_text'] !== '') {
                        $fallback = $page;
                        break;
                    }
                }
                $fallback ??= $numberPages[0];
                $out[$fallback['order']] = $this->productOf($catalogNo, $catalogNo, [$fallback], null, false);

                continue;
            }

            // Waluta w kluczu wyrobu (rozmiary jednej karty mają jedną walutę). Jednostki („/szt.”, „/komplet”,
            // „/zestaw”) w kluczu nie ma: Protekt pisze ją niekonsekwentnie na podstronach tego samego wyrobu w tej samej
            // cenie (CR 255V: czarny „/szt.”, pozostałe kolory „/komplet”; szelki bywają bez jednostki) — podział po niej
            // rozbiłby jeden wyrób.
            $lead = $priced[0];
            $sameCurrency = [];
            // podstrony poza kartami (bez ceny, inna waluta) — ich EAN i indeks to nadal kody tego numeru: zostają przy
            // pozycji karty numeru, jak dotąd (każdy adres numeru trafiał na tę pozycję)
            $outside = array_values(array_filter($numberPages, static fn (array $p): bool => $p['amount'] === null));
            foreach ($priced as $page) {
                if ($page['currency'] !== $lead['currency']) {
                    $this->otherCurrency[] = $catalogNo.' ('.$page['currency'].')';
                    $outside[] = $page;

                    continue;
                }
                $sameCurrency[] = $page;
            }
            $headings = self::sizeHeadings($sameCurrency);
            $pricedSizes = [];
            foreach ($sameCurrency as $page) {
                $pricedSizes[self::sizeOf($page, $headings) ?? ''] = true;
            }
            foreach ($numberPages as $page) {
                $size = $page['amount'] === null ? self::sizeOf($page, self::sizeHeadings([...$sameCurrency, $page])) : null;
                if ($size !== null && ! isset($pricedSizes[$size]) && ! in_array($catalogNo.' '.$size, $this->sizesWithoutPrice, true)) {
                    $this->sizesWithoutPrice[] = $catalogNo.' '.$size;
                }
            }

            $cards = self::cardClusters($catalogNo, $sameCurrency, $headings);
            foreach ($cards as $index => $card) {
                $out[$card['pages'][0]['order']] = $this->productOf($catalogNo, $card['sku'], $card['pages'], $headings, count($cards) > 1, $index === 0 ? $outside : []);
            }
        }

        ksort($out);

        return array_values($out);
    }

    /**
     * Karty numeru. Podstrona wiodąca — pierwsza wskazująca rozmiar (gdy numer ma rozmiary; P-04: pierwsza podstrona
     * „kolor , rozmiar M” nie ma siatek). Wyroby pod numerem (sameProduct) dzielą się na kolory; kolor albo wyrób,
     * którego ceny rozmiarów są takie same jak na karcie numeru, należy do tej karty (lista kolorów; inny wyrób w tej
     * samej cenie — jak dotąd, bez osobnej karty). W innej cenie to inny wyrób — karta „numer / kolor” (kod od koloru
     * pierwszej podstrony, jak przed 28.09.2026: BW200/LB101HV / jaskrawy pomarańczowy, KLK420 / czarny). Bez koloru
     * nie ma czym jej nazwać — zostaje na karcie numeru, a rozbieżność ceny trafia do podsumowania.
     *
     * @param  list<array<string, mixed>>  $pages  podstrony z ceną, w kolejności mapy
     * @param  list<string>  $headings
     * @return list<array{sku: string, pages: list<array<string, mixed>>}> pages[0] = podstrona wiodąca karty
     */
    private static function cardClusters(string $catalogNo, array $pages, array $headings): array
    {
        $hasSize = static fn (array $page): bool => $headings === [] || self::sizeOf($page, $headings) !== null;
        $ordered = [
            ...array_values(array_filter($pages, $hasSize)),
            ...array_values(array_filter($pages, static fn (array $page): bool => ! $hasSize($page))),
        ];

        /** @var list<list<array<string, mixed>>> $products */
        $products = [];
        foreach ($ordered as $page) {
            foreach ($products as $index => $productPages) {
                if (self::sameProduct($page, $productPages[0], $headings)) {
                    $products[$index][] = $page;

                    continue 2;
                }
            }
            $products[] = [$page];
        }

        $cards = [];
        foreach ($products as $productPages) {
            /** @var array<string, list<array<string, mixed>>> $byColour */
            $byColour = [];
            foreach ($productPages as $page) {
                $byColour[(string) $page['colour']][] = $page;
            }
            foreach ($byColour as $colour => $colourPages) {
                $colour = (string) $colour;
                $profile = self::priceProfile($colourPages, $headings);
                if ($cards === []) {
                    $cards[] = ['sku' => $catalogNo, 'pages' => $colourPages, 'profile' => $profile];

                    continue;
                }
                $target = null;
                foreach ($cards as $index => $card) {
                    if (self::sameProfile($card['profile'], $profile)) {
                        $target = $index;
                        break;
                    }
                }
                $sku = $catalogNo.' / '.$colour;
                if ($target === null && $colour !== '' && ! in_array($sku, array_column($cards, 'sku'), true)) {
                    $cards[] = ['sku' => $sku, 'pages' => $colourPages, 'profile' => $profile];

                    continue;
                }
                $target ??= 0;
                $cards[$target]['pages'] = [...$cards[$target]['pages'], ...$colourPages];
                $cards[$target]['profile'] += $profile;
            }
        }

        return array_map(static fn (array $card): array => ['sku' => $card['sku'], 'pages' => $card['pages']], $cards);
    }

    /**
     * Cena każdego rozmiaru koloru (pierwsza podstrona rozmiaru; bez siatki rozmiarów — klucz '').
     *
     * @param  list<array<string, mixed>>  $pages
     * @param  list<string>  $headings
     * @return array<string, float>
     */
    private static function priceProfile(array $pages, array $headings): array
    {
        $profile = [];
        foreach ($pages as $page) {
            $profile[self::sizeOf($page, $headings) ?? ''] ??= (float) $page['amount'];
        }

        return $profile;
    }

    /**
     * Te same ceny wspólnych rozmiarów (do grosza). Bez wspólnego rozmiaru (podstrona bez siatki rozmiarów, inny zestaw
     * rozmiarów) — każda cena $b jest jedną z cen $a (jak dotąd: ta sama cena = ta sama karta, inna = osobna).
     *
     * @param  array<string, float>  $a
     * @param  array<string, float>  $b
     */
    private static function sameProfile(array $a, array $b): bool
    {
        $common = array_intersect_key($a, $b);
        if ($common === []) {
            foreach ($b as $amount) {
                $known = false;
                foreach ($a as $other) {
                    $known = $known || abs($amount - $other) < 0.01;
                }
                if (! $known) {
                    return false;
                }
            }

            return $b !== [];
        }
        foreach ($common as $size => $amount) {
            if (abs($amount - $b[$size]) >= 0.01) {
                return false;
            }
        }

        return true;
    }

    /**
     * Nagłówki siatki rozmiarów, których wybór różni się między podstronami numeru — tylko one rozróżniają rozmiary
     * (stała „Długość [m] 10” zestawu ROOFER/10 nie jest rozmiarem, bo jest jedna).
     *
     * @param  list<array<string, mixed>>  $pages
     * @return list<string>
     */
    private static function sizeHeadings(array $pages): array
    {
        $values = [];
        foreach ($pages as $page) {
            foreach ($page['variants'] as $heading => $value) {
                if (preg_match(self::SIZE_HEADING, (string) $heading) === 1) {
                    $values[(string) $heading] ??= [];
                }
            }
        }
        foreach (array_keys($values) as $heading) {
            foreach ($pages as $page) {
                $values[$heading][(string) ($page['variants'][$heading] ?? '')] = true;
            }
        }

        return array_values(array_filter(array_keys($values), static fn (string $h): bool => count($values[$h]) > 1));
    }

    /**
     * Rozmiar podstrony dosłownie z siatki wariantów: „M - XL” pod „Rozmiar…”, „Długość [m]: 1.4” pod innym nagłówkiem;
     * kilka różniących się siatek — po przecinku. null = podstrona nie ma wyboru w żadnej z nich.
     *
     * @param  array<string, mixed>  $page
     * @param  list<string>  $headings
     */
    private static function sizeOf(array $page, array $headings): ?string
    {
        $parts = [];
        foreach ($headings as $heading) {
            $value = (string) ($page['variants'][$heading] ?? '');
            if ($value === '') {
                continue;
            }
            $parts[] = preg_match('/^rozmiar/iu', $heading) === 1 ? $value : $heading.': '.$value;
        }

        return $parts !== [] ? implode(', ', $parts) : null;
    }

    /**
     * Wyrób z podstron jednej karty. Rozmiary (co najmniej dwa różne) → pozycje (members) z ceną każdego rozmiaru;
     * pozycja wiodąca = pierwszy rozmiar w kolejności mapy, z remote_id = kod karty (tak jak powiązanie zapisane przed
     * 28.09.2026 — istniejąca karta zostaje znaleziona po powiązaniu), pozostałe — numer katalogowy + identyfikator
     * podstrony z adresu („~p4278”, stały dla adresu). Podstrony tego samego rozmiaru w innych kolorach to ta sama
     * pozycja (pierwsza podstrona rozmiaru niesie cenę; inna cena — do podsumowania rozbieżności).
     *
     * @param  list<array<string, mixed>>  $pages  podstrony karty w kolejności mapy
     * @param  list<string>|null  $headings  null = numer bez ceny (jeden produkt z tej podstrony, bez pozycji)
     * @param  bool  $colourCards  numer ma też kartę „numer / kolor” (kolor w innej cenie)
     * @param  list<array<string, mixed>>  $outside  podstrony numeru poza kartami (bez ceny, inna waluta) — tylko ich identyfikatory
     */
    private function productOf(string $catalogNo, string $sku, array $pages, ?array $headings, bool $colourCards, array $outside = []): B2bRemoteProduct
    {
        $lead = $pages[0];
        $headings ??= [];

        /** @var array<string, array<string, mixed>> $sizes rozmiar => pierwsza podstrona */
        $sizes = [];
        /** @var array<int, string> $sizeOfPage kolejność podstrony => jej rozmiar na tej karcie ('' = bez rozmiaru) */
        $sizeOfPage = [];
        /** @var list<array<string, mixed>> $loose podstrony na karcie, które nie są jej rozmiarem */
        $loose = [];
        foreach ($pages as $page) {
            $label = self::sizeOf($page, $headings);
            $isLead = $page['order'] === $lead['order'];
            // Protekt bywa, że daje jeden numer dwóm wyrobom (AB 159 21: P-50NmX ISOL za 990 zł i P-50NmX bez koloru za
            // 843 zł, AF 600 z zatrzaśnikami i bez) — rozmiar innego wyrobu nie jest rozmiarem tej karty; bez koloru na
            // osobną kartę (cardClusters) zostaje przy cenie karty jak dotąd, a rozbieżność trafia do podsumowania.
            // Podstrona bez wyboru w siatce rozmiarów (VS 080 „rozmiar XXL, kolor” bez siatek) też nie tworzy rozmiaru.
            if (! $isLead && (! self::sameProduct($page, $lead, $headings) || ($label === null && $headings !== []))) {
                $loose[] = $page;

                continue;
            }
            $size = $label ?? '';
            $sizeOfPage[$page['order']] = $size;
            if (! isset($sizes[$size])) {
                $sizes[$size] = $page;

                continue;
            }
            if ($page['amount'] !== null && abs((float) $sizes[$size]['amount'] - (float) $page['amount']) >= 0.01) {
                $this->conflict($catalogNo, $sku.($size !== '' ? ' '.$size : ''), (float) $sizes[$size]['amount'], (float) $page['amount']);
            }
        }
        foreach ($loose as $page) {
            $known = false;
            foreach ($sizes as $sizePage) {
                $known = $known || abs((float) $sizePage['amount'] - (float) $page['amount']) < 0.01;
            }
            if ($page['amount'] !== null && ! $known) {
                $this->conflict($catalogNo, $sku, (float) $lead['amount'], (float) $page['amount']);
            }
        }
        $sized = count($sizes) > 1;
        $remoteIdOf = static fn (array $page): string => $page['order'] === $lead['order']
            ? $sku
            : $catalogNo.' ~'.self::pageId((string) $page['url']);

        $cheapest = $lead;
        foreach ($sizes as $page) {
            if ($page['amount'] !== null && (float) $page['amount'] < (float) $cheapest['amount'] - 0.0049) {
                $cheapest = $page;
            }
        }

        $discount = null;
        if ($lead['amount'] !== null) {
            $match = $this->discounts->resolve($catalogNo, $lead['category'], $lead['name']);
            $discount = $match?->discountPercent;
        }

        $members = [];
        if ($sized) {
            $amounts = [];
            foreach ($sizes as $size => $page) {
                $amounts[] = (float) $page['amount'];
                $members[] = array_filter([
                    'remote_id' => $remoteIdOf($page),
                    // kod pozycji sprzed 28.09.2026, gdy inny niż dziś — silnik znajduje po nim dawną kartę „numer / kolor”
                    // (dawny podział długości/rozmiarów według ceny), żeby rozmiar trafił do size_spread, a nie osierocił
                    // kartę; pozycja wiodąca ma już swój dawny kod, a dawny kod = kod karty wskazuje tę samą kartę
                    'legacy_remote_id' => $page['order'] !== $lead['order']
                        && ! in_array($page['legacy_remote_id'], [$remoteIdOf($page), $sku], true)
                        ? (string) $page['legacy_remote_id']
                        : null,
                    'sku' => $sku,
                    'name' => (string) $page['name'],
                    'availability' => $page['availability'],
                    'size' => $size !== '' ? (string) $size : null,
                    // cena katalogowa rozmiaru ze strony tego rozmiaru; bez reguły rabatowej — bez cen (price() pominie)
                    'price' => $discount !== null ? self::discounted((float) $page['amount'], (float) $discount, (string) $page['currency']) : null,
                ], static fn (mixed $v): bool => $v !== null);
            }
            if (count(array_unique(array_map(static fn (float $a): string => number_format($a, 2, '.', ''), $amounts))) > 1) {
                $this->multiPriceProducts++;
            }
        }

        // pozycja podstrony (identyfikatory): jej rozmiar; bez rozmiarów albo inny wyrób pod tym numerem — pozycja karty
        $positionOf = [];
        foreach ($pages as $page) {
            $size = $sizeOfPage[$page['order']] ?? null;
            $positionOf[$page['order']] = $sized && $size !== null ? $remoteIdOf($sizes[$size]) : $sku;
        }

        $colours = self::coloursOf($pages, $lead, $colourCards);
        $sizeLabels = array_values(array_filter(array_map('strval', array_keys($sizes)), static fn (string $s): bool => $s !== ''));
        $summary = $colours;
        if ($sized && $sizeLabels !== []) {
            $summary = ($colours !== null ? $colours.'; ' : '').'Rozmiary: '.implode('; ', $sizeLabels);
        }

        return new B2bRemoteProduct(
            remoteId: $sku,
            sku: $sku,
            name: (string) $lead['name'],
            category: $lead['category'],
            sourceUrl: (string) $lead['url'],
            raw: [
                'status' => 'ok',
                // Numer katalogowy dosłownie ze strony: kod karty (sku) bywa numerem z dopiskiem koloru,
                // który jest nasz — do tabelki u dostawcy trafia to, co pokazuje Protekt.
                'catalog_no' => $catalogNo,
                // cena karty = najtańszy rozmiar (B2bCatalogSync liczy ją też z members[].price)
                'price_text' => (string) $cheapest['price_text'],
                'currency' => (string) $cheapest['currency'],
                'discount_percent' => $discount,
                'ean' => (string) $lead['ean'],
                'supplier_index' => (string) $lead['supplier_index'],
                ...$lead['raw'],
                'spec' => self::withColours($sized ? self::sizesSpec($sizes) : $lead['raw']['spec'], $colours),
            ],
            availability: $sized ? self::sizesAvailability($sizes) : $lead['availability'],
            variantSummary: $summary,
            members: $members,
            identifiers: self::identifiersFor($sku, $catalogNo, [...$pages, ...$outside], $headings, $positionOf),
            cardName: self::cardNameFor($lead, $sized ? $headings : []),
        );
    }

    /**
     * Kod pozycji, pod którym łącznik sprzed 28.09.2026 zapisywał każdą podstronę numeru (identityFor z HEAD przed
     * zmianą, te same reguły i kolejność mapy): pierwsza odczytana cena — czysty numer; inna cena przy kolorze
     * w specyfikacji — „numer / kolor” pierwszej podstrony w tej cenie; cena nieczytelna, ta sama albo bez koloru —
     * czysty numer. Tylko do legacy_remote_id — nowy podział kart od tego nie zależy.
     *
     * @param  list<array<string, mixed>>  $pages  podstrony numeru w kolejności mapy
     * @return list<array<string, mixed>>
     */
    private static function withLegacyIdentity(string $catalogNo, array $pages): array
    {
        $first = null;
        $split = [];
        foreach ($pages as $index => $page) {
            $legacy = $catalogNo;
            $price = self::money((string) $page['price_text']);
            if ($price !== null) {
                if ($first === null) {
                    $first = $price;
                } elseif (abs($first - $price) >= 0.01 && $page['colour'] !== '') {
                    $legacy = $split[number_format($price, 2, '.', '')] ??= $catalogNo.' / '.$page['colour'];
                }
            }
            $pages[$index]['legacy_remote_id'] = $legacy;
        }

        return $pages;
    }

    /** Jeden wpis rozbieżności na numer katalogowy: „kod [rozmiar] (pierwsza vs inna)”. */
    private function conflict(string $catalogNo, string $label, float $first, float $other): void
    {
        $this->priceConflicts[$catalogNo] ??= $label.' ('.number_format($first, 2, ',', ' ')
            .' vs '.number_format($other, 2, ',', ' ').')';
    }

    /**
     * Nazwa nowej karty bez rozmiaru podstrony wiodącej: rozmiar literowy — cardNameWithoutSize; długość (siatka
     * „Długość…”, gdy karta ma długości jako rozmiary) — zapis „dł. 1.4 m”, „dł. liny 15 m”, „- 0.9 m” z wartością
     * tej podstrony. Reszta nagłówka dosłownie; null = nagłówek bez zmian.
     *
     * @param  array<string, mixed>  $lead
     * @param  list<string>  $headings  siatki rozmiarów karty (puste = karta bez rozmiarów)
     */
    private static function cardNameFor(array $lead, array $headings): ?string
    {
        $name = (string) $lead['name'];
        $card = self::cardNameWithoutSize($name) ?? $name;
        foreach ($headings as $heading) {
            $value = (string) ($lead['variants'][$heading] ?? '');
            if ($value === '' || preg_match('/^rozmiar/iu', $heading) === 1) {
                continue;
            }
            $card = (string) preg_replace(
                '/(?:\s*[-,])?\s*(?:dł\.\s*(?:liny\s*)?)?(?<![\p{L}\p{N}.])'.preg_quote($value, '/').'\s*(?:mm|m)(?![\p{L}\p{N}])/iu',
                '',
                $card,
                1,
            );
            $card = trim((string) preg_replace('/\s{2,}/u', ' ', $card), " \t\n\r\0\x0B-,");
        }

        return $card !== '' && $card !== $name ? $card : null;
    }

    /**
     * Ta sama rzecz co podstrona wiodąca, tylko w innym rozmiarze albo kolorze: te same wybory w pozostałych siatkach
     * wariantów (wersja, zatrzaśniki, szelki zestawu — liczą się tylko siatki obecne na obu podstronach); gdy wspólnych
     * siatek nie ma — ten sam nagłówek po zdjęciu rozmiaru i koloru.
     *
     * @param  array<string, mixed>  $page
     * @param  array<string, mixed>  $lead
     * @param  list<string>  $headings  siatki rozmiarów
     */
    private static function sameProduct(array $page, array $lead, array $headings): bool
    {
        $common = 0;
        foreach ($page['variants'] as $heading => $value) {
            $heading = (string) $heading;
            if (in_array($heading, $headings, true) || preg_match('/^kolor/iu', $heading) === 1 || ! isset($lead['variants'][$heading])) {
                continue;
            }
            if ($lead['variants'][$heading] !== $value) {
                return false;
            }
            $common++;
        }

        // te same wybory w innych siatkach rozstrzygają (PROTON 1: nagłówki „PROTON 1 - …” i „PROTON1 200 002 500 - …”
        // przy tej samej wersji i wysokości); bez innych siatek — nagłówek (TH 130 21: TH-050mX i TH-030mX)
        return $common > 0 || self::baseName($page, $headings) === self::baseName($lead, $headings);
    }

    /**
     * Nagłówek podstrony bez jej rozmiaru i koloru (całe słowa) — do porównania, czy dwie podstrony to ten sam wyrób.
     *
     * @param  array<string, mixed>  $page
     * @param  list<string>  $headings
     */
    private static function baseName(array $page, array $headings): string
    {
        $name = (string) preg_replace('/[\s\x{00A0}]+/u', ' ', (string) $page['name']);
        // rozmiar literowy z nagłówka także wtedy, gdy podstrona nie wskazuje go w siatce
        $name = self::cardNameWithoutSize($name) ?? $name;
        $remove = [(string) $page['colour']];
        foreach ($headings as $heading) {
            $remove[] = (string) ($page['variants'][$heading] ?? '');
        }
        foreach ($remove as $value) {
            if ($value === '') {
                continue;
            }
            $name = (string) preg_replace('/(?<![\p{L}\p{N}])'.preg_quote($value, '/').'(?![\p{L}\p{N}])/iu', '', $name);
        }

        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }

    /**
     * Kolory karty. Numer bez karty „numer / kolor” — lista z siatki kolorów podstrony wiodącej (jak dotąd, ta sama na
     * każdej podstronie); gdy kolor w innej cenie ma osobną kartę — kolory podstron tej karty (dosłownie ze specyfikacji).
     *
     * @param  list<array<string, mixed>>  $pages
     * @param  array<string, mixed>  $lead
     */
    private static function coloursOf(array $pages, array $lead, bool $colourCards): ?string
    {
        if (! $colourCards) {
            return $lead['colours'];
        }
        $colours = [];
        foreach ($pages as $page) {
            if ($page['colour'] !== '' && ! in_array($page['colour'], $colours, true)) {
                $colours[] = (string) $page['colour'];
            }
        }

        return $colours !== [] ? implode(', ', $colours) : null;
    }

    /**
     * Identyfikatory karty dosłownie ze stron: numer katalogowy („Nr kat.”, itemprop gtin — to numer katalogowy,
     * nie GTIN) raz, na pozycji karty i bez etykiety; „Indeks” (itemprop sku) jako kod producenta i EAN (itemprop gtin13)
     * każdej podstrony — na pozycji jej rozmiaru (bez rozmiarów: na pozycji karty), z etykietą = kolor i rozmiar
     * podstrony. Numer katalogowy bez dopisku koloru — dopisek w kodzie karty jest nasz. Identyfikator, którego żadna
     * podstrona nie podała w pełnym przebiegu, dostaje removed_at dopiero na końcu przebiegu
     * (ProductIdentifierStore::sweepB2b).
     *
     * @param  list<array<string, mixed>>  $pages
     * @param  list<string>  $headings
     * @param  array<int, string>  $positionOf  kolejność podstrony => remote_id pozycji
     * @return list<B2bRemoteIdentifier>
     */
    private static function identifiersFor(string $sku, string $catalogNo, array $pages, array $headings, array $positionOf): array
    {
        $found = [
            new B2bRemoteIdentifier(
                type: ProductIdentifier::TYPE_MANUFACTURER_CODE,
                value: $catalogNo,
                remoteId: $sku,
                field: 'Nr katalogowy',
            ),
        ];
        $seen = [];
        foreach ($pages as $page) {
            $size = self::sizeOf($page, $headings);
            $position = $positionOf[$page['order']] ?? $sku;
            $parts = array_values(array_filter([(string) $page['colour'], (string) $size], static fn (string $s): bool => $s !== ''));
            $label = $parts !== [] ? implode(', ', $parts) : null;
            foreach ([
                [ProductIdentifier::TYPE_MANUFACTURER_CODE, (string) $page['supplier_index'], 'Indeks producenta'],
                [ProductIdentifier::TYPE_EAN, (string) $page['ean'], 'EAN'],
            ] as [$type, $value, $field]) {
                $key = $type."\n".$value."\n".$position."\n".$label;
                if ($value === '' || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $found[] = new B2bRemoteIdentifier(type: $type, value: $value, remoteId: $position, label: $label, field: $field);
            }
        }

        return $found;
    }

    /**
     * Dostępność karty z rozmiarami — jedna wspólna dosłownie, różne — „Wysoki: M - XL; Na wyczerpaniu: XXL”.
     * Któryś rozmiar bez dostępności na stronie — null (zapisana zostaje).
     *
     * @param  array<string, array<string, mixed>>  $sizes  rozmiar => podstrona
     */
    private static function sizesAvailability(array $sizes): ?string
    {
        $statuses = [];
        foreach ($sizes as $size => $page) {
            if ($page['availability'] === null) {
                return null;
            }
            $statuses[(string) $page['availability']][] = (string) $size;
        }
        if (count($statuses) === 1) {
            return (string) array_key_first($statuses);
        }
        $parts = [];
        foreach ($statuses as $status => $labels) {
            $parts[] = $status.': '.implode(', ', $labels);
        }

        return implode('; ', $parts);
    }

    /** Identyfikator podstrony z adresu (/slug~p4278~c5343 → „p4278”); stały dla adresu. */
    private static function pageId(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return preg_match('#~(p\d+)~c\d+$#', $path, $m) === 1 ? $m[1] : $path;
    }

    /**
     * Wybór tej podstrony w siatkach „Dostępne warianty”: nagłówek siatki => etykieta pozycji wskazującej na tę samą
     * podstronę (np. „Rozmiar” => „XXL”, „Długość [m]” => „1.4”). Siatka bez wskazania na siebie — bez wpisu.
     *
     * @return array<string, string>
     */
    private static function ownVariants(DOMXPath $xpath, string $url): array
    {
        $own = '~'.self::pageId($url).'~';
        $selected = [];
        foreach ($xpath->query('//*['.self::classPredicate('product-desc__sizes').']//*['.self::classPredicate('row').']') as $row) {
            $heading = self::variantLabel($xpath->query('.//h4', $row)->item(0));
            if ($heading === '' || isset($selected[$heading])) {
                continue;
            }
            foreach ($xpath->query('.//ul['.self::classPredicate('variants-grid').']//a[@href]', $row) as $link) {
                if (str_contains(self::attr($link, 'href'), $own)) {
                    $value = self::variantLabel($link);
                    if ($value !== '') {
                        $selected[$heading] = $value;
                    }
                    break;
                }
            }
        }

        return $selected;
    }

    /**
     * Specyfikacja karty z rozmiarami: układ podstrony wiodącej, a wiersz, którego wartość różni się między rozmiarami
     * („Długość: 1,4 m” / „2 m”, „Waga”, „Rozmiar”, obwody W/H/C/T szelek), dostaje wszystkie wartości rozmiarów po
     * średniku w kolejności rozmiarów — wartość jednego rozmiaru byłaby w tabelce karty obejmującej wszystkie nieprawdą.
     * Wiersz rozpoznajemy po podzespole, etykiecie i jej kolejnym wystąpieniu; wartości dosłownie ze stron.
     *
     * @param  array<string, array<string, mixed>>  $sizes  rozmiar => podstrona (pierwsza = wiodąca)
     * @return list<string>
     */
    private static function sizesSpec(array $sizes): array
    {
        $keyed = static function (array $rows): array {
            $section = '';
            $seen = [];
            $out = [];
            foreach ($rows as $index => $row) {
                $parts = explode(': ', (string) $row, 2);
                if (count($parts) < 2) {
                    $section = (string) $row;
                    $out[$index] = null;

                    continue;
                }
                $base = $section."\n".$parts[0];
                $seen[$base] = ($seen[$base] ?? 0) + 1;
                $out[$index] = [$base."\n".$seen[$base], $parts[0], $parts[1]];
            }

            return $out;
        };

        $pages = array_values($sizes);
        $lead = $keyed($pages[0]['raw']['spec']);
        $values = [];
        foreach ($pages as $page) {
            foreach ($keyed($page['raw']['spec']) as $row) {
                if ($row !== null && ! in_array($row[2], $values[$row[0]] ?? [], true)) {
                    $values[$row[0]][] = $row[2];
                }
            }
        }

        $spec = [];
        foreach ($pages[0]['raw']['spec'] as $index => $row) {
            $keyedRow = $lead[$index];
            $spec[] = $keyedRow === null ? (string) $row : $keyedRow[1].': '.implode('; ', $values[$keyedRow[0]]);
        }

        return $spec;
    }

    /**
     * Wiersze specyfikacji z wierszem „Kolor” = kolory karty: karta obejmuje wszystkie swoje wersje kolorystyczne,
     * więc kolor jednej podstrony byłby w tabelce nieprawdą (i tabelka różniłaby się między podstronami).
     *
     * @param  list<string>  $rows
     * @return list<string>
     */
    private static function withColours(array $rows, ?string $colours): array
    {
        if ($colours === null) {
            return $rows;
        }

        return array_map(static function (string $row) use ($colours): string {
            $parts = explode(': ', $row, 2);

            return count($parts) === 2 && mb_strtolower($parts[0]) === 'kolor' ? $parts[0].': '.$colours : $row;
        }, $rows);
    }

    /**
     * @param  list<string>  $items
     */
    private static function listing(array $items): string
    {
        return count($items).', np. '.implode(', ', array_slice($items, 0, 10));
    }

    private function progress(string $message): void
    {
        if ($this->listProgress !== null) {
            ($this->listProgress)($message);
        }
    }

    /** Kolor tego jednego adresu, dosłownie z wiersza „Kolor” specyfikacji; '' gdy karta go nie podaje. */
    private static function ownColour(DOMXPath $xpath): string
    {
        foreach ($xpath->query('//*['.self::classPredicate('spec-col__row').']') as $row) {
            $label = rtrim(self::text($xpath->query('.//*['.self::classPredicate('spec-col__type').']', $row)->item(0)), ':');
            if (mb_strtolower($label) === 'kolor') {
                return self::text($xpath->query('.//*['.self::classPredicate('spec-col__type_val').']', $row)->item(0));
            }
        }

        return '';
    }

    /**
     * Informacja o wycofaniu produktu, dosłownie ze strony: etykieta „Wycofany” i zdanie o zastąpieniu.
     * Produkt wycofany zostaje w katalogu (bywa potrzebny przy starych zapytaniach), ale karta ma o tym mówić
     * wprost — inaczej trafiłby do oferty jako towar z bieżącej sprzedaży.
     */
    private static function withdrawnNote(DOMXPath $xpath): string
    {
        $label = self::text($xpath->query('//*['.self::classPredicate('single-product-label').']')->item(0));
        if (mb_stripos($label, 'wycofan') === false) {
            return '';
        }

        $replaced = '';
        foreach ($xpath->query('//*['.self::classPredicate('replaces-box').']') as $box) {
            $text = self::text($box);
            if (mb_stripos($text, 'zastąpiony') !== false) {
                $replaced = $text;
                break;
            }
        }

        return $replaced !== '' ? $label.' — '.$replaced : $label;
    }

    /**
     * Pliki z sekcji „Do pobrania”: karta produktowa, instrukcje i deklaracje zgodności. Rodzaj rozpoznajemy
     * po nazwie linku ze strony — deklaracje są dla przetargów najważniejsze, więc mają własny rodzaj.
     *
     * @return list<array{title: string, url: string, kind: string}>
     */
    private static function documentList(DOMXPath $xpath): array
    {
        $documents = [];
        $seen = [];
        $box = $xpath->query('//*['.self::classPredicate('spec-tech__docs').']')->item(0);
        if ($box === null) {
            return [];
        }

        foreach ($xpath->query('.//a[@href]', $box) as $link) {
            $href = self::attr($link, 'href');
            $title = self::text($link);
            if ($href === '' || $title === '') {
                continue;
            }
            $url = str_starts_with($href, 'http') ? $href : ProtektB2bClient::BASE.$href;
            if (isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $documents[] = ['title' => $title, 'url' => $url, 'kind' => self::documentKind($title)];
        }

        return $documents;
    }

    private static function documentKind(string $title): string
    {
        $lower = mb_strtolower($title);
        if (str_contains($lower, 'deklaracj')) {
            return ProductDocument::KIND_CERTIFICATE;
        }
        if (str_contains($lower, 'karta')) {
            return ProductDocument::KIND_DATASHEET;
        }

        return ProductDocument::KIND_OTHER;
    }

    private static function skipped(string $url, ?string $category, string $reason): B2bRemoteProduct
    {
        // Nazwa i kod muszą być niepuste: B2bCatalogSync sprawdza je przed price(), a dopiero price()
        // zna prawdziwy powód pominięcia („brak kodu lub nazwy” zamiast niego nic by nie wyjaśniał).
        // Zastępczy kod to końcówka adresu karty — do zapisu i tak nie dochodzi, bo price() rzuca wyjątek.
        $label = basename((string) parse_url($url, PHP_URL_PATH));

        return new B2bRemoteProduct(
            remoteId: $url,
            sku: $label,
            name: $label,
            category: $category,
            sourceUrl: $url,
            raw: ['status' => 'skipped', 'reason' => $reason],
        );
    }

    /** Kategoria liścia z adresu karty (/slug~pID~cID); bez mapy kategorii zostaje samo „cID”. */
    private static function categoryOf(string $url, array $categories): ?string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        if (preg_match('#~c(\d+)$#', $path, $m) !== 1) {
            return null;
        }

        return $categories[$m[1]] ?? 'c'.$m[1];
    }

    /** Dostępność dosłownie ze strony („Wysoki”, „Na wyczerpaniu”, „zapytaj o dostępność…”). */
    private static function availability(DOMXPath $xpath): ?string
    {
        $node = $xpath->query('//*['.self::classPredicate('inventory').']//span')->item(0);
        $text = self::text($node);

        return $text !== '' ? $text : null;
    }

    /**
     * Wiersze specyfikacji jako „nazwa: wartość”; nagłówki podzespołów (spec-col__product) jako osobne linie.
     * Wiersz „Kolor” dostaje listę kolorów karty przy składaniu wyrobu (withColours).
     *
     * @return list<string>
     */
    private static function specRows(DOMXPath $xpath): array
    {
        $rows = [];
        foreach ($xpath->query('//*['.self::classPredicate('spec-col__row').']') as $row) {
            $product = self::text($xpath->query('.//*['.self::classPredicate('spec-col__product').']', $row)->item(0));
            if ($product !== '') {
                $rows[] = $product;

                continue;
            }
            $label = rtrim(self::text($xpath->query('.//*['.self::classPredicate('spec-col__type').']', $row)->item(0)), ':');
            $value = self::text($xpath->query('.//*['.self::classPredicate('spec-col__type_val').']', $row)->item(0));
            if ($label !== '' && $value !== '') {
                $rows[] = $label.': '.$value;
            }
        }

        return $rows;
    }

    /**
     * Kolory karty jako podsumowanie wersji. Protekt daje każdemu kolorowi osobny adres, ale ten sam numer
     * katalogowy i tę samą cenę (sprawdzone 16.09.2026), więc to jedna karta z listą kolorów — nie kilka kart.
     * Lista jest taka sama na każdym z tych adresów, dzięki czemu kolejne odwiedziny nie zmieniają karty.
     *
     * null = karta bez wyboru koloru (nie czyścimy wtedy podsumowania ustawionego skądinąd).
     */
    private static function colourSummary(DOMXPath $xpath): ?string
    {
        $colours = self::texts($xpath, '//ul['.self::classPredicate('variants-grid-color').']//p['.self::classPredicate('color--warn').']');

        return $colours !== [] ? implode(', ', $colours) : null;
    }

    private static function imageUrl(DOMXPath $xpath): string
    {
        return self::attr($xpath->query('//img[@itemprop="image"]')->item(0), 'src');
    }

    /**
     * @return list<string>
     */
    private static function texts(DOMXPath $xpath, string $query): array
    {
        $out = [];
        foreach ($xpath->query($query) as $node) {
            $text = self::text($node);
            if ($text !== '' && ! in_array($text, $out, true)) {
                $out[] = $text;
            }
        }

        return $out;
    }

    private static function text(?DOMNode $node): string
    {
        if ($node === null) {
            return '';
        }

        return trim((string) preg_replace('/\s+/u', ' ', $node->textContent));
    }

    /** Tekst etykiety siatki wariantów — twarde spacje ze strony („M&nbsp;-&nbsp;XL”) jako zwykłe. */
    private static function variantLabel(?DOMNode $node): string
    {
        return trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $node?->textContent ?? ''));
    }

    private static function attr(?DOMNode $node, string $name): string
    {
        return $node instanceof \DOMElement ? trim($node->getAttribute($name)) : '';
    }

    /** Cena ze strony: „1 597,00” → 1597.00. Kropka jako separator tysięcy tu nie występuje. */
    private static function money(string $text): ?float
    {
        $clean = str_replace(["\u{00A0}", ' '], '', trim($text));
        $clean = str_replace(',', '.', $clean);
        if (preg_match('/-?\d+(\.\d+)?/', $clean, $m) !== 1) {
            return null;
        }

        return (float) $m[0];
    }

    /** Dopasowanie po jednej klasie CSS, niezależnie od pozostałych klas elementu. */
    private static function classPredicate(string $class): string
    {
        return 'contains(concat(" ", normalize-space(@class), " "), " '.$class.' ")';
    }

    private static function dom(string $html): DOMXPath
    {
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }
}

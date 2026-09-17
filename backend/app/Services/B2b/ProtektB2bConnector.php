<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bAccount;
use App\Models\ProductDocument;
use DOMNode;
use DOMXPath;
use RuntimeException;

/**
 * protekt.pl — witryna producenta bez logowania i bez API. Jedna karta na stronie = jedna karta w katalogu,
 * identyfikowana numerem katalogowym („Nr kat.”, w mikrodanych itemprop="gtin").
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
final class ProtektB2bConnector implements B2bConnector, B2bDocumentSource, B2bPublicSite, B2bRunSummaryAware, B2bShopFieldSource
{
    private int $total = 0;

    /** @var array<string, float> numer katalogowy => pierwsza cena odczytana w tym przebiegu */
    private array $firstPrice = [];

    /** @var array<string, string> „numer|cena” => kod karty dla tej ceny (pierwsza cena zachowuje czysty numer) */
    private array $splitSku = [];

    /** @var array<string, list<string>> „numer|cena” => kolory zebrane dla tej ceny */
    private array $splitColours = [];

    /** @var array<string, string> numer katalogowy => opis rozbieżności (jeden wpis na numer) */
    private array $priceConflicts = [];

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

    public function products(): iterable
    {
        $urls = $this->client->sitemapProductUrls();
        $this->total = count($urls);
        $categories = $this->client->categorySlugs();

        foreach ($urls as $url) {
            yield $this->productFor($url, $categories);
        }
    }

    public function totalProducts(): int
    {
        return $this->total;
    }

    public function manufacturer(B2bRemoteProduct $product): string
    {
        return 'PROTEKT';
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

        // Ten sam numer katalogowy bywa pod kilkoma adresami (wersje kolorystyczne). U Protektu cena
        // wisi przy numerze katalogowym, więc powinny być identyczne — gdyby nie były, karta może mieć
        // tylko jedną cenę, a ciche przyjęcie ostatniej byłaby zgadywanką. Trzymamy pierwszą i mówimy o tym.
        $first = $this->firstPrice[$product->sku] ?? null;
        if ($first === null) {
            $this->firstPrice[$product->sku] = $amount;
        } elseif (abs($first - $amount) >= 0.01) {
            $this->priceConflicts[$product->sku] ??= $product->sku.' ('.number_format($first, 2, ',', ' ')
                .' vs '.number_format($amount, 2, ',', ' ').')';
            $amount = $first;
        }

        $match = $this->discounts->resolve($product->sku, $product->category, $product->name);
        if ($match === null) {
            throw new RuntimeException(
                'brak reguły rabatowej (nr kat. '.$product->sku
                .($product->category !== null ? ', kategoria '.$product->category : '').')'
            );
        }

        return new B2bRemotePrice(
            net: round($amount * (1 - $match->discountPercent / 100), 2),
            base: $amount,
            discountPercent: $match->discountPercent,
            currency: $currency,
        );
    }

    /**
     * Opis dosłownie z karty producenta: normy, specyfikacja techniczna (pary „nazwa: wartość”)
     * i cechy szczególne. Niczego nie dopisujemy — karta Protektu nie ma pola opisu ciągłego.
     */
    public function description(B2bRemoteProduct $product): string
    {
        $raw = $product->raw;
        $sections = [];

        $withdrawn = (string) ($raw['withdrawn'] ?? '');
        if ($withdrawn !== '') {
            $sections[] = 'UWAGA: produkt wycofany przez producenta — '.$withdrawn.'.';
        }

        $norms = $raw['norms'] ?? [];
        if ($norms !== []) {
            $sections[] = 'Normy: '.implode(', ', $norms);
        }

        $spec = $raw['spec'] ?? [];
        if ($spec !== []) {
            $sections[] = "Specyfikacja techniczna:\n".implode("\n", array_map(
                static fn (string $row): string => '- '.$row,
                $spec,
            ));
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
        if ($this->priceConflicts !== []) {
            $lines[] = 'Ten sam numer katalogowy z różnymi cenami na różnych adresach (zapisano pierwszą): '
                .implode('; ', array_slice(array_values($this->priceConflicts), 0, 20))
                .(count($this->priceConflicts) > 20 ? ' i '.(count($this->priceConflicts) - 20).' więcej' : '');
        }

        return $lines;
    }

    /**
     * @param  array<string, string>  $categories
     */
    private function productFor(string $url, array $categories): B2bRemoteProduct
    {
        $category = self::categoryOf($url, $categories);

        try {
            $page = $this->client->productPage($url);
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return self::skipped($url, $category, 'nie udało się pobrać strony produktu: '.$e->getMessage());
        }

        if ($page['status'] !== 'ok' || ! isset($page['html'])) {
            return self::skipped($url, $category, 'karta nie istnieje (martwy wpis w mapie strony)');
        }

        $xpath = self::dom($page['html']);
        $colours = self::colourSummary($xpath);
        $name = self::text($xpath->query('//h1['.self::classPredicate('product-desc__name').']')->item(0));
        $catalogNo = self::text($xpath->query('//*[@itemprop="gtin"]')->item(0));

        if ($catalogNo === '') {
            return self::skipped($url, $category, 'karta bez numeru katalogowego'.($name !== '' ? ' („'.$name.'”)' : ''));
        }
        if ($name === '') {
            return self::skipped($url, $category, 'karta bez nazwy produktu');
        }

        $priceText = self::text($xpath->query('//*[@itemprop="price"]')->item(0));
        [$sku, $summary] = $this->identityFor($catalogNo, self::money($priceText), self::ownColour($xpath), $colours);

        return new B2bRemoteProduct(
            remoteId: $sku,
            sku: $sku,
            name: $name,
            category: $category,
            sourceUrl: $url,
            variantSummary: $summary,
            raw: [
                'status' => 'ok',
                // Numer katalogowy dosłownie ze strony: kod karty (sku) bywa numerem z dopiskiem koloru,
                // który jest nasz — do tabelki u dostawcy trafia to, co pokazuje Protekt.
                'catalog_no' => $catalogNo,
                'price_text' => $priceText,
                'currency' => self::text($xpath->query('//*[@itemprop="priceCurrency"]')->item(0)),
                'ean' => self::text($xpath->query('//*[@itemprop="gtin13"]')->item(0)),
                // „Indeks” producenta — trzymamy do wglądu, kartę identyfikuje numer katalogowy.
                'supplier_index' => self::text($xpath->query('//*[@itemprop="sku"]')->item(0)),
                'norms' => self::texts($xpath, '//*['.self::classPredicate('product-desc__norms--bold').']'),
                'spec' => self::specRows($xpath, $summary),
                'withdrawn' => self::withdrawnNote($xpath),
                'documents' => self::documentList($xpath),
                'features' => self::texts($xpath, '//*['.self::classPredicate('product-desc__specific--warn').']'),
                'image_url' => self::imageUrl($xpath),
            ],
            availability: self::availability($xpath),
        );
    }

    /**
     * Kod karty i lista wersji dla adresu. Protekt powtarza numer katalogowy na kilku adresach:
     * najczęściej to warianty kolorystyczne w tej samej cenie (jedna karta z listą kolorów), ale zdarza się,
     * że kolor zmienia cenę — BW200/LB101HV kosztuje 102 zł bez koloru i 138 zł w odblaskowym. Karta ma jedną
     * cenę, więc taka wersja musi być osobną kartą; pierwsza cena zachowuje czysty numer, kolejne dostają kod
     * z dopiskiem koloru. Dopisek jest nasz — Protekt takiego kodu nie używa i dlatego bierzemy go dosłownie
     * z jego specyfikacji, a nie wymyślamy oznaczeń.
     *
     * Bez koloru nie ma czym rozróżnić wersji, więc zostaje jedna karta; rozbieżność zgłasza price().
     *
     * @return array{0: string, 1: ?string} kod karty i podsumowanie wersji
     */
    private function identityFor(string $catalogNo, ?float $price, string $ownColour, ?string $colours): array
    {
        if ($price === null) {
            return [$catalogNo, $colours];
        }

        $first = $this->firstPrice[$catalogNo] ?? null;
        if ($first === null) {
            $this->firstPrice[$catalogNo] = $price;

            return [$catalogNo, $colours];
        }
        if (abs($first - $price) < 0.01 || $ownColour === '') {
            return [$catalogNo, $colours];
        }

        $key = $catalogNo.'|'.number_format($price, 2, '.', '');
        if (! isset($this->splitSku[$key])) {
            $this->splitSku[$key] = $catalogNo.' / '.$ownColour;
            $this->splitColours[$key] = [];
        }
        if (! in_array($ownColour, $this->splitColours[$key], true)) {
            $this->splitColours[$key][] = $ownColour;
        }

        return [$this->splitSku[$key], implode(', ', $this->splitColours[$key])];
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
     *
     * Wiersz „Kolor” dostaje pełną listę kolorów karty zamiast koloru tego jednego adresu: karta obejmuje
     * wszystkie wersje kolorystyczne, więc pojedynczy kolor byłby w jej opisie nieprawdą. Przy okazji opis
     * przestaje się różnić między adresami rodzeństwa, więc kolejne odwiedziny nie liczą się jako zmiana.
     *
     * @return list<string>
     */
    private static function specRows(DOMXPath $xpath, ?string $colours = null): array
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
                if ($colours !== null && mb_strtolower($label) === 'kolor') {
                    $value = $colours;
                }
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

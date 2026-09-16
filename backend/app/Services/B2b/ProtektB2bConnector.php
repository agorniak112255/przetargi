<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bAccount;
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
final class ProtektB2bConnector implements B2bConnector, B2bPublicSite, B2bRunSummaryAware
{
    private int $total = 0;

    /** @var array<string, float> numer katalogowy => pierwsza cena odczytana w tym przebiegu */
    private array $firstPrice = [];

    /** @var list<string> numery katalogowe, które na różnych adresach miały różne ceny */
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
            if (! in_array($product->sku, $this->priceConflicts, true)) {
                $this->priceConflicts[] = $product->sku.' ('.number_format($first, 2, ',', ' ')
                    .' vs '.number_format($amount, 2, ',', ' ').')';
            }
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

    public function image(B2bRemoteProduct $product): ?B2bRemoteImage
    {
        $url = (string) ($product->raw['image_url'] ?? '');
        if ($url === '') {
            return null;
        }
        if (str_starts_with($url, '/')) {
            $url = ProtektB2bClient::BASE.$url;
        }

        $file = $this->client->imageBytes($url);
        if ($file['bytes'] === '' || ! str_starts_with($file['mime'], 'image/')) {
            return null;
        }

        return new B2bRemoteImage(bytes: $file['bytes'], mime: $file['mime'], sourceUrl: $url);
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
                .implode('; ', array_slice($this->priceConflicts, 0, 20))
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
        $name = self::text($xpath->query('//h1['.self::classPredicate('product-desc__name').']')->item(0));
        $catalogNo = self::text($xpath->query('//*[@itemprop="gtin"]')->item(0));

        if ($catalogNo === '') {
            return self::skipped($url, $category, 'karta bez numeru katalogowego'.($name !== '' ? ' („'.$name.'”)' : ''));
        }
        if ($name === '') {
            return self::skipped($url, $category, 'karta bez nazwy produktu');
        }

        return new B2bRemoteProduct(
            remoteId: $catalogNo,
            sku: $catalogNo,
            name: $name,
            category: $category,
            sourceUrl: $url,
            variantSummary: self::colourSummary($xpath),
            raw: [
                'status' => 'ok',
                'price_text' => self::text($xpath->query('//*[@itemprop="price"]')->item(0)),
                'currency' => self::text($xpath->query('//*[@itemprop="priceCurrency"]')->item(0)),
                'ean' => self::text($xpath->query('//*[@itemprop="gtin13"]')->item(0)),
                // „Indeks” producenta — trzymamy do wglądu, kartę identyfikuje numer katalogowy.
                'supplier_index' => self::text($xpath->query('//*[@itemprop="sku"]')->item(0)),
                'norms' => self::texts($xpath, '//*['.self::classPredicate('product-desc__norms--bold').']'),
                'spec' => self::specRows($xpath),
                'features' => self::texts($xpath, '//*['.self::classPredicate('product-desc__specific--warn').']'),
                'image_url' => self::imageUrl($xpath),
            ],
            availability: self::availability($xpath),
        );
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

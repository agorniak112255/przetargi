<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bAccount;
use RuntimeException;

/**
 * Portal B2B Mascot b2b.mascot.dk — producent odzieży roboczej i obuwia MASCOT. Sprawdzone na koncie 22.09.2026
 * (konto polskie, ceny w PLN, 3084 pozycji).
 *
 * Pozycja listy to ARTYKUŁ W KOLORZE: numer „180012491809” = artykuł 18001, jakość 249, kolor 1809 — Mascot i jego
 * zdjęcia zapisują go „18001-249-1809” (5-3-reszta, sprawdzone na nazwach zdjęć). Lista (/Distributor/GetProducts)
 * nie ma cen; ceny rozmiarów, opis i EAN-y są w szczegółach (/Distributor/GetProductDetail) — jedno zapytanie na
 * artykuł. Pełna lista idzie przed pierwszym zapisem, z kontrolami spójności jak u Procery (TotalCount stały na
 * każdej stronie, unikalne numery, liczba pozycji = TotalCount; zmiana w trakcie = jedno ponowne pobranie).
 *
 * Cena: portal podaje JEDNĄ cenę rozmiaru (pole Price, waluta z profilu konta) — przyjmujemy ją jako cenę konta.
 * Ceny katalogowej portal nie podaje, więc karta jej nie ma (podsumowanie przebiegu to mówi).
 *
 * Karta = rozmiary jednego artykułu w jednej cenie (decyzja użytkownika 15.09.2026: rozmiar w innej cenie to
 * osobna karta; w Mascot typowo XS–2XL w jednej cenie, 3XL i 4XL droższe). SKU: kod artykułu z myślnikami, gdy
 * artykuł jest jedną kartą; przy podziale — kod artykułu i pierwszy rozmiar karty („18001-249-1809 3XL”), żeby kod
 * nie przechodził między kartami przy zmianie podziału. Pozycje (members) = rozmiary, remote_id = EAN rozmiaru.
 * Rozmiary bez oznaczenia dostępności portal chowa (nie da się ich zamówić) — tak samo tutaj.
 *
 * Opis: pole Description dosłownie. Portal nie podaje norm ani certyfikatów — łącznik ich nie dopisuje.
 * „Brak tekstu w tym języku” to komunikat portalu, nie wartość — pole traktujemy jako puste.
 */
final class MascotB2bConnector implements B2bConnector, B2bListProgressAware, B2bManufacturerSite, B2bRunSummaryAware, B2bShopFieldSource
{
    public const BRAND = 'MASCOT';

    private const PAGE_SIZE = 100;

    /** Nieprzerwane pobieranie listy dłużej = błąd (przebieg bez postępu uznałby b2b:sync-due za przerwany). */
    private const LIST_BUDGET_SECONDS = 25 * 60;

    private const PROGRESS_EVERY_PAGES = 5;

    private const INCONSISTENT = 7201;

    /** Tekst portalu w miejscu brakującego tłumaczenia. */
    private const NO_TEXT = 'Brak tekstu w tym języku';

    /** Kody dostępności ze skryptu strony wyrobu (lang.pl.js, Availability_*). */
    private const AVAILABILITY = [
        'G' => 'Na stanie',
        'Y' => 'Ograniczona dostępność',
        'R' => 'Brak na stanie, możliwość zamówienia',
        'B' => 'Nie można określić stanów magazynowych',
    ];

    private const SHOP_SECTION = 'Informacje z portalu Mascot';

    /** Tyle rozmiarów wypisanych w nazwie karty z podziału cenowego; więcej = skrót z liczbą. */
    private const SIZES_IN_NAME = 8;

    private int $total = 0;

    /** @var list<string> */
    private array $summary = [];

    /** @var list<string> numery artykułów z rozmiarami bez ceny (rozmiary poza kartami) */
    private array $sizesWithoutPrice = [];

    /** @var list<string> */
    private array $withoutDescription = [];

    private int $cards = 0;

    private int $splitArticles = 0;

    private int $skippedArticles = 0;

    /** @var (callable(string): void)|null */
    private $listProgress = null;

    /**
     * @param  int  $pageSize  pozycji na stronę listy (testy stronicują drobniej)
     */
    public function __construct(
        private readonly MascotB2bClient $client,
        private readonly int $pageSize = self::PAGE_SIZE,
    ) {}

    public static function key(): string
    {
        return 'mascot';
    }

    public static function label(): string
    {
        return 'Mascot';
    }

    public static function host(): string
    {
        return MascotB2bClient::HOST;
    }

    public static function ownBrand(): string
    {
        return self::BRAND;
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new self(new MascotB2bClient(
            (string) $account->username,
            (string) $account->password,
            $delayMs,
        ));
    }

    public function onListProgress(callable $callback): void
    {
        $this->listProgress = $callback;
    }

    public function login(): void
    {
        $this->client->login();
    }

    public function products(): iterable
    {
        $this->summary = [];
        $this->sizesWithoutPrice = [];
        $this->withoutDescription = [];
        $this->cards = 0;
        $this->splitArticles = 0;
        $this->skippedArticles = 0;

        if (! $this->client->isLoggedIn()) {
            $this->client->login();
        }
        $currency = $this->client->currency();
        if ($currency !== 'PLN') {
            throw new RuntimeException('Konto '.MascotB2bClient::HOST.' ma ceny w walucie „'.$currency.'”, a katalog przyjmuje ceny w PLN — przebieg przerwany bez zapisu');
        }

        $rows = $this->listRows();
        $this->total = count($rows);
        $this->summary[] = 'Lista Mascot: '.count($rows).' artykułów w kolorach';

        foreach ($rows as $row) {
            $products = $this->productsFor($row);
            // artykuł w kilku cenach to kilka kart — licznik postępu rośnie, zanim je wydamy
            $this->total += max(0, count($products) - 1);
            yield from $products;
        }
    }

    public function totalProducts(): int
    {
        return $this->total;
    }

    public function runSummary(): array
    {
        $lines = $this->summary;
        $lines[] = sprintf(
            'Karty: %d (%d artykułów w kilku cenach — rozmiary w innej cenie to osobna karta; %d artykułów pominiętych)',
            $this->cards,
            $this->splitArticles,
            $this->skippedArticles,
        );
        $lines[] = 'Portal podaje jedną cenę rozmiaru (przyjęta jako cena konta) i nie podaje ceny katalogowej ani norm';
        if ($this->sizesWithoutPrice !== []) {
            $lines[] = 'Artykuły z rozmiarami bez ceny (te rozmiary poza kartami): '.self::listing($this->sizesWithoutPrice);
        }
        if ($this->withoutDescription !== []) {
            $lines[] = 'Bez polskiego opisu w portalu: '.self::listing($this->withoutDescription);
        }

        return $lines;
    }

    public function manufacturer(B2bRemoteProduct $product): string
    {
        return self::BRAND;
    }

    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        if (($product->raw['status'] ?? null) !== 'ok') {
            throw new RuntimeException((string) ($product->raw['reason'] ?? 'szczegóły wyrobu nieodczytane'));
        }

        return new B2bRemotePrice(net: round((float) $product->raw['price'], 2));
    }

    public function description(B2bRemoteProduct $product): string
    {
        return (string) ($product->raw['description'] ?? '');
    }

    /**
     * Pola artykułu dosłownie z portalu (kolekcja, rodzaj, materiał, kolor, kategoria) i EAN-y rozmiarów karty.
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
        foreach ([
            'Kod artykułu' => $raw['code'] ?? '',
            'Numer w portalu' => $raw['number'] ?? '',
            'Kolekcja' => $raw['group'] ?? '',
            'Rodzaj' => $raw['type'] ?? '',
            'Materiał' => $raw['quality'] ?? '',
            'Kolor' => $raw['color'] ?? '',
            'Kategoria' => $raw['category'] ?? '',
            'EAN' => implode('; ', array_map(
                static fn (array $size): string => $size['size'].': '.$size['ean'],
                $raw['sizes'] ?? [],
            )),
        ] as $name => $value) {
            if ($value !== '') {
                $fields[] = new B2bRemoteShopField(self::SHOP_SECTION, $name, (string) $value);
            }
        }

        return $fields;
    }

    public function image(B2bRemoteProduct $product): ?B2bRemoteImage
    {
        $url = (string) ($product->raw['image_url'] ?? '');
        if ($url === '' || ! MascotB2bClient::isImageUrl($url)) {
            return null;
        }

        // to samo ujęcie w 1000 px, gdy serwer je ma (lista podaje miniaturę 400 px)
        $candidates = str_ends_with($url, '_400px.jpg') ? [substr($url, 0, -strlen('_400px.jpg')).'_1000px.jpg', $url] : [$url];
        foreach ($candidates as $candidate) {
            try {
                $file = $this->client->imageBytes($candidate);
            } catch (B2bFatalException $e) {
                throw $e;
            } catch (RuntimeException) {
                continue;
            }
            if ($file['bytes'] !== '' && str_starts_with($file['mime'], 'image/')) {
                return new B2bRemoteImage(bytes: $file['bytes'], mime: $file['mime'], sourceUrl: $candidate);
            }
        }

        return null;
    }

    /**
     * „180012491809” → „18001-249-1809” (artykuł 5, jakość 3, kolor — reszta; zasada zgodna z nazwami zdjęć wszystkich
     * 3084 pozycji z 22.09.2026). Numer krótszy niż 9 znaków albo ze znakami innymi niż wielkie litery i cyfry zostaje
     * bez zmian.
     */
    public static function articleCode(string $number): string
    {
        $number = trim($number);
        if (preg_match('/^[A-Z0-9]{9,}$/', $number) !== 1) {
            return $number;
        }

        return substr($number, 0, 5).'-'.substr($number, 5, 3).'-'.substr($number, 8);
    }

    /**
     * Szczegóły wyrobu → pola karty i rozmiary z ceną w groszach (null = brak ceny). Rozmiary bez oznaczenia
     * dostępności pominięte, jak na stronie portalu.
     *
     * @param  array<string, mixed>  $detail
     * @return array{number: string, name: string, type: string, group: string, category: string, quality: string, color: string, description: string, image: string, sizes: list<array{size: string, ean: string, cents: int|null, availability: string}>}
     */
    public static function parseDetail(array $detail): array
    {
        $sizes = [];
        foreach (is_array($detail['ProductSizes'] ?? null) ? $detail['ProductSizes'] : [] as $size) {
            if (! is_array($size)) {
                continue;
            }
            $code = self::clean((string) ($size['Availability'] ?? ''));
            if ($code === '') {
                continue;
            }
            $price = $size['Price'] ?? null;
            $sizes[] = [
                'size' => self::clean((string) ($size['Size'] ?? '')),
                'ean' => self::clean((string) ($size['EanNumber'] ?? '')),
                'cents' => is_int($price) || is_float($price) ? (int) round($price * 100) : null,
                'availability' => self::availability($code, (string) ($size['ExpectedDate'] ?? '')),
            ];
        }

        return [
            'number' => self::clean((string) ($detail['Number'] ?? '')),
            'name' => self::field($detail['Name'] ?? ''),
            'type' => self::field($detail['Type'] ?? ''),
            'group' => self::field($detail['Group'] ?? ''),
            'category' => self::field($detail['Category'] ?? ''),
            'quality' => self::field($detail['Quality'] ?? ''),
            'color' => self::field($detail['Color'] ?? ''),
            'description' => self::descriptionText((string) ($detail['Description'] ?? '')),
            'image' => self::clean((string) ($detail['Image'] ?? '')),
            'sizes' => $sizes,
        ];
    }

    /** Kod dostępności z datą „10-11-2026 00:00:00” (dzień-miesiąc-rok) → tekst jak na stronie portalu. */
    public static function availability(string $code, string $expected): string
    {
        $code = mb_strtoupper(trim($code));
        $text = self::AVAILABILITY[$code] ?? 'Dostępność: '.$code;
        if ($code === 'R' && preg_match('/^(\d{2})-(\d{2})-(\d{4})/', trim($expected), $m) === 1) {
            $text .= ', spodziewane od '.$m[1].'.'.$m[2].'.'.$m[3];
        }

        return $text;
    }

    /**
     * Cała lista; niespójna — jedno ponowne pobranie od początku.
     *
     * @return list<array{number: string, name: string, image: string}>
     */
    private function listRows(): array
    {
        try {
            return $this->scanList();
        } catch (RuntimeException $e) {
            if ($e->getCode() !== self::INCONSISTENT) {
                throw $e;
            }
            $this->progress('Lista zmieniła się w trakcie pobierania ('.$e->getMessage().') — pobieram od nowa');
        }

        try {
            return $this->scanList();
        } catch (RuntimeException $e) {
            if ($e->getCode() !== self::INCONSISTENT) {
                throw $e;
            }

            throw new RuntimeException('Lista produktów '.MascotB2bClient::HOST.' niespójna także po ponownym pobraniu: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * @return list<array{number: string, name: string, image: string}>
     */
    private function scanList(): array
    {
        $started = microtime(true);
        $rows = [];
        $total = 0;
        $pages = 1;

        for ($page = 1; $page <= $pages; $page++) {
            if (microtime(true) - $started > self::LIST_BUDGET_SECONDS) {
                throw new RuntimeException('Pobieranie listy '.MascotB2bClient::HOST.' trwa ponad '.(self::LIST_BUDGET_SECONDS / 60).' min — przerwane bez zapisu');
            }
            $json = $this->client->listPage($page, $this->pageSize);
            $message = self::clean((string) ($json['Message'] ?? ''));
            if ($message !== '') {
                throw new RuntimeException('Lista produktów '.MascotB2bClient::HOST.' zwróciła komunikat: '.$message);
            }
            $pageTotal = is_int($json['TotalCount']) ? $json['TotalCount'] : -1;
            if ($page === 1) {
                $total = $pageTotal;
                if ($total <= 0) {
                    throw new RuntimeException('Lista produktów '.MascotB2bClient::HOST.' pusta albo bez licznika (TotalCount '.$pageTotal.') — pusta lista konta albo zmiana portalu');
                }
                $pages = (int) ceil($total / $this->pageSize);
                $this->progress('Lista produktów Mascot: '.$total.' artykułów na '.$pages.' stronach');
            } elseif ($pageTotal !== $total) {
                throw new RuntimeException('liczba produktów zmieniła się z '.$total.' na '.$pageTotal.' (strona '.$page.')', self::INCONSISTENT);
            }

            $expected = min($this->pageSize, $total - ($page - 1) * $this->pageSize);
            if (count($json['Results']) !== $expected) {
                throw new RuntimeException(sprintf('strona %d: %d pozycji, oczekiwano %d', $page, count($json['Results']), $expected), self::INCONSISTENT);
            }

            foreach ($json['Results'] as $item) {
                $number = is_array($item) ? self::clean((string) ($item['Number'] ?? '')) : '';
                if ($number === '') {
                    throw new RuntimeException('Strona '.$page.' listy '.MascotB2bClient::HOST.': pozycja bez numeru — zmiana portalu?');
                }
                if (isset($rows[$number])) {
                    throw new RuntimeException('artykuł '.$number.' na dwóch stronach', self::INCONSISTENT);
                }
                $rows[$number] = [
                    'number' => $number,
                    'name' => self::field($item['Name'] ?? ''),
                    'image' => self::clean((string) ($item['Image'] ?? '')),
                ];
            }

            if ($page > 1 && ($page % self::PROGRESS_EVERY_PAGES === 0 || $page === $pages)) {
                $this->progress('Lista produktów Mascot: strona '.$page.'/'.$pages);
            }
        }

        if (count($rows) !== $total) {
            throw new RuntimeException('pobrano '.count($rows).' z '.$total.' pozycji', self::INCONSISTENT);
        }

        return array_values($rows);
    }

    /**
     * Karty jednego artykułu (jedna na każdą cenę); szczegóły nieczytelne albo cudze — jedna pozycja pominięta
     * z powodem.
     *
     * @param  array{number: string, name: string, image: string}  $row
     * @return list<B2bRemoteProduct>
     */
    private function productsFor(array $row): array
    {
        try {
            $json = $this->client->productDetail($row['number']);
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return [$this->skipped($row, 'szczegóły wyrobu: '.$e->getMessage())];
        }
        if ($json['IsSuccess'] !== true || ! is_array($json['ProductDetail'] ?? null)) {
            $message = self::clean((string) ($json['Message'] ?? ''));

            return [$this->skipped($row, 'portal nie podał szczegółów wyrobu'.($message !== '' ? ' ('.$message.')' : ''))];
        }

        $detail = self::parseDetail($json['ProductDetail']);
        if ($detail['number'] !== $row['number']) {
            return [$this->skipped($row, 'szczegóły dotyczą innego numeru ('.$detail['number'].')')];
        }

        /** @var array<int, list<array{size: string, ean: string, cents: int|null, availability: string}>> $groups */
        $groups = [];
        $missing = false;
        foreach ($detail['sizes'] as $size) {
            if ($size['cents'] !== null && $size['cents'] > 0 && $size['ean'] !== '' && $size['size'] !== '') {
                $groups[$size['cents']][] = $size;
            } else {
                $missing = true;
            }
        }
        if ($missing) {
            $this->sizesWithoutPrice[] = $row['number'];
        }
        if ($groups === []) {
            return [$this->skipped($row, 'żaden rozmiar nie ma ceny')];
        }
        if ($detail['description'] === '') {
            $this->withoutDescription[] = $row['number'];
        }

        $split = count($groups) > 1;
        $this->splitArticles += $split ? 1 : 0;

        $products = [];
        foreach ($groups as $cents => $group) {
            $products[] = $this->productFor($row, $detail, $group, $cents, $split);
        }
        $this->cards += count($products);

        return $products;
    }

    /**
     * @param  array{number: string, name: string, image: string}  $row
     * @param  array{number: string, name: string, type: string, group: string, category: string, quality: string, color: string, description: string, image: string, sizes: list<array{size: string, ean: string, cents: int|null, availability: string}>}  $detail
     * @param  list<array{size: string, ean: string, cents: int|null, availability: string}>  $group
     */
    private function productFor(array $row, array $detail, array $group, int $cents, bool $split): B2bRemoteProduct
    {
        $code = self::articleCode($row['number']);
        $sku = $split ? $code.' '.$group[0]['size'] : $code;
        $base = self::cardName($detail['name'] !== '' ? $detail['name'] : $row['name'], $detail['group'], $code, $detail['color']);
        $name = $split ? $base.' ('.self::sizesLabel(array_column($group, 'size')).')' : $base;

        $members = array_map(static fn (array $size): array => [
            'remote_id' => $size['ean'],
            'sku' => $code.' '.$size['size'],
            'name' => $base.' '.$size['size'],
        ], $group);

        return new B2bRemoteProduct(
            remoteId: $group[0]['ean'],
            sku: $sku,
            name: $name,
            // rodzaj wyrobu po polsku („Kurtka membranowa”); pole Category portal podaje tylko po angielsku
            category: $detail['type'] !== '' ? $detail['type'] : null,
            sourceUrl: MascotB2bClient::PRODUCT_PAGE.rawurlencode($row['number']),
            raw: [
                'status' => 'ok',
                'price' => $cents / 100,
                'number' => $row['number'],
                'code' => $code,
                'group' => $detail['group'],
                'type' => $detail['type'],
                'quality' => $detail['quality'],
                'color' => $detail['color'],
                'category' => $detail['category'],
                'description' => $detail['description'],
                'image_url' => $detail['image'] !== '' ? $detail['image'] : $row['image'],
                'sizes' => array_map(static fn (array $s): array => ['size' => $s['size'], 'ean' => $s['ean']], $group),
            ],
            availability: self::groupAvailability($group),
            variantSummary: 'Rozmiary: '.implode('; ', array_map(
                static fn (array $s): string => $s['size'].' (EAN '.$s['ean'].')',
                $group,
            )),
            members: $members,
        );
    }

    /** „Kurtka membranowa MASCOT ACCELERATE 18001-249-1809, ciemny antracyt/czerń” — części puste pominięte. */
    private static function cardName(string $name, string $group, string $code, string $color): string
    {
        $parts = array_filter([$name, self::BRAND, $group, $code], static fn (string $s): bool => $s !== '');
        $text = implode(' ', array_unique($parts));

        return $color !== '' ? $text.', '.$color : $text;
    }

    /**
     * „rozm. 3XL, 4XL”; długa lista (spodnie mają po kilkadziesiąt rozmiarów) skrócona do pierwszych i ostatniego
     * z liczbą — pełna lista jest w podsumowaniu rozmiarów karty.
     *
     * @param  list<string>  $sizes
     */
    private static function sizesLabel(array $sizes): string
    {
        if (count($sizes) <= self::SIZES_IN_NAME) {
            return 'rozm. '.implode(', ', $sizes);
        }

        return count($sizes).' rozm.: '.implode(', ', array_slice($sizes, 0, self::SIZES_IN_NAME - 1)).' … '.$sizes[count($sizes) - 1];
    }

    /**
     * @param  array{number: string, name: string, image: string}  $row
     */
    private function skipped(array $row, string $reason): B2bRemoteProduct
    {
        $this->skippedArticles++;
        $code = self::articleCode($row['number']);

        return new B2bRemoteProduct(
            remoteId: $row['number'],
            sku: $code,
            name: $row['name'] !== '' ? $row['name'].' '.$code : $code,
            sourceUrl: MascotB2bClient::PRODUCT_PAGE.rawurlencode($row['number']),
            raw: ['status' => 'skipped', 'reason' => $reason],
        );
    }

    /**
     * Jedna dostępność dla wszystkich rozmiarów — dosłownie; różne — „Na stanie: S, M; Ograniczona dostępność: 4XL”.
     *
     * @param  list<array{size: string, ean: string, cents: int|null, availability: string}>  $group
     */
    private static function groupAvailability(array $group): ?string
    {
        $statuses = [];
        foreach ($group as $size) {
            $statuses[$size['availability']][] = $size['size'];
        }
        if (count($statuses) === 1) {
            return (string) array_key_first($statuses);
        }
        $parts = [];
        foreach ($statuses as $status => $sizes) {
            $parts[] = $status.': '.implode(', ', $sizes);
        }

        return implode('; ', $parts);
    }

    /** Opis dosłownie, z liniami ze źródła; komunikat o braku tłumaczenia = pusty opis. */
    private static function descriptionText(string $text): string
    {
        $lines = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = self::clean($line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }
        $text = implode("\n", $lines);

        return $text === self::NO_TEXT ? '' : mb_substr($text, 0, 10000);
    }

    /** Pole tekstowe portalu; komunikat o braku tłumaczenia = puste. */
    private static function field(mixed $value): string
    {
        $text = is_string($value) ? self::clean($value) : '';

        return $text === self::NO_TEXT ? '' : $text;
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

    private static function clean(string $value): string
    {
        return trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $value));
    }
}

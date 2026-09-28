<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bAccount;
use App\Models\ProductIdentifier;
use App\Models\ProductShopCard;
use Illuminate\Support\Str;
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
 * Karta = model (artykuł i jakość, „18001-249”) ze wszystkimi kolorami i rozmiarami z ceną (decyzje użytkownika
 * 28.09.2026: rozmiary w różnych cenach to jedna karta; w Mascot typowo XS–2XL w jednej cenie, 3XL i 4XL droższe —
 * a wieczorem: kolory jednego modelu to też jedna karta, z tabelą kolor × rozmiar). Model w jednym kolorze = karta jak
 * dotąd: SKU = kod artykułu z myślnikami („18001-249-1809”), nazwa z kolorem, pozycja = rozmiar. Model w kilku kolorach
 * (modelProducts) = SKU kodu modelu („18001-249”), nazwa nowej karty bez koloru (cardName), pozycja = kolor × rozmiar
 * z etykietą „{kolor z portalu} / {rozmiar}” i kodem „18001-249-1809 S”. Łączymy ostrożnie: tylko pozycje listy o tym
 * samym artykule i jakości (kod tkaniny), które w szczegółach mają ten sam rodzaj, kategorię i materiał, zgodny opis
 * (albo pusty po jednej stronie), różne nazwy kolorów i różne EAN-y — inaczej każdy kolor zostaje osobną kartą
 * (lista z powodem w podsumowaniu przebiegu); nazwa nie decyduje (modelDifference).
 * Pozycje (members): remote_id = EAN rozmiaru (jak dotąd — powiązania kart zostają), z etykietą, ceną i dostępnością;
 * cena karty = najniższa cena pozycji (raw['price'], price()), pozostałe ceny — wiersze karty. Dawne karty (kolor,
 * a do 28.09.2026 — decyzja 15.09.2026 — rozmiar w innej cenie, „18001-249-1809 3XL”) zostają, dopóki nie scali ich
 * „Scal rozmiary” (synchronizacja daje każdej jej pozycje, B2bCatalogSync::syncMembersByCard). Zdjęcie: jedno, jak
 * dotąd (tylko karcie bez zdjęć) — nowa karta modelu dostaje zdjęcie koloru wiodącego, dawna karta koloru — swojego
 * koloru; zdjęcia pozostałych kolorów przenosi na kartę scalenie. Rozmiary bez oznaczenia dostępności portal chowa
 * (nie da się ich zamówić) — tak samo tutaj.
 *
 * Opis: pole Description dosłownie. Portal nie podaje norm ani certyfikatów — łącznik ich nie dopisuje.
 * „Brak tekstu w tym języku” to komunikat portalu, nie wartość — pole traktujemy jako puste.
 */
final class MascotB2bConnector implements B2bConnector, B2bGroupsSizes, B2bListProgressAware, B2bManufacturerSite, B2bRunSummaryAware, B2bShopFieldSource, B2bSizePriceSource
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

    /** Podobieństwo opisów kolorów jednego modelu po normalizacji (sameDescription). */
    private const DESCRIPTION_SIMILARITY = 0.97;

    private int $total = 0;

    /** Karty modeli w kilku kolorach w przebiegu i liczba ich kolorów (runSummary). */
    private int $colourCards = 0;

    private int $colourArticles = 0;

    /** @var list<string> kody modeli, których kolory zostały osobnymi kartami, z powodem („18001-249 (inny materiał)”) */
    private array $splitModels = [];

    /** @var list<string> */
    private array $summary = [];

    /** @var list<string> numery artykułów z rozmiarami bez ceny (rozmiary poza kartami) */
    private array $sizesWithoutPrice = [];

    /** @var list<string> */
    private array $withoutDescription = [];

    private int $cards = 0;

    /** artykuły z rozmiarami w różnych cenach (jedna karta, cena od najniższej) */
    private int $multiPriceArticles = 0;

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
        $this->multiPriceArticles = 0;
        $this->skippedArticles = 0;
        $this->colourCards = 0;
        $this->colourArticles = 0;
        $this->splitModels = [];

        if (! $this->client->isLoggedIn()) {
            $this->client->login();
        }
        $currency = $this->client->currency();
        if ($currency !== 'PLN') {
            throw new RuntimeException('Konto '.MascotB2bClient::HOST.' ma ceny w walucie „'.$currency.'”, a katalog przyjmuje ceny w PLN — przebieg przerwany bez zapisu');
        }

        $rows = $this->listRows();
        $groups = self::modelGroups($rows);
        // na start zakładamy kartę na model; model rozdzielony po szczegółach (modelProducts) dokłada swoje karty
        $this->total = count($groups);
        $this->summary[] = 'Lista Mascot: '.count($rows).' artykułów w kolorach, '.count($groups).' modeli';

        foreach ($groups as $group) {
            if (count($group) === 1) {
                yield $this->productFor($group[0]);

                continue;
            }
            $products = $this->modelProducts($group);
            $this->total += count($products) - 1;
            foreach ($products as $product) {
                yield $product;
            }
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
            'Karty: %d (%d artykułów z rozmiarami w różnych cenach — jedna karta, cena karty = najniższa cena rozmiaru, ceny rozmiarów w tabeli rozmiarów karty; %d artykułów pominiętych)',
            $this->cards,
            $this->multiPriceArticles,
            $this->skippedArticles,
        );
        if ($this->colourCards > 0) {
            $lines[] = 'Modele w kilku kolorach: '.$this->colourCards.' kart z '.$this->colourArticles.' artykułów w kolorach (tabela kolor × rozmiar)';
        }
        if ($this->splitModels !== []) {
            $lines[] = 'Modele z kolorami na osobnych kartach (powód w nawiasie): '.self::listing($this->splitModels);
        }
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
     * Pola artykułu dosłownie z portalu (kolekcja, rodzaj, materiał, kolor, kategoria) i EAN-y pozycji karty. Karta
     * modelu w kilku kolorach: kod modelu, numery wszystkich kolorów, bez pola „Kolor” (kolory są w tabeli pozycji —
     * jeden kolor w tabelce mówiłby o całej karcie), EAN-y z etykietą „kolor / rozmiar”.
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
            'Numer w portalu' => implode('; ', $raw['numbers'] ?? []),
            'Kolekcja' => $raw['group'] ?? '',
            'Rodzaj' => $raw['type'] ?? '',
            'Materiał' => $raw['quality'] ?? '',
            'Kolor' => $raw['color'] ?? '',
            'Kategoria' => $raw['category'] ?? '',
            'EAN' => self::eanList(array_map(
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

    /**
     * Jedno zdjęcie (synchronizacja zapisuje je tylko karcie bez zdjęć): ujęcie artykułu w kolorze. Karta modelu
     * w kilku kolorach — zdjęcie koloru wiodącego; dawna karta koloru, którą synchronizacja prowadzi osobno do scalenia
     * (pozycje tylko jej koloru), dostaje zdjęcie swojego koloru, jak przed połączeniem kolorów.
     */
    public function image(B2bRemoteProduct $product): ?B2bRemoteImage
    {
        $url = (string) ($product->raw['image_url'] ?? '');
        $ids = $product->members !== [] ? array_column($product->members, 'remote_id') : [$product->remoteId];
        foreach (is_array($product->raw['colour_images'] ?? null) ? $product->raw['colour_images'] : [] as $colour) {
            if (array_intersect($colour['eans'], $ids) !== []) {
                $url = (string) $colour['url'];
                break;
            }
        }
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
     * Kod modelu (artykuł i jakość) z numeru pozycji: „180012491809” → „18001-249”; null = numer bez części koloru
     * (articleCode zostawia go bez zmian) — taka pozycja nie łączy się z innymi.
     */
    public static function modelCode(string $number): ?string
    {
        $code = self::articleCode($number);

        return $code !== trim($number) ? substr($code, 0, 9) : null;
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
     * Pozycje listy w modelach (modelCode), w kolejności pierwszego wystąpienia; numer bez kodu modelu — sam.
     *
     * @param  list<array{number: string, name: string, image: string}>  $rows
     * @return list<list<array{number: string, name: string, image: string}>>
     */
    private static function modelGroups(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $groups[self::modelCode($row['number']) ?? '#'.$row['number']][] = $row;
        }

        return array_values($groups);
    }

    /**
     * Karta jednego artykułu w kolorze ze wszystkimi rozmiarami z ceną; szczegóły nieczytelne albo cudze — pozycja
     * pominięta z powodem.
     *
     * @param  array{number: string, name: string, image: string}  $row
     */
    private function productFor(array $row): B2bRemoteProduct
    {
        $article = $this->article($row);

        return $article instanceof B2bRemoteProduct ? $article : $this->articleProduct($article);
    }

    /**
     * Model w kilku kolorach (pozycje listy o tym samym kodzie modelu). Kolory ze szczegółami i ceną to jedna karta
     * z tabelą kolor × rozmiar (colourProduct), gdy szczegóły potwierdzają ten sam wyrób (sameModel); inaczej — i gdy
     * cenę ma tylko jeden kolor — każdy kolor to karta jak dotąd. Kolor pominięty (szczegóły, żaden rozmiar bez ceny)
     * — pozycja pominięta z powodem, jak dotąd; błąd odczytu szczegółów któregoś koloru (nie treść) — cały model
     * pominięty w tym przebiegu (modelNotRead).
     *
     * @param  list<array{number: string, name: string, image: string}>  $group
     * @return list<B2bRemoteProduct>
     */
    private function modelProducts(array $group): array
    {
        $articles = [];
        $skipped = [];
        $failed = [];
        foreach ($group as $row) {
            $article = $this->article($row);
            if ($article instanceof B2bRemoteProduct) {
                $skipped[] = $article;
                if ($article->raw['read_error'] ?? false) {
                    $failed[] = $row['number'];
                }
            } else {
                $articles[] = $article;
            }
        }
        if ($failed !== []) {
            // błąd odczytu któregoś koloru — model stoi w tym przebiegu (modelNotRead)
            return $articles !== [] ? [$this->modelNotRead($articles, $failed), ...$skipped] : $skipped;
        }
        $difference = count($articles) >= 2 ? self::modelDifference($articles) : null;
        if (count($articles) >= 2 && $difference === null) {
            return [$this->colourProduct($articles), ...$skipped];
        }
        if ($difference !== null) {
            $this->splitModels[] = self::modelCode($group[0]['number']).' ('.$difference.')';
        }

        return [...array_map(fn (array $article): B2bRemoteProduct => $this->articleProduct($article), $articles), ...$skipped];
    }

    /**
     * Czy kolory to ten sam wyrób — u Mascot artykuł i jakość (kod tkaniny) wyznaczają wyrób, a kolory różnią się numerem
     * koloru; nazwy kolorów w portalu bywają różnie zapisane („Kurtka membranowa” / „Kurtka membranowa, niska waga”),
     * więc nazwa nie decyduje (nazwa karty — z koloru wiodącego). Muszą się zgadzać: rodzaj, kategoria i materiał
     * (po normalizacji: małe litery bez znaków diakrytycznych, same litery i cyfry), opis (sameDescription; pusty po
     * jednej stronie się nie liczy — karta dostaje pierwszy niepusty), nazwy kolorów różne (bez wielkości liter —
     * inaczej wiersze tabeli byłyby nie do odróżnienia) i żaden wspólny EAN.
     *
     * @param  list<array{row: array{number: string, name: string, image: string}, detail: array<string, mixed>, sizes: list<array{size: string, ean: string, cents: int, availability: string}>}>  $articles
     * @return string|null powód rozdzielenia do podsumowania przebiegu; null = ten sam wyrób
     */
    private static function modelDifference(array $articles): ?string
    {
        foreach (['type' => 'inny rodzaj', 'category' => 'inna kategoria', 'quality' => 'inny materiał'] as $field => $reason) {
            $values = array_map(static fn (array $a): string => self::normalised((string) $a['detail'][$field]), $articles);
            if (count(array_unique($values)) > 1) {
                return $reason;
            }
        }
        $descriptions = array_values(array_filter(
            array_map(static fn (array $a): string => (string) $a['detail']['description'], $articles),
            static fn (string $d): bool => $d !== '',
        ));
        foreach ($descriptions as $i => $description) {
            foreach (array_slice($descriptions, $i + 1) as $other) {
                if (! self::sameDescription($description, $other)) {
                    return 'inny opis';
                }
            }
        }
        $colours = array_map(static fn (array $a): string => mb_strtolower(self::colourLabel($a['row'], $a['detail'])), $articles);
        if (count(array_unique($colours)) !== count($colours)) {
            return 'powtórzona nazwa koloru';
        }
        $eans = array_merge(...array_map(static fn (array $a): array => array_column($a['sizes'], 'ean'), $articles));

        return count(array_unique($eans)) !== count($eans) ? 'wspólny EAN' : null;
    }

    /**
     * Opisy kolorów to ten sam tekst: procenty i gramatury co do jednej, reszta (po normalizacji) podobna co najmniej
     * w DESCRIPTION_SIMILARITY — drobne różnice zapisu w portalu, nie inna treść.
     */
    private static function sameDescription(string $a, string $b): bool
    {
        if (self::descriptionNumbers($a) !== self::descriptionNumbers($b)) {
            return false;
        }
        $x = self::normalised($a);
        $y = self::normalised($b);
        if ($x === $y) {
            return true;
        }
        $longest = max(strlen($x), strlen($y));
        // odległość jest nie mniejsza niż różnica długości — bez liczenia, gdy już ona przekracza próg
        if (abs(strlen($x) - strlen($y)) > (1 - self::DESCRIPTION_SIMILARITY) * $longest) {
            return false;
        }

        return 1 - levenshtein($x, $y) / $longest >= self::DESCRIPTION_SIMILARITY;
    }

    /**
     * Procenty („35%”) i gramatury („155 g/m2”, „190 gr/m²”) opisu — posortowane, z powtórzeniami.
     *
     * @return list<string>
     */
    private static function descriptionNumbers(string $text): array
    {
        $numbers = [];
        // „35,0” = „35.0” = „35”
        $number = static fn (string $value): string => (string) (float) str_replace(',', '.', $value);
        if (preg_match_all('/(\d+(?:[.,]\d+)?)\s*%/u', $text, $m) > 0) {
            foreach ($m[1] as $value) {
                $numbers[] = '%'.$number($value);
            }
        }
        if (preg_match_all('/(\d+(?:[.,]\d+)?)\s*gr?\s*\/\s*m\s*(?:2|²)/iu', $text, $m) > 0) {
            foreach ($m[1] as $value) {
                $numbers[] = 'g'.$number($value);
            }
        }
        sort($numbers);

        return $numbers;
    }

    /** Tekst do porównania: małe litery bez znaków diakrytycznych, tylko litery i cyfry. */
    private static function normalised(string $text): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', strtolower(Str::ascii($text)));
    }

    /**
     * Szczegóły artykułu i jego rozmiary z ceną; szczegóły nieczytelne, cudze albo bez żadnej ceny — pozycja pominięta
     * z powodem.
     *
     * @param  array{number: string, name: string, image: string}  $row
     * @return array{row: array{number: string, name: string, image: string}, detail: array<string, mixed>, sizes: list<array{size: string, ean: string, cents: int, availability: string}>}|B2bRemoteProduct
     */
    private function article(array $row): array|B2bRemoteProduct
    {
        try {
            $json = $this->client->productDetail($row['number']);
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return $this->skipped($row, 'szczegóły wyrobu: '.$e->getMessage(), readError: true);
        }
        if ($json['IsSuccess'] !== true || ! is_array($json['ProductDetail'] ?? null)) {
            $message = self::clean((string) ($json['Message'] ?? ''));

            return $this->skipped($row, 'portal nie podał szczegółów wyrobu'.($message !== '' ? ' ('.$message.')' : ''), readError: true);
        }

        $detail = self::parseDetail($json['ProductDetail']);
        if ($detail['number'] !== $row['number']) {
            return $this->skipped($row, 'szczegóły dotyczą innego numeru ('.$detail['number'].')', readError: true);
        }

        /** @var list<array{size: string, ean: string, cents: int, availability: string}> $sizes */
        $sizes = [];
        $missing = false;
        foreach ($detail['sizes'] as $size) {
            if ($size['cents'] !== null && $size['cents'] > 0 && $size['ean'] !== '' && $size['size'] !== '') {
                $sizes[] = $size;
            } else {
                $missing = true;
            }
        }
        if ($missing) {
            $this->sizesWithoutPrice[] = $row['number'];
        }
        if ($sizes === []) {
            return $this->skipped($row, 'żaden rozmiar nie ma ceny');
        }
        if ($detail['description'] === '') {
            $this->withoutDescription[] = $row['number'];
        }

        return ['row' => $row, 'detail' => $detail, 'sizes' => $sizes];
    }

    /**
     * Karta artykułu w jednym kolorze: rozmiary z ceną jako pozycje.
     *
     * @param  array{row: array{number: string, name: string, image: string}, detail: array<string, mixed>, sizes: list<array{size: string, ean: string, cents: int, availability: string}>}  $article
     */
    private function articleProduct(array $article): B2bRemoteProduct
    {
        ['row' => $row, 'detail' => $detail, 'sizes' => $sizes] = $article;
        $cents = array_column($sizes, 'cents');
        $this->multiPriceArticles += count(array_unique($cents)) > 1 ? 1 : 0;
        $this->cards++;

        $code = self::articleCode($row['number']);
        $name = self::articleName($row, $detail);

        $members = array_map(static fn (array $size): array => [
            'remote_id' => $size['ean'],
            'sku' => $code.' '.$size['size'],
            'name' => $name.' '.$size['size'],
            'availability' => $size['availability'],
            'size' => $size['size'],
            // jedna cena rozmiaru = cena konta; ceny katalogowej portal nie podaje
            'price' => new B2bRemotePrice(net: $size['cents'] / 100),
        ], $sizes);

        return new B2bRemoteProduct(
            remoteId: $sizes[0]['ean'],
            sku: $code,
            name: $name,
            // rodzaj wyrobu po polsku („Kurtka membranowa”); pole Category portal podaje tylko po angielsku
            category: $detail['type'] !== '' ? $detail['type'] : null,
            sourceUrl: MascotB2bClient::PRODUCT_PAGE.rawurlencode($row['number']),
            raw: [
                'status' => 'ok',
                // cena karty = najniższa cena rozmiaru (B2bCatalogSync liczy ją też z members[].price)
                'price' => min($cents) / 100,
                'numbers' => [$row['number']],
                'code' => $code,
                'group' => $detail['group'],
                'type' => $detail['type'],
                'quality' => $detail['quality'],
                'color' => $detail['color'],
                'category' => $detail['category'],
                'description' => $detail['description'],
                'image_url' => $detail['image'] !== '' ? $detail['image'] : $row['image'],
                'sizes' => array_map(static fn (array $s): array => ['size' => $s['size'], 'ean' => $s['ean']], $sizes),
            ],
            availability: self::groupAvailability($sizes),
            variantSummary: 'Rozmiary: '.implode('; ', array_map(
                static fn (array $s): string => $s['size'].' (EAN '.$s['ean'].')',
                $sizes,
            )),
            members: $members,
            // numer z portalu dosłownie (18001-249-1809 w nazwie to nasz zapis) i EAN każdego rozmiaru
            identifiers: [
                new B2bRemoteIdentifier(type: ProductIdentifier::TYPE_MANUFACTURER_CODE, value: $row['number'], field: 'Number'),
                ...array_map(static fn (array $size): B2bRemoteIdentifier => new B2bRemoteIdentifier(
                    type: ProductIdentifier::TYPE_EAN,
                    value: $size['ean'],
                    remoteId: $size['ean'],
                    label: $size['size'],
                    field: 'EanNumber',
                ), $sizes),
            ],
        );
    }

    /**
     * Karta modelu w kilku kolorach (decyzja użytkownika 28.09.2026 wieczór): pozycja = kolor × rozmiar z ceną konta,
     * etykieta „{kolor z portalu} / {rozmiar}”, kod „18001-249-1809 S” (z kodem koloru), remote_id = EAN jak dotąd.
     * Kolor wiodący = najniższy numer (niezależny od cen): jego pierwszy rozmiar daje remoteId, jego nazwa — nazwę ze
     * źródła i nazwę karty (bez koloru); opis — pierwszy niepusty w kolejności kolorów. SKU = kod modelu („18001-249” — inny niż kody kart kolorów, więc nie koliduje z nimi
     * w przebiegu), nazwa nowej karty bez koloru. Numer koloru z portalu (identyfikator) przy pierwszym rozmiarze koloru
     * — tam, gdzie leżał na karcie koloru (jej remoteId), więc dawna karta koloru zachowuje swój numer.
     *
     * @param  non-empty-list<array{row: array{number: string, name: string, image: string}, detail: array<string, mixed>, sizes: list<array{size: string, ean: string, cents: int, availability: string}>}>  $articles
     */
    private function colourProduct(array $articles): B2bRemoteProduct
    {
        usort($articles, static fn (array $a, array $b): int => strcmp($a['row']['number'], $b['row']['number']));
        ['row' => $leadRow, 'detail' => $leadDetail, 'sizes' => $leadSizes] = $articles[0];
        $model = self::modelCode($leadRow['number']) ?? self::articleCode($leadRow['number']);

        $members = [];
        $rows = [];
        $identifiers = [];
        $images = [];
        $colours = [];
        $sizeNames = [];
        foreach ($articles as ['row' => $row, 'detail' => $detail, 'sizes' => $sizes]) {
            $code = self::articleCode($row['number']);
            $name = self::articleName($row, $detail);
            $colour = self::colourLabel($row, $detail);
            $colours[] = $colour.' ('.self::colourCode($row['number']).')';
            $images[] = self::colourImage($row, $detail, $sizes);
            $identifiers[] = new B2bRemoteIdentifier(
                type: ProductIdentifier::TYPE_MANUFACTURER_CODE,
                value: $row['number'],
                remoteId: $sizes[0]['ean'],
                label: $colour,
                field: 'Number',
            );
            foreach ($sizes as $size) {
                $label = $colour.' / '.$size['size'];
                $members[] = [
                    'remote_id' => $size['ean'],
                    'sku' => $code.' '.$size['size'],
                    'name' => $name.' '.$size['size'],
                    'availability' => $size['availability'],
                    'size' => $label,
                    // jedna cena rozmiaru = cena konta; ceny katalogowej portal nie podaje
                    'price' => new B2bRemotePrice(net: $size['cents'] / 100),
                ];
                $rows[] = ['size' => $label, 'ean' => $size['ean'], 'cents' => $size['cents'], 'availability' => $size['availability']];
                $identifiers[] = new B2bRemoteIdentifier(
                    type: ProductIdentifier::TYPE_EAN,
                    value: $size['ean'],
                    remoteId: $size['ean'],
                    label: $label,
                    field: 'EanNumber',
                );
                $sizeNames[$size['size']] = true;
            }
        }
        $cents = array_column($rows, 'cents');
        $this->multiPriceArticles += count(array_unique($cents)) > 1 ? 1 : 0;
        $this->cards++;
        $this->colourCards++;
        $this->colourArticles += count($articles);

        return new B2bRemoteProduct(
            remoteId: $leadSizes[0]['ean'],
            sku: $model,
            name: self::articleName($leadRow, $leadDetail),
            category: $leadDetail['type'] !== '' ? $leadDetail['type'] : null,
            sourceUrl: MascotB2bClient::PRODUCT_PAGE.rawurlencode($leadRow['number']),
            raw: [
                'status' => 'ok',
                // cena karty = najniższa cena pozycji (B2bCatalogSync liczy ją też z members[].price)
                'price' => min($cents) / 100,
                'numbers' => array_map(static fn (array $a): string => $a['row']['number'], $articles),
                'code' => $model,
                'group' => $leadDetail['group'],
                'type' => $leadDetail['type'],
                'quality' => $leadDetail['quality'],
                // kolor jednego artykułu nie opisuje karty — kolory są w tabeli pozycji
                'color' => '',
                'category' => $leadDetail['category'],
                // pierwszy niepusty opis w kolejności kolorów (wiodący pierwszy) — opisy kolorów są zgodne (modelDifference)
                'description' => (string) (array_values(array_filter(
                    array_map(static fn (array $a): string => (string) $a['detail']['description'], $articles),
                    static fn (string $d): bool => $d !== '',
                ))[0] ?? ''),
                'image_url' => $images[0]['url'],
                // zdjęcie koloru dla dawnej karty koloru (image())
                'colour_images' => $images,
                'sizes' => array_map(static fn (array $r): array => ['size' => $r['size'], 'ean' => $r['ean']], $rows),
            ],
            availability: self::groupAvailability($rows),
            variantSummary: 'Kolory: '.implode(', ', $colours).'; rozmiary: '.implode(', ', array_map('strval', array_keys($sizeNames))),
            members: $members,
            identifiers: $identifiers,
            cardName: self::modelName($leadRow, $leadDetail),
        );
    }

    /**
     * Nazwa artykułu w kolorze jak dotąd.
     *
     * @param  array{number: string, name: string, image: string}  $row
     * @param  array<string, mixed>  $detail
     */
    private static function articleName(array $row, array $detail): string
    {
        return self::cardName($detail['name'] !== '' ? $detail['name'] : $row['name'], $detail['group'], self::articleCode($row['number']), $detail['color']);
    }

    /**
     * Nazwa modelu bez koloru: „Kurtka membranowa MASCOT ACCELERATE 18001-249”.
     *
     * @param  array{number: string, name: string, image: string}  $row
     * @param  array<string, mixed>  $detail
     */
    private static function modelName(array $row, array $detail): string
    {
        $code = self::modelCode($row['number']) ?? self::articleCode($row['number']);

        return self::cardName($detail['name'] !== '' ? $detail['name'] : $row['name'], $detail['group'], $code, '');
    }

    /**
     * Kolor dosłownie z portalu („ciemny antracyt/czerń”); bez nazwy (brak tłumaczenia) — „kolor 1809”.
     *
     * @param  array{number: string, name: string, image: string}  $row
     * @param  array<string, mixed>  $detail
     */
    private static function colourLabel(array $row, array $detail): string
    {
        return $detail['color'] !== '' ? $detail['color'] : 'kolor '.self::colourCode($row['number']);
    }

    /** Kod koloru z numeru: „180012491809” → „1809”. */
    private static function colourCode(string $number): string
    {
        return substr(trim($number), 8);
    }

    /**
     * Zdjęcie artykułu w kolorze (ze szczegółów, bez nich — z listy) z EAN-ami jego rozmiarów (image() wybiera kolor
     * pozycji karty).
     *
     * @param  array{number: string, name: string, image: string}  $row
     * @param  array<string, mixed>  $detail
     * @param  list<array{size: string, ean: string, cents: int, availability: string}>  $sizes
     * @return array{url: string, eans: list<string>}
     */
    private static function colourImage(array $row, array $detail, array $sizes): array
    {
        return ['url' => $detail['image'] !== '' ? $detail['image'] : $row['image'], 'eans' => array_column($sizes, 'ean')];
    }

    /** „Kurtka membranowa MASCOT ACCELERATE 18001-249-1809, ciemny antracyt/czerń” — części puste pominięte. */
    private static function cardName(string $name, string $group, string $code, string $color): string
    {
        $parts = array_filter([$name, self::BRAND, $group, $code], static fn (string $s): bool => $s !== '');
        $text = implode(' ', array_unique($parts));

        return $color !== '' ? $text.', '.$color : $text;
    }

    /**
     * @param  array{number: string, name: string, image: string}  $row
     * @param  bool  $readError  szczegóły nieodczytane (błąd odczytu, nie treść) — model tego koloru stoi w tym przebiegu
     */
    private function skipped(array $row, string $reason, bool $readError = false): B2bRemoteProduct
    {
        $this->skippedArticles++;
        $code = self::articleCode($row['number']);

        return new B2bRemoteProduct(
            remoteId: $row['number'],
            sku: $code,
            name: $row['name'] !== '' ? $row['name'].' '.$code : $code,
            sourceUrl: MascotB2bClient::PRODUCT_PAGE.rawurlencode($row['number']),
            raw: ['status' => 'skipped', 'reason' => $reason, 'read_error' => $readError],
        );
    }

    /**
     * Model, którego któregoś koloru nie dało się odczytać: karta modelu nie idzie w tym przebiegu (zapis bez tego koloru
     * oznaczyłby jego wiersze jako wycofane, zmienił cenę karty, a przy jednym kolorze — przełączył kartę na układ
     * jednego koloru). Pozycja pominięta z EAN-ami odczytanych kolorów jako pozycjami (bez cen) — synchronizacja uzna
     * je za nieudane i nie ruszy ich karty przy sprzątaniu wierszy i identyfikatorów.
     *
     * @param  non-empty-list<array{row: array{number: string, name: string, image: string}, detail: array<string, mixed>, sizes: list<array{size: string, ean: string, cents: int, availability: string}>}>  $articles
     * @param  list<string>  $failed  numery kolorów z błędem odczytu
     */
    private function modelNotRead(array $articles, array $failed): B2bRemoteProduct
    {
        usort($articles, static fn (array $a, array $b): int => strcmp($a['row']['number'], $b['row']['number']));
        ['row' => $leadRow, 'detail' => $leadDetail] = $articles[0];
        $members = [];
        foreach ($articles as ['row' => $row, 'detail' => $detail, 'sizes' => $sizes]) {
            $code = self::articleCode($row['number']);
            $name = self::articleName($row, $detail);
            foreach ($sizes as $size) {
                $members[] = ['remote_id' => $size['ean'], 'sku' => $code.' '.$size['size'], 'name' => $name.' '.$size['size']];
            }
        }
        $this->skippedArticles += count($articles);

        return new B2bRemoteProduct(
            remoteId: $members[0]['remote_id'],
            sku: self::modelCode($leadRow['number']) ?? self::articleCode($leadRow['number']),
            name: self::modelName($leadRow, $leadDetail),
            sourceUrl: MascotB2bClient::PRODUCT_PAGE.rawurlencode($leadRow['number']),
            raw: [
                'status' => 'skipped',
                'reason' => 'szczegóły innego koloru modelu nieodczytane ('.implode(', ', $failed).') — model bez zmian w tym przebiegu',
                'read_error' => true,
            ],
            members: $members,
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

    /**
     * EAN-y pozycji do jednego pola tabelki; lista dłuższa niż pole (ProductShopCard::MAX_VALUE_CHARS — karta modelu
     * w wielu kolorach) kończy się jawną informacją, nie ucięciem w pół — pełna lista jest w wierszach i identyfikatorach.
     *
     * @param  list<string>  $items
     */
    private static function eanList(array $items): string
    {
        $all = implode('; ', $items);
        if (mb_strlen($all) <= ProductShopCard::MAX_VALUE_CHARS) {
            return $all;
        }
        $more = '; … (pełna lista w tabeli wariantów)';
        $out = '';
        foreach ($items as $item) {
            $next = ($out === '' ? '' : $out.'; ').$item;
            if (mb_strlen($next.$more) > ProductShopCard::MAX_VALUE_CHARS) {
                break;
            }
            $out = $next;
        }

        return $out.$more;
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

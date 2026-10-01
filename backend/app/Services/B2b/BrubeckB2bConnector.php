<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bAccount;
use App\Models\ProductIdentifier;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Sklep B2B producenta odzieży termoaktywnej, bielizny i skarpet BRUBECK (FILATI Mirosław Kubiak S.K.A.) — Comarch
 * B2B na ERP XL, ta sama platforma co Fagum (BrubeckB2bClient i ComarchB2bClient opisują adres, TLS i logowanie).
 * Sprawdzone na zalogowanym koncie 01.10.2026.
 *
 * Różnice względem Fagum (FagumB2bConnector — wzór przebiegu, listy, działów i podsumowania): lista (articleListXl,
 * cała oferta konta: 2046 towarów 01.10.2026) podaje KAŻDY towar ERP osobno — jeden kolor w jednym rozmiarze, bez
 * wariantów sklepu (articleDetailsType „NotContainVariants” u wszystkich, getArticleVariantsDetailsXl = HTTP 500),
 * bez symbolu, bez atrybutów i plików; jedno zdjęcie na towar. Kod towaru ma stały układ
 * „P1BRU-{kolekcja}-{model}-{kolor}-{kod rozmiaru}-{rozmiar}” („P1BRU-THEN-LE1432M-99XX35XXX-26-L”), a nazwa —
 * „{model} {nazwa wyrobu} {kolor} {rozmiar}[ - {nadruk}]” („LS1414M Koszulka … OUTDOOR WOOL PRO czarny M - WILK”).
 *
 * Karta = model (trzeci człon kodu) ze wszystkimi kolorami i rozmiarami (decyzje użytkownika 28.09.2026: rozmiary
 * i kolory jednego wyrobu to jedna karta, cena karty = najniższa) — B2bGroupsSizes, B2bSizePriceSource. Ten sam
 * model bywa w kilku kolekcjach (drugi człon: kolory z różnych sezonów) — to nadal jeden wyrób; ten sam kolor z dwóch
 * kolekcji to jeden kolor, chyba że nazwa wyrobu jest inna (HM1008U: „Czapka Wełniana Protect” z linii PROTECT
 * i „Czapka wełniana unisex ACTIVE WOOL”, inne ceny — osobne karty, SKU z kolekcją i kolorem). Pozycja (remote_id) = id towaru w sklepie (stałe id ERP), sku
 * pozycji = pełny kod towaru. SKU karty = kod modelu („LE1432M”). Nazwa karty = kod modelu i wspólny początek nazw
 * kolorów (bez koloru i rozmiaru); nazwa ze źródła (remote_name) = nazwa towaru wiodącego. Kolor pozycji = część
 * nazwy po wspólnym początku (z nadrukiem), rozmiar = ostatnie słowo nazwy przed nadrukiem; nazwy mają literówki
 * w pojedynczych rozmiarach, więc nazwa koloru to najczęstsza wśród jego rozmiarów.
 *
 * Ceny (articleFromListXl, jedno zapytanie na towar): sklep podaje cenę za jednostkę podstawową „szt.” (bez jednostki
 * pomocniczej — unitNetPrice pusty, inaczej niż u Fagum): netPrice = cena konta, baseNetPrice = cena cennikowa (gdy
 * wyższa). Towar w jednostce pomocniczej (karton) nie był widziany na tym koncie — model pomijany z powodem, zamiast
 * zgadywać przelicznik. Warunku zamawiania sklep nie stawia (unitLockChange false) — jednostka w warunku.
 *
 * Co skąd: opis = opis towaru (articleBasicDetails.description: skład surowcowy, np. „80% bawełna 16% poliamid
 * 4% elastan”), dosłownie — z danych ogólnych towaru wiodącego każdej grupy kolor+kolekcja (pusty — z kolejnego
 * rozmiaru); grupy z innym opisem idą na osobne karty (SKU = kod modelu z kodem koloru; LS1414M ma skład raz
 * skrótami „73% PO 27% WOOL”, raz słownie — opisu nie wybieramy ani nie łączymy). Producent z danych towaru: marka
 * „BRUBECK” → „Brubeck” (wszystkie towary 01.10.2026; spółka w danych to FILATI, a przy części skarpet producent
 * kontraktowy Mondo-Calza albo Marek Bryła — w tabelce sklepu dosłownie); inna marka — dosłownie, bez zgadywania;
 * bez marki — Brubeck tylko dla spółki FILATI, inaczej nazwa spółki dosłownie. Zdjęcia: pierwsze zdjęcie towaru
 * wiodącego każdego koloru (bez zdjęcia w atrybutach — miniatura z listy).
 */
final class BrubeckB2bConnector implements B2bConnector, B2bGroupsSizes, B2bImageGallery, B2bListProgressAware, B2bManufacturerSite, B2bRunSummaryAware, B2bShopFieldSource, B2bSizePriceSource
{
    public const BRAND = 'Brubeck';

    private const SECTION = 'Informacje ze sklepu Brubeck';

    /** Spółka-właściciel marki w danych towaru („FILATI MIROSŁAW KUBIAK SPÓŁKA KOMANDYTOWO-AKCYJNA”). */
    private const OWNER_COMPANY = '/\bFILATI\b/iu';

    /** Kod towaru: P1BRU-{kolekcja}-{model}-{kolor}-{kod rozmiaru}-{rozmiar}. */
    private const CODE_PATTERN = '/^([A-Z0-9]+)-([A-Z0-9]+)-([A-Z0-9]+)-([A-Z0-9]+)-([A-Z0-9]+)-(\S+)$/u';

    /** Status towaru jak na stronie (ProductStatus w skrypcie sklepu; tłumaczenia articleState*). */
    private const STATUSES = [
        'Available' => 'Dostępny',
        'AvailableOnDemand' => 'Na zamówienie',
        'Preview' => 'Zapowiedź',
        'NotAvailable' => 'Niedostępny',
    ];

    /**
     * Tyle modeli z odczytanymi cenami, a żaden z ceną konta, zanim pojawi się pierwsza cena = sesja bez cen (albo
     * zmiana API) — przebieg przerwany. Modele pominięte z innego powodu się nie liczą.
     */
    private const MAX_FIRST_WITHOUT_PRICE = 20;

    private const LIST_BUDGET_SECONDS = 20 * 60;

    private const INCONSISTENT = 7301;

    /** Komunikat postępu co tyle stron listy. */
    private const PROGRESS_EVERY = 10;

    private int $total = 0;

    private int $cards = 0;

    private int $multiPrice = 0;

    private int $withPrice = 0;

    /** @var list<string> */
    private array $summary = [];

    /** @var list<string> */
    private array $withoutPrice = [];

    /** @var list<string> */
    private array $itemsWithoutPrice = [];

    /** @var list<string> */
    private array $withoutBase = [];

    /** @var list<string> */
    private array $withoutDescription = [];

    /** @var list<string> */
    private array $splitByDescription = [];

    /** @var list<string> */
    private array $splitByName = [];

    /** @var list<string> */
    private array $oddCodes = [];

    /** @var list<string> */
    private array $withExtras = [];

    /** @var list<string> */
    private array $thresholdPrices = [];

    /** @var array<string, int> producent zapisany dosłownie (spoza marki Brubeck) → liczba kart */
    private array $otherManufacturers = [];

    /** @var (callable(string): void)|null */
    private $listProgress = null;

    public function __construct(private readonly BrubeckB2bClient $client) {}

    public static function key(): string
    {
        return 'brubeck';
    }

    public static function label(): string
    {
        return self::BRAND;
    }

    public static function host(): string
    {
        return BrubeckB2bClient::HOST;
    }

    public static function ownBrand(): string
    {
        return self::BRAND;
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new self(new BrubeckB2bClient(
            (string) $account->contractor_code,
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
        $this->withoutPrice = [];
        $this->itemsWithoutPrice = [];
        $this->withoutBase = [];
        $this->withoutDescription = [];
        $this->splitByDescription = [];
        $this->splitByName = [];
        $this->oddCodes = [];
        $this->withExtras = [];
        $this->thresholdPrices = [];
        $this->otherManufacturers = [];
        $this->cards = 0;
        $this->multiPrice = 0;
        $this->withPrice = 0;

        if (! $this->client->isLoggedIn()) {
            $this->client->login();
        }

        $rows = $this->listRows();
        $models = $this->models($rows);
        $this->total = count($models);
        $this->summary[] = 'Lista Brubeck: '.count($rows).' towarów (kolor i rozmiar), '.count($models).' modeli';
        if ($this->oddCodes !== []) {
            $this->summary[] = 'Kod towaru spoza układu P1BRU-kolekcja-model-kolor-rozmiar — karta z jednego towaru: '.self::listing($this->oddCodes);
        }
        $categories = $this->categories();

        foreach ($models as $model) {
            foreach ($this->productsFor($model, $categories) as $product) {
                yield $product;
            }
            if ($this->withPrice === 0 && count($this->withoutPrice) >= self::MAX_FIRST_WITHOUT_PRICE) {
                throw new B2bFatalException(count($this->withoutPrice).' pierwszych modeli '.BrubeckB2bClient::HOST.' bez żadnej ceny konta — sesja konta nie pokazuje cen albo sklep zmienił API; przebieg przerwany');
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
            'Karty: %d (%d z pozycjami w różnych cenach — cena karty = najniższa, ceny kolorów i rozmiarów w tabeli rozmiarów karty)',
            $this->cards,
            $this->multiPrice,
        );
        if ($this->splitByDescription !== []) {
            $lines[] = 'Kolory jednego modelu z innym opisem (składem) — osobne karty: '.self::listing($this->splitByDescription);
        }
        if ($this->splitByName !== []) {
            $lines[] = 'Ten sam kod modelu i koloru z inną nazwą wyrobu — osobne karty: '.self::listing($this->splitByName);
        }
        if ($this->withoutPrice !== []) {
            $lines[] = 'Bez ceny konta (pominięte): '.self::listing($this->withoutPrice);
        }
        if ($this->itemsWithoutPrice !== []) {
            $lines[] = 'Towary bez ceny konta (poza kartami): '.self::listing($this->itemsWithoutPrice);
        }
        if ($this->withoutBase !== []) {
            $lines[] = 'Bez ceny cennikowej wyższej od ceny konta: '.self::listing($this->withoutBase);
        }
        if ($this->thresholdPrices !== []) {
            $lines[] = 'Ceny progowe w sklepie (karta ma cenę za 1 szt.): '.self::listing($this->thresholdPrices);
        }
        if ($this->withoutDescription !== []) {
            $lines[] = 'Bez opisu w sklepie: '.self::listing($this->withoutDescription);
        }
        if ($this->withExtras !== []) {
            $lines[] = 'Atrybuty albo pliki w sklepie (łącznik ich jeszcze nie zapisuje): '.self::listing($this->withExtras);
        }
        foreach ($this->otherManufacturers as $name => $count) {
            $lines[] = 'Producent spoza marki Brubeck (zapisany dosłownie): '.$name.' — '.$count.' kart';
        }

        return $lines;
    }

    public function manufacturer(B2bRemoteProduct $product): string
    {
        return (string) ($product->raw['manufacturer'] ?? self::BRAND);
    }

    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        if (($product->raw['status'] ?? null) !== 'ok') {
            throw new RuntimeException((string) ($product->raw['reason'] ?? 'towar nieodczytany'));
        }
        $price = $product->raw['price'] ?? null;
        if (! $price instanceof B2bRemotePrice) {
            throw new RuntimeException('towar bez ceny konta');
        }

        return $price;
    }

    public function description(B2bRemoteProduct $product): string
    {
        return (string) ($product->raw['description'] ?? '');
    }

    /**
     * @return list<B2bRemoteShopField>
     */
    public function shopFields(B2bRemoteProduct $product): array
    {
        $raw = $product->raw;
        if (($raw['status'] ?? null) !== 'ok') {
            return [];
        }

        $fields = [];
        $add = static function (string $name, string $value) use (&$fields): void {
            if ($value !== '') {
                $fields[] = new B2bRemoteShopField(self::SECTION, $name, $value);
            }
        };
        $add('Producent', (string) $raw['manufacturer_name']);
        $add('Marka', (string) $raw['brand']);
        $add('Model', (string) $raw['model']);
        $add('Cena za', implode(', ', $raw['units']));

        return $fields;
    }

    /**
     * @return list<string>
     */
    public function imageUrls(B2bRemoteProduct $product): array
    {
        return $product->raw['images'] ?? [];
    }

    public function imageAt(string $url): ?B2bRemoteImage
    {
        $file = $this->client->fileBytes($url);
        if ($file['bytes'] === '' || ! str_starts_with($file['mime'], 'image/')) {
            return null;
        }

        return new B2bRemoteImage(bytes: $file['bytes'], mime: $file['mime'], sourceUrl: $url);
    }

    public function image(B2bRemoteProduct $product): ?B2bRemoteImage
    {
        $urls = $this->imageUrls($product);

        return $urls === [] ? null : $this->imageAt($urls[0]);
    }

    /**
     * Cena konta towaru za jednostkę podstawową: null = towar poza cennikiem konta albo bez ceny. Cena w jednostce
     * pomocniczej (karton z przelicznikiem) — błąd: na tym koncie jej nie było, więc przelicznika nie zgadujemy.
     *
     * @param  array<string, mixed>  $json  odpowiedź articleFromListXl
     * @return array{net: float, base: float|null, currency: string, unit: string, ean: string, thresholds: bool}|null
     */
    public static function parsePrice(array $json): ?array
    {
        if (($json['itemExistsInCurrentPriceList'] ?? null) !== true) {
            return null;
        }
        $price = is_array($json['price'] ?? null) ? $json['price'] : [];
        $unit = is_array($json['unit'] ?? null) ? $json['unit'] : [];
        if (($unit['auxiliaryUnit']['representsExistingValue'] ?? false) === true) {
            throw new RuntimeException('cena w jednostce pomocniczej „'.self::clean((string) ($unit['auxiliaryUnit']['unit'] ?? '')).'” — łącznik nie zna przelicznika tego konta');
        }
        $net = self::number($price['netPrice'] ?? null);
        if ($net === null || $net <= 0) {
            return null;
        }
        $net = round($net, 2);
        $base = self::number($price['baseNetPrice'] ?? null);
        $base = $base !== null ? round($base, 2) : null;
        $currency = mb_strtoupper(self::clean((string) ($price['currency'] ?? '')));
        $ean = self::clean((string) ($json['ean'] ?? ''));

        return [
            'net' => $net,
            'base' => $base !== null && $base > $net ? $base : null,
            'currency' => $currency !== '' ? $currency : 'PLN',
            'unit' => self::clean((string) ($unit['basicUnit'] ?? '')),
            'ean' => preg_match('/^\d{8,14}$/', $ean) === 1 ? $ean : '',
            'thresholds' => ($json['thresholdPriceLists']['hasAnyThresholdPriceList'] ?? false) === true,
        ];
    }

    /**
     * Rozkład nazwy towaru na część koloru (nazwa wyrobu z kolorem i nadrukiem) i rozmiar: rozmiar = ostatnie słowo
     * przed nadrukiem („ - WILK”), nadruk zostaje przy kolorze. „LS1414M Koszulka … czarny M - WILK” →
     * [„LS1414M Koszulka … czarny - WILK”, „M”].
     *
     * @return array{0: string, 1: string}
     */
    public static function splitName(string $name): array
    {
        $name = self::clean($name);
        $print = '';
        $dash = mb_strpos($name, ' - ');
        if ($dash !== false) {
            $print = mb_substr($name, $dash);
            $name = mb_substr($name, 0, $dash);
        }
        $space = mb_strrpos($name, ' ');
        if ($space === false) {
            return [$name.$print, ''];
        }

        return [mb_substr($name, 0, $space).$print, mb_substr($name, $space + 1)];
    }

    /**
     * Nazwa karty = kod modelu i wspólny początek nazw kolorów (całe słowa, bez końcowych łączników); kolor = reszta
     * nazwy koloru. Pierwsze słowo nazwy to kod modelu, w sklepie czasem z literówką („LE13540” przy LE1354J), więc
     * w nazwie karty stoi kod modelu z kodu towaru ($model; null = nazwa bez zmian, kod spoza układu). Jeden kolor —
     * cała nazwa koloru jest nazwą karty, a kolor pusty (sklep nie podaje go osobno).
     *
     * @param  non-empty-list<string>  $colourNames
     * @return array{0: string, 1: list<string>}
     */
    public static function cardNameAndColours(?string $model, array $colourNames): array
    {
        $split = array_map(static fn (string $name): array => explode(' ', $name), $colourNames);
        if ($model !== null) {
            $split = array_map(static fn (array $words): array => array_slice($words, 1), $split);
        }
        $named = static fn (array $words): string => trim(($model !== null ? $model.' ' : '').implode(' ', $words));
        if (count($split) === 1) {
            return [$named($split[0]), ['']];
        }
        $common = $split[0];
        foreach ($split as $words) {
            $i = 0;
            while ($i < count($common) && $i < count($words) && $common[$i] === $words[$i]) {
                $i++;
            }
            $common = array_slice($common, 0, $i);
        }
        while ($common !== [] && preg_match('/^[-\/–,]+$/u', (string) end($common)) === 1) {
            array_pop($common);
        }
        $colours = array_map(
            static fn (array $words): string => trim((string) preg_replace('/^[-\/–,\s]+/u', '', implode(' ', array_slice($words, count($common))))),
            $split,
        );

        return [$common !== [] ? $named($common) : $named($split[0]), $colours];
    }

    /**
     * Producent karty z danych towaru: marka BRUBECK → „Brubeck”; inna marka — dosłownie; bez marki — Brubeck dla
     * spółki FILATI (właściciel marki), inna spółka dosłownie; bez marki i spółki — Brubeck (sklep producenta).
     */
    public static function manufacturerName(string $brand, string $company): string
    {
        $brand = self::clean($brand);
        $company = self::clean($company);

        return match (true) {
            $brand !== '' => mb_strtolower($brand) === mb_strtolower(self::BRAND) ? self::BRAND : $brand,
            $company === '', preg_match(self::OWNER_COMPANY, $company) === 1 => self::BRAND,
            default => $company,
        };
    }

    /**
     * Cała lista; niespójna (towar na dwóch stronach, inna liczba stron) — jedno ponowne pobranie od początku.
     *
     * @return array<int, array{id: int, name: string, code: string, status: string, image: int|null}>
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

            throw new RuntimeException('Lista towarów '.BrubeckB2bClient::HOST.' niespójna także po ponownym pobraniu: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array<int, array{id: int, name: string, code: string, status: string, image: int|null}>
     */
    private function scanList(): array
    {
        $started = microtime(true);
        $rows = [];
        $pages = 1;
        for ($page = 1; $page <= $pages; $page++) {
            if (microtime(true) - $started > self::LIST_BUDGET_SECONDS) {
                throw new RuntimeException('Pobieranie listy '.BrubeckB2bClient::HOST.' trwa ponad '.(self::LIST_BUDGET_SECONDS / 60).' min — przerwane bez zapisu');
            }
            $json = $this->client->listPage($page);
            $pageCount = is_int($json['paging']['totalPages'] ?? null) ? $json['paging']['totalPages'] : -1;
            if ($page === 1) {
                $pages = $pageCount;
                if ($pages <= 0 || $json['articleList'] === []) {
                    throw new RuntimeException('Lista towarów '.BrubeckB2bClient::HOST.' pusta albo bez liczby stron (totalPages '.$pageCount.') — zmiana witryny?');
                }
                $this->progress('Lista towarów Brubeck: '.$pages.' stron');
            } elseif ($pageCount !== $pages) {
                throw new RuntimeException('liczba stron zmieniła się z '.$pages.' na '.$pageCount.' (strona '.$page.')', self::INCONSISTENT);
            }
            foreach ($json['articleList'] as $item) {
                $article = is_array($item) && is_array($item['article'] ?? null) ? $item['article'] : [];
                $id = is_int($article['id'] ?? null) ? $article['id'] : 0;
                if ($id <= 0) {
                    throw new RuntimeException('Strona '.$page.' listy '.BrubeckB2bClient::HOST.': pozycja bez id towaru — zmiana witryny?');
                }
                if (isset($rows[$id])) {
                    throw new RuntimeException('towar '.$id.' na dwóch stronach', self::INCONSISTENT);
                }
                $rows[$id] = [
                    'id' => $id,
                    'name' => self::clean((string) ($article['name'] ?? '')),
                    'code' => self::clean((string) ($article['code']['value'] ?? '')),
                    'status' => self::clean((string) ($item['status'] ?? '')),
                    // miniatura z listy (imageType 1 = obraz w sklepie) — zapas, gdy towar wiodący koloru nie ma zdjęcia
                    'image' => ($article['image']['imageType'] ?? null) === 1 && is_int($article['image']['imageId'] ?? null) ? $article['image']['imageId'] : null,
                ];
            }
            if ($page % self::PROGRESS_EVERY === 0) {
                $this->progress('Lista towarów Brubeck: strona '.$page.'/'.$pages);
            }
        }

        return $rows;
    }

    /**
     * Towary listy pogrupowane w modele (prefiks i trzeci człon kodu), w kolejności listy; w modelu — grupy kolorów
     * (kolekcja i kolor: drugi i czwarty człon) w kolejności pierwszego wystąpienia, a w grupie — rozmiary w kolejności
     * kodu rozmiaru (piąty człon, jak w tabelach rozmiarów sklepu: XS 23, S 24, M 25…). Kod spoza układu — osobny
     * model z jednego towaru.
     *
     * @param  array<int, array{id: int, name: string, code: string, status: string, image: int|null}>  $rows
     * @return list<array{key: string, model: string, colours: list<non-empty-list<array{id: int, name: string, code: string, status: string, image: int|null, collection: string, colour: string, size_code: string}>>}>
     */
    private function models(array $rows): array
    {
        $models = [];
        foreach ($rows as $row) {
            if (preg_match(self::CODE_PATTERN, $row['code'], $m) === 1) {
                $key = $m[1].'|'.$m[3];
                $model = $m[3];
                $collection = $m[2];
                $colour = $m[4];
                $sizeCode = $m[5];
            } else {
                $key = 'id|'.$row['id'];
                $model = $row['code'] !== '' ? $row['code'] : (string) $row['id'];
                $collection = '';
                $colour = '';
                $sizeCode = '';
                $this->oddCodes[] = $model;
            }
            $models[$key] ??= ['key' => $key, 'model' => $model, 'colours' => []];
            $models[$key]['colours'][$collection.'|'.$colour][] = $row + ['collection' => $collection, 'colour' => $colour, 'size_code' => $sizeCode];
        }

        return array_values(array_map(static function (array $model): array {
            $colours = [];
            foreach ($model['colours'] as $items) {
                usort($items, static fn (array $a, array $b): int => strnatcmp($a['size_code'], $b['size_code']) ?: $a['id'] <=> $b['id']);
                $colours[] = $items;
            }
            $model['colours'] = $colours;

            return $model;
        }, $models));
    }

    /**
     * Działy towarów z drzewa grup: id towaru → nazwy grup dosłownie (także „02 SUPER CENA” i „02 WYPRZEDAŻ” —
     * towary z tych grup zwykle nie są w innym dziale). Błąd — bez kategorii, bo kategoria nie jest powodem, by pominąć
     * towar.
     *
     * @return array<int, list<string>>
     */
    private function categories(): array
    {
        $out = [];
        try {
            foreach ($this->client->groups() as $group) {
                $groupId = is_int($group['id'] ?? null) ? $group['id'] : 0;
                $name = self::clean((string) ($group['name'] ?? ''));
                if ($groupId <= 0 || $name === '') {
                    continue;
                }
                $pages = 1;
                for ($page = 1; $page <= $pages; $page++) {
                    $json = $this->client->groupPage($groupId, $page);
                    $pages = is_int($json['paging']['totalPages'] ?? null) ? $json['paging']['totalPages'] : 1;
                    foreach ($json['articleList'] as $item) {
                        $id = is_array($item) ? ($item['article']['id'] ?? null) : null;
                        if (is_int($id) && ! in_array($name, $out[$id] ?? [], true)) {
                            $out[$id][] = $name;
                        }
                    }
                }
            }
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            $this->summary[] = 'Działy z drzewa grup nieodczytane ('.$e->getMessage().') — karty bez kategorii';

            return [];
        }

        return $out;
    }

    /**
     * Karty jednego modelu: dane ogólne i zdjęcia towaru wiodącego każdej grupy kolorów, cena każdego towaru. Grupy
     * z różnymi opisami — osobne karty (opis karty jest jeden i dosłowny, a inny skład może znaczyć inny wyrób pod tym
     * samym kodem modelu).
     *
     * @param  array{key: string, model: string, colours: list<non-empty-list<array{id: int, name: string, code: string, status: string, image: int|null, collection: string, colour: string, size_code: string}>>}  $model
     * @param  array<int, list<string>>  $categories
     * @return list<B2bRemoteProduct>
     */
    private function productsFor(array $model, array $categories): array
    {
        $allIds = [];
        foreach ($model['colours'] as $items) {
            foreach ($items as $item) {
                $allIds[] = $item['id'];
            }
        }
        $leadName = $model['colours'][0][0]['name'];

        $groups = [];
        try {
            foreach ($model['colours'] as $items) {
                // opis bywa pusty w pojedynczych rozmiarach („HM1008U … S/M” bez składu, XS ze składem) — dane
                // kolejnych rozmiarów, aż któryś poda opis; wszystkie puste — kolor bez opisu
                $info = $this->client->generalInfo($items[0]['id']);
                $description = FagumB2bConnector::descriptionText((string) ($info['articleBasicDetails']['description'] ?? ''));
                for ($i = 1; $description === '' && $i < count($items); $i++) {
                    $next = $this->client->generalInfo($items[$i]['id']);
                    $description = FagumB2bConnector::descriptionText((string) ($next['articleBasicDetails']['description'] ?? ''));
                }
                $extras = $this->client->attributes($items[0]['id']);
                $priced = [];
                foreach ($items as $item) {
                    $price = self::parsePrice($this->client->price($item['id']));
                    if ($price === null) {
                        $this->itemsWithoutPrice[] = $item['code'];

                        continue;
                    }
                    if ($price['thresholds']) {
                        $this->thresholdPrices[] = $item['code'];
                    }
                    $priced[] = $item + ['price' => $price];
                }
                if ($priced === []) {
                    continue;
                }
                if (self::hasExtras($extras)) {
                    $this->withExtras[] = $items[0]['code'];
                }
                $images = self::imageLinks($extras['articleImages'] ?? null);
                if ($images === []) {
                    $images = self::imageLinks(array_map(
                        static fn (array $item): array => ['imageType' => $item['image'] !== null ? 1 : 0, 'imageId' => $item['image']],
                        $items,
                    ));
                }
                $groups[] = ['info' => $info, 'description' => $description, 'images' => $images, 'items' => $priced];
            }
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return [$this->skipped($model['model'], $leadName, 'dane albo ceny: '.$e->getMessage(), $allIds)];
        }

        if ($groups === []) {
            $this->withoutPrice[] = $model['model'];

            return [];
        }
        $this->withPrice++;

        // Grupa dołącza do karty z tym samym opisem, jeśli karta nie ma już tego kodu koloru pod inną nazwą wyrobu.
        // Inny opis — osobna karta; ten sam kod koloru z inną nazwą (HM1008U: „Czapka Wełniana Protect czarny” z linii
        // PROTECT obok „Czapka wełniana unisex ACTIVE WOOL czarny”, inne ceny) — inny wyrób pod tym samym kodem modelu,
        // też osobna karta.
        $cards = [];
        $descriptions = [];
        $renamed = false;
        foreach ($groups as $group) {
            $descriptions[$group['description']] = true;
            $name = self::colourName($group['items']);
            $target = null;
            foreach ($cards as $i => $card) {
                if ($card[0]['description'] !== $group['description']) {
                    continue;
                }
                $conflict = false;
                foreach ($card as $member) {
                    if ($member['items'][0]['colour'] === $group['items'][0]['colour'] && ! self::sameColourName(self::colourName($member['items']), $name)) {
                        $conflict = true;
                        break;
                    }
                }
                if ($conflict) {
                    $renamed = true;

                    continue;
                }
                $target = $i;
                break;
            }
            if ($target === null) {
                $cards[] = [$group];
            } else {
                $cards[$target][] = $group;
            }
        }
        if (count($descriptions) > 1) {
            $this->splitByDescription[] = $model['model'].' ('.count($descriptions).' opisy)';
        }
        if ($renamed) {
            $this->splitByName[] = $model['model'];
        }

        // SKU osobnych kart: kod modelu z kodem koloru pierwszej grupy, a gdy ten się powtarza — także z kolekcją
        $leads = array_map(static fn (array $cardGroups): array => $cardGroups[0]['items'][0], $cards);
        $colourCounts = array_count_values(array_map(static fn (array $lead): string => $lead['colour'], $leads));
        $out = [];
        foreach ($cards as $i => $cardGroups) {
            $lead = $leads[$i];
            $sku = $model['model'];
            if (count($cards) > 1) {
                $suffix = $colourCounts[$lead['colour']] > 1 ? $lead['collection'].'-'.$lead['colour'] : $lead['colour'];
                $sku .= '-'.(trim($suffix, '-') !== '' ? trim($suffix, '-') : (string) $lead['id']);
            }
            $out[] = $this->cardFor($model['model'], $sku, $cardGroups[0]['description'], $cardGroups, $categories);
        }

        return $out;
    }

    /**
     * Karta z grup kolorów o jednym opisie. Grupy tego samego koloru z różnych kolekcji i o tej samej nazwie (HM1008U
     * czarna „ACTIVE WOOL” z ACSA i ACSB) to jeden kolor. Kolor = najczęstsza część koloru nazw jego towarów (nazwy
     * w sklepie mają literówki w pojedynczych rozmiarach: „bokserki meskie”, „pudrowy róź”, „LE13540” zamiast „LE1354J”).
     *
     * @param  non-empty-list<array{info: array<string, mixed>, description: string, images: list<string>, items: non-empty-list<array{id: int, name: string, code: string, status: string, collection: string, colour: string, size_code: string, price: array{net: float, base: float|null, currency: string, unit: string, ean: string, thresholds: bool}}>}>  $groups
     * @param  array<int, list<string>>  $categories
     */
    private function cardFor(string $model, string $sku, string $description, array $groups, array $categories): B2bRemoteProduct
    {
        $colours = [];
        foreach ($groups as $group) {
            // ten sam kod koloru i ta sama nazwa (z dokładnością do literówki) — jeden kolor; inna nazwa przy tym samym
            // kodzie koloru trafia na osobną kartę już w productsFor, a gdyby tu doszła — zostaje osobnym wierszem
            $code = $group['items'][0]['colour'] !== '' ? $group['items'][0]['colour'] : (string) $group['items'][0]['id'];
            $name = self::colourName($group['items']);
            $match = null;
            foreach ($colours as $i => $colour) {
                if ($colour['code'] === $code && self::sameColourName($colour['name'], $name)) {
                    $match = $i;
                    break;
                }
            }
            if ($match === null) {
                $colours[] = ['code' => $code, 'name' => $name, 'images' => $group['images'], 'items' => $group['items']];

                continue;
            }
            $colours[$match]['images'] = array_values(array_unique([...$colours[$match]['images'], ...$group['images']]));
            $colours[$match]['items'] = [...$colours[$match]['items'], ...$group['items']];
        }
        // rozmiary koloru złożonego z kilku kolekcji — znów w kolejności kodu rozmiaru
        foreach ($colours as $i => $colour) {
            usort($colour['items'], static fn (array $a, array $b): int => strnatcmp($a['size_code'], $b['size_code']) ?: $a['id'] <=> $b['id']);
            $colours[$i]['items'] = $colour['items'];
        }
        $allIds = [];
        $currencies = [];
        foreach ($colours as $colour) {
            foreach ($colour['items'] as $item) {
                $allIds[] = $item['id'];
                $currencies[$item['price']['currency']] = true;
            }
        }
        $lead = $colours[0]['items'][0];
        if (count($currencies) !== 1) {
            return $this->skipped($sku, $lead['name'], 'pozycje bez jednej waluty ceny ('.implode(', ', array_keys($currencies)).')', $allIds);
        }

        $colourNames = array_map(static fn (array $colour): string => self::colourName($colour['items']), $colours);
        [$cardName, $colourLabels] = self::cardNameAndColours($lead['colour'] !== '' ? $model : null, $colourNames);
        $manyColours = count($colours) > 1;
        // dwa kolory o tej samej nazwie (różny kod koloru) — etykieta z kodem koloru, żeby wiersze się nie myliły
        $labelCounts = array_count_values($colourLabels);
        foreach ($colours as $i => $colour) {
            if ($manyColours && ($colourLabels[$i] === '' || $labelCounts[$colourLabels[$i]] > 1)) {
                $colourLabels[$i] = trim($colourLabels[$i].' ('.($colour['items'][0]['colour'] !== '' ? $colour['items'][0]['colour'] : $colour['items'][0]['id']).')');
            }
        }

        $details = $groups[0]['info']['articleBasicDetails'] ?? [];
        $brand = self::clean((string) ($details['brand']['name'] ?? ''));
        $company = self::clean((string) ($details['manufacturer']['name'] ?? ''));
        $manufacturer = self::manufacturerName($brand, $company);
        if ($manufacturer !== self::BRAND) {
            $this->otherManufacturers[$manufacturer] = ($this->otherManufacturers[$manufacturer] ?? 0) + 1;
        }
        if ($description === '') {
            $this->withoutDescription[] = $sku;
        }

        $members = [];
        $prices = [];
        $identifiers = [new B2bRemoteIdentifier(type: ProductIdentifier::TYPE_MODEL_CODE, value: $model, field: 'Kod')];
        $statuses = [];
        $sizeNames = [];
        $images = [];
        $units = [];
        $withoutBase = false;
        foreach ($colours as $i => $colour) {
            if ($colour['images'] !== []) {
                $images[] = $colour['images'][0];
            }
            $sizes = array_map(static fn (array $item): string => self::splitName($item['name'])[1], $colour['items']);
            $sizeCounts = array_count_values($sizes);
            foreach ($colour['items'] as $j => $item) {
                $p = $item['price'];
                $size = $sizes[$j];
                // ten sam rozmiar koloru z dwóch kolekcji — rozmiar z kodem kolekcji
                $sizeLabel = $size !== '' && $sizeCounts[$size] > 1 && $item['collection'] !== '' ? $size.' ('.$item['collection'].')' : $size;
                $label = $manyColours ? $colourLabels[$i].' / '.$sizeLabel : $sizeLabel;
                $price = new B2bRemotePrice(
                    net: $p['net'],
                    base: $p['base'],
                    discountPercent: $p['base'] !== null ? round((1 - $p['net'] / $p['base']) * 100, 2) : 0.0,
                    currency: $p['currency'],
                );
                $prices[] = $price;
                $withoutBase = $withoutBase || $p['base'] === null;
                $units[$p['unit']] = true;
                $availability = self::STATUSES[$item['status']] ?? '';
                $remoteId = (string) $item['id'];
                $members[] = array_filter([
                    'remote_id' => $remoteId,
                    'sku' => $item['code'] !== '' ? $item['code'] : $remoteId,
                    'name' => $item['name'] !== '' ? $item['name'] : $cardName.' '.$label,
                    'availability' => $availability,
                    'size' => $label !== '' ? $label : $remoteId,
                    'price' => $price,
                ], static fn (mixed $value): bool => $value !== '');
                if ($availability !== '') {
                    $statuses[$availability][] = $label !== '' ? $label : $remoteId;
                }
                if ($size !== '') {
                    $sizeNames[$size] = isset($sizeNames[$size]) && strnatcmp($sizeNames[$size], $item['size_code']) <= 0 ? $sizeNames[$size] : $item['size_code'];
                }
                $identifierLabel = $label !== '' ? $label : null;
                if ($item['code'] !== '') {
                    $identifiers[] = new B2bRemoteIdentifier(type: ProductIdentifier::TYPE_MANUFACTURER_CODE, value: $item['code'], remoteId: $remoteId, label: $identifierLabel, field: 'Kod');
                }
                if ($p['ean'] !== '') {
                    $identifiers[] = new B2bRemoteIdentifier(type: ProductIdentifier::TYPE_EAN, value: $p['ean'], remoteId: $remoteId, label: $identifierLabel, field: 'EAN');
                }
            }
        }
        if ($withoutBase) {
            $this->withoutBase[] = $sku;
        }
        $nets = array_unique(array_map(static fn (B2bRemotePrice $p): string => sprintf('%.2F', $p->net), $prices));
        $this->multiPrice += count($nets) > 1 ? 1 : 0;
        $this->cards++;

        $units = array_values(array_filter(array_keys($units), static fn (string $unit): bool => $unit !== ''));
        $cheapest = self::cheapest($prices);
        $cheapest = new B2bRemotePrice(
            net: $cheapest->net,
            base: $cheapest->base,
            discountPercent: $cheapest->discountPercent,
            currency: $cheapest->currency,
            // sklep nie stawia minimum ani kroku (unitLockChange false) — sama jednostka ceny
            order: new B2bOrderQuantity(null, null, count($units) === 1 ? $units[0] : null),
        );
        // lista rozmiarów karty w kolejności kodu rozmiaru (XS 23, S 24…), nie kolejności kolorów
        uasort($sizeNames, static fn (string $a, string $b): int => strnatcmp($a, $b));
        $sizeList = implode(', ', array_map('strval', array_keys($sizeNames)));
        $groupNames = [];
        foreach ($allIds as $id) {
            foreach ($categories[$id] ?? [] as $name) {
                $groupNames[$name] = true;
            }
        }

        return new B2bRemoteProduct(
            remoteId: $members[0]['remote_id'],
            sku: $sku,
            name: $lead['name'] !== '' ? $lead['name'] : $cardName,
            category: $groupNames !== [] ? implode(', ', array_keys($groupNames)) : null,
            sourceUrl: BrubeckB2bClient::BASE.'/itemdetails/'.$lead['id'],
            raw: [
                'status' => 'ok',
                'price' => $cheapest,
                'manufacturer' => $manufacturer,
                'manufacturer_name' => $company,
                'brand' => $brand,
                'model' => $model,
                'description' => $description,
                'images' => $images,
                'units' => $units,
            ],
            availability: self::availabilityText($statuses),
            variantSummary: $manyColours
                ? 'Kolory: '.implode(', ', $colourLabels).($sizeList !== '' ? '; rozmiary: '.$sizeList : '')
                : ($sizeList !== '' ? 'Rozmiary: '.$sizeList : null),
            // jedna pozycja — pojedyncza (cena z price()); kilka — każda z własną ceną
            members: count($members) > 1 ? $members : [],
            identifiers: $identifiers,
            cardName: $cardName !== $lead['name'] ? $cardName : null,
        );
    }

    /**
     * Część koloru nazwy (bez rozmiaru) najczęstsza wśród towarów koloru; remis — pierwszy towar.
     *
     * @param  non-empty-list<array{name: string}>  $items
     */
    private static function colourName(array $items): string
    {
        $counts = [];
        foreach ($items as $item) {
            $name = self::splitName($item['name'])[0];
            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }
        arsort($counts);

        return (string) array_key_first($counts);
    }

    /**
     * Te same nazwy koloru z dokładnością do literówki sklepu: bez pierwszego słowa (kod modelu, „LE13540”), bez
     * wielkości liter i polskich znaków („meskie”/„męskie”, „róź”/„róż”), najwyżej dwa znaki różnicy („uinsex”).
     */
    private static function sameColourName(string $a, string $b): bool
    {
        $fold = static fn (string $name): string => mb_strtolower(Str::ascii((string) preg_replace('/^\S+\s*/u', '', $name)));
        [$a, $b] = [$fold($a), $fold($b)];

        return $a === $b || (strlen($a) <= 255 && strlen($b) <= 255 && levenshtein($a, $b) <= 2);
    }

    /** Czy towar ma w sklepie atrybuty, pliki albo filmy (na koncie 01.10.2026 żaden nie miał). */
    private static function hasExtras(array $extras): bool
    {
        foreach (['articleAttributes', 'articleAttachments', 'articleMovies'] as $key) {
            if (is_array($extras[$key] ?? null) && $extras[$key] !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Zdjęcia towaru w kolejności sklepu (imageType 1 = obraz w sklepie; największy rozmiar, jaki wydaje), na hoście
     * konta (BrubeckB2bClient tłumaczy go przy pobieraniu).
     *
     * @return list<string>
     */
    private static function imageLinks(mixed $images): array
    {
        $out = [];
        foreach (is_array($images) ? $images : [] as $image) {
            if (! is_array($image) || ($image['imageType'] ?? null) !== 1 || ! is_int($image['imageId'] ?? null)) {
                continue;
            }
            $url = BrubeckB2bClient::BASE.'/imagehandler.ashx?id='.$image['imageId'].'&width=2000&height=2000';
            if (! in_array($url, $out, true)) {
                $out[] = $url;
            }
        }

        return $out;
    }

    /**
     * Najtańsza pozycja karty, ta sama reguła co w B2bCatalogSync::sizePricing (remis — B2bCatalogSync::winsSizePriceTie).
     *
     * @param  non-empty-list<B2bRemotePrice>  $prices
     */
    private static function cheapest(array $prices): B2bRemotePrice
    {
        $cheapest = $prices[0];
        foreach ($prices as $price) {
            if ($price->net < $cheapest->net - 0.0049
                || (abs($price->net - $cheapest->net) < 0.005 && B2bCatalogSync::winsSizePriceTie($price->base, $cheapest->base))) {
                $cheapest = $price;
            }
        }

        return $cheapest;
    }

    /**
     * Jedna dostępność dla wszystkich pozycji — dosłownie; różne — „Dostępny: czarny / S, …; Niedostępny: …”.
     *
     * @param  array<string, list<string>>  $statuses
     */
    private static function availabilityText(array $statuses): ?string
    {
        if ($statuses === []) {
            return null;
        }
        if (count($statuses) === 1) {
            return (string) array_key_first($statuses);
        }
        $parts = [];
        foreach ($statuses as $status => $labels) {
            $parts[] = $status.': '.implode(', ', $labels);
        }

        return mb_substr(implode('; ', $parts), 0, 1000);
    }

    /**
     * Model, którego nie da się zapisać w tym przebiegu. Znane pozycje (id towarów) idą jako pozycje bez cen —
     * przebieg widzi je na liście i jako pominięte (poza sprzątaniem), jak u Fagum.
     *
     * @param  list<int|string>  $positions
     */
    private function skipped(string $sku, string $name, string $reason, array $positions): B2bRemoteProduct
    {
        $positions = array_values(array_map('strval', $positions));
        $name = $name !== '' ? $name : $sku;

        return new B2bRemoteProduct(
            remoteId: $positions[0],
            sku: $sku,
            name: $name,
            raw: ['status' => 'skipped', 'reason' => $reason],
            members: count($positions) > 1
                ? array_map(static fn (string $position): array => ['remote_id' => $position, 'sku' => $position, 'name' => $name.' '.$position], $positions)
                : [],
        );
    }

    private function progress(string $message): void
    {
        if ($this->listProgress !== null) {
            ($this->listProgress)($message);
        }
    }

    /**
     * @param  list<string>  $items
     */
    private static function listing(array $items): string
    {
        return count($items).', np. '.implode(', ', array_slice($items, 0, 10));
    }

    private static function number(mixed $value): ?float
    {
        return is_int($value) || is_float($value) ? (float) $value : null;
    }

    /** Tekst ze sklepu: bez znaków zerowej szerokości, odstępy zwinięte. */
    private static function clean(string $text): string
    {
        $text = str_replace(["\u{200B}", "\u{200C}", "\u{200D}", "\u{200E}", "\u{200F}", "\u{FEFF}"], '', $text);

        return trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $text)));
    }
}

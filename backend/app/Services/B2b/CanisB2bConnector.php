<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bAccount;
use App\Models\ProductDocument;
use App\Models\ProductIdentifier;
use RuntimeException;

/**
 * portal.canis.cz — portal B2B Canis Safety (marka własna CXS; sprzedaje też 3M, MSA i inne marki). Sprawdzone
 * 04.10.2026 na zalogowanym koncie (CanisB2bClient — logowanie i sesja, CanisPageParser — znaczniki stron). Ceny konta
 * w PLN (cennik konta „E2”), bez ceny katalogowej.
 *
 * Kod pozycji Canis to MMMM-MMM-KKK-RR (model, kolor, rozmiar). Kafle listy są na różnych poziomach: model, kolor,
 * rozmiar albo wyrób bez wariantów, a strony grupują pozycje po swojemu — strona modelu SIRIUS (1010-001-000-00) pokazuje
 * linie LUCIUS i BRIGHTON oraz skrócone 1010-035, a kolor 1010-001-410 (inna cena, inna norma) ma osobną stronę. Karta nie
 * jest więc stroną, tylko grupą pozycji: model MMMM-MMM + pierwszy człon nazwy pozycji (przed przecinkiem, bez rozmiaru)
 * — „Bluza CXS SIRIUS LUCIUS” (708 i 410), „Bluza CXS SIRIUS BRIGHTON” (802), skrócone 1010-035 osobno.
 * Jak u Sary (Skrót + nazwa): błąd idzie w stronę podziału, nigdy sklejenia różnych wyrobów.
 *
 * Przebieg w dwóch fazach. 1) lista: wszystkie kategorie-liście z menu, każda strona listy; 2) strony wyrobów w stałej
 * kolejności (kafle bez rozmiaru w nazwie, potem z rozmiarem, rosnąco po numerze — niezależnie od menu i sortowania
 * sklepu), z pominięciem kafla, który jest już pozycją albo wyrobem nadrzędnym pobranej strony. Strona rozmiaru prowadzi
 * do strony swojego wyrobu nadrzędnego (tam są wszystkie rozmiary i kod „-00”); portal bez takiej strony (404, rękawice)
 * — zostaje strona rozmiaru. Karty powstają dopiero po pobraniu wszystkich stron. Lista ucięta (budżet czasu, limit
 * stron, podejrzanie mało kafli, za dużo stron nieodczytanych) przerywa przebieg wyjątkiem — przebieg bez wszystkich
 * pozycji nie może wyglądać na pełny (sprzątanie rozmiarów i identyfikatorów).
 *
 * Karta: pozycje z ceną w zł (kod = remote_id = sku pozycji), cena karty = najniższa; remoteId = najniższy kod. SKU nowej
 * karty: kod modelu „MMMM-MMM-000-00” z nagłówka strony, której wszystkie pozycje należą do karty; inaczej kod koloru
 * „…-KKK-00” najniższego koloru, gdy portal pokazał taką stronę; inaczej najniższy kod pozycji (kodu, którego portal nie
 * pokazał, nie zgadujemy). Treść (opis, tabelka, pliki, zdjęcia) ze strony wiodącej — tej z najniższym kodem karty;
 * parametry różne na innych stronach karty z opisem pozycji przy wartości, a różne „Normy” jako osobne wiersze „Normy”
 * (ShopCardNormFacts dzieli wiersz na oznaczenia norm — dopisek przy wartości stałby się poziomem normy).
 *
 * Producent = parametr „Znak”: CXS → Canis, inna marka dosłownie (pisownia z katalogu ze słownika BRANDS). Pusty „Znak”
 * (wyroby katalogu Canis bez marki CXS, robione przez fabryki OEM) — marka z pola Producent/Dostawca, gdy to znana marka
 * ze słownika (obcy wyrób jako Canis dostałby nadpisany opis i normy — B2bManufacturerSite), inaczej Canis; w dzienniku.
 */
final class CanisB2bConnector implements B2bConnector, B2bDocumentSource, B2bGroupsSizes, B2bImageGallery, B2bListProgressAware, B2bManufacturerSite, B2bRunSummaryAware, B2bShopFieldNormSource, B2bShopFieldSource, B2bSizePriceSource
{
    public const BRAND = 'Canis';

    private const NORM_ROW = 'Normy';

    /**
     * „Znak” (bez wielkości liter) → pisownia producenta na kartach katalogu. Reszta — dosłownie ze strony.
     *
     * @var array<string, string>
     */
    private const BRANDS = [
        'cxs' => self::BRAND,
        'canis' => self::BRAND,
        '3m' => '3M',
        'msa' => 'MSA',
        'ansell' => 'Ansell',
        'dupont' => 'DuPont',
        'honeywell' => 'Honeywell',
        'uvex' => 'UVEX',
        'atlas' => 'ATLAS',
        'mapa' => 'MAPA',
        'okula' => 'OKULA',
    ];

    /** Parametry pozycji, nie karty (rozmiar jest w tabeli rozmiarów) albo formułki bez treści o wyrobie. */
    private const SKIPPED_PARAMS = ['Rozmiar', 'Informacje dotyczące bezpieczeństwa'];

    /** Parametr koloru — przy kilku kolorach na karcie należy do jednej strony, nie do karty. */
    private const COLOUR_PARAM = 'Kolor';

    /** Rodzaj towaru zwykłej oferty — inny („Wyprzedaż”, „Na zamówienie”) idzie do dostępności pozycji. */
    private const REGULAR_KIND = 'Produkty całoroczne';

    private const LIST_BUDGET_SECONDS = 45 * 60;

    private const PAGES_BUDGET_SECONDS = 4 * 3600;

    private const MAX_PAGES_PER_CATEGORY = 150;

    /** Mniej kafli w całym portalu = pusta oferta konta albo zmiana sklepu (04.10.2026: kilka tysięcy). */
    private const MIN_TILES = 200;

    /** Udział stron wyrobów nieodczytanych także za drugim razem, przy którym przebieg jest przerywany. */
    private const MAX_FAILED_SHARE = 0.02;

    private const MAX_IMAGES_ONE_COLOUR = 6;

    private const MAX_DESCRIPTION = 10000;

    /** Tyle kart bez ceny konta, zanim pojawi się pierwsza cena = sesja bez cen (albo zmiana sklepu). */
    private const MAX_FIRST_WITHOUT_PRICE = 20;

    private const PROGRESS_EVERY = 50;

    /** Rozmiar na końcu nazwy pozycji: „, roz. 64”, „rozmiar 09”, „vel. 10”, „roz. 6-7”. */
    private const SIZE_TAIL = '/[\s,]*\b(?:roz\.|rozm\.|rozmiar|vel\.)\s*[0-9A-Za-z][0-9A-Za-z.,\/\-]*\s*$/iu';

    private int $total = 0;

    /** @var array<string, array<string, mixed>> grupy pozycji (klucz karty → dane) */
    private array $groups = [];

    /** @var array<int, array<string, mixed>> pobrane strony wyrobów (numer → dane bez HTML) */
    private array $pages = [];

    /** @var array<string, string> kod pozycji → klucz grupy */
    private array $rowIndex = [];

    /** @var array<string, true> kody nagłówków stron pobranych w przebiegu */
    private array $headerCodes = [];

    /** @var array<string, true> SKU kart oddanych w tym przebiegu */
    private array $usedSkus = [];

    private int $cards = 0;

    private int $withPrice = 0;

    private int $multiPrice = 0;

    /** @var list<string> */
    private array $summary = [];

    /** @var list<string> */
    private array $withoutPrice = [];

    /** @var list<string> */
    private array $withoutDescription = [];

    /** @var list<string> pozycje bez ceny w zł (poza kartą) */
    private array $unpriced = [];

    /** @var list<string> ten sam kod na dwóch stronach z inną ceną */
    private array $priceConflicts = [];

    /** @var list<string> strony nieodczytane także za drugim razem */
    private array $failedPages = [];

    /** @var list<string> karty bez parametru „Znak” (kod → producent, dostawca ze strony) */
    private array $withoutMark = [];

    /** @var array<string, int> marka → liczba kart innych marek niż Canis */
    private array $foreignBrands = [];

    /** @var list<string> karty z pozycjami w różnych jednostkach stanu */
    private array $mixedUnits = [];

    /** @var list<string> pozycje z kodem spoza wzoru MMMM-MMM-KKK-RR */
    private array $oddCodes = [];

    /** @var (callable(string): void)|null */
    private $listProgress = null;

    /** @var (callable(): float)|null */
    private $clock = null;

    /**
     * @param  (callable(): float)|null  $clock  bieżący czas w sekundach (w testach sterowany)
     * @param  int  $minTiles  najmniejsza wiarygodna liczba kafli (testy mają kilka)
     */
    public function __construct(
        private readonly CanisB2bClient $client,
        ?callable $clock = null,
        private readonly int $minTiles = self::MIN_TILES,
    ) {
        $this->clock = $clock;
    }

    public static function key(): string
    {
        return 'canis';
    }

    public static function label(): string
    {
        return self::BRAND;
    }

    public static function host(): string
    {
        return CanisB2bClient::HOST;
    }

    public static function ownBrand(): string
    {
        return self::BRAND;
    }

    public static function normShopFieldNames(): array
    {
        return [self::NORM_ROW];
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new self(new CanisB2bClient((string) $account->username, (string) $account->password, $delayMs));
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
        $this->withoutDescription = [];
        $this->unpriced = [];
        $this->priceConflicts = [];
        $this->failedPages = [];
        $this->withoutMark = [];
        $this->foreignBrands = [];
        $this->oddCodes = [];
        $this->groups = [];
        $this->pages = [];
        $this->rowIndex = [];
        $this->mixedUnits = [];
        $this->headerCodes = [];
        $this->usedSkus = [];
        $this->cards = 0;
        $this->withPrice = 0;
        $this->multiPrice = 0;

        if (! $this->client->isLoggedIn()) {
            $this->client->login();
        }

        [$tiles, $categories, $listPages] = $this->scanList();
        $fetched = $this->fetchPages($tiles);
        ksort($this->groups, SORT_STRING);
        $this->total = count($this->groups);
        $rows = array_sum(array_map(static fn (array $g): int => count($g['rows']), $this->groups));
        $this->summary[] = 'Lista Canis: '.$categories.' kategorii, '.$listPages.' stron list, '.count($tiles).' kafli → '
            .$fetched.' stron wyrobów, '.$rows.' pozycji → '.$this->total.' kart';

        $done = 0;
        foreach (array_keys($this->groups) as $key) {
            $group = $this->groups[$key];
            unset($this->groups[$key]);
            $product = $this->productFor($group);
            if ($this->withPrice === 0 && count($this->withoutPrice) >= self::MAX_FIRST_WITHOUT_PRICE) {
                throw new B2bFatalException(count($this->withoutPrice).' pierwszych kart '.CanisB2bClient::HOST.' bez ceny konta — sesja konta nie pokazuje cen albo portal zmienił strony wyrobów; przebieg przerwany');
            }
            $done++;
            if ($done % self::PROGRESS_EVERY === 0) {
                $this->progress('Karty Canis: '.$done.'/'.$this->total);
            }
            yield $product;
        }
        $this->pages = [];
    }

    public function totalProducts(): int
    {
        return $this->total;
    }

    public function runSummary(): array
    {
        $lines = $this->summary;
        $lines[] = sprintf(
            'Karty: %d (%d z rozmiarami albo kolorami w różnych cenach — cena karty = najniższa, ceny pozycji w tabeli karty)',
            $this->cards,
            $this->multiPrice,
        );
        if ($this->foreignBrands !== []) {
            arsort($this->foreignBrands);
            $parts = [];
            foreach ($this->foreignBrands as $brand => $count) {
                $parts[] = $brand.' '.$count;
            }
            $lines[] = 'Karty innych marek niż Canis (kody Canis — do „Łączenia kart” z kartami producentów): '.implode(', ', array_slice($parts, 0, 30));
        }
        if ($this->withoutPrice !== []) {
            $lines[] = 'Bez ceny konta (pominięte): '.self::listing($this->withoutPrice);
        }
        if ($this->unpriced !== []) {
            $lines[] = 'Pozycje bez ceny w zł (poza kartą): '.self::listing($this->unpriced);
        }
        if ($this->withoutMark !== []) {
            $lines[] = 'Bez parametru „Znak” (producent z pola Producent/Dostawca, gdy to znana marka, inaczej Canis): '.self::listing($this->withoutMark);
        }
        if ($this->priceConflicts !== []) {
            $lines[] = 'Ten sam kod na dwóch stronach z inną ceną (wzięta cena pierwszej strony): '.self::listing($this->priceConflicts);
        }
        if ($this->mixedUnits !== []) {
            $lines[] = 'Karty z pozycjami w różnych jednostkach (do sprawdzenia, czy to jeden wyrób): '.self::listing($this->mixedUnits);
        }
        if ($this->oddCodes !== []) {
            $lines[] = 'Kody spoza wzoru MMMM-MMM-KKK-RR (karta z samego kodu): '.self::listing($this->oddCodes);
        }
        if ($this->failedPages !== []) {
            $lines[] = 'Strony wyrobów nieodczytane (ich pozycje poza przebiegiem): '.self::listing($this->failedPages);
        }
        if ($this->withoutDescription !== []) {
            $lines[] = 'Bez opisu w portalu: '.self::listing($this->withoutDescription);
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
            throw new RuntimeException((string) ($product->raw['reason'] ?? 'wyrób nieodczytany'));
        }
        $price = $product->raw['price'] ?? null;
        if (! $price instanceof B2bRemotePrice) {
            throw new RuntimeException('wyrób bez ceny konta');
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
        if (($product->raw['status'] ?? null) !== 'ok') {
            return [];
        }

        return array_map(
            static fn (array $row): B2bRemoteShopField => new B2bRemoteShopField($row['section'], $row['name'], $row['value']),
            $product->raw['fields'] ?? [],
        );
    }

    /**
     * @return list<B2bRemoteDocument>
     */
    public function documents(B2bRemoteProduct $product): array
    {
        return array_map(
            static fn (array $file): B2bRemoteDocument => new B2bRemoteDocument($file['title'], $file['url'], $file['kind']),
            $product->raw['documents'] ?? [],
        );
    }

    public function documentBytes(B2bRemoteDocument $document): array
    {
        return $this->client->fileBytes($document->sourceUrl);
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
     * Klucz karty pozycji: model (MMMM-MMM) i pierwszy człon nazwy bez rozmiaru (bez wielkości liter). Kod spoza wzoru
     * — cały kod (karta z jednej pozycji, bez zgadywania modelu). Jednostka stanu nie wchodzi do klucza: pozycja bez
     * stanu nie ma jej wcale, a karta nie może się przez to rozpaść (różne jednostki na karcie — dziennik).
     */
    public static function groupKey(string $code, string $name): string
    {
        $model = preg_match(CanisPageParser::CODE, $code) === 1 ? substr($code, 0, 8) : $code;

        return $model."\n".mb_strtolower(self::baseName($name));
    }

    /** Pierwszy człon nazwy pozycji (przed przecinkiem), bez rozmiaru na końcu i z ujednoliconymi odstępami. */
    public static function baseName(string $name): string
    {
        $first = explode(',', CanisPageParser::clean($name), 2)[0];

        return CanisPageParser::clean((string) preg_replace(self::SIZE_TAIL, '', $first));
    }

    /** Nazwa pozycji bez rozmiaru na końcu („…, kolor szaro-zielony, roz. 44” → „…, kolor szaro-zielony”). */
    public static function withoutSize(string $name): string
    {
        return rtrim(CanisPageParser::clean((string) preg_replace(self::SIZE_TAIL, '', CanisPageParser::clean($name))), ' ,;');
    }

    /**
     * Faza 1: kafle wszystkich kategorii-liści (numer → adres, nazwa, najgłębsza kategoria kafla).
     *
     * @return array{0: array<int, array{url: string, name: string, category: string}>, 1: int, 2: int}
     */
    private function scanList(): array
    {
        $started = $this->now();
        $categories = CanisPageParser::categories($this->client->homePage());
        if ($categories === []) {
            throw new RuntimeException('Menu kategorii portalu '.CanisB2bClient::HOST.' puste — zmiana portalu?');
        }
        $this->progress('Kategorie Canis: '.count($categories));

        $tiles = [];
        $listPages = 0;
        foreach ($categories as $category) {
            $last = 1;
            for ($page = 1; $page <= $last; $page++) {
                if ($this->now() - $started > self::LIST_BUDGET_SECONDS) {
                    throw new RuntimeException('Pobieranie listy '.CanisB2bClient::HOST.' trwa ponad '.(self::LIST_BUDGET_SECONDS / 60).' min — przerwane bez zapisu');
                }
                $result = CanisPageParser::categoryPage($this->client->categoryPage($category['path'], $page));
                $listPages++;
                if ($page === 1) {
                    $last = $result['last_page'];
                    if ($last > self::MAX_PAGES_PER_CATEGORY) {
                        throw new RuntimeException('Kategoria '.$category['label'].' ma '.$last.' stron listy (limit '.self::MAX_PAGES_PER_CATEGORY.') — zmiana portalu?');
                    }
                }
                foreach ($result['tiles'] as $tile) {
                    $known = $tiles[$tile['id']] ?? null;
                    // kafel w kilku kategoriach: najgłębsza ścieżka, przy równej — pierwsza w porządku adresów
                    if ($known === null || substr_count($category['label'], ' > ') > substr_count($known['category'], ' > ')) {
                        $tiles[$tile['id']] = ['url' => $known['url'] ?? $tile['url'], 'name' => $known['name'] ?? $tile['name'], 'category' => $category['label']];
                    }
                }
            }
            $this->progress('Kategoria '.$category['label'].': '.$last.' stron (razem kafli: '.count($tiles).')');
        }
        if (count($tiles) < $this->minTiles) {
            throw new RuntimeException('Lista '.CanisB2bClient::HOST.' ma tylko '.count($tiles).' kafli (oczekiwane co najmniej '.$this->minTiles.') — pusta oferta konta albo zmiana portalu; przebieg przerwany');
        }

        return [$tiles, count($categories), $listPages];
    }

    /**
     * Faza 2: strony wyrobów w stałej kolejności, pozycje do grup kart. Zwraca liczbę pobranych stron.
     *
     * @param  array<int, array{url: string, name: string, category: string}>  $tiles
     */
    private function fetchPages(array $tiles): int
    {
        $started = $this->now();
        $order = array_keys($tiles);
        usort($order, static fn (int $a, int $b): int => [self::sizeTile($tiles[$a]['name']), $a] <=> [self::sizeTile($tiles[$b]['name']), $b]);

        $covered = [];
        $failed = [];
        $fetched = 0;
        foreach ([1, 2] as $pass) {
            $queue = $pass === 1 ? $order : array_keys($failed);
            $failed = [];
            foreach ($queue as $id) {
                if (isset($covered[$id])) {
                    continue;
                }
                if ($this->now() - $started > self::PAGES_BUDGET_SECONDS) {
                    throw new RuntimeException('Pobieranie stron wyrobów '.CanisB2bClient::HOST.' trwa ponad '.(self::PAGES_BUDGET_SECONDS / 3600).' h — przerwane bez zapisu');
                }
                try {
                    $page = $this->pageFor($id, $tiles);
                } catch (B2bFatalException $e) {
                    throw $e;
                } catch (RuntimeException $e) {
                    $failed[$id] = $tiles[$id]['url'].' ('.$e->getMessage().')';

                    continue;
                }
                $fetched++;
                $this->addPage($page, $tiles[$id]['category'], $covered);
                $covered[$id] = true;
                if ($fetched % self::PROGRESS_EVERY === 0) {
                    $this->progress('Strony wyrobów Canis: '.$fetched.' (kafli '.count($tiles).', pokrytych '.count(array_intersect_key($covered, $tiles)).')');
                }
            }
        }

        $this->failedPages = array_values($failed);
        if ($failed !== [] && count($failed) > max(1, (int) floor(self::MAX_FAILED_SHARE * max(1, $fetched)))) {
            throw new RuntimeException(count($failed).' stron wyrobów '.CanisB2bClient::HOST.' nieodczytanych także za drugim razem (np. '.implode('; ', array_slice($this->failedPages, 0, 3)).') — przebieg przerwany bez zapisu');
        }

        return $fetched;
    }

    /**
     * Strona kafla; strona rozmiaru — strona jej wyrobu nadrzędnego, gdy portal ją ma (kafel listy albo „/pl/x_p{id}”).
     *
     * @param  array<int, array{url: string, name: string, category: string}>  $tiles
     * @return array<string, mixed>
     */
    private function pageFor(int $id, array $tiles): array
    {
        $page = CanisPageParser::productPage((string) $this->client->productPage($tiles[$id]['url']));
        $master = (int) $page['master'];
        $isSizePage = $master > 0 && in_array($page['id'], array_column($page['rows'], 'id'), true);
        if (! $isSizePage) {
            return $page + ['url' => $tiles[$id]['url']];
        }
        $masterUrl = $tiles[$master]['url'] ?? '/pl/x_p'.$master;
        $html = $this->client->productPage($masterUrl, allowMissing: true);
        if ($html === null) {
            return $page + ['url' => $tiles[$id]['url']];
        }
        $masterPage = CanisPageParser::productPage($html);
        if ($masterPage['rows'] === [] || (int) $masterPage['id'] !== $master) {
            return $page + ['url' => $tiles[$id]['url']];
        }
        $masterPage['covers'] = [$page['id']];

        return $masterPage + ['url' => $masterUrl];
    }

    /**
     * Strona do grup kart: pozycje (wiersze tabeli albo sam wyrób z nagłówka), dane strony bez HTML.
     *
     * @param  array<string, mixed>  $page
     * @param  array<int, true>  $covered
     */
    private function addPage(array $page, string $category, array &$covered): void
    {
        $pageId = (int) $page['id'];
        $covered[$pageId] = true;
        if ((int) $page['master'] > 0) {
            $covered[(int) $page['master']] = true;
        }
        foreach ($page['covers'] ?? [] as $id) {
            $covered[(int) $id] = true;
        }
        if ($page['code'] !== '') {
            $this->headerCodes[$page['code']] = true;
        }

        $rows = $page['rows'];
        if ($rows === [] && $page['code'] !== '') {
            // wyrób bez wariantów (3M): jedna pozycja z nagłówka strony
            $rows = [[
                'id' => $pageId,
                'master' => 0,
                'code' => $page['code'],
                'name' => $page['name'],
                'pack' => '',
                'kind' => '',
                'size' => '',
                'stock' => $page['stock'],
                'unit' => self::stockUnit($page['stock']),
                'price' => $page['price'] !== null ? round((float) $page['price'], 2) : null,
                'currency' => $page['currency'] === 'PLN' ? 'zł' : $page['currency'],
                'step' => $page['step'],
            ]];
        }

        $masters = [];
        foreach ($rows as $row) {
            $covered[(int) $row['id']] = true;
            if ((int) $row['master'] > 0) {
                $covered[(int) $row['master']] = true;
                $masters[(int) $row['master']] = true;
            }
            $code = $row['code'];
            if ($code === '') {
                continue;
            }
            if (preg_match(CanisPageParser::CODE, $code) !== 1) {
                $this->oddCodes[] = $code;
            }
            $unit = $row['unit'] ?? self::stockUnit((string) $row['stock']);
            $key = self::groupKey($code, (string) $row['name']);
            $existingKey = $this->rowIndex[$code] ?? null;
            if ($existingKey !== null) {
                $known = &$this->groups[$existingKey]['rows'][$code];
                $known['pages'][] = $pageId;
                if ($known['price'] !== $row['price']) {
                    $this->priceConflicts[] = $code.' ('.self::money($known['price']).' / '.self::money($row['price']).')';
                }
                unset($known);

                continue;
            }
            $this->groups[$key] ??= ['key' => $key, 'rows' => []];
            $this->groups[$key]['rows'][$code] = [
                'code' => $code,
                'name' => (string) $row['name'],
                'size' => (string) $row['size'],
                'stock' => (string) $row['stock'],
                'unit' => $unit,
                'kind' => (string) $row['kind'],
                'pack' => (string) $row['pack'],
                'master' => (int) $row['master'],
                'price' => $row['price'],
                'currency' => (string) $row['currency'],
                'step' => $row['step'],
                'pages' => [$pageId],
            ];
            $this->rowIndex[$code] = $key;
        }

        $this->pages[$pageId] = [
            'id' => $pageId,
            'url' => (string) $page['url'],
            'name' => (string) $page['name'],
            'code' => (string) $page['code'],
            'is_master' => (bool) $page['is_master'],
            // pseudo-EAN Canis = kod bez kresek (12 cyfr) — suma kontrolna bywa przypadkiem poprawna, więc odpada wprost
            'eans' => array_values(array_filter(
                $page['eans'],
                static fn (string $ean): bool => $ean !== str_replace('-', '', (string) $page['code']) && CanisPageParser::validGtin($ean),
            )),
            'alt_units' => (string) $page['alt_units'],
            'description' => mb_substr((string) $page['description'], 0, self::MAX_DESCRIPTION),
            'params' => $page['params'],
            'documents' => $page['documents'],
            'gallery' => array_slice($page['gallery'], 0, self::MAX_IMAGES_ONE_COLOUR),
            'colour_images' => array_intersect_key($page['colour_images'], $masters + [$pageId => true]),
            'codes' => array_values(array_filter(array_column($rows, 'code'), static fn (string $c): bool => $c !== '')),
            'category' => $category,
        ];
    }

    /**
     * Karta grupy: pozycje z ceną w zł; bez nich — pominięta z powodem.
     *
     * @param  array{key: string, rows: array<string, array<string, mixed>>}  $group
     */
    private function productFor(array $group): B2bRemoteProduct
    {
        $rows = $group['rows'];
        ksort($rows, SORT_STRING);
        $priced = [];
        foreach ($rows as $code => $row) {
            if ($row['price'] === null || $row['currency'] !== 'zł') {
                $this->unpriced[] = $code;

                continue;
            }
            $priced[$code] = $row;
        }
        $first = array_key_first($rows);
        $name = self::cardName(array_map(static fn (array $r): string => $r['name'], $priced !== [] ? $priced : $rows));
        if ($priced === []) {
            $this->withoutPrice[] = (string) $first;

            return $this->skipped((string) $first, $name, $this->pageUrlOf($rows[$first]), 'żadna pozycja nie ma ceny konta w zł');
        }

        $lead = $this->leadPage($priced);
        $pageIds = $this->groupPages($priced);
        $remoteId = (string) array_key_first($priced);
        $manufacturer = $this->manufacturerOf($lead, $remoteId);
        if ($manufacturer !== self::BRAND) {
            $this->foreignBrands[$manufacturer] = ($this->foreignBrands[$manufacturer] ?? 0) + 1;
        }

        // kolory karty (segment KKK kodu) w kolejności kodów
        $colours = [];
        foreach ($priced as $code => $row) {
            $colour = self::colourCode($code);
            $colours[$colour] ??= ['label' => self::colourLabel($row['name'], $name, $colour), 'first' => $code, 'master' => $row['master']];
        }
        $manyColours = count($colours) > 1;

        $members = [];
        $identifiers = [];
        $usedLabels = [];
        $statuses = [];
        $steps = [];
        $units = [];
        $sizeNames = [];
        $cheapest = null;
        foreach ($priced as $code => $row) {
            $colour = $colours[self::colourCode($code)];
            $size = $row['size'] !== '' ? $row['size'] : '';
            $label = $manyColours ? $colour['label'].($size !== '' ? ' / '.$size : '') : ($size !== '' ? $size : $code);
            if (isset($usedLabels[$label])) {
                $label .= ' ('.$code.')';
            }
            $usedLabels[$label] = true;
            $price = new B2bRemotePrice(net: round((float) $row['price'], 2), currency: 'PLN');
            [$status, $availability] = self::availabilityOf($row['stock'], $row['kind']);
            $members[] = [
                'remote_id' => $code,
                'sku' => $code,
                'name' => $row['name'],
                'availability' => $availability,
                'size' => $label,
                'price' => $price,
            ];
            $statuses[$status][] = $label;
            $steps[(string) ($row['step'] ?? 1.0)] = (float) ($row['step'] ?? 1.0);
            if ($row['unit'] !== '') {
                $units[$row['unit']] = true;
            }
            if ($size !== '') {
                $sizeNames[$size] = true;
            }
            if ($cheapest === null || $price->net < $cheapest->net) {
                $cheapest = $price;
            }
        }

        foreach ($pageIds as $pageId) {
            $page = $this->pages[$pageId] ?? null;
            if ($page === null) {
                continue;
            }
            $codesHere = array_values(array_intersect($page['codes'], array_keys($priced)));
            if ($codesHere === []) {
                continue;
            }
            sort($codesHere, SORT_STRING);
            // kod nadrzędny strony („-00”) — tylko gdy cała strona należy do tej karty (strona SIRIUS pokazuje dwie karty);
            // kod nagłówka będący pozycją (strona rozmiaru, wyrób bez wariantów) ma przy sobie EAN-y strony
            if ($page['code'] !== '' && ! isset($priced[$page['code']]) && array_diff($page['codes'], array_keys($rows)) === []) {
                $identifiers[] = new B2bRemoteIdentifier(type: ProductIdentifier::TYPE_SOURCE_CODE, value: $page['code'], remoteId: $codesHere[0], label: $page['name'], field: 'Kod');
            }
            $eanOwner = isset($priced[$page['code']]) ? $page['code'] : null;
            foreach ($page['eans'] as $ean) {
                if ($eanOwner !== null) {
                    $identifiers[] = new B2bRemoteIdentifier(type: ProductIdentifier::TYPE_EAN, value: $ean, remoteId: $eanOwner, label: $eanOwner, field: 'EAN');
                }
            }
        }

        $this->withPrice++;
        $this->cards++;
        $nets = array_map(static fn (array $m): float => $m['price']->net, $members);
        $this->multiPrice += count(array_unique(array_map('strval', $nets))) > 1 ? 1 : 0;

        $unit = count($units) === 1 ? (string) array_key_first($units) : null;
        if (count($units) > 1) {
            $this->mixedUnits[] = $remoteId.' ('.implode(', ', array_keys($units)).')';
        }
        $order = count($steps) > 1
            ? new B2bOrderQuantity(min: null, step: null, unit: $unit, varies: true)
            : self::orderQuantity((float) array_values($steps)[0], $unit);

        $description = $lead['description'];
        if ($description === '') {
            foreach ($pageIds as $pageId) {
                if (($this->pages[$pageId]['description'] ?? '') !== '') {
                    $description = $this->pages[$pageId]['description'];

                    break;
                }
            }
        }
        if ($description === '') {
            $this->withoutDescription[] = $remoteId;
        }

        $sku = $this->cardSku($priced, $pageIds, $colours);
        $this->usedSkus[mb_strtolower($sku)] = true;

        return new B2bRemoteProduct(
            remoteId: $remoteId,
            sku: $sku,
            name: $name,
            category: $lead['category'] !== '' ? $lead['category'] : null,
            sourceUrl: CanisB2bClient::absolute($lead['url']),
            raw: [
                'status' => 'ok',
                'manufacturer' => $manufacturer,
                // cena karty = najniższa cena pozycji (B2bCatalogSync liczy ją też z members[].price)
                'price' => new B2bRemotePrice(net: $cheapest->net, currency: 'PLN', order: $order),
                'description' => $description,
                'fields' => $this->fields($lead, $pageIds, $priced, $manyColours, $colours),
                'documents' => $this->documentsOf($lead, $pageIds),
                'images' => $this->imagesOf($lead, $pageIds, array_map('strval', array_keys($priced)), $colours, $manyColours),
            ],
            availability: self::groupAvailability($statuses),
            variantSummary: self::variantSummary($colours, $manyColours, array_keys($sizeNames)),
            members: $members,
            identifiers: $identifiers,
        );
    }

    /**
     * Lista kolorów i rozmiarów karty; kolor znany tylko z kodu (rękawice „000”) — bez części o kolorze.
     *
     * @param  array<string, array{label: string, first: string, master: int}>  $colours
     * @param  list<int|string>  $sizes
     */
    private static function variantSummary(array $colours, bool $manyColours, array $sizes): ?string
    {
        $parts = [];
        $labels = array_values(array_map(static fn (array $c): string => $c['label'], $colours));
        if ($manyColours) {
            $parts[] = 'Kolory: '.implode(', ', $labels);
        } elseif ($labels[0] !== (string) array_key_first($colours)) {
            $parts[] = 'Kolor: '.$labels[0];
        }
        if ($sizes !== []) {
            $parts[] = 'rozmiary: '.implode(', ', array_map('strval', $sizes));
        }

        return $parts !== [] ? implode('; ', $parts) : null;
    }

    /**
     * Strona wiodąca karty: ta, która pokazała najniższy kod karty (przy kilku — najniższy numer strony).
     *
     * @param  array<string, array<string, mixed>>  $priced
     * @return array<string, mixed>
     */
    private function leadPage(array $priced): array
    {
        $firstRow = reset($priced);
        $ids = $firstRow['pages'];
        sort($ids);
        foreach ($ids as $id) {
            if (isset($this->pages[$id])) {
                return $this->pages[$id];
            }
        }

        throw new RuntimeException('pozycja '.array_key_first($priced).' bez zapisanej strony');
    }

    /**
     * Strony karty: najpierw wiodąca, potem pozostałe w kolejności najniższego kodu, który pokazały.
     *
     * @param  array<string, array<string, mixed>>  $priced
     * @return list<int>
     */
    private function groupPages(array $priced): array
    {
        $out = [];
        foreach ($priced as $row) {
            $ids = $row['pages'];
            sort($ids);
            foreach ($ids as $id) {
                $out[$id] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * SKU nowej karty (synchronizacja nie zmienia SKU istniejącej): kod modelu z nagłówka strony, której wszystkie pozycje
     * są na tej karcie; inaczej kod koloru „-KKK-00” najniższego koloru, gdy portal pokazał taką stronę; inaczej najniższy
     * kod pozycji. Kod zajęty w tym przebiegu przez inną kartę — najniższy kod pozycji.
     *
     * @param  array<string, array<string, mixed>>  $priced
     * @param  list<int>  $pageIds
     * @param  array<string, array{label: string, first: string, master: int}>  $colours
     */
    private function cardSku(array $priced, array $pageIds, array $colours): string
    {
        $lowest = (string) array_key_first($priced);
        if (preg_match(CanisPageParser::CODE, $lowest) !== 1) {
            return $lowest;
        }
        $model = substr($lowest, 0, 8);
        $candidates = [];
        foreach ($pageIds as $pageId) {
            $page = $this->pages[$pageId] ?? null;
            if ($page === null || $page['code'] !== $model.'-000-00') {
                continue;
            }
            if (array_diff($page['codes'], array_keys($priced)) === []) {
                $candidates[] = $page['code'];
            }
        }
        $colourCode = $model.'-'.self::colourCode($lowest).'-00';
        if (isset($this->headerCodes[$colourCode])) {
            $candidates[] = $colourCode;
        }
        $candidates[] = $lowest;
        foreach ($candidates as $candidate) {
            if (! isset($this->usedSkus[mb_strtolower($candidate)])) {
                return $candidate;
            }
        }

        return $lowest;
    }

    /**
     * Producent ze strony wiodącej: „Znak” ze słownika BRANDS albo dosłownie. Pusty „Znak” (04.10.2026: 119 kart, np.
     * odzież ostrzegawcza LEEDS/DOVER, Producent/Dostawca „VIZWELL INTERNATIONAL INC”) to wyrób z katalogu Canis bez
     * marki CXS — jak w dawnym cenniku Canis: Canis, chyba że Producent/Dostawca zaczyna się od znanej marki ze słownika
     * („3M Česko, spol. s r.o.” → 3M). Każda taka karta trafia do dziennika z nazwą dostawcy, a pole Producent/Dostawca
     * zostaje w tabelce karty.
     *
     * @param  array<string, mixed>  $lead
     */
    private function manufacturerOf(array $lead, string $remoteId): string
    {
        $mark = '';
        $supplier = '';
        foreach ($lead['params'] as $param) {
            if ($param['name'] === 'Znak' && $mark === '') {
                $mark = $param['value'];
            } elseif ($param['name'] === 'Producent/Dostawca' && $supplier === '') {
                $supplier = $param['value'];
            }
        }
        if ($mark !== '') {
            return self::BRANDS[mb_strtolower($mark)] ?? $mark;
        }
        $first = mb_strtolower(explode(' ', trim(explode(',', $supplier, 2)[0]), 2)[0]);
        $brand = self::BRANDS[$first] ?? self::BRAND;
        $this->withoutMark[] = $remoteId.' → '.$brand.' ('.($supplier !== '' ? mb_substr(explode(',', $supplier, 2)[0], 0, 40) : 'bez dostawcy').')';

        return $brand;
    }

    /**
     * Tabelka: parametry strony wiodącej dosłownie (bez rozmiaru, formułki bezpieczeństwa i — przy kilku kolorach —
     * koloru strony); parametr innej strony karty z inną wartością — z opisem jej pozycji przy wartości, a różne „Normy”
     * jako kolejne wiersze „Normy”. Do tego jednostka opakowania, jednostki alternatywne i rodzaj towaru pozycji.
     *
     * @param  array<string, mixed>  $lead
     * @param  list<int>  $pageIds
     * @param  array<string, array<string, mixed>>  $priced
     * @param  array<string, array{label: string, first: string, master: int}>  $colours
     * @return list<array{section: string, name: string, value: string}>
     */
    private function fields(array $lead, array $pageIds, array $priced, bool $manyColours, array $colours): array
    {
        $skip = $manyColours ? [...self::SKIPPED_PARAMS, self::COLOUR_PARAM] : self::SKIPPED_PARAMS;
        $out = [];
        $seen = [];
        $leadValues = [];
        $add = static function (string $section, string $name, string $value) use (&$out, &$seen): void {
            $key = $name."\n".$value;
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = ['section' => $section, 'name' => $name, 'value' => $value];
            }
        };
        foreach ($lead['params'] as $param) {
            if (in_array($param['name'], $skip, true)) {
                continue;
            }
            $leadValues[$param['name']][] = $param['value'];
            $add($param['section'], $param['name'], $param['value']);
        }
        foreach ($pageIds as $pageId) {
            $page = $this->pages[$pageId] ?? null;
            if ($page === null || $pageId === $lead['id']) {
                continue;
            }
            $labels = [];
            foreach (array_intersect($page['codes'], array_keys($priced)) as $code) {
                $labels[$colours[self::colourCode($code)]['label']] = true;
            }
            $label = implode(', ', array_keys($labels));
            foreach ($page['params'] as $param) {
                if (in_array($param['name'], $skip, true) || in_array($param['value'], $leadValues[$param['name']] ?? [], true)) {
                    continue;
                }
                if ($param['name'] === self::NORM_ROW) {
                    $add($param['section'], self::NORM_ROW, $param['value']);
                } elseif ($param['name'] !== self::COLOUR_PARAM) {
                    $add($param['section'], $param['name'].($label !== '' ? ' ('.$label.')' : ''), $param['value']);
                }
            }
        }

        $section = $lead['params'][0]['section'] ?? 'Parametry towaru';
        $packs = array_unique(array_filter(array_column($priced, 'pack'), static fn (string $p): bool => $p !== ''));
        if (count($packs) === 1) {
            $add($section, 'Jednostka opakowania', (string) reset($packs));
        }
        if ($lead['alt_units'] !== '') {
            $add($section, 'Jednostki alternatywne', $lead['alt_units']);
        }
        $kinds = [];
        foreach ($priced as $code => $row) {
            if ($row['kind'] !== '') {
                $kinds[$row['kind']][] = $code;
            }
        }
        if (count($kinds) === 1) {
            $add($section, 'Rodzaj towaru', (string) array_key_first($kinds));
        } elseif ($kinds !== []) {
            $parts = [];
            foreach ($kinds as $kind => $codes) {
                $parts[] = $kind.': '.implode(', ', $codes);
            }
            $add($section, 'Rodzaj towaru', mb_substr(implode('; ', $parts), 0, 2000));
        }

        return $out;
    }

    /**
     * Pliki stron karty (najpierw wiodącej), bez powtórzeń adresu i bez ulotek („leták”).
     *
     * @param  array<string, mixed>  $lead
     * @param  list<int>  $pageIds
     * @return list<array{title: string, url: string, kind: string}>
     */
    private function documentsOf(array $lead, array $pageIds): array
    {
        $out = [];
        foreach ([$lead['id'], ...$pageIds] as $pageId) {
            foreach ($this->pages[$pageId]['documents'] ?? [] as $file) {
                $kind = self::documentKind($file['title'].' '.rawurldecode($file['url']));
                if ($kind === null || isset($out[$file['url']])) {
                    continue;
                }
                $out[$file['url']] = ['title' => $file['title'], 'url' => $file['url'], 'kind' => $kind];
            }
        }

        return array_values($out);
    }

    /** Rodzaj pliku z nazwy portalu („17590-product”, „-manual”, „-deklarace eu”); ulotka = null (pomijana). */
    public static function documentKind(string $title): ?string
    {
        $lower = mb_strtolower($title);

        return match (true) {
            str_contains($lower, 'leták') || str_contains($lower, 'letak') => null,
            str_contains($lower, 'deklarac') || str_contains($lower, 'prohlášení') || str_contains($lower, 'prohlaseni') || str_contains($lower, 'declaration') => ProductDocument::KIND_CERTIFICATE,
            str_contains($lower, 'manual') || str_contains($lower, 'návod') || str_contains($lower, 'instrukcja') => ProductDocument::KIND_MANUAL,
            str_contains($lower, 'product') || str_contains($lower, 'technick') || str_contains($lower, 'karta') => ProductDocument::KIND_DATASHEET,
            default => ProductDocument::KIND_OTHER,
        };
    }

    /**
     * Zdjęcia z portalu producenta. Najpierw zdjęcie koloru (galeria strony koloru albo miniatura jego kafla „Wybierz
     * wariant”), a gdy portal go nie pokazał — galeria strony, na której jest ten wyrób (strona modelu SIRIUS ma zdjęcie
     * całego modelu; decyzja użytkownika 05.10.2026: zdjęcie modelu od producenta jest lepsze niż żadne). Jeden kolor:
     * cała galeria strony koloru albo strony wiodącej; kilka kolorów: po jednym zdjęciu koloru, kolor wiodący pierwszy.
     *
     * @param  array<string, mixed>  $lead
     * @param  list<int>  $pageIds
     * @param  list<string>  $codes  kody pozycji karty
     * @param  array<string, array{label: string, first: string, master: int}>  $colours
     * @return list<string>
     */
    private function imagesOf(array $lead, array $pageIds, array $codes, array $colours, bool $manyColours): array
    {
        if (! $manyColours) {
            if (array_diff($lead['codes'], $codes) === [] && $lead['gallery'] !== []) {
                return $lead['gallery'];
            }
            $colour = reset($colours);
            $master = $colour['master'];
            if ($master > 0 && ($this->pages[$master]['gallery'] ?? []) !== []) {
                return $this->pages[$master]['gallery'];
            }
            foreach ($pageIds as $pageId) {
                if ($master > 0 && isset($this->pages[$pageId]['colour_images'][$master])) {
                    return [$this->pages[$pageId]['colour_images'][$master]];
                }
            }

            return $lead['gallery'];
        }
        $out = [];
        foreach ($colours as $colour) {
            $url = $this->colourImage($colour, $pageIds);
            if ($url !== null && ! in_array($url, $out, true)) {
                $out[] = $url;
            }
        }

        return $out !== [] ? $out : array_slice($lead['gallery'], 0, 1);
    }

    /**
     * Zdjęcie koloru: galeria strony koloru, miniatura jego kafla na stronie karty, pierwsza z galerii strony karty
     * z tym kolorem; null = portal nie pokazał żadnego.
     *
     * @param  array{label: string, first: string, master: int}  $colour
     * @param  list<int>  $pageIds
     */
    private function colourImage(array $colour, array $pageIds): ?string
    {
        $master = $colour['master'];
        if ($master > 0 && isset($this->pages[$master]['gallery'][0])) {
            return $this->pages[$master]['gallery'][0];
        }
        foreach ($pageIds as $pageId) {
            if ($master > 0 && isset($this->pages[$pageId]['colour_images'][$master])) {
                return $this->pages[$pageId]['colour_images'][$master];
            }
        }
        foreach ($pageIds as $pageId) {
            $page = $this->pages[$pageId] ?? null;
            if ($page !== null && in_array($colour['first'], $page['codes'], true) && isset($page['gallery'][0])) {
                return $page['gallery'][0];
            }
        }

        return null;
    }

    /**
     * Nazwa karty: wspólny początek nazw pozycji bez rozmiaru, liczony całymi członami po przecinkach (kolory bez słowa
     * „kolor” też odpadają); jedna nazwa — ona cała.
     *
     * @param  array<string, string>  $names
     */
    public static function cardName(array $names): string
    {
        $stripped = array_values(array_unique(array_map(self::withoutSize(...), $names)));
        if (count($stripped) === 1) {
            return $stripped[0];
        }
        $parts = array_map(static fn (string $n): array => array_map('trim', explode(',', $n)), $stripped);
        $common = [];
        foreach ($parts[0] as $i => $segment) {
            foreach ($parts as $other) {
                if (($other[$i] ?? null) !== $segment) {
                    break 2;
                }
            }
            $common[] = $segment;
        }

        return $common !== [] ? implode(', ', $common) : self::baseName($stripped[0]);
    }

    /** Opis koloru pozycji: człon „kolor …” z nazwy; bez niego — nazwa bez rozmiaru po nazwie karty; inaczej kod koloru. */
    private static function colourLabel(string $rowName, string $cardName, string $colourCode): string
    {
        $name = self::withoutSize($rowName);
        if (preg_match('/(?:^|,)\s*(kolor\s[^,]+)/iu', $name, $m) === 1) {
            return CanisPageParser::clean($m[1]);
        }
        if ($cardName !== '' && str_starts_with($name, $cardName)) {
            $rest = trim(substr($name, strlen($cardName)), ' ,;');
            if ($rest !== '') {
                return $rest;
            }
        }

        return $colourCode;
    }

    private static function colourCode(string $code): string
    {
        return preg_match(CanisPageParser::CODE, $code) === 1 ? substr($code, 9, 3) : $code;
    }

    /** Kafel z rozmiarem w nazwie („Rękawice ASTAR, rozmiar 09”) — pobierany po kaflach modeli i kolorów. */
    private static function sizeTile(string $name): int
    {
        return preg_match(self::SIZE_TAIL, $name) === 1 ? 1 : 0;
    }

    /** Jednostka stanu („138 szt.”, „Na stanie (5106 para)”) — ostatnie słowo po liczbie; '' = brak. */
    private static function stockUnit(string $stock): string
    {
        return preg_match('/\d[\d\s]*\s+([^\d\s()]+)\)?\s*$/u', trim($stock), $m) === 1 ? rtrim($m[1], ')') : '';
    }

    /**
     * Stan pozycji: „Na stanie: 138 szt.” / „Brak na stanie” / tekst portalu dosłownie; rodzaj towaru inny niż zwykły
     * („Wyprzedaż”, „Na zamówienie”) dopisany.
     *
     * @return array{0: string, 1: string}
     */
    private static function availabilityOf(string $stock, string $kind): array
    {
        $stock = CanisPageParser::clean($stock);
        if (preg_match('/(\d[\d\s]*)\s*([^\d\s()]*)\)?\s*$/u', $stock, $m) === 1) {
            $qty = (float) preg_replace('/\s+/', '', $m[1]);
            $status = $qty > 0 ? 'Na stanie' : 'Brak na stanie';
            $text = $qty > 0 ? $status.': '.B2bOrderQuantity::format($qty).($m[2] !== '' ? ' '.$m[2] : '') : $status;
        } else {
            $status = $stock !== '' ? $stock : 'Brak danych o stanie';
            $text = $status;
        }
        if ($kind !== '' && $kind !== self::REGULAR_KIND) {
            $text .= ' ('.$kind.')';
        }

        return [$status, $text];
    }

    /** Krok pola ilości > 1 = sprzedaż w wielokrotnościach (minimum = krok); 1 = bez ograniczeń. */
    private static function orderQuantity(float $step, ?string $unit): B2bOrderQuantity
    {
        return $step > 1 ? new B2bOrderQuantity(min: $step, step: $step, unit: $unit) : new B2bOrderQuantity(min: 1.0, step: null, unit: $unit);
    }

    /**
     * @param  array<string, list<string>>  $statuses
     */
    private static function groupAvailability(array $statuses): ?string
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
     * @param  array<string, mixed>  $row
     */
    private function pageUrlOf(array $row): ?string
    {
        foreach ($row['pages'] as $id) {
            if (isset($this->pages[$id])) {
                return CanisB2bClient::absolute($this->pages[$id]['url']);
            }
        }

        return null;
    }

    private function skipped(string $sku, string $name, ?string $url, string $reason): B2bRemoteProduct
    {
        return new B2bRemoteProduct(
            remoteId: $sku,
            sku: $sku,
            name: $name !== '' ? $name : $sku,
            sourceUrl: $url !== null && $url !== '' ? CanisB2bClient::absolute($url) : null,
            raw: ['status' => 'skipped', 'reason' => $reason],
        );
    }

    private static function money(?float $value): string
    {
        return $value === null ? 'brak' : number_format($value, 2, ',', '');
    }

    private function now(): float
    {
        return $this->clock !== null ? (float) ($this->clock)() : microtime(true);
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
}

<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bAccount;
use App\Models\Product;
use App\Models\ProductShopCard;
use App\Models\ProductVariant;
use RuntimeException;

/**
 * signproject.pl — jeden znak (kod, np. BB014) = jedna karta, wersje (format × podłoże) = osobne produkty
 * IdoSell z własną ceną konta. Lista z mapy strony, ceny z ajax/projector.php (osobne zapytanie na wersję,
 * seriami z jedną przerwą na serię — SignProjectB2bClient::projectorMany).
 *
 * Bezpieczeństwo sesji: przy logowaniu zapamiętujemy cenę konta i cenę anonimową wersji kontrolnej.
 * Po zebraniu cen znaku cena kontrolna jest pobierana ponownie — inna niż zapamiętana (albo równa anonimowej)
 * = ponowne logowanie i ponowne zebranie cen; drugi raz źle = B2bFatalException (nic nie zapisujemy).
 */
final class SignProjectB2bConnector implements B2bShopFieldSource, B2bVariantConnector
{
    public const SOURCE = 'b2b:signproject';

    /** BB014 10 x 14,8 cm FN — cena konta różna od anonimowej (0,97 vs 3,23 netto, 14.09.2026). */
    public const CONTROL_PRODUCT_ID = 7109;

    private const SIGN_GET = 'sizes,sizeprices,versions';

    private const PRICE_GET = 'sizes,sizeprices';

    private const SESSION_LOST = 'Utracono sesję konta signproject.pl — ceny konta niedostępne';

    private const EPSILON = 0.005;

    private const VERSIONS_LINE_LIMIT = 1500;

    /** Sekcje karty wyrobu u dostawcy (B2bShopFieldSource). */
    private const SHOP_SECTION_TRADE = 'Informacje handlowe';

    private const SHOP_SECTION_VERSIONS = 'Dostępne wersje';

    /** @var list<int> */
    private array $listedIds = [];

    /** @var array<int, string> */
    private array $listedUrls = [];

    private bool $listLoaded = false;

    private bool $listComplete = false;

    /** @var array<int, true> */
    private array $seen = [];

    private int $seenListed = 0;

    private int $signsYielded = 0;

    /** @var array<string, true> wersje z kilkoma pozycjami cenowymi: "{id}:{klucz}" */
    private array $multiKeyIds = [];

    private bool $sessionReady = false;

    private ?float $controlPrice = null;

    private ?string $controlKey = null;

    private ?float $anonymousPrice = null;

    /** false = cena konta wersji kontrolnej równa anonimowej; wtedy utratę sesji wykrywa tylko „Wyloguj”. */
    private bool $controlDistinguishable = false;

    /**
     * ID wersji (bazowe, sprzed „:”) znane w product_variants → karta; 0 = wersja na kilku kartach.
     * Wypełniane przy starcie products() — tylko do decyzji, czy strona znaku jest potrzebna dla kategorii.
     *
     * @var array<int, int>
     */
    private array $knownCardOf = [];

    /** @var array<int, array<string, mixed>> odpowiedź projector.php znaku bieżącego (z products()) */
    private array $projectorCache = [];

    /**
     * Jednostka sprzedaży i stawka VAT znaku, zapamiętane przy zbieraniu cen wersji — tylko gdy wszystkie wersje
     * podają to samo (inaczej to dane wersji, nie znaku). Bez nowego zapytania: wartości są w tych samych
     * odpowiedziach projector.php, które czyta variants().
     *
     * @var array{remote_id: string, unit: string, vat: string}|null
     */
    private ?array $saleTerms = null;

    private ?string $pageUrl = null;

    private ?string $pageHtml = null;

    public function __construct(
        private readonly SignProjectB2bClient $client,
        private ?int $controlProductId = self::CONTROL_PRODUCT_ID,
    ) {}

    public static function key(): string
    {
        return 'signproject';
    }

    public static function label(): string
    {
        return 'SignProject';
    }

    public static function host(): string
    {
        return SignProjectB2bClient::HOST;
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new self(new SignProjectB2bClient($account->username, (string) $account->password, $delayMs));
    }

    public function login(): void
    {
        $this->client->login();
        $this->sessionReady = true;

        if ($this->controlProductId === null) {
            return;
        }
        $logged = $this->controlPriceOf(false);
        if ($logged === null) {
            // wersja kontrolna niedostępna — kontrolą zostanie pierwsza wersja z ceną pierwszego znaku
            $this->controlProductId = null;

            return;
        }
        $this->rememberControl($logged, $this->controlPriceOf(true));
    }

    public function products(): iterable
    {
        $this->loadList();

        foreach ($this->candidateOrder() as $id) {
            if (isset($this->seen[$id])) {
                continue;
            }
            $this->markSeen($id);

            try {
                $json = $this->client->projector($id, self::SIGN_GET);
            } catch (B2bFatalException $e) {
                throw $e;
            } catch (RuntimeException) {
                continue;
            }

            $sign = $this->signFromProjector($id, $json);
            if ($sign === null) {
                continue;
            }
            foreach ($sign->raw['versions'] as $version) {
                $this->markSeen($version['id']);
            }
            $this->projectorCache = [$id => $json];
            $this->signsYielded++;

            yield $sign;
        }
        $this->projectorCache = [];
    }

    /** Znaki podane dotąd + szacunek pozostałych (średnia liczba wersji na znak); dokładna na końcu listy. */
    public function totalProducts(): int
    {
        if ($this->signsYielded === 0) {
            return 0;
        }
        $remaining = count($this->listedIds) - $this->seenListed;
        if ($remaining <= 0) {
            return $this->signsYielded;
        }
        $perSign = max(1.0, $this->seenListed / $this->signsYielded);

        return $this->signsYielded + (int) ceil($remaining / $perSign);
    }

    public function totalVariants(): int
    {
        return count($this->listedIds);
    }

    public function listedVariantIds(): ?array
    {
        if (! $this->listLoaded || ! $this->listComplete) {
            return null;
        }
        $ids = array_map('strval', $this->listedIds);
        // wersje z kilkoma pozycjami cenowymi są na liście, gdy jest na niej ich ID bazowe
        foreach (array_keys($this->multiKeyIds) as $remoteId) {
            if (isset($this->listedUrls[(int) explode(':', $remoteId, 2)[0]])) {
                $ids[] = $remoteId;
            }
        }

        return $ids;
    }

    public function runBudgetMinutes(): ?int
    {
        return 240;
    }

    public function manufacturer(B2bRemoteProduct $product): string
    {
        return (string) ($product->raw['firm'] ?? '');
    }

    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        return null;
    }

    public function variants(B2bRemoteProduct $product): array
    {
        if (! $this->sessionReady) {
            $this->login();
        }

        $variants = $this->collectVariants($product, true);
        if ($this->controlProductId === null) {
            $this->adoptControl($variants);
        }

        if (! $this->sessionHealthy()) {
            $this->relogin();
            $variants = $this->collectVariants($product, false);
            if (! $this->sessionHealthy()) {
                throw new B2bFatalException(self::SESSION_LOST);
            }
        }
        $this->projectorCache = [];

        return $variants;
    }

    public function description(B2bRemoteProduct $product): string
    {
        $html = $this->pageFor($product);

        $long = self::longDescription($html);
        if ($long !== '') {
            return mb_substr($long, 0, 10000);
        }

        return $this->factualDescription($product, self::categorySegments($html));
    }

    /**
     * Karta wyrobu u dostawcy dla znaku jako całości, z danych, które łącznik już ma (żadnego zapytania więcej):
     * producent, kategoria i — gdy wszystkie wersje podają to samo — jednostka sprzedaży oraz stawka VAT
     * z odpowiedzi projector.php zebranych w variants(). Dalej człony wersji z nagłówka („Format \ Podłoże”)
     * z zestawem dostępnych wartości; samej tabeli wersji nie powtarzamy, bo karta ma ją osobno. Ceny nie
     * dokładamy — karta ma na nie własną sekcję.
     *
     * @return list<B2bRemoteShopField>
     */
    public function shopFields(B2bRemoteProduct $product): array
    {
        $fields = [];
        $firm = trim((string) ($product->raw['firm'] ?? ''));
        if ($firm !== '') {
            $fields[] = new B2bRemoteShopField(self::SHOP_SECTION_TRADE, 'Producent', $firm);
        }
        $category = trim((string) ($product->category ?? ''));
        if ($category !== '') {
            $fields[] = new B2bRemoteShopField(self::SHOP_SECTION_TRADE, 'Kategoria', $category);
        }
        if (($this->saleTerms['remote_id'] ?? null) === $product->remoteId) {
            if ($this->saleTerms['unit'] !== '') {
                $fields[] = new B2bRemoteShopField(self::SHOP_SECTION_TRADE, 'Jednostka sprzedaży', $this->saleTerms['unit']);
            }
            if ($this->saleTerms['vat'] !== '') {
                $fields[] = new B2bRemoteShopField(self::SHOP_SECTION_TRADE, 'Stawka VAT (%)', $this->saleTerms['vat']);
            }
        }

        foreach (self::versionMemberValues($product) as $name => $values) {
            $fields[] = new B2bRemoteShopField(
                self::SHOP_SECTION_VERSIONS,
                (string) $name,
                self::limitedList($values, ProductShopCard::MAX_VALUE_CHARS),
            );
        }

        return $fields;
    }

    /**
     * Człon nagłówka wersji → wartości dostępne w wersjach znaku, dosłownie i bez powtórzeń. Etykieta o innej
     * liczbie członów niż nagłówek jest pomijana (tak samo jak w attributes() — bez zgadywania, co jest czym).
     *
     * @return array<string, list<string>>
     */
    private static function versionMemberValues(B2bRemoteProduct $product): array
    {
        $header = self::members((string) ($product->raw['version_header'] ?? ''));
        if ($header === [] || in_array('', $header, true) || count(array_unique($header)) !== count($header)) {
            return [];
        }

        $values = array_fill_keys($header, []);
        foreach ($product->raw['versions'] ?? [] as $version) {
            $parts = self::members((string) $version['name']);
            if (count($parts) !== count($header)) {
                continue;
            }
            foreach ($header as $i => $name) {
                if ($parts[$i] !== '' && ! in_array($parts[$i], $values[$name], true)) {
                    $values[$name][] = $parts[$i];
                }
            }
        }

        return array_filter($values, static fn (array $list): bool => $list !== []);
    }

    public function image(B2bRemoteProduct $product): ?B2bRemoteImage
    {
        $candidates = [];
        try {
            $og = self::metaContent($this->pageFor($product), 'property', 'og:image');
            if ($og !== '') {
                $candidates[] = self::absoluteUrl($og);
            }
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException) {
            // strona niedostępna — zostaje miniatura z projector.php
        }
        $icon = trim((string) ($product->raw['icon'] ?? ''));
        if ($icon !== '') {
            $candidates[] = self::absoluteUrl($icon);
        }

        foreach (array_values(array_unique($candidates)) as $url) {
            try {
                $file = $this->client->imageBytes($url);
            } catch (B2bFatalException $e) {
                throw $e;
            } catch (RuntimeException) {
                continue;
            }
            if ($file['bytes'] !== '' && str_starts_with($file['mime'], 'image/')) {
                return new B2bRemoteImage(bytes: $file['bytes'], mime: $file['mime'], sourceUrl: self::withoutQuery($url));
            }
        }

        return null;
    }

    private function loadList(): void
    {
        if ($this->listLoaded) {
            return;
        }
        $list = $this->client->sitemapProductIds();
        $this->listedIds = $list['ids'];
        $this->listedUrls = $list['urls'];
        $this->listComplete = $list['complete'];
        $this->listLoaded = true;
    }

    /**
     * Najpierw ID nieznane w product_variants (kolejność mapy), potem znane — kartami od najstarszego
     * MIN(price_checked_at); przerwany przebieg kontynuuje się bez kursora.
     *
     * @return iterable<int>
     */
    private function candidateOrder(): iterable
    {
        /** @var array<int, int> $productOf */
        $productOf = [];
        /** @var array<int, string> $oldest */
        $oldest = [];
        foreach (ProductVariant::query()->where('source', self::SOURCE)
            ->select(['id', 'product_id', 'remote_id', 'price_checked_at'])
            ->toBase()
            ->lazyById(5000) as $row) {
            $baseId = (int) explode(':', (string) $row->remote_id, 2)[0];
            $productId = (int) $row->product_id;
            $productOf[$baseId] ??= $productId;
            $this->knownCardOf[$baseId] = $this->knownCardOf[$baseId] ?? $productId;
            if ($this->knownCardOf[$baseId] !== $productId) {
                $this->knownCardOf[$baseId] = 0;
            }
            // nigdy niesprawdzona cena = najstarsza
            $checked = $row->price_checked_at !== null ? (string) $row->price_checked_at : '';
            if (! isset($oldest[$productId]) || strcmp($checked, $oldest[$productId]) < 0) {
                $oldest[$productId] = $checked;
            }
        }

        $known = [];
        foreach ($this->listedIds as $id) {
            if (isset($productOf[$id])) {
                $known[$productOf[$id]][] = $id;
            } else {
                yield $id;
            }
        }

        uksort($known, static fn (int $a, int $b): int => [$oldest[$a], $a] <=> [$oldest[$b], $b]);
        foreach ($known as $ids) {
            yield from $ids;
        }
    }

    private function markSeen(int $id): void
    {
        if (isset($this->seen[$id])) {
            return;
        }
        $this->seen[$id] = true;
        if (isset($this->listedUrls[$id])) {
            $this->seenListed++;
        }
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function signFromProjector(int $id, array $json): ?B2bRemoteProduct
    {
        $sizes = $json['sizes'] ?? null;
        if (! is_array($sizes)) {
            return null;
        }
        $code = trim((string) ($sizes['code'] ?? ''));
        $name = trim((string) ($sizes['name'] ?? ''));
        $versionsNode = is_array($json['versions'] ?? null) ? $json['versions'] : [];
        $header = trim((string) ($versionsNode['name'] ?? ''));

        $versions = [];
        $ids = [];
        foreach (is_array($versionsNode['items'] ?? null) ? $versionsNode['items'] : [] as $item) {
            $versionId = is_array($item) ? (int) ($item['id'] ?? 0) : 0;
            if ($versionId <= 0 || isset($ids[$versionId])) {
                continue;
            }
            $ids[$versionId] = true;
            $link = trim((string) ($item['link'] ?? ''));
            $versions[] = [
                'id' => $versionId,
                'name' => trim((string) ($item['name'] ?? '')),
                'link' => $link !== '' ? self::absoluteUrl($link) : ($this->listedUrls[$versionId] ?? null),
            ];
        }
        if (! isset($ids[$id])) {
            // produkt bez wersji (albo lista wersji bez niego samego) — sam jest wersją; etykieta = nazwa ze sklepu
            $link = trim((string) ($sizes['link'] ?? ''));
            $versions[] = [
                'id' => $id,
                'name' => '',
                'link' => $this->listedUrls[$id] ?? ($link !== '' ? self::absoluteUrl($link) : null),
            ];
        }

        $sourceUrl = $versions[0]['link'];
        $category = null;
        if ($sourceUrl !== null && $this->categoryNeeded($versions)) {
            try {
                $segments = self::categorySegments($this->page($sourceUrl));
                $category = $segments !== [] ? implode(' › ', $segments) : null;
            } catch (B2bFatalException $e) {
                throw $e;
            } catch (RuntimeException) {
                // kategoria zostaje pusta; description() spróbuje pobrać stronę jeszcze raz
            }
        }

        $firm = is_array($sizes['firm'] ?? null) ? ($sizes['firm']['name'] ?? '') : '';

        return new B2bRemoteProduct(
            remoteId: $code,
            sku: $code,
            name: $name,
            category: $category,
            sourceUrl: $sourceUrl,
            raw: [
                'id' => $id,
                'versions' => $versions,
                'version_header' => $header,
                'firm' => is_string($firm) ? $firm : '',
                'page_url' => $sourceUrl,
                'icon' => (string) ($sizes['icon'] ?? ''),
            ],
        );
    }

    /**
     * Strona znaku jest potrzebna dla kategorii, chyba że znane wersje tego znaku należą do jednej karty, która
     * kategorię już ma — B2bCatalogSync uzupełnia tylko pustą kategorię (karta wybierana po znanych wersjach),
     * więc pobranie strony nic by nie zmieniło. description()/image() i tak pobiorą stronę, gdy synchronizacja
     * ich potrzebuje.
     *
     * @param  list<array{id: int, name: string, link: string|null}>  $versions
     */
    private function categoryNeeded(array $versions): bool
    {
        $cards = [];
        foreach ($versions as $version) {
            $card = $this->knownCardOf[$version['id']] ?? null;
            if ($card === 0) {
                return true;
            }
            if ($card !== null) {
                $cards[$card] = true;
            }
        }
        if (count($cards) !== 1) {
            return true;
        }

        $category = Product::query()->whereKey(array_key_first($cards))->value('category');

        return trim((string) $category) === '';
    }

    /**
     * Ceny wersji znaku: z pamięci products() (useCache) albo z SignProjectB2bClient::projectorMany (serie zapytań).
     *
     * @return list<B2bRemoteVariant>
     */
    private function collectVariants(B2bRemoteProduct $product, bool $useCache): array
    {
        $versions = $product->raw['versions'] ?? [];
        $toFetch = [];
        foreach ($versions as $version) {
            $id = (int) $version['id'];
            if (! $useCache || ! isset($this->projectorCache[$id])) {
                $toFetch[] = $id;
            }
        }
        $fetched = $toFetch !== [] ? $this->client->projectorMany($toFetch, self::PRICE_GET) : [];

        $headerParts = self::members((string) ($product->raw['version_header'] ?? ''));
        $variants = [];
        $units = [];
        $vats = [];
        $sort = 0;
        foreach ($versions as $version) {
            $id = (int) $version['id'];
            $label = $version['name'] !== '' ? $version['name'] : $product->name;
            $attributes = $version['name'] !== '' ? self::attributes($headerParts, $version['name']) : [];

            $json = $useCache && isset($this->projectorCache[$id]) ? $this->projectorCache[$id] : ($fetched[$id] ?? null);
            if (! is_array($json)) {
                $reason = $json instanceof RuntimeException ? $json->getMessage() : 'brak odpowiedzi sklepu';
                $variants[] = new B2bRemoteVariant((string) $id, $label, $attributes, null,
                    'nie udało się pobrać ceny: '.$reason, $version['link'], $sort++);
                // o tej wersji nie wiadomo nic — jednostka i VAT znaku przestają być pewne
                $units[] = '';
                $vats[] = '';

                continue;
            }

            // jednostka i stawka VAT z tej samej odpowiedzi — karta dostawcy pokaże je, gdy są wspólne
            $sizes = is_array($json['sizes'] ?? null) ? $json['sizes'] : [];
            $units[] = is_string($sizes['unit'] ?? null) ? trim($sizes['unit']) : '';
            $vat = $sizes['taxes']['vat'] ?? null;
            $vats[] = is_scalar($vat) ? trim((string) $vat) : '';

            foreach ($this->versionVariants($product, $id, $label, $attributes, $version['link'], $json) as $variant) {
                $variants[] = new B2bRemoteVariant(
                    remoteId: $variant->remoteId,
                    label: $variant->label,
                    attributes: $variant->attributes,
                    price: $variant->price,
                    priceError: $variant->priceError,
                    sourceUrl: $variant->sourceUrl,
                    sortOrder: $sort++,
                    vatRate: $variant->vatRate,
                    unit: $variant->unit,
                );
            }
        }
        $this->saleTerms = [
            'remote_id' => $product->remoteId,
            'unit' => self::commonValue($units),
            'vat' => self::commonValue($vats),
        ];

        return $variants;
    }

    /**
     * Wartość wspólna dla wszystkich wersji; '' = którejś brakuje albo się różnią (to dana wersji, nie znaku).
     *
     * @param  list<string>  $values
     */
    private static function commonValue(array $values): string
    {
        if ($values === [] || in_array('', $values, true)) {
            return '';
        }

        return count(array_unique($values)) === 1 ? $values[0] : '';
    }

    /**
     * @param  array<string, string>  $attributes
     * @param  array<string, mixed>  $json
     * @return list<B2bRemoteVariant>
     */
    private function versionVariants(B2bRemoteProduct $product, int $id, string $label, array $attributes, ?string $link, array $json): array
    {
        $sizes = is_array($json['sizes'] ?? null) ? $json['sizes'] : null;
        if ($sizes === null) {
            return [new B2bRemoteVariant((string) $id, $label, $attributes, null, 'brak danych wersji w odpowiedzi sklepu', $link)];
        }
        $versionCode = trim((string) ($sizes['code'] ?? ''));
        if ($product->sku !== '' && $versionCode !== '' && $versionCode !== $product->sku) {
            return [new B2bRemoteVariant((string) $id, $label, $attributes, null,
                'kod wersji ('.$versionCode.') inny niż kod znaku ('.$product->sku.')', $link)];
        }

        $vat = $sizes['taxes']['vat'] ?? null;
        $vatRate = is_numeric($vat) ? (float) $vat : null;
        $unit = is_string($sizes['unit'] ?? null) && $sizes['unit'] !== '' ? $sizes['unit'] : null;
        $currency = self::currency($json['sizeprices'] ?? null);

        $items = is_array($sizes['items'] ?? null) ? $sizes['items'] : [];
        if ($items === []) {
            return [new B2bRemoteVariant((string) $id, $label, $attributes, null, 'sklep nie podał pozycji z ceną', $link, 0, $vatRate, $unit)];
        }

        $multi = count($items) > 1;
        $out = [];
        foreach ($items as $key => $item) {
            $remoteId = $multi ? $id.':'.$key : (string) $id;
            $itemName = is_array($item) ? trim((string) ($item['name'] ?? '')) : '';
            $itemLabel = $multi && $itemName !== '' ? $label.' ('.$itemName.')' : $label;
            if ($multi) {
                $this->multiKeyIds[$remoteId] = true;
            }

            $net = is_array($item) ? ($item['prices']['price_net'] ?? null) : null;
            $net = is_numeric($net) ? round((float) $net, 2) : null;
            $error = match (true) {
                $net === null || $net <= 0 => 'sklep nie podał ceny netto konta',
                $currency === null => 'sklep nie podał waluty ceny',
                default => null,
            };

            $out[] = new B2bRemoteVariant(
                remoteId: $remoteId,
                label: $itemLabel,
                attributes: $attributes,
                price: $error === null ? new B2bRemotePrice(net: (float) $net, base: null, discountPercent: 0.0, currency: (string) $currency) : null,
                priceError: $error,
                sourceUrl: $link,
                vatRate: $vatRate,
                unit: $unit,
            );
        }

        return $out;
    }

    /**
     * Kontrola z pierwszej wersji z ceną, gdy stała wersja kontrolna była niedostępna przy logowaniu.
     *
     * @param  list<B2bRemoteVariant>  $variants
     */
    private function adoptControl(array $variants): void
    {
        foreach ($variants as $variant) {
            if ($variant->price === null) {
                continue;
            }
            [$baseId, $key] = array_pad(explode(':', $variant->remoteId, 2), 2, null);
            $this->controlProductId = (int) $baseId;
            $this->controlKey = $key;
            $this->rememberControl($variant->price->net, $this->controlPriceOf(true));

            return;
        }
    }

    private function rememberControl(float $logged, ?float $anonymous): void
    {
        $this->controlPrice = $logged;
        $this->anonymousPrice = $anonymous;
        $this->controlDistinguishable = $anonymous !== null && abs($logged - $anonymous) >= self::EPSILON;
    }

    private function sessionHealthy(): bool
    {
        if ($this->controlProductId === null || $this->controlPrice === null) {
            return true;
        }
        $price = $this->controlPriceOf(false);
        if ($price === null || abs($price - $this->controlPrice) >= self::EPSILON) {
            return false;
        }

        return ! ($this->controlDistinguishable && $this->anonymousPrice !== null
            && abs($price - $this->anonymousPrice) < self::EPSILON);
    }

    private function relogin(): void
    {
        try {
            $this->client->login();
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw new B2bFatalException(self::SESSION_LOST.' ('.$e->getMessage().')', 0, $e);
        }
        if ($this->controlProductId === null) {
            return;
        }

        $logged = $this->controlPriceOf(false);
        $anonymous = $this->controlPriceOf(true);
        if ($logged === null) {
            throw new B2bFatalException(self::SESSION_LOST);
        }
        if ($this->controlDistinguishable && $anonymous !== null && abs($logged - $anonymous) < self::EPSILON) {
            // przed chwilą konto miało inną cenę niż gość, a po zalogowaniu już nie — ceny konta nie są dostępne
            throw new B2bFatalException(self::SESSION_LOST);
        }
        // cena kontrolna mogła się naprawdę zmienić — nowa podstawa po potwierdzonym logowaniu
        $this->rememberControl($logged, $anonymous);
    }

    private function controlPriceOf(bool $anonymous): ?float
    {
        if ($this->controlProductId === null) {
            return null;
        }
        try {
            $json = $this->client->projector($this->controlProductId, self::PRICE_GET, $anonymous);
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException) {
            return null;
        }
        $items = $json['sizes']['items'] ?? null;
        if (! is_array($items) || $items === []) {
            return null;
        }
        $item = $this->controlKey !== null ? ($items[$this->controlKey] ?? null) : reset($items);
        $net = is_array($item) ? ($item['prices']['price_net'] ?? null) : null;

        return is_numeric($net) && (float) $net > 0 ? round((float) $net, 2) : null;
    }

    private function pageFor(B2bRemoteProduct $product): string
    {
        $url = $product->raw['page_url'] ?? $product->sourceUrl;
        if (! is_string($url) || $url === '') {
            throw new RuntimeException('brak adresu strony znaku');
        }

        return $this->page($url);
    }

    /** Strona znaku pobierana raz: kategoria w products(), potem opis i zdjęcie z tej samej kopii. */
    private function page(string $url): string
    {
        if ($this->pageUrl !== $url || $this->pageHtml === null) {
            $this->pageHtml = null;
            $this->pageHtml = $this->client->productPage($url);
            $this->pageUrl = $url;
        }

        return $this->pageHtml;
    }

    /**
     * @param  list<string>  $categorySegments
     */
    private function factualDescription(B2bRemoteProduct $product, array $categorySegments): string
    {
        $lines = [$product->name];
        if ($categorySegments !== []) {
            $lines[] = 'Kategoria: '.implode(' › ', $categorySegments);
        }

        $header = self::members((string) ($product->raw['version_header'] ?? ''));
        $formatAt = array_search('Format', $header, true);
        $substrateAt = array_search('Podłoże', $header, true);
        $labels = [];
        $formats = [];
        $substrates = [];
        foreach ($product->raw['versions'] ?? [] as $version) {
            if ($version['name'] === '') {
                continue;
            }
            $labels[] = $version['name'];
            $parts = self::members($version['name']);
            if (count($parts) !== count($header)) {
                continue;
            }
            if ($formatAt !== false && $parts[$formatAt] !== '') {
                $formats[] = $parts[$formatAt];
            }
            if ($substrateAt !== false && $parts[$substrateAt] !== '') {
                $substrates[] = $parts[$substrateAt];
            }
        }
        $formats = array_values(array_unique($formats));
        $substrates = array_values(array_unique($substrates));

        if ($formats !== []) {
            $lines[] = 'Dostępne formaty: '.implode('; ', $formats);
        }
        if ($substrates !== []) {
            $lines[] = 'Podłoża: '.implode('; ', $substrates);
        }
        if ($formats === [] && $substrates === [] && $labels !== []) {
            $lines[] = 'Wersje: '.self::limitedList(array_values(array_unique($labels)), self::VERSIONS_LINE_LIMIT);
        }
        $lines[] = 'Opis z danych katalogu SignProject — sklep nie podaje opisu tego znaku.';

        return implode("\n", $lines);
    }

    /**
     * @param  list<string>  $items
     */
    private static function limitedList(array $items, int $limit): string
    {
        $out = '';
        foreach ($items as $i => $item) {
            $next = ($out === '' ? '' : $out.'; ').$item;
            if (mb_strlen($next) > $limit) {
                return $out.($i > 0 ? '; …' : '…');
            }
            $out = $next;
        }

        return $out;
    }

    /**
     * Człony etykiety/nagłówka rozdzielone „ \ ”.
     *
     * @return list<string>
     */
    private static function members(string $value): array
    {
        $value = trim($value);

        return $value === '' ? [] : array_map('trim', explode(' \\ ', $value));
    }

    /**
     * Atrybuty tylko, gdy liczba członów etykiety = liczbie członów nagłówka; inaczej [] (bez zgadywania).
     *
     * @param  list<string>  $headerParts
     * @return array<string, string>
     */
    private static function attributes(array $headerParts, string $label): array
    {
        $parts = self::members($label);
        if ($headerParts === [] || count($parts) !== count($headerParts)
            || in_array('', $parts, true) || in_array('', $headerParts, true)
            || count(array_unique($headerParts)) !== count($headerParts)) {
            return [];
        }

        return array_combine($headerParts, $parts);
    }

    /** Waluta tylko z tekstu ceny podanego przez sklep („3,23 zł” → PLN); brak = null, bez domyślnej. */
    private static function currency(mixed $sizeprices): ?string
    {
        if (! is_array($sizeprices)) {
            return null;
        }
        foreach (['price_net_formatted', 'price_formatted'] as $field) {
            $text = $sizeprices[$field] ?? null;
            if (! is_string($text)) {
                continue;
            }
            if (mb_stripos($text, 'zł') !== false) {
                return 'PLN';
            }
            if (preg_match('/(?<![A-Za-z])([A-Z]{3})(?![A-Za-z])/', $text, $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }

    /**
     * Ścieżka kategorii: breadcrumbs bez „Strona główna” i samego produktu; gdy dają najwyżej jeden poziom,
     * środkowy człon szablonu meta description („{wersja} | {Kategoria \ Podkategoria} | - Sklep … SignProject”).
     *
     * @return list<string>
     */
    private static function categorySegments(string $html): array
    {
        $crumbs = [];
        if (preg_match('#<div[^>]*\bid="breadcrumbs"[^>]*>(.*?)</ol>#is', $html, $m) === 1) {
            $block = preg_replace('#<ul[^>]*breadcrumbs__sub[^>]*>.*?</ul>#is', '', $m[1]) ?? $m[1];
            preg_match_all('#<li\b[^>]*\bclass="([^"]*)"[^>]*>(.*?)</li>#is', $block, $items, PREG_SET_ORDER);
            foreach ($items as [, $class, $inner]) {
                $classes = preg_split('/\s+/', trim($class)) ?: [];
                if (! in_array('category', $classes, true) || in_array('bc-product-name', $classes, true)) {
                    continue;
                }
                $text = self::inlineText($inner);
                if ($text !== '' && $text !== 'Strona główna') {
                    $crumbs[] = $text;
                }
            }
        }

        if (count($crumbs) <= 1) {
            $parts = explode(' | ', self::metaContent($html, 'name', 'description'));
            if (count($parts) >= 3 && str_contains((string) end($parts), 'SignProject')) {
                $segments = array_values(array_filter(self::members($parts[count($parts) - 2]), static fn (string $s): bool => $s !== ''));
                if (count($segments) > count($crumbs)) {
                    $crumbs = $segments;
                }
            }
        }

        return $crumbs;
    }

    private static function longDescription(string $html): string
    {
        if (preg_match('#<section\b[^>]*\bid="projector_longdescription"[^>]*>(.*?)</section>#is', $html, $m) !== 1) {
            return '';
        }
        // białe znaki jak w przeglądarce; podziały tylko z tagów blokowych
        $text = preg_replace('/\s+/u', ' ', $m[1]) ?? $m[1];
        $text = preg_replace('#<\s*/?\s*br\s*/?\s*>#i', "\n", $text) ?? $text;
        $text = preg_replace('#<\s*li[^>]*>#i', "\n- ", $text) ?? $text;
        $text = preg_replace('#</?\s*(p|div|h[1-6]|ul|ol|tr|table)\b[^>]*>#i', "\n", $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $lines = [];
        foreach (preg_split('/\n/u', $text) ?: [] as $line) {
            $line = trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', $line) ?? $line);
            if ($line !== '' && $line !== '-') {
                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }

    private static function metaContent(string $html, string $attribute, string $value): string
    {
        preg_match_all('#<meta\b[^>]*>#i', $html, $tags);
        foreach ($tags[0] as $tag) {
            if (preg_match('#\b'.$attribute.'\s*=\s*"'.preg_quote($value, '#').'"#i', $tag) === 1
                && preg_match('#\bcontent\s*=\s*"([^"]*)"#i', $tag, $m) === 1) {
                return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
        }

        return '';
    }

    private static function inlineText(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $text) ?? $text);
    }

    private static function absoluteUrl(string $link): string
    {
        if (preg_match('#^https?://#i', $link) === 1) {
            return $link;
        }

        return SignProjectB2bClient::BASE.'/'.ltrim($link, '/');
    }

    private static function withoutQuery(string $url): string
    {
        return explode('#', explode('?', $url, 2)[0], 2)[0];
    }
}

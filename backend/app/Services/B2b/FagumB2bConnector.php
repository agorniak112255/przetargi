<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bAccount;
use App\Models\ProductDocument;
use App\Models\ProductIdentifier;
use RuntimeException;

/**
 * b2b.fagum.pl — platforma Comarch B2B producenta obuwia Fagum-Stomil (także wyroby ŁUKPOL, marka Techwork).
 * Sprawdzone na zalogowanym koncie 01.10.2026 (FagumB2bClient opisuje API i logowanie).
 *
 * Lista (articleListXl, cała oferta konta: 285 modeli) podaje jeden towar na model — „towar wiodący” jednego
 * rozmiaru. Rozmiary to osobne towary ERP z własnym id, kodem, EAN-em i ceną (getArticleVariantsDetailsXl →
 * expandedValues). Część modeli nie ma wariantów (asortyment w kartonie „(22222+2) 12p”, wyściółki) — wtedy karta =
 * sam towar z listy.
 *
 * Karta = model ze wszystkimi rozmiarami z ceną konta (B2bGroupsSizes, B2bSizePriceSource); pozycja (remote_id) =
 * id towaru w sklepie (stałe id ERP; kody towarów Fagum bywają przenumerowane, „L-…” i „FL-…”), sku pozycji = kod
 * towaru (z rozmiarem). SKU karty = symbol modelu („56201-GARDEN”), a gdy ten sam symbol mają dwa modele z listy
 * (12 par „L-…”/„FL-…” 01.10.2026) — kod towaru wiodącego, dosłownie.
 *
 * Ceny (articleFromListXl): sklep pokazuje cenę za karton (jednostka pomocnicza „krt”, przelicznik numerator/
 * denominator par), a unitNetPrice to cena konta za parę (jednostka podstawowa) — tę bierzemy. Cena bazowa
 * (baseNetPrice) jest za tę samą jednostkę co netPrice, więc przeliczamy ją tym samym stosunkiem
 * unitNetPrice/netPrice. Towar z zablokowaną zmianą jednostki (unitLockChange) sprzedawany jest tylko w kartonach —
 * warunek zamawiania: minimum i krok = zawartość kartonu.
 *
 * Co skąd: opis = opis towaru wiodącego (articleBasicDetails.description, HTML z <br/>), dosłownie; atrybuty
 * (wierzch, spód, normy, właściwości…) — do tabelki sklepu (B2bShopFieldSource), normy z wiersza „Spełnia normy”
 * (B2bShopFieldNormSource — tylko oznaczenia EN…); producent z danych towaru (nazwa spółki → „Fagum-Stomil” /
 * „Łukpol”), marka (Techwork) do tabelki; pliki i zdjęcia z atrybutów towaru wiodącego.
 */
final class FagumB2bConnector implements B2bConnector, B2bDocumentSource, B2bGroupsSizes, B2bImageGallery, B2bListProgressAware, B2bManufacturerSite, B2bRunSummaryAware, B2bShopFieldNormSource, B2bShopFieldSource, B2bSizePriceSource
{
    public const BRAND = 'Fagum-Stomil';

    public const LUKPOL = 'Łukpol';

    private const SECTION = 'Informacje ze sklepu Fagum';

    private const ATTRIBUTES_SECTION = 'Atrybuty';

    /** Wiersz atrybutów z normami (ISO 20345: 2022 S2 FO SR HRO, PN-EN ISO 20347…). */
    private const NORM_ATTRIBUTE = 'Spełnia normy';

    /** Atrybuty towaru wiodącego, które mówią o jednym rozmiarze (rozmiar, jego dostępność), nie o modelu. */
    private const SIZE_ATTRIBUTES = ['rozmiar', 'dostępność'];

    /** Grupy drzewa, które nie są działem oferty (wyróżnione wyroby na stronie głównej). */
    private const PROMO_GROUPS = ['polecane'];

    /** Status towaru jak na stronie (tłumaczenia articleState*: Dostępny, Na zamówienie, Zapowiedź, Niedostępny). */
    private const STATUSES = [
        'Available' => 'Dostępny',
        'AvailableOnDemand' => 'Na zamówienie',
        'Preview' => 'Zapowiedź',
        'Unavailable' => 'Niedostępny',
    ];

    /** articleDetailsType towaru z rozmiarami (drugi rodzaj: „NotContainVariants”). */
    private const WITH_VARIANTS = 'ContainsVariants';

    /**
     * Tyle modeli z odczytanymi cenami rozmiarów, a żaden z ceną konta, zanim pojawi się pierwsza cena = sesja bez cen
     * (albo zmiana API) — przebieg przerwany. Modele pominięte z innego powodu się nie liczą.
     */
    private const MAX_FIRST_WITHOUT_PRICE = 20;

    private const LIST_BUDGET_SECONDS = 20 * 60;

    private const INCONSISTENT = 7301;

    /** Komunikat postępu co tyle modeli przy odczycie danych ogólnych przed pierwszym zapisem. */
    private const PROGRESS_EVERY = 50;

    private const DOCUMENT_ORDER = [
        ProductDocument::KIND_DATASHEET => 0,
        ProductDocument::KIND_CERTIFICATE => 1,
        ProductDocument::KIND_MANUAL => 2,
        ProductDocument::KIND_OTHER => 3,
    ];

    private int $total = 0;

    private int $cards = 0;

    private int $multiPrice = 0;

    private int $withPrice = 0;

    /** @var list<string> */
    private array $summary = [];

    /** @var list<string> */
    private array $withoutPrice = [];

    /** @var list<string> */
    private array $sizesWithoutPrice = [];

    /** @var list<string> */
    private array $withoutBase = [];

    /** @var list<string> */
    private array $withoutDescription = [];

    /** @var list<string> */
    private array $codeAsSku = [];

    /** @var array<string, int> producent ze sklepu dosłownie → liczba modeli spoza znanych spółek */
    private array $otherManufacturers = [];

    /** @var (callable(string): void)|null */
    private $listProgress = null;

    public function __construct(private readonly FagumB2bClient $client) {}

    public static function key(): string
    {
        return 'fagum';
    }

    public static function label(): string
    {
        return self::BRAND;
    }

    public static function host(): string
    {
        return FagumB2bClient::HOST;
    }

    public static function ownBrand(): string
    {
        return self::BRAND;
    }

    public static function normShopFieldNames(): array
    {
        return [self::NORM_ATTRIBUTE];
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new self(new FagumB2bClient(
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
        $this->sizesWithoutPrice = [];
        $this->withoutBase = [];
        $this->withoutDescription = [];
        $this->codeAsSku = [];
        $this->otherManufacturers = [];
        $this->cards = 0;
        $this->multiPrice = 0;
        $this->withPrice = 0;

        if (! $this->client->isLoggedIn()) {
            $this->client->login();
        }

        $rows = $this->listRows();
        $this->total = count($rows);
        $this->summary[] = 'Lista Fagum: '.count($rows).' modeli';
        $categories = $this->categories();
        $infos = $this->generalInfos($rows);
        $symbolCounts = array_count_values(array_filter(array_map(
            static fn (?array $info): string => $info !== null ? self::clean((string) ($info['article']['symbol'] ?? '')) : '',
            $infos,
        )));

        foreach ($rows as $id => $row) {
            $info = $infos[$id];
            $product = $info === null
                ? $this->skipped((string) $id, $row['name'], 'dane towaru nieodczytane', [$id])
                : $this->productFor($row, $info, $categories[$id] ?? [], $symbolCounts);
            if ($this->withPrice === 0 && count($this->withoutPrice) >= self::MAX_FIRST_WITHOUT_PRICE) {
                throw new B2bFatalException(count($this->withoutPrice).' pierwszych modeli '.FagumB2bClient::HOST.' bez żadnej ceny konta — sesja konta nie pokazuje cen albo sklep zmienił API; przebieg przerwany');
            }
            if ($product !== null) {
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
            'Karty: %d (%d z rozmiarami w różnych cenach — cena karty = najniższa cena rozmiaru, ceny rozmiarów w tabeli rozmiarów karty)',
            $this->cards,
            $this->multiPrice,
        );
        if ($this->codeAsSku !== []) {
            $lines[] = 'Ten sam symbol w kilku modelach — SKU karty = kod towaru wiodącego: '.self::listing($this->codeAsSku);
        }
        if ($this->withoutPrice !== []) {
            $lines[] = 'Bez ceny konta (pominięte): '.self::listing($this->withoutPrice);
        }
        if ($this->sizesWithoutPrice !== []) {
            $lines[] = 'Modele z rozmiarami bez ceny konta (te rozmiary poza kartami): '.self::listing($this->sizesWithoutPrice);
        }
        if ($this->withoutBase !== []) {
            $lines[] = 'Bez ceny bazowej wyższej od ceny konta: '.self::listing($this->withoutBase);
        }
        if ($this->withoutDescription !== []) {
            $lines[] = 'Bez opisu w sklepie: '.self::listing($this->withoutDescription);
        }
        foreach ($this->otherManufacturers as $name => $count) {
            $lines[] = 'Producent spoza Fagum-Stomil i Łukpol (zapisany dosłownie): '.$name.' — '.$count.' modeli';
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
        $add = static function (string $section, string $name, string $value) use (&$fields): void {
            if ($name !== '' && $value !== '') {
                $fields[] = new B2bRemoteShopField($section, $name, $value);
            }
        };
        $add(self::SECTION, 'Producent', (string) $raw['manufacturer_name']);
        $add(self::SECTION, 'Marka', (string) $raw['brand']);
        $add(self::SECTION, 'Symbol', (string) $raw['symbol']);
        $add(self::SECTION, 'Cena za', implode(', ', $raw['units']));
        $add(self::SECTION, 'Opakowanie', implode('; ', $raw['packs']));
        foreach ($raw['attributes'] as $attribute) {
            $add(self::ATTRIBUTES_SECTION, $attribute['name'], $attribute['value']);
        }

        return $fields;
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
        $file = $this->client->fileBytes($document->sourceUrl);
        if (str_starts_with($file['bytes'], '%PDF-')) {
            $file['mime'] = 'application/pdf';
        }

        return $file;
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
     * Cena konta jednego towaru za jednostkę podstawową (para): null = towar poza cennikiem konta albo bez ceny.
     *
     * @param  array<string, mixed>  $json  odpowiedź articleFromListXl
     * @return array{net: float, base: float|null, currency: string, unit: string, pack: string, pack_qty: float|null, locked: bool}|null
     */
    public static function parsePrice(array $json): ?array
    {
        if (($json['itemExistsInCurrentPriceList'] ?? null) !== true) {
            return null;
        }
        $price = is_array($json['price'] ?? null) ? $json['price'] : [];
        $unit = is_array($json['unit'] ?? null) ? $json['unit'] : [];
        $shown = self::number($price['netPrice'] ?? null);
        $net = ($price['unitNetPrice']['representsExistingValue'] ?? false) === true ? self::number($price['unitNetPrice']['value'] ?? null) : null;
        if ($net === null || $net <= 0 || $shown === null || $shown <= 0) {
            return null;
        }
        // cena bazowa jest za jednostkę ceny pokazanej (karton) — ten sam przelicznik co cena konta za parę
        $baseShown = self::number($price['baseNetPrice'] ?? null);
        $base = $baseShown !== null ? round($baseShown * $net / $shown, 2) : null;
        $net = round($net, 2);

        $basic = self::clean((string) ($unit['basicUnit'] ?? ''));
        $auxiliary = ($unit['auxiliaryUnit']['representsExistingValue'] ?? false) === true ? self::clean((string) ($unit['auxiliaryUnit']['unit'] ?? '')) : '';
        $numerator = self::number($unit['numerator']['value'] ?? null);
        $denominator = self::number($unit['denominator']['value'] ?? null);
        $packQty = $auxiliary !== '' && $numerator !== null && $numerator > 0 && $denominator !== null && $denominator > 0
            ? $numerator / $denominator
            : null;
        $currency = mb_strtoupper(self::clean((string) ($price['currency'] ?? '')));

        return [
            'net' => $net,
            'base' => $base !== null && $base > $net ? $base : null,
            'currency' => $currency !== '' ? $currency : 'PLN',
            'unit' => $basic,
            'pack' => $packQty !== null ? $auxiliary.' = '.B2bOrderQuantity::format($packQty).($basic !== '' ? ' '.$basic : '') : '',
            'pack_qty' => $packQty,
            'locked' => ($unit['unitLockChange'] ?? false) === true && $packQty !== null,
        ];
    }

    /**
     * Opis towaru z HTML-a sklepu (akapity rozdzielone <br/>): bez znaczników, z rozwiniętymi encjami, odstępy w linii
     * zwinięte, puste linie odrzucone — treść dosłownie.
     */
    public static function descriptionText(string $html): string
    {
        $text = (string) preg_replace('/<\s*br\s*\/?\s*>|<\/\s*(?:p|div|li)\s*>/iu', "\n", $html);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines = array_values(array_filter(
            array_map(self::clean(...), explode("\n", $text)),
            static fn (string $line): bool => $line !== '',
        ));

        return mb_substr(implode("\n", $lines), 0, 10000);
    }

    /**
     * Producent karty z nazwy spółki w danych towaru: Fagum-Stomil (jak w katalogu z cenników Ardonu i Raw-Polu),
     * Łukpol; inna nazwa — dosłownie; brak — Fagum-Stomil (dostawca).
     */
    public static function manufacturerName(string $company): string
    {
        $company = self::clean($company);

        return match (true) {
            $company === '', preg_match('/FAGUM\s*-?\s*STOMIL/iu', $company) === 1 => self::BRAND,
            preg_match('/\bŁUKPOL\b/iu', $company) === 1 => self::LUKPOL,
            default => $company,
        };
    }

    /**
     * Rodzaj pliku z nazwy: deklaracja zgodności i certyfikat, instrukcja użytkowania; pozostałe pliki Fagum to karty
     * produktu („909P-2F.pdf”, „56201, 56203, 56213 GARDEN.pdf”, „1152 PL skóra DWT.pdf”).
     */
    public static function documentKind(string $name): string
    {
        return match (true) {
            preg_match('/deklarac|declaration|zgodno|cert/iu', $name) === 1 => ProductDocument::KIND_CERTIFICATE,
            preg_match('/instrukc|instruction|manual/iu', $name) === 1 => ProductDocument::KIND_MANUAL,
            default => ProductDocument::KIND_DATASHEET,
        };
    }

    /**
     * Cała lista; niespójna (towar na dwóch stronach, inna liczba stron) — jedno ponowne pobranie od początku.
     *
     * @return array<int, array{id: int, name: string, code: string, status: string}>
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

            throw new RuntimeException('Lista towarów '.FagumB2bClient::HOST.' niespójna także po ponownym pobraniu: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array<int, array{id: int, name: string, code: string, status: string}>
     */
    private function scanList(): array
    {
        $started = microtime(true);
        $rows = [];
        $pages = 1;
        for ($page = 1; $page <= $pages; $page++) {
            if (microtime(true) - $started > self::LIST_BUDGET_SECONDS) {
                throw new RuntimeException('Pobieranie listy '.FagumB2bClient::HOST.' trwa ponad '.(self::LIST_BUDGET_SECONDS / 60).' min — przerwane bez zapisu');
            }
            $json = $this->client->listPage($page);
            $pageCount = is_int($json['paging']['totalPages'] ?? null) ? $json['paging']['totalPages'] : -1;
            if ($page === 1) {
                $pages = $pageCount;
                if ($pages <= 0 || $json['articleList'] === []) {
                    throw new RuntimeException('Lista towarów '.FagumB2bClient::HOST.' pusta albo bez liczby stron (totalPages '.$pageCount.') — zmiana witryny?');
                }
                $this->progress('Lista towarów Fagum: '.$pages.' stron');
            } elseif ($pageCount !== $pages) {
                throw new RuntimeException('liczba stron zmieniła się z '.$pages.' na '.$pageCount.' (strona '.$page.')', self::INCONSISTENT);
            }
            foreach ($json['articleList'] as $item) {
                $article = is_array($item) && is_array($item['article'] ?? null) ? $item['article'] : [];
                $id = is_int($article['id'] ?? null) ? $article['id'] : 0;
                if ($id <= 0) {
                    throw new RuntimeException('Strona '.$page.' listy '.FagumB2bClient::HOST.': pozycja bez id towaru — zmiana witryny?');
                }
                if (isset($rows[$id])) {
                    throw new RuntimeException('towar '.$id.' na dwóch stronach', self::INCONSISTENT);
                }
                $rows[$id] = [
                    'id' => $id,
                    'name' => self::clean((string) ($article['name'] ?? '')),
                    'code' => self::clean((string) ($article['code']['value'] ?? '')),
                    'status' => self::clean((string) ($item['status'] ?? '')),
                ];
            }
        }

        return $rows;
    }

    /**
     * Działy towarów z drzewa grup: id towaru → nazwy grup (bez grup wyróżnień). Błąd — bez kategorii, bo kategoria
     * nie jest powodem, by pominąć towar.
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
                if ($groupId <= 0 || $name === '' || in_array(mb_strtolower($name), self::PROMO_GROUPS, true)) {
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
     * Dane ogólne wszystkich towarów z listy przed pierwszym zapisem — symbol karty zależy od tego, czy inny model
     * ma ten sam symbol. Towar nieodczytany = null (pominięty z powodem).
     *
     * @param  array<int, array{id: int, name: string, code: string, status: string}>  $rows
     * @return array<int, array<string, mixed>|null>
     */
    private function generalInfos(array $rows): array
    {
        $out = [];
        $done = 0;
        foreach ($rows as $id => $row) {
            try {
                $out[$id] = $this->client->generalInfo($id);
            } catch (B2bFatalException $e) {
                throw $e;
            } catch (RuntimeException) {
                $out[$id] = null;
            }
            $done++;
            if ($done % self::PROGRESS_EVERY === 0) {
                $this->progress('Dane towarów Fagum: '.$done.'/'.count($rows));
            }
        }

        return $out;
    }

    /**
     * @param  array{id: int, name: string, code: string, status: string}  $row
     * @param  array<string, mixed>  $info
     * @param  list<string>  $categories
     * @param  array<string, int>  $symbolCounts
     */
    private function productFor(array $row, array $info, array $categories, array $symbolCounts): ?B2bRemoteProduct
    {
        $id = $row['id'];
        $name = self::clean((string) ($info['article']['name'] ?? '')) ?: $row['name'];
        $listCode = self::clean((string) ($info['article']['code']['value'] ?? '')) ?: $row['code'];
        $symbol = self::clean((string) ($info['article']['symbol'] ?? ''));

        try {
            $sizes = $this->sizes($row);
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return $this->skipped($listCode, $name, 'warianty: '.$e->getMessage(), [$id]);
        }
        $positions = array_column($sizes, 'id');

        $items = [];
        try {
            foreach ($sizes as $size) {
                $sizeInfo = $size['id'] === $id ? $info : $this->client->generalInfo($size['id']);
                $price = self::parsePrice($this->client->price($size['id']));
                if ($price === null) {
                    continue;
                }
                $ean = self::clean((string) ($sizeInfo['articleBasicDetails']['ean'] ?? ''));
                $items[] = [
                    'id' => (string) $size['id'],
                    'code' => self::clean((string) ($sizeInfo['article']['code']['value'] ?? '')),
                    'label' => $size['label'],
                    'ean' => preg_match('/^\d{8,14}$/', $ean) === 1 ? $ean : '',
                    'availability' => self::STATUSES[$size['status']] ?? '',
                    'price' => $price,
                ];
            }
            $attributes = $items !== [] ? $this->client->attributes($id) : [];
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return $this->skipped($listCode, $name, 'rozmiary albo ceny: '.$e->getMessage(), $positions);
        }

        if ($items === []) {
            $this->withoutPrice[] = $listCode;

            return null;
        }
        $this->withPrice++;
        if (count($items) < count($sizes)) {
            $this->sizesWithoutPrice[] = $listCode;
        }
        $currencies = array_values(array_unique(array_map(static fn (array $item): string => $item['price']['currency'], $items)));
        if (count($currencies) !== 1) {
            return $this->skipped($listCode, $name, 'rozmiary bez jednej waluty ceny ('.implode(', ', $currencies).')', array_column($items, 'id'));
        }

        return $this->cardFor($info, $name, $listCode, $symbol, $symbolCounts, $categories, $items, $attributes);
    }

    /**
     * Rozmiary modelu: towary z expandedValues (id, rozmiar dosłownie, status) w kolejności sklepu; towar bez wariantów
     * (articleDetailsType inny niż „ContainsVariants”, jak sprawdza to strona) — sam towar z listy. Warianty w kilku
     * wymiarach (headerVariants) nie są rozmiarami jednego modelu — błąd.
     *
     * @param  array{id: int, name: string, code: string, status: string}  $row
     * @return non-empty-list<array{id: int, label: string, status: string}>
     */
    private function sizes(array $row): array
    {
        $single = [['id' => $row['id'], 'label' => '', 'status' => $row['status']]];
        if ($this->client->detailsType($row['id']) !== self::WITH_VARIANTS) {
            return $single;
        }
        $json = $this->client->variants($row['id']);
        if (is_array($json['headerVariants'] ?? null) && $json['headerVariants'] !== []) {
            throw new RuntimeException('warianty w kilku wymiarach (np. kolor i rozmiar) — łącznik ich nie obsługuje');
        }
        $values = $json['expandedVariant']['expandedValues'] ?? null;
        if (! is_array($values) || $values === []) {
            return $single;
        }
        $out = [];
        foreach ($values as $value) {
            $sizeId = is_array($value) && is_int($value['articleId'] ?? null) ? $value['articleId'] : 0;
            if ($sizeId <= 0 || isset($out[$sizeId])) {
                continue;
            }
            $out[$sizeId] = [
                'id' => $sizeId,
                'label' => self::clean((string) ($value['value']['translatedName'] ?? '')),
                'status' => self::clean((string) ($value['articleStatus'] ?? '')),
            ];
        }
        if (! isset($out[$row['id']])) {
            throw new RuntimeException('towar z listy ('.$row['id'].') nie jest wśród swoich wariantów');
        }

        return array_values($out);
    }

    /**
     * @param  array<string, mixed>  $info
     * @param  array<string, int>  $symbolCounts
     * @param  list<string>  $categories
     * @param  non-empty-list<array{id: string, code: string, label: string, ean: string, availability: string, price: array{net: float, base: float|null, currency: string, unit: string, pack: string, pack_qty: float|null, locked: bool}}>  $items
     * @param  array<string, mixed>  $attributes
     */
    private function cardFor(array $info, string $name, string $listCode, string $symbol, array $symbolCounts, array $categories, array $items, array $attributes): B2bRemoteProduct
    {
        $sku = $symbol;
        if ($symbol === '' || ($symbolCounts[$symbol] ?? 0) > 1) {
            $sku = $listCode;
            if ($symbol !== '') {
                $this->codeAsSku[] = $symbol.' → '.$listCode;
            }
        }
        $details = $info['articleBasicDetails'];
        $company = self::clean((string) ($details['manufacturer']['name'] ?? ''));
        $manufacturer = self::manufacturerName($company);
        if ($company !== '' && ! in_array($manufacturer, [self::BRAND, self::LUKPOL], true)) {
            $this->otherManufacturers[$company] = ($this->otherManufacturers[$company] ?? 0) + 1;
        }
        $description = self::descriptionText((string) ($details['description'] ?? ''));
        if ($description === '') {
            $this->withoutDescription[] = $sku;
        }

        $prices = [];
        foreach ($items as $item) {
            $p = $item['price'];
            $prices[$item['id']] = new B2bRemotePrice(
                net: $p['net'],
                base: $p['base'],
                discountPercent: $p['base'] !== null ? round((1 - $p['net'] / $p['base']) * 100, 2) : 0.0,
                currency: $p['currency'],
            );
        }
        $nets = array_unique(array_map(static fn (B2bRemotePrice $p): string => sprintf('%.2F', $p->net), $prices));
        $this->multiPrice += count($nets) > 1 ? 1 : 0;
        if (array_filter($prices, static fn (B2bRemotePrice $p): bool => $p->base === null) !== []) {
            $this->withoutBase[] = $sku;
        }
        $this->cards++;

        $cheapest = self::cheapest(array_values($prices));
        $cheapest = new B2bRemotePrice(
            net: $cheapest->net,
            base: $cheapest->base,
            discountPercent: $cheapest->discountPercent,
            currency: $cheapest->currency,
            order: self::orderQuantity(array_column($items, 'price')),
        );
        $labelled = array_values(array_filter($items, static fn (array $item): bool => $item['label'] !== ''));

        return new B2bRemoteProduct(
            remoteId: $items[0]['id'],
            sku: $sku,
            name: $name,
            category: $categories !== [] ? implode(', ', $categories) : null,
            sourceUrl: FagumB2bClient::BASE.'/itemdetails/'.$info['article']['id'],
            raw: [
                'status' => 'ok',
                'price' => $cheapest,
                'manufacturer' => $manufacturer,
                'manufacturer_name' => $company,
                'brand' => self::clean((string) ($details['brand']['name'] ?? '')),
                'symbol' => $symbol,
                'description' => $description,
                'attributes' => self::attributeRows($attributes['articleAttributes'] ?? null),
                'documents' => self::documentLinks($attributes['articleAttachments'] ?? null),
                'images' => self::imageLinks($attributes['articleImages'] ?? null),
                'units' => array_values(array_unique(array_filter(array_map(static fn (array $item): string => $item['price']['unit'], $items)))),
                'packs' => array_values(array_unique(array_filter(array_map(static fn (array $item): string => $item['price']['pack'], $items)))),
            ],
            availability: self::availabilityText($items),
            variantSummary: $labelled !== []
                ? 'Rozmiary: '.implode('; ', array_map(static fn (array $item): string => $item['label'].($item['code'] !== '' ? ' ('.$item['code'].')' : ''), $labelled))
                : null,
            // jeden rozmiar — pozycja pojedyncza (cena z price()); kilka — każdy z własną ceną
            members: count($items) > 1
                ? array_map(static fn (array $item): array => array_filter([
                    'remote_id' => $item['id'],
                    'sku' => $item['code'] !== '' ? $item['code'] : $item['id'],
                    'name' => trim($name.' '.$item['label']),
                    'availability' => $item['availability'],
                    'size' => $item['label'] !== '' ? $item['label'] : $item['id'],
                    'price' => $prices[$item['id']],
                ], static fn (mixed $value): bool => $value !== ''), $items)
                : [],
            identifiers: self::identifiers($symbol, $items),
        );
    }

    /**
     * Symbol modelu i dla każdego rozmiaru: kod towaru i EAN (jednostki podstawowej — para), dosłownie ze sklepu.
     *
     * @param  list<array{id: string, code: string, label: string, ean: string}>  $items
     * @return list<B2bRemoteIdentifier>
     */
    private static function identifiers(string $symbol, array $items): array
    {
        $out = [];
        if ($symbol !== '') {
            $out[] = new B2bRemoteIdentifier(type: ProductIdentifier::TYPE_MODEL_CODE, value: $symbol, field: 'Symbol');
        }
        foreach ($items as $item) {
            $label = $item['label'] !== '' ? $item['label'] : null;
            if ($item['code'] !== '') {
                $out[] = new B2bRemoteIdentifier(type: ProductIdentifier::TYPE_MANUFACTURER_CODE, value: $item['code'], remoteId: $item['id'], label: $label, field: 'Kod');
            }
            if ($item['ean'] !== '') {
                $out[] = new B2bRemoteIdentifier(type: ProductIdentifier::TYPE_EAN, value: $item['ean'], remoteId: $item['id'], label: $label, field: 'EAN');
            }
        }

        return $out;
    }

    /**
     * Warunek zamawiania: towar sprzedawany tylko w kartonach (unitLockChange) — minimum i krok = zawartość kartonu
     * w jednostce ceny; rozmiary karty z różnym warunkiem — „zależy od rozmiaru”; bez blokady — brak ograniczenia.
     *
     * @param  list<array{unit: string, pack_qty: float|null, locked: bool}>  $prices
     */
    private static function orderQuantity(array $prices): B2bOrderQuantity
    {
        $conditions = [];
        foreach ($prices as $price) {
            $qty = $price['locked'] ? $price['pack_qty'] : null;
            $conditions[($qty === null ? '-' : (string) $qty).'|'.$price['unit']] = [$qty, $price['unit']];
        }
        if (count($conditions) > 1) {
            return new B2bOrderQuantity(null, null, varies: true);
        }
        [$qty, $unit] = array_values($conditions)[0];

        return new B2bOrderQuantity($qty, $qty, $unit !== '' ? $unit : null);
    }

    /**
     * Atrybuty modelu w kolejności sklepu; powtórzona nazwa („Właściwości użytkowe”) — jeden wiersz z wartościami po
     * średniku. Puste wartości i atrybuty jednego rozmiaru (rozmiar, dostępność towaru wiodącego) odpadają.
     *
     * @return list<array{name: string, value: string}>
     */
    private static function attributeRows(mixed $attributes): array
    {
        $rows = [];
        foreach (is_array($attributes) ? $attributes : [] as $attribute) {
            if (! is_array($attribute)) {
                continue;
            }
            $name = self::clean((string) ($attribute['name'] ?? ''));
            $value = self::clean(html_entity_decode(strip_tags((string) ($attribute['value'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($name === '' || $value === '' || in_array(mb_strtolower($name), self::SIZE_ATTRIBUTES, true)) {
                continue;
            }
            if (! in_array($value, $rows[$name] ?? [], true)) {
                $rows[$name][] = $value;
            }
        }

        return array_map(
            static fn (string $name, array $values): array => ['name' => $name, 'value' => implode('; ', $values)],
            array_keys($rows),
            array_values($rows),
        );
    }

    /**
     * Załączniki towaru (type 0 = plik w sklepie, pobierany przez /filehandler.ashx z hashem pliku); odnośniki
     * zewnętrzne (type 1) pominięte. Karty produktu pierwsze.
     *
     * @return list<array{title: string, url: string, kind: string}>
     */
    private static function documentLinks(mixed $attachments): array
    {
        $files = [];
        foreach (is_array($attachments) ? $attachments : [] as $attachment) {
            if (! is_array($attachment) || ($attachment['type'] ?? null) !== 0 || ! is_int($attachment['id'] ?? null)) {
                continue;
            }
            $title = self::clean((string) ($attachment['fullName'] ?? ''));
            $hash = trim((string) ($attachment['hash'] ?? ''));
            if ($title === '' || $hash === '') {
                continue;
            }
            $url = FagumB2bClient::BASE.'/filehandler.ashx?id='.$attachment['id'].'&fileName='.rawurlencode($title).'&customerData='.rawurlencode($hash);
            $files[$url] = ['title' => $title, 'url' => $url, 'kind' => self::documentKind($title)];
        }
        $files = array_values($files);
        usort($files, static fn (array $a, array $b): int => self::DOCUMENT_ORDER[$a['kind']] <=> self::DOCUMENT_ORDER[$b['kind']]);

        return $files;
    }

    /**
     * Zdjęcia towaru w kolejności sklepu (imageType 1 = obraz w sklepie; największy rozmiar, jaki wydaje — 800 px).
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
            $url = FagumB2bClient::BASE.'/imagehandler.ashx?id='.$image['imageId'].'&width=2000&height=2000';
            if (! in_array($url, $out, true)) {
                $out[] = $url;
            }
        }

        return $out;
    }

    /**
     * Najtańszy rozmiar karty, ta sama reguła co w B2bCatalogSync::sizePricing (remis — B2bCatalogSync::winsSizePriceTie).
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
     * Jedna dostępność dla wszystkich rozmiarów — dosłownie; różne — „Dostępny: 40, 41; Na zamówienie: 47”.
     *
     * @param  list<array{label: string, id: string, availability: string}>  $items
     */
    private static function availabilityText(array $items): ?string
    {
        $statuses = [];
        foreach ($items as $item) {
            if ($item['availability'] !== '') {
                $statuses[$item['availability']][] = $item['label'] !== '' ? $item['label'] : $item['id'];
            }
        }
        if ($statuses === []) {
            return null;
        }
        if (count($statuses) === 1) {
            return (string) array_key_first($statuses);
        }
        $parts = [];
        foreach ($statuses as $status => $sizes) {
            $parts[] = $status.': '.implode(', ', $sizes);
        }

        return mb_substr(implode('; ', $parts), 0, 1000);
    }

    /**
     * Model, którego nie da się zapisać w tym przebiegu. Znane pozycje (id rozmiarów) idą jako pozycje bez cen —
     * przebieg widzi je na liście i jako pominięte (poza sprzątaniem), jak u Ejendals.
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

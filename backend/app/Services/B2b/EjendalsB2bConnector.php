<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bAccount;
use App\Models\ProductDocument;
use App\Models\ProductIdentifier;
use RuntimeException;

/**
 * www.ejendals.com — witryna producenta Ejendals (rękawice TEGERA, obuwie JALAS, GRANINGE) ze sklepem dla firm.
 * Sprawdzone na stronie gościa i na koncie #20 30.09.2026 (EjendalsB2bClient opisuje API i logowanie).
 *
 * Lista: /api/v2/search/products rynku PL (609 wyrobów 30.09.2026), pozycja = wyrób (model, „8807”) z kodami
 * rozmiarów („8807-5” … „8807-11”). Rozmiary z EAN — publiczne /api/v2/products/get/pdp/{kod}/variants
 * (specyfikacja „Rozmiar (EU)”, „Numer artykułu”, „EAN-13”). Ceny konta są w samej zalogowanej liście: articles[] —
 * rozmiar z ceną konta i ceną podstawową za parę (baseUnit), walutą konta (konto #20: EUR) i opakowaniami, w których
 * koszyk go sprzedaje (articlePrices; stąd warunek zamawiania — orderQuantity). Strona wyrobu
 * (polska wersja) to aplikacja Vue: HTML bez treści, a wszystko, co pokazuje — opis, normy, cechy, specyfikacja,
 * pliki i zdjęcia w pełnej rozdzielczości — niesie JSON window.contentDataModel w skrypcie strony.
 *
 * Karta = wyrób ze wszystkimi rozmiarami z ceną konta (B2bGroupsSizes, B2bSizePriceSource); pozycja (remote_id) = kod
 * rozmiaru ze sklepu („8807-9”), SKU = kod wyrobu. Producent = Ejendals (tak prowadzi go katalog — marka JALAS/TEGERA
 * stoi w nazwie karty i w tabelce sklepu).
 *
 * Co skąd: opis = hasło (valueProposition) i opis producenta, dosłownie; cechy („Characteristics”, „Functions”,
 * „Features”), specyfikacja (po angielsku, jak na stronie), normy i oznaczenia — do tabelki sklepu
 * (B2bShopFieldSource), nie do opisu. Normy („EN 388:2016+A1:2018” z poziomem „4X43D”) także jako fakty producenta
 * (B2bNormFactSource), dosłownie; znaki CE/UKCA, EAC i „Odpowiednie do kontaktu z żywnością” normami nie są.
 */
final class EjendalsB2bConnector implements B2bConnector, B2bDocumentSource, B2bGroupsSizes, B2bImageGallery, B2bListProgressAware, B2bManufacturerSite, B2bNormFactSource, B2bRunSummaryAware, B2bShopFieldSource, B2bSizePriceSource
{
    public const BRAND = 'Ejendals';

    private const PAGE_SIZE = 100;

    /** Nieprzerwane pobieranie listy dłużej = błąd (przebieg bez postępu uznałby b2b:sync-due za przerwany). */
    private const LIST_BUDGET_SECONDS = 20 * 60;

    private const INCONSISTENT = 7301;

    /** Etykiety specyfikacji rozmiaru (/variants) — polska wersja API. */
    private const SPEC_SIZE = 'Rozmiar (EU)';

    private const SPEC_ARTICLE = 'Numer artykułu';

    private const SPEC_EAN = 'EAN-13';

    /** Rodzaj wyrobu z listy (typeOfProduct, SearchQueryModel na stronie) → początek nazwy karty. */
    private const TYPE_NAMES = [
        1 => 'Rękawice',
        2 => 'Obuwie',
        3 => 'Wkładki',
        4 => 'Skarpety',
        6 => 'Rękawy',
    ];

    /** Opakowania pozycji listy, w których koszyk sprzedaje rozmiar (VariantCanvas: minUnit, bundle, carton). */
    private const SHIPMENTS = ['minUnit', 'bundle', 'carton'];

    /** Dostępność rozmiaru jak w koszyku sklepu (tłumaczenia cart.in.stock / cart.out.of.stock). */
    private const IN_STOCK = 'W magazynie';

    private const OUT_OF_STOCK = 'Brak w magazynie';

    /** Kolejność rodzajów plików przy karcie — z pierwszej karty technicznej bierzemy tekst do indeksu. */
    private const DOCUMENT_ORDER = [
        ProductDocument::KIND_DATASHEET => 0,
        ProductDocument::KIND_CERTIFICATE => 1,
        ProductDocument::KIND_MANUAL => 2,
        ProductDocument::KIND_OTHER => 3,
    ];

    /**
     * specialComplianceType kafelka „Compliance”, który jest znakiem (1 = CE, 2 = UKCA; kategoria ŚOI w
     * protectionCategory), nie normą — sprawdzone na stronach 8807 i 1738 30.09.2026 (4 = EN z poziomami, 5 = ASTM).
     */
    private const MARK_COMPLIANCE_TYPES = [1, 2];

    /** Oznaczenie normy na kafelku (EN 388:2016+A1:2018, EN ISO 20345:2022, ASTM F2413-18, TP TC 019:2011, ANSI/ISEA 105). */
    private const NORM_LABEL = '/^(?:EN|ISO|IEC|ANSI|ASTM|TP\s*TC|CSA|AS\/NZS|DIN|GOST|GB|NFPA)\b/iu';

    /** Karta produktu z listy wersji językowych („Product sheet” + kod języka) — bierzemy polską, bez niej angielską. */
    private const SHEET_LANGUAGES = ['pl', 'en'];

    private int $total = 0;

    private int $cards = 0;

    private int $multiPrice = 0;

    /** @var list<string> */
    private array $summary = [];

    /** @var list<string> wyroby bez ceny konta (pominięte) */
    private array $withoutPrice = [];

    /** @var list<string> wyroby z częścią rozmiarów bez ceny konta (te rozmiary poza kartą) */
    private array $sizesWithoutPrice = [];

    /** @var list<string> */
    private array $withoutDescription = [];

    /** @var list<string> */
    private array $withoutBase = [];

    /** @var (callable(string): void)|null */
    private $listProgress = null;

    /**
     * @param  int  $pageSize  pozycji na stronę listy (testy stronicują drobniej)
     */
    public function __construct(
        private readonly EjendalsB2bClient $client,
        private readonly int $pageSize = self::PAGE_SIZE,
    ) {}

    public static function key(): string
    {
        return 'ejendals';
    }

    public static function label(): string
    {
        return self::BRAND;
    }

    public static function host(): string
    {
        return EjendalsB2bClient::HOST;
    }

    public static function ownBrand(): string
    {
        return self::BRAND;
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new self(new EjendalsB2bClient((string) $account->username, (string) $account->password, $delayMs));
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
        $this->withoutDescription = [];
        $this->withoutBase = [];
        $this->cards = 0;
        $this->multiPrice = 0;

        if (! $this->client->isLoggedIn()) {
            $this->client->login();
        }

        $rows = $this->listRows();
        $this->total = count($rows);
        $this->summary[] = 'Lista Ejendals (rynek PL): '.count($rows).' wyrobów';
        // lista gościa nie ma cen — żadna pozycja z ceną znaczy, że sesja konta nie zadziałała, a nie że nic nie kupimy
        if (array_filter($rows, static fn (array $row): bool => self::articlePrices($row) !== []) === []) {
            throw new B2bFatalException('Lista '.EjendalsB2bClient::HOST.' bez żadnej ceny konta — sesja konta nie działa albo sklep zmienił API; przebieg przerwany bez zapisu');
        }

        foreach ($rows as $row) {
            $product = $this->productFor($row);
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
            'Karty: %d (%d z rozmiarami w różnych cenach — jedna karta, cena karty = najniższa cena rozmiaru, ceny rozmiarów w tabeli rozmiarów karty)',
            $this->cards,
            $this->multiPrice,
        );
        if ($this->withoutPrice !== []) {
            $lines[] = 'Bez ceny konta (pominięte): '.self::listing($this->withoutPrice);
        }
        if ($this->sizesWithoutPrice !== []) {
            $lines[] = 'Wyroby z rozmiarami bez ceny konta (te rozmiary poza kartami): '.self::listing($this->sizesWithoutPrice);
        }
        if ($this->withoutBase !== []) {
            $lines[] = 'Bez ceny podstawowej (katalogowej) w sklepie: '.self::listing($this->withoutBase);
        }
        if ($this->withoutDescription !== []) {
            $lines[] = 'Bez opisu na polskiej stronie wyrobu: '.self::listing($this->withoutDescription);
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

        $add('Informacje ze sklepu Ejendals', 'Marka', (string) $raw['brand']);
        $add('Informacje ze sklepu Ejendals', 'Numer wyrobu', (string) $raw['code']);
        $add('Informacje ze sklepu Ejendals', 'Krótki opis', (string) $raw['short']);
        $add('Informacje ze sklepu Ejendals', 'Cena za', implode(', ', $raw['units']));
        $add('Informacje ze sklepu Ejendals', 'Opakowania', implode('; ', $raw['packs']));
        foreach ($raw['lists'] as $list) {
            $add($list['title'], $list['title'], implode('; ', $list['items']));
        }
        foreach ([...$raw['norms'], ...$raw['marks']] as $norm) {
            $norm['value'] === ''
                ? $add('Compliance', 'Oznaczenie', $norm['label'])
                : $add('Compliance', $norm['label'], $norm['value']);
        }
        foreach ($raw['specs'] as $spec) {
            $add('Specifications', $spec['name'], $spec['value']);
        }

        return $fields;
    }

    /**
     * Normy z nagłówkiem i poziomem ze strony wyrobu, dosłownie.
     *
     * @return list<B2bRemoteNormFact>
     */
    public function normFacts(B2bRemoteProduct $product): array
    {
        $facts = [];
        foreach ($product->raw['norms'] ?? [] as $norm) {
            $facts[] = new B2bRemoteNormFact($norm['label'], $norm['value'] === '' ? null : $norm['value']);
        }

        return $facts;
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
     * Rozmiary z /variants: kod rozmiaru → rozmiar i EAN dosłownie, w kolejności sklepu. Rozmiar bez kodu pominięty.
     *
     * @param  list<array<string, mixed>>  $variants
     * @return array<string, array{code: string, size: string, ean: string}>
     */
    public static function parseVariants(array $variants): array
    {
        $out = [];
        foreach ($variants as $variant) {
            $code = self::clean((string) ($variant['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $spec = [];
            foreach (is_array($variant['specification'] ?? null) ? $variant['specification'] : [] as $row) {
                if (is_array($row)) {
                    $spec[self::clean((string) ($row['label'] ?? ''))] = self::clean((string) ($row['value'] ?? ''));
                }
            }
            $size = $spec[self::SPEC_SIZE] ?? '';
            if ($size === '' && is_array($variant['sizes'] ?? null)) {
                $size = self::clean((string) ($variant['sizes']['eu'] ?? ''));
            }
            $out[$code] = [
                'code' => $spec[self::SPEC_ARTICLE] ?? $code,
                'size' => $size,
                'ean' => preg_match('/^\d{8,14}$/', $spec[self::SPEC_EAN] ?? '') === 1 ? $spec[self::SPEC_EAN] : '',
            ];
        }

        return $out;
    }

    /**
     * Ceny rozmiarów z pozycji zalogowanej listy: kod rozmiaru → cena konta (articles[].price.price), cena podstawowa
     * (price.basePrice — „Cena podstawowa” sklepu; bez niej model.basePrice wyrobu; tylko wyższa od ceny konta), waluta,
     * dostępność i opakowania, w kolejności articles. Sprawdzone na koncie #20 30.09.2026: cena jest za jednostkę
     * bazową (baseUnit „Pary”), a koszyk sprzedaje wyłącznie całe opakowania (minUnit 1 para, bundle 6 par,
     * carton 120 par — ilość w koszyku to liczba opakowań × quantity). Pozycja bez ceny > 0 — pominięta.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, array{net: float, base: float|null, currency: string, in_stock: bool|null, size: string, unit: string, packs: list<array{text: string, quantity: float}>}>
     */
    public static function articlePrices(array $row): array
    {
        $model = is_array($row['model'] ?? null) ? $row['model'] : [];
        $modelBase = self::number($model['basePrice'] ?? null);
        $modelCurrency = mb_strtoupper(self::clean((string) ($model['currency'] ?? '')));

        $out = [];
        foreach (is_array($row['articles'] ?? null) ? $row['articles'] : [] as $article) {
            if (! is_array($article)) {
                continue;
            }
            $code = self::clean((string) ($article['code'] ?? ''));
            $price = is_array($article['price'] ?? null) ? $article['price'] : [];
            $net = self::number($price['price'] ?? null);
            if ($code === '' || $net === null || $net <= 0 || isset($out[$code])) {
                continue;
            }
            $base = self::number($price['basePrice'] ?? null) ?? $modelBase;
            $currency = mb_strtoupper(self::clean((string) ($price['currency'] ?? '')));
            $packs = [];
            foreach (self::SHIPMENTS as $shipment) {
                $pack = is_array($article[$shipment] ?? null) ? $article[$shipment] : null;
                $quantity = $pack !== null ? self::number($pack['quantity'] ?? null) : null;
                if ($quantity !== null && $quantity > 0) {
                    $text = self::clean((string) ($pack['fullText'] ?? $pack['text'] ?? ''));
                    $packs[] = ['text' => $text, 'quantity' => $quantity];
                }
            }
            $out[$code] = [
                'net' => round($net, 2),
                'base' => $base !== null && round($base, 2) > round($net, 2) ? round($base, 2) : null,
                'currency' => $currency !== '' ? $currency : $modelCurrency,
                'in_stock' => is_bool($article['inStock'] ?? null) ? $article['inStock'] : null,
                'size' => self::clean((string) ($article['size'] ?? '')),
                // jednostka ceny: przy rozmiarze albo — w liście tylko tak — przy jego opakowaniach
                'unit' => self::unit($article),
                'packs' => $packs,
            ];
        }

        return $out;
    }

    /**
     * Jednostka bazowa rozmiaru („Pary”): baseUnit pozycji, a bez niej — wspólna baseUnit jej opakowań; różne = ''.
     *
     * @param  array<string, mixed>  $article
     */
    private static function unit(array $article): string
    {
        $own = self::clean((string) ($article['baseUnit']['value'] ?? ''));
        if ($own !== '') {
            return $own;
        }
        $units = [];
        foreach (self::SHIPMENTS as $shipment) {
            $unit = self::clean((string) ($article[$shipment]['baseUnit']['value'] ?? ''));
            if ($unit !== '') {
                $units[$unit] = true;
            }
        }

        return count($units) === 1 ? (string) array_key_first($units) : '';
    }

    /**
     * Warunek zamawiania: najmniejsze opakowanie rozmiaru jest minimum i krokiem (koszyk liczy całe opakowania —
     * ExtendedCartItemRequest: ilość = liczba opakowań × quantity), jednostka = jednostka ceny. Rozmiary karty z różnym
     * najmniejszym opakowaniem albo jednostką — „zależy od rozmiaru”; żaden rozmiar bez opakowania — null.
     *
     * @param  list<array{packs: list<array{text: string, quantity: float}>, unit: string}>  $prices
     */
    private static function orderQuantity(array $prices): ?B2bOrderQuantity
    {
        $conditions = [];
        foreach ($prices as $price) {
            $smallest = $price['packs'] !== [] ? min(array_column($price['packs'], 'quantity')) : null;
            $conditions[($smallest === null ? '-' : (string) $smallest).'|'.$price['unit']] = [$smallest, $price['unit']];
        }
        if (count($conditions) > 1) {
            return new B2bOrderQuantity(null, null, varies: true);
        }
        [$smallest, $unit] = array_values($conditions)[0] ?? [null, ''];
        if ($smallest === null) {
            return null;
        }

        return new B2bOrderQuantity($smallest, $smallest, $unit !== '' ? $unit : null);
    }

    /**
     * Model strony wyrobu (window.contentDataModel w polskiej wersji witryny — strona jest aplikacją Vue i ten JSON
     * niesie wszystko, co pokazuje); null, gdy strona go nie ma albo to nie wyrób (brak kodu i nazwy).
     *
     * @return array<string, mixed>|null
     */
    public static function parseProductPage(string $html): ?array
    {
        $model = self::pageModel($html);
        if ($model === null) {
            return null;
        }
        $name = self::plain($model['title'] ?? '');
        $code = self::plain($model['code'] ?? '');
        if ($name === '' || $code === '') {
            return null;
        }

        $characteristics = self::values($model['characteristics'] ?? null, 'value');
        $functions = self::values($model['functions'] ?? null, 'value');
        $features = self::values(array_map(
            static fn (array $feature): mixed => $feature['cvl'] ?? null,
            self::listOf($model['features'] ?? null),
        ), 'value');

        // hasło pod nazwą, opis producenta i jego listy, dosłownie — jak w polskiej karcie produktu PDF: „Właściwości”
        // = characteristics, „Cechy” = functions (sprawdzone na 879 30.09.2026) i piktogramy features, których lista
        // funkcji nie ma (obuwie: „Aluminowy podnosek”, „Wkładka antyprzebiciowa … (PTC)”)
        $paragraphs = array_values(array_filter(
            [
                self::plain($model['valueProposition'] ?? ''),
                self::plain($model['description'] ?? ''),
                self::bulletList('Właściwości', $characteristics),
                self::bulletList('Cechy', array_values(array_unique([...$functions, ...$features]))),
            ],
            static fn (string $text): bool => $text !== '',
        ));

        [$norms, $marks] = self::compliances(self::listOf($model['compliances'] ?? null));

        return [
            'name' => $name,
            'code' => $code,
            'description' => mb_substr(implode("\n\n", $paragraphs), 0, 10000),
            'lists' => array_values(array_filter([
                ['title' => 'Characteristics', 'items' => $characteristics],
                ['title' => 'Functions', 'items' => $functions],
                ['title' => 'Features', 'items' => $features],
            ], static fn (array $list): bool => $list['items'] !== [])),
            'norms' => $norms,
            'marks' => $marks,
            'specs' => [
                ...self::labelled($model['specification'] ?? null),
                ...self::labelled($model['generalInformation'] ?? null),
            ],
            'documents' => self::documentLinks(self::listOf($model['documents'] ?? null)),
            'images' => self::imageLinks(self::listOf($model['images'] ?? null)),
        ];
    }

    /**
     * „Cechy:” i pod nim „- Do ekranów dotykowych” w osobnych liniach (nagłówek i punkty jak w opisach JSP, Protekt);
     * pusta lista — ''.
     *
     * @param  list<string>  $items
     */
    private static function bulletList(string $title, array $items): string
    {
        return $items === [] ? '' : $title.":\n".implode("\n", array_map(static fn (string $item): string => '- '.$item, $items));
    }

    /**
     * JSON przypisany do window.contentDataModel w skrypcie strony; null = brak albo nieczytelny.
     *
     * @return array<string, mixed>|null
     */
    private static function pageModel(string $html): ?array
    {
        $marker = 'window.contentDataModel = ';
        $start = strpos($html, $marker);
        if ($start === false) {
            return null;
        }
        $start += strlen($marker);
        $end = self::jsonObjectEnd($html, $start);
        if ($end === null) {
            return null;
        }
        $model = json_decode(substr($html, $start, $end - $start + 1), true);

        return is_array($model) && ! array_is_list($model) ? $model : null;
    }

    /**
     * Pozycja klamry zamykającej obiekt JSON zaczynający się w $start (klamry i nawiasy w napisach pomijane); null = brak.
     */
    private static function jsonObjectEnd(string $text, int $start): ?int
    {
        if (($text[$start] ?? '') !== '{') {
            return null;
        }
        $depth = 0;
        $inString = false;
        $length = strlen($text);
        for ($i = $start; $i < $length; $i++) {
            $char = $text[$i];
            if ($inString) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === '"') {
                    $inString = false;
                }

                continue;
            }
            if ($char === '"') {
                $inString = true;
            } elseif ($char === '{' || $char === '[') {
                $depth++;
            } elseif ($char === '}' || $char === ']') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * Kafelki „Compliance”: normy (oznaczenie normy z poziomami z renderValues, dosłownie) i pozostałe oznaczenia —
     * znaki CE/UKCA (z kategorią ŚOI), EAC, „Odpowiednie do kontaktu z żywnością” — które normą nie są.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array{0: list<array{label: string, value: string}>, 1: list<array{label: string, value: string}>}
     */
    private static function compliances(array $items): array
    {
        $norms = [];
        $marks = [];
        foreach ($items as $item) {
            $label = self::plain($item['cvl']['value'] ?? '');
            if ($label === '') {
                continue;
            }
            $value = implode(' ', array_filter(
                array_map(self::plain(...), self::listOf($item['renderValues'] ?? null, strings: true)),
                static fn (string $v): bool => $v !== '',
            ));
            $special = (int) ($item['specialComplianceType'] ?? 0);
            if (! in_array($special, self::MARK_COMPLIANCE_TYPES, true) && preg_match(self::NORM_LABEL, $label) === 1) {
                $norms[] = ['label' => $label, 'value' => $value];

                continue;
            }
            $category = self::plain($item['protectionCategory']['value'] ?? '');
            $marks[] = ['label' => $label, 'value' => $value !== '' ? $value : $category];
        }

        return [$norms, $marks];
    }

    /**
     * Pary label/value (specyfikacja, informacje ogólne), dosłownie; odnośnik w wartości („<a …>Kolekcja</a>”) — sam tekst.
     *
     * @return list<array{name: string, value: string}>
     */
    private static function labelled(mixed $rows): array
    {
        $out = [];
        foreach (self::listOf($rows) as $row) {
            $name = self::plain($row['label'] ?? '');
            $value = self::plain($row['value'] ?? '');
            if ($name !== '' && $value !== '') {
                $out[] = ['name' => $name, 'value' => $value];
            }
        }

        return $out;
    }

    /**
     * Wartości pola $key z listy obiektów, dosłownie, bez powtórzeń.
     *
     * @return list<string>
     */
    private static function values(mixed $rows, string $key): array
    {
        $out = [];
        foreach (self::listOf($rows) as $row) {
            $value = self::plain($row[$key] ?? '');
            if ($value !== '' && ! in_array($value, $out, true)) {
                $out[] = $value;
            }
        }

        return $out;
    }

    /**
     * Pliki wyrobu: deklaracja zgodności UE, instrukcja, certyfikaty i karta produktu po polsku (bez niej — po
     * angielsku); pozostałe języki karty i deklaracja UKCA — pominięte. Nazwa = podpis ze strony (+ język karty).
     *
     * @param  list<array<string, mixed>>  $documents
     * @return list<array{title: string, url: string, kind: string}>
     */
    private static function documentLinks(array $documents): array
    {
        $files = [];
        $sheets = [];
        foreach ($documents as $document) {
            $url = EjendalsB2bClient::absoluteUrl(self::plain($document['url'] ?? ''));
            if ($url === null || ! str_ends_with(strtolower((string) parse_url($url, PHP_URL_PATH)), '.pdf') || isset($files[$url])) {
                continue;
            }
            $kind = self::documentKind(self::plain($document['type'] ?? ''));
            if ($kind === null) {
                continue;
            }
            $text = self::plain($document['text'] ?? '');
            $title = $text !== '' ? $text : self::plain($document['name'] ?? '');
            if ($kind === ProductDocument::KIND_DATASHEET) {
                foreach (self::listOf($document['languages'] ?? null, strings: true) as $language) {
                    $language = mb_strtolower(self::plain($language));
                    if (in_array($language, self::SHEET_LANGUAGES, true)) {
                        $sheets[$language] ??= ['title' => trim($title.' '.$language), 'url' => $url, 'kind' => $kind];
                    }
                }

                continue;
            }
            $files[$url] = ['title' => $title, 'url' => $url, 'kind' => $kind];
        }
        foreach (self::SHEET_LANGUAGES as $language) {
            if (isset($sheets[$language])) {
                $files[$sheets[$language]['url']] = $sheets[$language];
                break;
            }
        }
        $files = array_values($files);
        usort($files, static fn (array $a, array $b): int => self::DOCUMENT_ORDER[$a['kind']] <=> self::DOCUMENT_ORDER[$b['kind']]);

        return $files;
    }

    /** Rodzaj pliku z pola type modelu strony („ProductSheet”, „DoC-EU”…); null = plik pomijany (deklaracja UKCA). */
    private static function documentKind(string $type): ?string
    {
        $type = mb_strtolower($type);

        return match (true) {
            $type === 'doc-ukca' => null,
            $type === 'productsheet' => ProductDocument::KIND_DATASHEET,
            $type === 'doc-eu', $type === 'oekotex', str_contains($type, 'cert') => ProductDocument::KIND_CERTIFICATE,
            $type === 'userinstructions' => ProductDocument::KIND_MANUAL,
            default => ProductDocument::KIND_OTHER,
        };
    }

    /**
     * Zdjęcia w pełnej rozdzielczości (orginalUrl) z witryny, w kolejności strony: główne, ujęcia wyrobu, szczegóły,
     * zdjęcia w użyciu; obrót 360° (orbitvu, poza witryną) pominięty.
     *
     * @param  list<array<string, mixed>>  $images
     * @return list<string>
     */
    private static function imageLinks(array $images): array
    {
        $out = [];
        foreach ($images as $image) {
            $url = EjendalsB2bClient::absoluteUrl(self::plain($image['orginalUrl'] ?? ''));
            if ($url !== null && preg_match('/\.(webp|jpe?g|png)$/i', (string) parse_url($url, PHP_URL_PATH)) === 1 && ! in_array($url, $out, true)) {
                $out[] = $url;
            }
        }

        return $out;
    }

    /**
     * Lista z pola modelu: same obiekty (albo same napisy przy $strings); co innego — pusta lista.
     *
     * @return list<mixed>
     */
    private static function listOf(mixed $value, bool $strings = false): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return [];
        }

        return array_values(array_filter($value, $strings ? 'is_string' : 'is_array'));
    }

    /** Tekst z pola modelu: bez znaczników HTML, z rozwiniętymi encjami, odstępy zwinięte; nie-napis = ''. */
    private static function plain(mixed $value): string
    {
        if (! is_string($value)) {
            return is_int($value) || is_float($value) ? (string) $value : '';
        }

        return self::clean(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Cała lista rynku PL; niespójna — jedno ponowne pobranie od początku.
     *
     * @return list<array<string, mixed>>
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

            throw new RuntimeException('Lista wyrobów '.EjendalsB2bClient::HOST.' niespójna także po ponownym pobraniu: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function scanList(): array
    {
        $started = microtime(true);
        $rows = [];
        $total = 0;
        $pages = 1;

        for ($page = 1; $page <= $pages; $page++) {
            if (microtime(true) - $started > self::LIST_BUDGET_SECONDS) {
                throw new RuntimeException('Pobieranie listy '.EjendalsB2bClient::HOST.' trwa ponad '.(self::LIST_BUDGET_SECONDS / 60).' min — przerwane bez zapisu');
            }
            $json = $this->client->listPage($page, $this->pageSize);
            $pageTotal = is_int($json['totalMatching']) ? $json['totalMatching'] : -1;
            if ($page === 1) {
                $total = $pageTotal;
                if ($total <= 0) {
                    throw new RuntimeException('Lista wyrobów '.EjendalsB2bClient::HOST.' pusta albo bez licznika (totalMatching '.$pageTotal.') — zmiana witryny?');
                }
                $pages = (int) ceil($total / $this->pageSize);
                $this->progress('Lista wyrobów Ejendals: '.$total.' wyrobów na '.$pages.' stronach');
            } elseif ($pageTotal !== $total) {
                throw new RuntimeException('liczba wyrobów zmieniła się z '.$total.' na '.$pageTotal.' (strona '.$page.')', self::INCONSISTENT);
            }

            $expected = min($this->pageSize, $total - ($page - 1) * $this->pageSize);
            if (count($json['products']) !== $expected) {
                throw new RuntimeException(sprintf('strona %d: %d pozycji, oczekiwano %d', $page, count($json['products']), $expected), self::INCONSISTENT);
            }
            foreach ($json['products'] as $item) {
                $code = is_array($item) ? self::clean((string) ($item['code'] ?? '')) : '';
                if ($code === '') {
                    throw new RuntimeException('Strona '.$page.' listy '.EjendalsB2bClient::HOST.': pozycja bez kodu — zmiana witryny?');
                }
                if (isset($rows[$code])) {
                    throw new RuntimeException('wyrób '.$code.' na dwóch stronach', self::INCONSISTENT);
                }
                $rows[$code] = self::listRow($item);
            }
            // strona zalogowanej listy trwa ok. 37 s — sygnał życia przebiegu po każdej
            if ($page > 1) {
                $this->progress('Lista wyrobów Ejendals: strona '.$page.'/'.$pages);
            }
        }

        if (count($rows) !== $total) {
            throw new RuntimeException('pobrano '.count($rows).' z '.$total.' wyrobów', self::INCONSISTENT);
        }

        return array_values($rows);
    }

    /**
     * Pozycja listy przycięta do pól, których łącznik używa: zalogowana lista niesie przy każdym wyrobie logo marki
     * w SVG, obrazy i pełne opakowania — 609 takich pozycji na raz nie mieściło się z tinkerem w 128 MB serwera.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private static function listRow(array $item): array
    {
        $pack = static fn (mixed $pack): ?array => is_array($pack) ? [
            'quantity' => $pack['quantity'] ?? null,
            'text' => $pack['text'] ?? null,
            'fullText' => $pack['fullText'] ?? null,
            'baseUnit' => ['value' => $pack['baseUnit']['value'] ?? null],
        ] : null;
        $articles = [];
        foreach (is_array($item['articles'] ?? null) ? $item['articles'] : [] as $article) {
            if (! is_array($article)) {
                continue;
            }
            $row = [
                'code' => $article['code'] ?? null,
                'size' => $article['size'] ?? null,
                'price' => $article['price'] ?? null,
                'inStock' => $article['inStock'] ?? null,
                'baseUnit' => ['value' => $article['baseUnit']['value'] ?? null],
            ];
            foreach (self::SHIPMENTS as $shipment) {
                $row[$shipment] = $pack($article[$shipment] ?? null);
            }
            $articles[] = $row;
        }

        return [
            'code' => $item['code'],
            'title' => $item['title'] ?? null,
            'href' => $item['href'] ?? null,
            'typeOfProduct' => $item['typeOfProduct'] ?? null,
            'brand' => ['cvl' => ['value' => $item['brand']['cvl']['value'] ?? null]],
            'text' => $item['text'] ?? null,
            'categories' => $item['categories'] ?? null,
            'model' => is_array($item['model'] ?? null) ? $item['model'] : null,
            'articles' => $articles,
        ];
    }

    /**
     * Karta wyrobu z rozmiarami z ceną konta; null = wyrób bez ceny konta (liczony w podsumowaniu); pozycja pominięta
     * z powodem, gdy czegoś nie da się odczytać.
     *
     * @param  array<string, mixed>  $row
     */
    private function productFor(array $row): ?B2bRemoteProduct
    {
        $code = self::clean((string) $row['code']);
        $title = self::clean((string) ($row['title'] ?? ''));
        $prices = self::articlePrices($row);
        if ($prices === []) {
            $this->withoutPrice[] = $code;

            return null;
        }
        // pominięcie z powodu odczytu podaje rozmiary z ceną jako pozycje (skipped)
        $positions = array_map('strval', array_keys($prices));
        $url = EjendalsB2bClient::absoluteUrl((string) ($row['href'] ?? ''));
        if ($url === null) {
            return $this->skipped($code, $title, null, 'wyrób bez strony na polskiej witrynie (lista nie podaje adresu)', $positions);
        }
        // konto #20 ma ceny w EUR (katalog przyjmuje walutę producenta, jak Bolle); rozmiary karty w jednej walucie
        $currencies = array_values(array_unique(array_column($prices, 'currency')));
        if (count($currencies) !== 1 || preg_match('/^[A-Z]{3}$/', $currencies[0]) !== 1) {
            return $this->skipped($code, $title, $url, 'rozmiary bez jednej waluty ceny ('.implode(', ', $currencies).')', $positions);
        }

        try {
            $sizes = self::parseVariants($this->client->variants($code));
            $html = $this->client->productPage($url);
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return $this->skipped($code, $title, $url, 'rozmiary albo strona wyrobu: '.$e->getMessage(), $positions);
        }
        if ($html === null) {
            return $this->skipped($code, $title, $url, 'strona wyrobu nie istnieje (martwy odnośnik na liście)', $positions);
        }
        $page = self::parseProductPage($html);
        if ($page === null) {
            return $this->skipped($code, $title, $url, 'strona nie zawiera karty wyrobu', $positions);
        }

        return $this->cardFor([...$row, 'categories' => $this->polishCategories($code) ?? $row['categories'] ?? []], $code, $url, $page, $sizes, $prices);
    }

    /**
     * Kategorie po polsku z karty wyrobu (lista podaje je po angielsku); null = karty nie dało się odczytać — wtedy
     * zostają kategorie z listy, bo kategoria nie jest powodem, by pominąć wyrób.
     *
     * @return list<mixed>|null
     */
    private function polishCategories(string $code): ?array
    {
        try {
            $categories = $this->client->card($code)['categories'] ?? null;
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException) {
            return null;
        }

        return is_array($categories) && $categories !== [] ? array_values($categories) : null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $page
     * @param  array<string, array{code: string, size: string, ean: string}>  $sizes
     * @param  non-empty-array<string, array{net: float, base: float|null, currency: string, in_stock: bool|null, size: string, unit: string, packs: list<array{text: string, quantity: float}>}>  $prices
     */
    private function cardFor(array $row, string $code, string $url, array $page, array $sizes, array $prices): B2bRemoteProduct
    {
        $name = self::cardName((int) ($row['typeOfProduct'] ?? 0), $page['name']);

        // rozmiary w kolejności sklepu (/variants), potem rozmiary z ceną, których /variants nie zna
        $items = [];
        foreach ([...array_keys($sizes), ...array_keys($prices)] as $sizeCode) {
            $sizeCode = (string) $sizeCode;
            if (isset($items[$sizeCode]) || ! isset($prices[$sizeCode])) {
                continue;
            }
            $price = $prices[$sizeCode];
            $size = $sizes[$sizeCode]['size'] ?? '';
            $items[$sizeCode] = [
                'code' => $sizeCode,
                'label' => $size !== '' ? $size : $price['size'],
                'ean' => $sizes[$sizeCode]['ean'] ?? '',
                'article' => $sizes[$sizeCode]['code'] ?? $sizeCode,
                'availability' => match ($price['in_stock']) {
                    true => self::IN_STOCK,
                    false => self::OUT_OF_STOCK,
                    null => '',
                },
                'price' => new B2bRemotePrice(
                    net: $price['net'],
                    base: $price['base'],
                    discountPercent: $price['base'] !== null ? round((1 - $price['net'] / $price['base']) * 100, 2) : 0.0,
                    currency: $price['currency'],
                ),
                'unit' => $price['unit'],
                'packs' => $price['packs'],
            ];
        }
        $items = array_values($items);
        if (count(array_diff(array_keys($sizes), array_column($items, 'code'))) > 0) {
            $this->sizesWithoutPrice[] = $code;
        }
        $nets = array_unique(array_map(static fn (array $item): string => sprintf('%.2F', $item['price']->net), $items));
        $this->multiPrice += count($nets) > 1 ? 1 : 0;
        if (array_filter($items, static fn (array $item): bool => $item['price']->base === null) !== []) {
            $this->withoutBase[] = $code;
        }
        if ($page['description'] === '') {
            $this->withoutDescription[] = $code;
        }
        $this->cards++;

        $cheapest = self::cheapest($items);
        $order = self::orderQuantity($items);
        $cheapest = new B2bRemotePrice(
            net: $cheapest->net,
            base: $cheapest->base,
            discountPercent: $cheapest->discountPercent,
            currency: $cheapest->currency,
            order: $order,
        );
        $labelled = array_values(array_filter($items, static fn (array $item): bool => $item['label'] !== ''));

        return new B2bRemoteProduct(
            remoteId: $items[0]['code'],
            sku: $code,
            name: $name,
            category: self::category($row),
            sourceUrl: $url,
            raw: [
                'status' => 'ok',
                // cena karty = najtańszy rozmiar (B2bCatalogSync liczy ją też z members[].price)
                'price' => $cheapest,
                'code' => $code,
                'brand' => self::clean((string) ($row['brand']['cvl']['value'] ?? '')),
                'short' => self::clean((string) ($row['text'] ?? '')),
                'description' => $page['description'],
                'lists' => $page['lists'],
                'norms' => $page['norms'],
                'marks' => $page['marks'],
                'specs' => $page['specs'],
                'documents' => $page['documents'],
                'images' => $page['images'],
                'units' => array_values(array_unique(array_filter(array_column($items, 'unit')))),
                'packs' => array_values(array_unique(array_merge(...array_map(
                    static fn (array $item): array => array_filter(array_column($item['packs'], 'text')),
                    $items,
                )))),
            ],
            availability: self::availabilityText($items),
            variantSummary: $labelled !== []
                ? 'Rozmiary: '.implode('; ', array_map(static fn (array $item): string => $item['label'].' ('.$item['code'].')', $labelled))
                : null,
            // jeden rozmiar — pozycja pojedyncza (cena z price()); kilka — każdy z własną ceną
            members: count($items) > 1
                ? array_map(static fn (array $item): array => array_filter([
                    'remote_id' => $item['code'],
                    'sku' => $item['code'],
                    'name' => trim($name.' '.$item['label']),
                    'availability' => $item['availability'],
                    'size' => $item['label'],
                    'price' => $item['price'],
                ], static fn (mixed $value): bool => $value !== ''), $items)
                : [],
            identifiers: self::identifiers($code, $items),
        );
    }

    /**
     * Kod wyrobu (model) i dla każdego rozmiaru: numer artykułu (kod producenta) i EAN-13, dosłownie ze sklepu.
     *
     * @param  list<array{code: string, label: string, ean: string, article: string}>  $items
     * @return list<B2bRemoteIdentifier>
     */
    private static function identifiers(string $code, array $items): array
    {
        $out = [new B2bRemoteIdentifier(type: ProductIdentifier::TYPE_MODEL_CODE, value: $code, field: 'code')];
        foreach ($items as $item) {
            $label = $item['label'] !== '' ? $item['label'] : null;
            $out[] = new B2bRemoteIdentifier(type: ProductIdentifier::TYPE_MANUFACTURER_CODE, value: $item['article'], remoteId: $item['code'], label: $label, field: self::SPEC_ARTICLE);
            if ($item['ean'] !== '') {
                $out[] = new B2bRemoteIdentifier(type: ProductIdentifier::TYPE_EAN, value: $item['ean'], remoteId: $item['code'], label: $label, field: self::SPEC_EAN);
            }
        }

        return $out;
    }

    /**
     * „Rękawice TEGERA Infinity 8807”: rodzaj wyrobu z listy (typeOfProduct) przed nazwą ze strony (h1), gdy nazwa
     * go jeszcze nie ma; akcesoria i nieznany rodzaj — sama nazwa.
     */
    private static function cardName(int $type, string $h1): string
    {
        $prefix = self::TYPE_NAMES[$type] ?? '';
        if ($prefix === '' || str_starts_with(mb_strtolower($h1), mb_strtolower($prefix))) {
            return $h1;
        }

        return $prefix.' '.$h1;
    }

    /**
     * Kategoria z listy: sklep podaje kategorie wyrobu, a na końcu dział („Izolacja przed zimnem”, „Rękawice montażowe”,
     * „Rękawice”) — „Rękawice > Izolacja przed zimnem, Rękawice montażowe”.
     *
     * @param  array<string, mixed>  $row
     */
    private static function category(array $row): ?string
    {
        $categories = array_values(array_filter(
            array_map(static fn (mixed $c): string => is_string($c) ? self::clean($c) : '', is_array($row['categories'] ?? null) ? $row['categories'] : []),
            static fn (string $c): bool => $c !== '',
        ));

        if ($categories === []) {
            return null;
        }
        $root = array_pop($categories);

        return $categories !== [] ? $root.' > '.implode(', ', $categories) : $root;
    }

    /**
     * Najtańszy rozmiar karty, ta sama reguła co w B2bCatalogSync::sizePricing (remis — B2bCatalogSync::winsSizePriceTie).
     *
     * @param  non-empty-list<array{price: B2bRemotePrice}>  $items
     */
    private static function cheapest(array $items): B2bRemotePrice
    {
        $cheapest = $items[0]['price'];
        foreach ($items as $item) {
            $price = $item['price'];
            if ($price->net < $cheapest->net - 0.0049
                || (abs($price->net - $cheapest->net) < 0.005 && B2bCatalogSync::winsSizePriceTie($price->base, $cheapest->base))) {
                $cheapest = $price;
            }
        }

        return $cheapest;
    }

    /**
     * Jedna dostępność dla wszystkich rozmiarów — dosłownie; różne — „W magazynie: 8, 9; Brak w magazynie: 11”.
     *
     * @param  list<array{label: string, code: string, availability: string}>  $items
     */
    private static function availabilityText(array $items): ?string
    {
        $statuses = [];
        foreach ($items as $item) {
            if ($item['availability'] !== '') {
                $statuses[$item['availability']][] = $item['label'] !== '' ? $item['label'] : $item['code'];
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
     * Wyrób, którego nie da się zapisać w tym przebiegu. Rozmiary z ceną ($positions) idą jako pozycje (bez cen), tak
     * jak powiązała je karta: przebieg widzi je na liście (pozycja wiodąca scalonej karty nie jest zgłaszana jako
     * zniknięta) i jako pominięte (poza sprzątaniem) — jak u Mascot (modelNotRead); bez nich pozycją jest kod wyrobu.
     *
     * @param  list<string>  $positions  kody rozmiarów z ceną konta
     */
    private function skipped(string $code, string $title, ?string $url, string $reason, array $positions = []): B2bRemoteProduct
    {
        $name = $title !== '' ? $title : $code;

        return new B2bRemoteProduct(
            remoteId: $positions[0] ?? $code,
            sku: $code,
            name: $name,
            sourceUrl: $url,
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

    /** Tekst ze strony: bez znaków zerowej szerokości, odstępy zwinięte. */
    private static function clean(string $text): string
    {
        $text = str_replace(["\u{200B}", "\u{200C}", "\u{200D}", "\u{200E}", "\u{200F}", "\u{FEFF}"], '', $text);

        return trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $text)));
    }
}

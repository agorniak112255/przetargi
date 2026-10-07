<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Support\BrandKey;
use App\Support\ProductCodeMatch;
use App\Support\ProductIdentifierCode;

/**
 * Werdykt tożsamości źródła opisu (etap 1 opisów z cenników). Sygnał dla przeglądu, nie bramka — obecne bramki
 * pobierania (keepConfirmedCardPages, orderPagesForDescription, primarySource) działają jak dotąd.
 *
 * - hard: klucz karty (SKU = kod producenta, EAN, kod producenta i kod modelu ze źródeł cen, przy profilu z
 *   model_alias_is_key także nazwa modelu) stoi na stronie jako osobny ciąg — w ścieżce adresu, tytule albo
 *   mikrodanych głównego wyrobu strony (każdy host, także sklep), a w tekście strony i numerach części (data-part)
 *   tylko na hoście producenta z profilu, gdy profil pozwala (identity_in: text) — tekst sklepu niesie też kafelki
 *   „podobne / klienci kupili”. Krótki kod liczbowy (do 5 cyfr) w adresie nie liczy się na początku segmentu (numer
 *   wpisu sklepu PrestaShop „/4535-nazwa.html”), w tytule tylko obok marki. Twardy też adres wskazany ręcznie i blok
 *   katalogu PDF marki (ManufacturerCatalogPdf wycina go po kodzie wyrobu);
 * - soft: klucza brak, ale strona ma markę oraz SKU albo nazwę (ProductSearchIdentity::pageHasSkuOrNameAndManufacturer);
 * - none: stronę przepuściła tylko heurystyka.
 *
 * Niczego nie zapisuje.
 */
final class SourceIdentity
{
    public const HARD = 'hard';

    public const SOFT = 'soft';

    public const NONE = 'none';

    private const WHERE_LABELS = [
        'url' => 'w adresie',
        'title' => 'w tytule',
        'markup' => 'w mikrodanych',
        'text' => 'w tekście strony',
    ];

    /** Słowa, po których liczba nie jest numerem modelu („rozmiar 10”, „ISO 374”, „kat 3”) — modelAliases. */
    private const ALIAS_STOP_WORDS = [
        'rozmiar', 'rozm', 'size', 'en', 'iso', 'pn', 'ml', 'l', 'szt', 'para', 'par', 'nr', 'typ', 'kat', 'kategoria',
        'klasa', 'cl', 'mm', 'cm', 'op', 'opak', 'pak',
    ];

    /**
     * Jednostka po liczbie („MAPA 100 ml”, „300 mm”) — wtedy liczba nie jest numerem modelu. Bez gołego „l” i „m”:
     * tak po numerze modelu stoi rozmiar („VITAL 117 L”).
     */
    private const ALIAS_UNIT_AFTER = '(?:ml|mm|cm|µm|um|mic|mb|g|kg|szt|par|pary|%)';

    private const KEY_LABELS = [
        'sku' => 'SKU',
        'ean' => 'EAN',
        'manufacturer_code' => 'kod producenta',
        'model_code' => 'kod modelu',
        'model_alias' => 'model',
    ];

    public function __construct(
        private readonly ManufacturerProfiles $profiles,
        private readonly ProductSearchIdentity $identity,
    ) {}

    /**
     * Klucze karty, każdy raz (po ProductCodeMatch::key). Kod krótszy niż min_length profilu albo bez cyfry nie jest
     * kluczem — słowo („FIRST-STEP”) to nazwa, nie kod. SKU z naszego cennika w kształcie opisowym
     * (looksLikeInternalSku: „URG-HSV-WOR-BLUZA”) pomijamy. Kod producenta i kod modelu ze źródeł cen tylko w marce
     * karty (jak ManufacturerNormIdentity::codesFor) — dystrybutor wielu marek nazywa tak też cudze kody.
     *
     * @return list<array{type: string, value: string}>
     */
    public function keysFor(Product $p, ?ManufacturerProfile $prof): array
    {
        $min = $prof->minLength ?? max(1, (int) config('manufacturer_profiles.default.code.min_length', 4));
        $out = [];
        $seen = [];
        $add = static function (string $type, string $value) use (&$out, &$seen, $min): void {
            $value = trim((string) preg_replace('/\s+/u', ' ', $value));
            $key = ProductCodeMatch::key($value);
            if ($key === '' || isset($seen[$key])) {
                return;
            }
            if ($type === 'ean') {
                $value = (string) preg_replace('/[\s\-]+/u', '', $value);
                if (ProductIdentifierCode::gtin($value) === null) {
                    return;
                }
            } elseif (mb_strlen($key) < $min || preg_match('/\p{N}/u', $key) !== 1) {
                return;
            }
            $seen[$key] = true;
            $out[] = ['type' => $type, 'value' => $value];
        };

        $sku = trim((string) $p->sku);
        if ($sku !== '' && ! $this->identity->looksLikeInternalSku($p)) {
            foreach ($this->skuForms($sku, $prof) as $form) {
                $add('sku', $form);
            }
        }
        $add('ean', (string) $p->ean);

        if ($p->exists && (int) $p->id > 0) {
            $brandKey = BrandKey::of((string) $p->manufacturer);
            $rows = ProductIdentifier::query()
                ->where('product_id', $p->id)
                ->whereNull('removed_at')
                ->whereIn('type', [ProductIdentifier::TYPE_EAN, ProductIdentifier::TYPE_MANUFACTURER_CODE, ProductIdentifier::TYPE_MODEL_CODE])
                ->orderBy('id')
                ->get(['type', 'value', 'normalized', 'brand_key', 'manufacturer']);
            foreach ($rows as $row) {
                if ($row->type === ProductIdentifier::TYPE_EAN) {
                    // normalized null = zła suma kontrolna albo „5.9E+12” z arkusza
                    if ($row->normalized !== null) {
                        $add('ean', (string) $row->value);
                    }

                    continue;
                }
                $rowBrand = trim((string) $row->brand_key) !== '' ? (string) $row->brand_key : BrandKey::of((string) $row->manufacturer);
                if ($brandKey !== '' && $rowBrand === $brandKey) {
                    $add((string) $row->type, (string) $row->value);
                }
            }
        }

        if ($prof !== null && $prof->modelAliasIsKey) {
            foreach ($this->modelAliases($p) as $alias) {
                $add('model_alias', $alias);
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $page  strona z ProductPageFetcher (url, final_url, title, text, markup_codes)
     * @return array{verdict: 'hard'|'soft'|'none', reason: string, key_type: ?string, key: ?string, where: ?string}
     */
    public function judgePage(Product $p, array $page, ?ManufacturerProfile $prof): array
    {
        $url = trim((string) ($page['url'] ?? ''));
        $final = trim((string) ($page['final_url'] ?? ''));
        $title = (string) ($page['title'] ?? '');
        $text = (string) ($page['text'] ?? '');

        foreach (array_unique(array_filter([$url, $final])) as $address) {
            if ($p->isTrustedShopUrl($address)) {
                return $this->verdict(self::HARD, 'adres wskazany ręcznie', 'manual', $address, 'url');
            }
        }

        $identityIn = $prof->identityIn ?? (array) config('manufacturer_profiles.default.identity_in', ['url', 'title', 'markup']);
        $paths = [];
        foreach (array_unique(array_filter([$url, $final])) as $address) {
            $path = rawurldecode((string) (parse_url($address, PHP_URL_PATH) ?? ''));
            if ($path !== '') {
                $paths[] = $path;
            }
        }
        $address = $final !== '' ? $final : $url;
        // Tekst i numery części to treść strony pod adresem po przekierowaniach — liczą się tylko u producenta.
        $onManufacturerHost = $prof !== null && $address !== '' && $prof->ownsUrl($address);
        $markup = is_array($page['markup_codes'] ?? null) ? $page['markup_codes'] : [];

        foreach ($this->keysFor($p, $prof) as $entry) {
            $key = ProductCodeMatch::key($entry['value']);
            $shortNumber = self::isShortNumber($entry);
            foreach ($identityIn as $where) {
                $hit = match ($where) {
                    'url' => array_filter($paths, fn (string $path): bool => $this->pathCarries($path, $key, $shortNumber)) !== [],
                    'title' => $shortNumber ? $this->titleCarriesNextToBrand($title, $key, $p) : ProductCodeMatch::textCarries($title, $key),
                    'markup' => $this->markupHas($markup, $entry, $onManufacturerHost, $p),
                    // Kod krótki w treści to za mało („VP01”, „2039” bywa ceną, normą czy kodem sąsiada w tabeli) —
                    // jak w ManufacturerNormIdentity: tylko w adresie, tytule i mikrodanych. Tekst sklepu — nigdy.
                    'text' => $onManufacturerHost && ! self::isShort($entry) && ProductCodeMatch::textCarries($text, $key),
                    default => false,
                };
                if ($hit) {
                    $label = self::KEY_LABELS[$entry['type']] ?? $entry['type'];

                    return $this->verdict(
                        self::HARD,
                        $label.' '.$entry['value'].' '.(self::WHERE_LABELS[$where] ?? $where),
                        $entry['type'],
                        $entry['value'],
                        $where
                    );
                }
            }
        }

        // Kod rodziny z cennika (Coba „LM0102”) przy tabeli części karty producenta z samymi kodami rozmiarów
        // („LM010201”, „LM010202”) — reguła bramki pobierania z jej trzema warunkami (domena producenta, każdy dłuższy
        // kod dokleja tylko rozmiar, pierwsze słowo nazwy w adresie albo tytule). Tylko przy profilu z tekstem strony.
        // Kody z mikrodanych dochodzą do tekstu: tabela części bywa w tekście strony ucięta (coba.com/product/deckstep
        // — wiersze DS0112xx są tylko w data-part).
        $markupText = implode(' ', array_map(
            static fn (mixed $code): string => is_array($code) ? (string) ($code['value'] ?? '') : (is_string($code) ? $code : ''),
            $markup
        ));
        if (in_array('text', $identityIn, true) && $onManufacturerHost
            && $this->identity->officialFamilyPageListsSizeCodes($address, $title, trim($text.' '.$markupText), $p)) {
            return $this->verdict(self::HARD, 'SKU '.trim((string) $p->sku).' jako kod rodziny w tabeli rozmiarów karty producenta', 'sku', trim((string) $p->sku), 'text');
        }

        if ($this->identity->pageHasSkuOrNameAndManufacturer($address, $title, $text, $p)) {
            return $this->verdict(self::SOFT, 'bez kodu karty na stronie — marka z SKU albo nazwą', null, null, null);
        }

        return $this->verdict(self::NONE, 'bez kodu karty i bez marki z nazwą — stronę przepuściła tylko heurystyka', null, null, null);
    }

    /**
     * Werdykt karty = werdykt strony głównego źródła (primary_source_url). Bez źródła albo bez pobranej strony
     * źródła werdykt jest null — brak rozstrzygnięcia to informacja, nie „none”.
     *
     * @param  list<array<string, mixed>>  $pages
     * @param  list<string>  $catalogUrls  katalogi PDF marki (ManufacturerCatalogPdf::catalogUrlsFor)
     * @return array{verdict: 'hard'|'soft'|'none'|null, reason: string, key_type: ?string, key: ?string, where: ?string, source_url: ?string, profile: ?string}
     */
    public function judgeCard(Product $p, array $pages, ?string $primaryUrl, ?string $primaryKind, array $catalogUrls): array
    {
        $prof = $this->profiles->for($p);
        $profile = $prof === null ? null : ($prof->profileKey ?? 'default');
        $primaryUrl = $primaryUrl !== null && trim($primaryUrl) !== '' ? trim($primaryUrl) : null;
        $card = static fn (array $verdict): array => $verdict + ['source_url' => $primaryUrl, 'profile' => $profile];

        if ($primaryUrl === null) {
            return $card(['verdict' => null, 'reason' => 'karta bez źródła opisu', 'key_type' => null, 'key' => null, 'where' => null]);
        }
        if ($primaryKind === 'manual' || $p->isTrustedShopUrl($primaryUrl)) {
            return $card($this->verdict(self::HARD, 'adres wskazany ręcznie', 'manual', $primaryUrl, 'url'));
        }
        $wanted = Product::normalizeShopUrl($primaryUrl);
        $catalogs = array_map(static fn (string $u): string => Product::normalizeShopUrl($u), array_filter($catalogUrls, 'is_string'));
        if ($primaryKind === 'catalog' || in_array($wanted, $catalogs, true)) {
            return $card($this->verdict(self::HARD, 'blok katalogu PDF marki dopasowany po kodzie wyrobu', 'sku', trim((string) $p->sku), 'catalog'));
        }

        foreach ($pages as $page) {
            if (! is_array($page)) {
                continue;
            }
            foreach (array_filter([(string) ($page['url'] ?? ''), (string) ($page['final_url'] ?? '')]) as $address) {
                if (Product::normalizeShopUrl($address) === $wanted
                    || Product::normalizeShopUrl($this->identity->preferredLocaleUrl($address, $p)) === $wanted) {
                    return $card($this->judgePage($p, $page, $prof));
                }
            }
        }

        return $card(['verdict' => null, 'reason' => 'strona źródła opisu nie została pobrana', 'key_type' => null, 'key' => null, 'where' => null]);
    }

    /**
     * SKU i jego zapisy na stronie producenta według profilu marki (Coba): końcówka „-5” = „05” doklejone do kodu
     * („FF0100-5” → „FF010005”, dash_suffix_pad) i litera postaci sprzedaży („CD010610C” na metry → rolka
     * „CD010610”, variant_suffixes) — tylko po cyfrze, więc „ALURAMP-YE” zostaje sobą.
     *
     * @return list<string>
     */
    private function skuForms(string $sku, ?ManufacturerProfile $prof): array
    {
        $forms = [$sku];
        if ($prof === null) {
            return $forms;
        }
        if ($prof->dashSuffixPad > 1
            && preg_match('/^(.*\d)[\s\-](\d{1,'.($prof->dashSuffixPad - 1).'})$/u', $sku, $m) === 1) {
            $forms[] = $m[1].str_pad($m[2], $prof->dashSuffixPad, '0', STR_PAD_LEFT);
        }
        foreach ($prof->variantSuffixes as $suffix) {
            foreach ($forms as $form) {
                if (preg_match('/^(.*\d)'.preg_quote($suffix, '/').'$/iu', $form, $m) === 1) {
                    $forms[] = $m[1];
                }
            }
        }

        return array_values(array_unique($forms));
    }

    /**
     * Nazwa modelu jako klucz (profil z model_alias_is_key — MAPA nie pisze kodu wyrobu na stronie). Pierwszeństwo ma
     * kolumna model_name z cennika (z numerem); bez niej pierwsza para „słowo numer” z nazwy karty
     * („ULTRANITRIL 358 - POLYBAG” → „ULTRANITRIL 358”, „TEMP-ICE 700”), przy słowie przed nią także z nim
     * („TEMP DEX 720”). Pomijane pary: słowo z ALIAS_STOP_WORDS albo marka karty przed liczbą („rozmiar 10”, „ISO 374”,
     * „MAPA 358”), liczba z jednostką („MAPA 100 ml”) albo z częścią dziesiętną. Numer w całości — „VITAL 115” nie
     * trafia w „VITAL 1150”.
     *
     * @return list<string>
     */
    private function modelAliases(Product $p): array
    {
        $model = $this->identity->modelNamePhrase($p);
        if ($model !== '' && preg_match('/\p{N}/u', $model) === 1) {
            return [$model];
        }
        $stop = self::ALIAS_STOP_WORDS;
        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(trim((string) $p->manufacturer))) ?: [] as $brandWord) {
            if (mb_strlen($brandWord) >= 2) {
                $stop[] = $brandWord;
            }
        }
        $isStop = static fn (string $word): bool => in_array(mb_strtolower(trim($word, '-')), $stop, true);
        $pattern = '/(?<![\p{L}\p{N}])(?:(\p{L}[\p{L}\-]+)\s+)?(\p{L}[\p{L}\-]{2,})[\s\-]+(\d{2,5})(?![\p{L}\p{N}])'
            .'(?![.,]\d)(?!\s*'.self::ALIAS_UNIT_AFTER.'(?![\p{L}\p{N}]))/iu';
        if (preg_match_all($pattern, (string) $p->name, $matches, PREG_SET_ORDER) < 1) {
            return [];
        }
        foreach ($matches as $m) {
            if ($isStop($m[2])) {
                continue;
            }
            // Nazwa dwuczłonowa („TEMP DEX 720” → strona „tempdex-720”) — także z oboma słowami.
            $out = $m[1] !== '' && ! $isStop($m[1]) ? [$m[1].' '.$m[2].' '.$m[3]] : [];
            $out[] = $m[2].' '.$m[3];

            return $out;
        }

        return [];
    }

    /**
     * Mikrodane to pole z samym kodem — porównanie całej wartości: kod po ProductCodeMatch::key, EAN jako GTIN
     * („5901234567890” = „05901234567890”). Sklep dokleja do kodu producenta markę z separatorem (icd.pl
     * „COBA-PG010001”) — wartość bez tego przedrostka też się liczy; to dalej kod głównego wyrobu strony.
     *
     * Numer części z data-part (rodzaj „part”) liczy się tylko na hoście producenta — przycisk z numerem bywa też
     * w karuzelach sklepów.
     *
     * @param  list<mixed>  $markup
     * @param  array{type: string, value: string}  $entry
     */
    private function markupHas(array $markup, array $entry, bool $onManufacturerHost, Product $p): bool
    {
        $ean = $entry['type'] === 'ean';
        $ours = $ean ? ProductIdentifierCode::gtin($entry['value']) : ProductCodeMatch::key($entry['value']);
        if ($ours === null || $ours === '') {
            return false;
        }
        $brands = array_values(array_unique(array_filter([
            $this->identity->shortBrand((string) $p->manufacturer),
            trim((string) (preg_split('/\s+/u', trim((string) $p->manufacturer))[0] ?? '')),
        ], static fn (string $b): bool => mb_strlen($b) >= 2)));
        foreach ($markup as $code) {
            $value = is_array($code) ? (string) ($code['value'] ?? '') : (is_string($code) ? $code : '');
            if ($value === '' || (! $onManufacturerHost && is_array($code) && ($code['type'] ?? '') === 'part')) {
                continue;
            }
            if ($ean) {
                if (ProductIdentifierCode::gtin($value) === $ours) {
                    return true;
                }

                continue;
            }
            $candidates = [$value];
            foreach ($brands as $brand) {
                $candidates[] = $this->identity->stripBrandPrefix($value, $brand);
            }
            foreach (array_unique($candidates) as $candidate) {
                if (ProductCodeMatch::key($candidate) === $ours) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Kod krótki: do 4 znaków albo same cyfry, najwyżej 5 — jak ManufacturerNormIdentity::isShort. EAN i nazwa modelu
     * (słowo z numerem) nigdy.
     *
     * @param  array{type: string, value: string}  $entry
     */
    private static function isShort(array $entry): bool
    {
        if (in_array($entry['type'], ['ean', 'model_alias'], true)) {
            return false;
        }
        $key = ProductCodeMatch::key($entry['value']);

        return mb_strlen($key) <= 4 || (ctype_digit($key) && strlen($key) <= 5);
    }

    /**
     * Kod czysto liczbowy do 5 cyfr („4535”) — taki bywa numerem wpisu sklepu w adresie i liczbą w tytule. EAN i nazwa
     * modelu nigdy.
     *
     * @param  array{type: string, value: string}  $entry
     */
    private static function isShortNumber(array $entry): bool
    {
        if (in_array($entry['type'], ['ean', 'model_alias'], true)) {
            return false;
        }
        $key = ProductCodeMatch::key($entry['value']);

        return ctype_digit($key) && strlen($key) <= 5;
    }

    /**
     * Klucz w ścieżce adresu jako osobny ciąg. Krótki kod liczbowy na początku segmentu przed myślnikiem, kropką albo
     * końcem segmentu to numer wpisu sklepu (PrestaShop „/4535-mata-coba.html”), nie kod wyrobu — ten fragment pomijamy.
     */
    private function pathCarries(string $path, string $key, bool $shortNumber): bool
    {
        if ($shortNumber) {
            $path = implode('/', array_map(
                static fn (string $segment): string => (string) preg_replace('/^\d{1,7}(?=[\-.]|$)/', '', $segment),
                explode('/', $path)
            ));
        }

        return ProductCodeMatch::textCarries($path, $key);
    }

    /**
     * Krótki kod liczbowy w tytule tylko obok marki karty (do 40 znaków przed albo po nim) — sama liczba w tytule bywa
     * ceną, wymiarem albo numerem wpisu sklepu. Karta bez producenta: nigdy.
     */
    private function titleCarriesNextToBrand(string $title, string $key, Product $p): bool
    {
        if ($title === '' || $key === '' || trim((string) $p->manufacturer) === '') {
            return false;
        }
        $chars = preg_split('//u', $key, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $pattern = '/(?<![\p{L}\p{N}])'
            .implode('[\s._\/-]?', array_map(static fn (string $c): string => preg_quote($c, '/'), $chars))
            .'(?![\p{L}\p{N}])/u';
        if (preg_match_all($pattern, $title, $matches, PREG_OFFSET_CAPTURE) < 1) {
            return false;
        }
        foreach ($matches[0] as [$found, $offset]) {
            $before = mb_substr(substr($title, 0, $offset), -40);
            $after = mb_substr(substr($title, $offset + strlen($found)), 0, 40);
            if ($this->identity->hayHasBrand($before.' '.$after, $p)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{verdict: 'hard'|'soft'|'none', reason: string, key_type: ?string, key: ?string, where: ?string}
     */
    private function verdict(string $verdict, string $reason, ?string $keyType, ?string $key, ?string $where): array
    {
        return ['verdict' => $verdict, 'reason' => $reason, 'key_type' => $keyType, 'key' => $key, 'where' => $where];
    }
}

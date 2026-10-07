<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Support\BlockedSourceHost;
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
 *   wpisu sklepu PrestaShop „/4535-nazwa.html”), w tytule tylko obok marki. Przy profilu z labelled_short_codes krótki
 *   kod (także krótszy niż min_length, AJ GROUP „103”) liczy się z etykietą w adresie albo tytule („model-103”,
 *   „REF: 6036”) albo w mikrodanych strony producenta (shortCodeVerdict). Twardy też adres wskazany ręcznie i blok
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

    /**
     * Etykieta przed krótkim kodem — wtedy liczba jest numerem wyrobu, nie ceną, wymiarem czy numerem wpisu sklepu
     * („model-103”, „Modèle 4402”, „REF: 6036”, „nr 08C”).
     */
    private const SHORT_CODE_LABEL = '(?:mod(?:el[eu]?|èle)?|ref|art|nr|kod)';

    /** Krótszy kod z etykietą to już za mało — „nr 12”, „art. 5” to numery czegokolwiek. */
    private const LABELLED_CODE_MIN_LENGTH = 3;

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
        return $this->cardKeys($p, $prof)['keys'];
    }

    /**
     * Klucze karty (keysFor) i jej krótkie kody (shortCodeVerdict) z jednego odczytu identyfikatorów. Krótkie kody tylko
     * przy profilu z labelled_short_codes: kod wyrobu (SKU, kod producenta, kod modelu) z cyfrą, krótszy niż min_length
     * profilu, ale najmniej LABELLED_CODE_MIN_LENGTH znaków, albo krótki według isShort.
     *
     * @return array{keys: list<array{type: string, value: string}>, short: list<array{type: string, value: string, key: string}>}
     */
    private function cardKeys(Product $p, ?ManufacturerProfile $prof): array
    {
        $min = $prof->minLength ?? max(1, (int) config('manufacturer_profiles.default.code.min_length', 4));
        $labelled = $prof !== null && $prof->labelledShortCodes;
        $keys = [];
        $short = [];
        foreach ($this->collectKeys($p, $prof, $labelled ? min($min, self::LABELLED_CODE_MIN_LENGTH) : $min) as $entry) {
            $key = ProductCodeMatch::key($entry['value']);
            if ($entry['type'] === 'ean' || mb_strlen($key) >= $min) {
                $keys[] = $entry;
            }
            if ($labelled && in_array($entry['type'], ['sku', 'manufacturer_code', 'model_code'], true)
                && mb_strlen($key) >= self::LABELLED_CODE_MIN_LENGTH
                && (mb_strlen($key) < $min || self::isShort($entry))) {
                $short[] = $entry + ['key' => $key];
            }
        }

        return ['keys' => $keys, 'short' => $short];
    }

    /**
     * Kody karty przy danej najmniejszej długości kodu (cardKeys).
     *
     * @return list<array{type: string, value: string}>
     */
    private function collectKeys(Product $p, ?ManufacturerProfile $prof, int $min): array
    {
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

        // Nasz sklep pokazuje nasze własne opisy — niczego o wyrobie nie potwierdza, nawet z kodem w adresie
        // i nawet wskazany ręcznie (CEDERROTH 490710 ze źródłem supon.rzeszow.pl, 07.10.2026).
        foreach (array_unique(array_filter([$url, $final])) as $address) {
            if (BlockedSourceHost::matches($address)) {
                return $this->verdict(self::NONE, 'strona naszego sklepu albo wykluczona jako źródło', null, null, null);
            }
        }

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

        $cardKeys = $this->cardKeys($p, $prof);
        foreach ($cardKeys['keys'] as $entry) {
            $key = ProductCodeMatch::key($entry['value']);
            $shortNumber = self::isShortNumber($entry);
            foreach ($identityIn as $where) {
                $hit = match ($where) {
                    'url' => array_filter($paths, fn (string $path): bool => $this->pathCarries($path, $key, $shortNumber)) !== [],
                    'title' => $shortNumber ? $this->titleCarriesNextToBrand($title, $key, $p) : ProductCodeMatch::textCarries($title, $key),
                    'markup' => $this->markupHas($markup, $entry, $onManufacturerHost, $p, $prof, $address),
                    // Kod krótki w treści to za mało („VP01”, „2039” bywa ceną, normą czy kodem sąsiada w tabeli) —
                    // jak w ManufacturerNormIdentity: tylko w adresie, tytule i mikrodanych. Tekst sklepu — nigdy.
                    'text' => $onManufacturerHost && ! self::isShort($entry) && (ProductCodeMatch::textCarries($text, $key)
                        // Nazwy zdjęć wyrobu na stronie producenta — jak kod w treści (cederroth.com podaje numer tylko
                        // w nazwach plików i za limitem zapytań 429 strona przychodzi z czytnika bez mikrodanych).
                        || $this->imageFileCarries($markup, $page, $key)),
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

        $short = $this->shortCodeVerdict($p, $cardKeys['short'], $identityIn, $paths, $title, $markup, $address, $onManufacturerHost, $prof);
        if ($short !== null) {
            return $short;
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
     * Krótki kod karty (cardKeys: isShort albo krótszy niż min_length profilu — AJ GROUP „103”, „08C”) sam w sobie nie
     * jest kluczem, ale jest nim:
     * - z etykietą (SHORT_CODE_LABEL) i separatorem przed nim i granicą za nim w adresie albo tytule: „model-103”,
     *   „Model 616”, „REF: 6036”. „model-1102” nie potwierdza 102 (pros.pl /102-plaszcz-model-1102.html — „102-” to
     *   numer wpisu sklepu), „model 104/1” ani „model 333/WZ” nie potwierdza 104 i 333. Na innym hoście niż producenta
     *   tylko z marką karty w adresie albo tytule. Gdy mikrodane głównego wyrobu podają wyłącznie dłuższy kod z literą
     *   po naszym („001 MAX” przy „001”), etykieta nie wystarcza — to strona innego wariantu;
     * - na hoście producenta w mikrodanych głównego wyrobu (sku/mpn) równy kodowi, także po zdjęciu marki albo nazwy
     *   witryny („BEMOREGREEN-901”), gdy stoi też w adresie (poza numerem wpisu sklepu) albo w tytule — samo pole bywa
     *   numerem wpisu (securabc.com „sku: 21”).
     *
     * @param  list<array{type: string, value: string, key: string}>  $codes
     * @param  list<string>  $identityIn
     * @param  list<string>  $paths
     * @param  list<mixed>  $markup
     * @return array{verdict: 'hard'|'soft'|'none', reason: string, key_type: ?string, key: ?string, where: ?string}|null
     */
    private function shortCodeVerdict(Product $p, array $codes, array $identityIn, array $paths, string $title, array $markup, string $address, bool $onManufacturerHost, ?ManufacturerProfile $prof): ?array
    {
        if ($codes === []) {
            return null;
        }

        $mainCodes = [];
        foreach ($markup as $code) {
            if (is_string($code) || (is_array($code) && in_array($code['type'] ?? '', ['sku', 'mpn'], true))) {
                $value = trim(is_array($code) ? (string) ($code['value'] ?? '') : $code);
                if ($value !== '') {
                    $mainCodes[] = $value;
                }
            }
        }
        $brandOnPage = ! $onManufacturerHost && trim((string) $p->manufacturer) !== ''
            && $this->identity->hayHasBrand(implode(' ', $paths).' '.$title, $p);
        $hays = ['url' => $paths, 'title' => [$title]];

        foreach ($codes as $entry) {
            $key = $entry['key'];
            $label = self::KEY_LABELS[$entry['type']] ?? $entry['type'];
            if ($onManufacturerHost && in_array('markup', $identityIn, true)
                && $this->shortCodeInManufacturerMarkup($mainCodes, $key, $p, $prof, $address)
                && (array_filter($paths, fn (string $path): bool => $this->pathCarries($path, $key, ctype_digit($key))) !== []
                    || $this->titleCarriesShortCode($title, $key))) {
                return $this->verdict(self::HARD, $label.' '.$entry['value'].' w mikrodanych strony producenta', $entry['type'], $entry['value'], 'markup');
            }
            if ((! $onManufacturerHost && ! $brandOnPage) || $this->markupNamesLongerVariant($mainCodes, $key)) {
                continue;
            }
            // Tylko adres i tytuł — tekst strony nie: pomiar CEDERROTH 07.10.2026 („REF: 1882” w treści) dał 1 kartę,
            // a krzyżowo 40 fałszywych (strony zestawów i dozowników wymieniają „REF: …” wkładów; po zawężeniu do
            // pierwszego REF strony dalej 9).
            foreach (['url', 'title'] as $where) {
                if (! in_array($where, $identityIn, true)) {
                    continue;
                }
                foreach ($hays[$where] as $hay) {
                    if ($this->labelledCodeIn($hay, $key, $where === 'url', $p)) {
                        return $this->verdict(
                            self::HARD,
                            $label.' '.$entry['value'].' z oznaczeniem modelu '.(self::WHERE_LABELS[$where] ?? $where),
                            $entry['type'],
                            $entry['value'],
                            $where
                        );
                    }
                }
            }
        }

        return null;
    }

    /**
     * Krótki kod z etykietą przed nim i granicą za nim (shortCodeVerdict). Za kodem nie może stać dalszy numer
     * („104/1”, „3000.02”, „101 112”) ani przyrostek po ukośniku („333/WZ”). Oznaczenie wariantu za numerem modelu
     * („model 001 MAX”, „model 1011 R”) to inny wyrób: w adresie kod musi zamykać nazwę strony („…-model-103.html”;
     * „-model-001-max” małymi literami nie odróżnia wariantu od opisu), w tytule nie może za nim stać krótki
     * znacznik wielkimi literami, chyba że to marka karty („model 103 PROS”).
     */
    private function labelledCodeIn(string $hay, string $key, bool $inPath, Product $p): bool
    {
        if ($hay === '' || $key === '') {
            return false;
        }
        $chars = preg_split('//u', $key, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $code = implode('[\s._\/-]?', array_map(static fn (string $c): string => preg_quote($c, '/'), $chars));
        $pattern = '/(?<![\p{L}\p{N}])'.self::SHORT_CODE_LABEL.'[\s\-_.:#]{1,4}'.$code
            .'(?![\p{L}\p{N}])(?![\s._\/-]{1,2}\p{N})(?!\/\p{L})/iu';
        // false (np. zepsuty UTF-8 w tekście) = nic nie potwierdza
        if ((int) preg_match_all($pattern, $hay, $matches, PREG_OFFSET_CAPTURE) < 1) {
            return false;
        }
        foreach ($matches[0] as [$found, $offset]) {
            $after = mb_strcut($hay, $offset + strlen($found), 40, 'UTF-8');
            if ($this->followedByUnitOrLaw($after)) {
                continue;
            }
            if ($inPath) {
                if (preg_match('/^(?:$|[.\/?#])/u', $after) === 1) {
                    return true;
                }

                continue;
            }
            $variant = preg_match('/^[\s\-]+(\p{Lu}{1,5}(?:\/\p{Lu}+)?)(?![\p{L}\p{N}])/u', $after, $m);
            if ($variant === false || ($variant === 1 && ! $this->identity->hayHasBrand($m[1], $p))) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * Za liczbą stoi jednostka, waluta albo przepis — wtedy to ilość, cena czy artykuł ustawy, nie numer modelu
     * („nr 100 szt”, „nr 300 g/m2”, „103 zł”, „art. 103 kodeksu pracy”). Niepoprawny tekst też nie potwierdza.
     */
    private function followedByUnitOrLaw(string $after): bool
    {
        $hit = preg_match(
            '/^\s*(?:(?:'.self::ALIAS_UNIT_AFTER.'|zł|zl|pln|eur|€|g\/m|m2|m²)(?![\p{L}\p{N}])'
            .'|[.,]\d|kodeks|k\.\s?p\.|kp(?![\p{L}\p{N}])|ustaw|rozporz|§|dz\.\s?u)/iu',
            $after
        );

        return $hit !== 0;
    }

    /**
     * Krótki kod w tytule jako osobny ciąg, bez jednostki, ceny ani przepisu za nim (gałąź mikrodanych producenta —
     * „Kurtka model 103” tak, „Promocja 103 zł” nie).
     */
    private function titleCarriesShortCode(string $title, string $key): bool
    {
        if ($title === '' || $key === '') {
            return false;
        }
        $chars = preg_split('//u', $key, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $pattern = '/(?<![\p{L}\p{N}])'
            .implode('[\s._\/-]?', array_map(static fn (string $c): string => preg_quote($c, '/'), $chars))
            .'(?![\p{L}\p{N}])/iu';
        if ((int) preg_match_all($pattern, $title, $matches, PREG_OFFSET_CAPTURE) < 1) {
            return false;
        }
        foreach ($matches[0] as [$found, $offset]) {
            if (! $this->followedByUnitOrLaw(mb_strcut($title, $offset + strlen($found), 40, 'UTF-8'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mikrodane głównego wyrobu strony producenta podają kod równy naszemu krótkiemu kodowi — wprost albo po zdjęciu
     * marki karty lub nazwy witryny z przodu („BEMOREGREEN-901” na bemoregreen.eu).
     *
     * @param  list<string>  $mainCodes
     */
    private function shortCodeInManufacturerMarkup(array $mainCodes, string $key, Product $p, ?ManufacturerProfile $prof, string $address): bool
    {
        foreach ($mainCodes as $value) {
            foreach ($this->markupCandidates($value, $p, $prof, $address) as $candidate) {
                if (ProductCodeMatch::key($candidate) === $key) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Wartość pola mikrodanych i jej zapisy bez przedrostka marki karty („COBA-PG010001”). Na hoście producenta
     * (podany $manufacturerAddress) także bez nazwy witryny („BEMOREGREEN-901” na bemoregreen.eu) i bez końcówki kodu
     * kombinacji z profilu („103-00033-48/XS”).
     *
     * @return list<string>
     */
    private function markupCandidates(string $value, Product $p, ?ManufacturerProfile $prof, ?string $manufacturerAddress): array
    {
        $prefixes = [
            $this->identity->shortBrand((string) $p->manufacturer),
            trim((string) (preg_split('/\s+/u', trim((string) $p->manufacturer))[0] ?? '')),
        ];
        if ($manufacturerAddress !== null) {
            $host = (string) preg_replace('/^www\./', '', mb_strtolower((string) (parse_url($manufacturerAddress, PHP_URL_HOST) ?? '')));
            $labels = explode('.', $host);
            $prefixes[] = count($labels) >= 2 ? $labels[count($labels) - 2] : '';
        }
        $candidates = [$value];
        foreach (array_unique(array_filter($prefixes, static fn (string $b): bool => mb_strlen($b) >= 2)) as $prefix) {
            $candidates[] = $this->identity->stripBrandPrefix($value, $prefix);
        }
        if ($manufacturerAddress !== null && $prof !== null) {
            foreach ($candidates as $candidate) {
                $candidates[] = $prof->withoutCombinationSuffix($candidate);
            }
        }

        return array_values(array_unique($candidates));
    }

    /**
     * Mikrodane głównego wyrobu podają nasz kod tylko z literą doklejoną za nim („001 MAX”, „333/WZ” przy „001”, „333”)
     * — strona innego wariantu, etykieta w adresie („model-001-max”) nie wystarcza. Numer wariantu z kolorem i rozmiarem
     * („103-00033-48/XS”) to dalej nasz wyrób.
     *
     * @param  list<string>  $mainCodes
     */
    private function markupNamesLongerVariant(array $mainCodes, string $key): bool
    {
        $longer = false;
        foreach ($mainCodes as $value) {
            $other = ProductCodeMatch::key($value);
            if ($other === $key) {
                return false;
            }
            if (str_starts_with($other, $key) && preg_match('/^\p{L}/u', mb_substr($other, mb_strlen($key))) === 1) {
                $longer = true;
            }
        }

        return $longer;
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
    /**
     * Kod w nazwie pliku zdjęcia strony — głównego (og:image) albo zdjęć wyrobu ze strony (image_urls, także ze strony
     * z czytnika). Konwencja producenta: cederroth.com na stronach globalnych podaje numer tylko tak
     * („51011026-cederroth-first-aid-station-f-low-scaled.jpg”), bez REF w treści. Wołane tylko tam, gdzie liczy się
     * tekst strony (strona producenta z profilem czytającym treść), i dla kodów, które nie są krótkie.
     *
     * @param  list<mixed>  $markup
     * @param  array<string, mixed>  $page
     */
    private function imageFileCarries(array $markup, array $page, string $key): bool
    {
        $urls = [];
        foreach ($markup as $code) {
            if (is_array($code) && ($code['type'] ?? '') === 'og_image') {
                $urls[] = (string) ($code['value'] ?? '');
            }
        }
        foreach (['trusted_image_urls', 'image_urls'] as $field) {
            foreach (array_slice(is_array($page[$field] ?? null) ? $page[$field] : [], 0, 20) as $url) {
                $urls[] = is_string($url) ? $url : '';
            }
        }
        foreach ($urls as $url) {
            $file = rawurldecode(basename((string) (parse_url($url, PHP_URL_PATH) ?? '')));
            if ($file !== '' && ProductCodeMatch::textCarries($file, $key)) {
                return true;
            }
        }

        return false;
    }

    private function markupHas(array $markup, array $entry, bool $onManufacturerHost, Product $p, ?ManufacturerProfile $prof, string $address): bool
    {
        $ean = $entry['type'] === 'ean';
        $ours = $ean ? ProductIdentifierCode::gtin($entry['value']) : ProductCodeMatch::key($entry['value']);
        if ($ours === null || $ours === '') {
            return false;
        }
        foreach ($markup as $code) {
            $value = is_array($code) ? (string) ($code['value'] ?? '') : (is_string($code) ? $code : '');
            if ($value === '' || (is_array($code) && ($code['type'] ?? '') === 'og_image')
                || (! $onManufacturerHost && is_array($code) && ($code['type'] ?? '') === 'part')) {
                continue;
            }
            if ($ean) {
                if (ProductIdentifierCode::gtin($value) === $ours) {
                    return true;
                }

                continue;
            }
            foreach ($this->markupCandidates($value, $p, $prof, $onManufacturerHost ? $address : null) as $candidate) {
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

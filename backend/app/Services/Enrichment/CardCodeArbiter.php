<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Models\Product;
use App\Support\ProductCodeMatch;
use App\Support\ShopEntryId;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Str;

/**
 * Najdłuższy kod z katalogu marki decyduje (etap 3 opisów z cenników, §1.2). Audyt 08.10.2026: karta 1011 opisana
 * ze strony „…-model-1011-r”, 104/1 ze strony 104/1 OC, SB04 AIR ze strony SB04 AIR CARP, 102 ze strony płaszcza 1102,
 * CEDERROTH 310366 ze strony REF 6943, SECURA S565E202 ze strony „Indeks: S565A202”. Werdykt dla strony, zdjęcia albo
 * pliku (wyrób źródła z jego ciągów — pageHays, fileHays):
 * - foreign: kod innej karty tej marki, który wydłuża nasz (1011 → 1011 R, SB04 AIR → SB04 AIR CARP), albo kod innej
 *   karty marki przy braku naszego (102 przy 1102, B9V na stronie FLASHV, „Indeks: S565A202” przy S565E202); na hoście
 *   producenta także etykietowany kod z tytułu albo adresu („REF 6943”, „model 1102”) niezgodny z naszym przy braku
 *   naszego — nawet bez takiej karty;
 * - own: nasz kod jest w źródle, a żaden dłuższy kod innej karty go nie wydłuża;
 * - none: ani jednego, ani drugiego — albo profil bez code.longest_code_wins (Coba, MAPA, Ansell), albo karta bez kodu
 *   od 3 znaków.
 *
 * Wyjątki od foreign (runda 2 etapu 3, audyty SECURA i Bolle 08.10.2026) — wtedy none:
 * - kod innej karty albo etykieta różniąca się od naszego kodu tylko rozmiarem z profilu (code.size_letters,
 *   ManufacturerProfile::sizeSibling: S56T0SL0 przy „Indeks S56T0SM0” — jedna strona półmaski SECURA 3000 na S, M, L);
 * - pole „{index_label}: X” z treści strony z kodem spoza katalogu marki (securabc.com „25-elsec-25-kv.html”: „Indeks
 *   S5911000” — rękawice, a karta to zestaw S5921000 z tymi rękawicami). Etykieta z tytułu albo adresu nazywa wyrób
 *   strony wprost, pole treści bywa kodem składnika zestawu, którego nie mamy w cenniku; kod z katalogu marki dalej
 *   daje foreign (S565A202 to nasza karta pochłaniacza A2);
 * - przy braku naszego kodu kod innej karty poprzedzony „do / dla / for / pasuje do / compatible with / kompatybilny z”
 *   (najwyżej dwa słowa między nimi, każde także w nazwie karty), gdy karta też jest akcesorium (taki zwrot w jej
 *   nazwie): wizjer COVFLASHEXT „…do przyłbicy FLASH” na stronie „Wizjer zewnętrzny do przyłbicy FLASHV”; „Przyłbica
 *   do spawania FLASHV” — „spawania” nie ma w nazwie wizjera, więc foreign. Filtr B9V („FLASH – filtr
 *   elektrooptyczny”) na stronie przyłbicy FLASHV dalej foreign. „art.” nie jest takim zwrotem — „Nr art. X” nazywa
 *   wyrób strony (icd.pl „Nr art. SEC-T5912200”).
 *
 * Kod w ciągu: jako osobny ciąg (ProductCodeMatch::textCarries) albo — dla kodu z kilku członów („SB01 STRONG”,
 * „071 STRAŻ”) — wszystkie człony od 2 znaków jako osobne słowa w dowolnej kolejności („…-strong-red-sb01”). Kod innej
 * karty z samych cyfr do 5 znaków (krótki numer — bywa rozmiarem, rokiem czy ceną) liczy się tylko z etykietą
 * („model-1102”, „REF 7200”), jako całe pole mikrodanych albo na początku nazwy pliku („6943-sensitive….jpg”).
 *
 * Katalog marki z bazy (SKU kart producentów z brand_keys profilu) trzyma tylko kody, raz na markę w przebiegu
 * (#[Scoped]: kolejne zadanie kolejki czyta od nowa; forget() zwalnia wcześniej). Niczego nie zapisuje.
 */
#[Scoped]
final class CardCodeArbiter
{
    public const OWN = 'own';

    public const FOREIGN = 'foreign';

    public const NONE = 'none';

    /** Znacznik etykietowanego kodu producenta w ciągach pageHays (nie występuje w tekście stron). */
    private const LABEL = "\x1Flabel\x1F";

    /** Znacznik pola „{index_label}: X” z treści strony producenta w ciągach pageHays. */
    private const FIELD = "\x1Ffield\x1F";

    /**
     * Zwrot akcesorium przed kodem innej karty (najwyżej dwa słowa między nimi): „do przyłbicy FLASHV”, „for FLASHV”,
     * „compatible with”. Ciąg po Str::ascii, separatory adresu jak spacje.
     */
    private const FITS = '(?:pasuj\w*\s+do|kompatybiln\w*\s+z|compatible\s+with|to\s+fit|do|dla|for)';

    /** Etykiety kodu wyrobu na stronie producenta (§1.2) i przed krótkim numerem innej karty. */
    private const CODE_LABEL = '(?:model[eu]?|mod\.?|ref|indeks|index)';

    /** Etykiety, przy których krótki numer innej karty liczy się w ciągu (jak SourceIdentity::SHORT_CODE_LABEL). */
    private const SHORT_LABEL = '(?:mod(?:el[eu]?|èle)?|ref|art|nr|kod|indeks|index)';

    private const MIN_LENGTH = 3;

    /** Kod bez cyfry („FLASHV”, „TRYON”) liczy się dopiero od tylu znaków — krótsze to słowa. */
    private const LETTERS_ONLY_MIN_LENGTH = 5;

    /** @var array<string, list<array{value: string, key: string, ascii: string, tokens: list<string>}>> */
    private array $catalogs = [];

    /** @var list<string>|null */
    private ?array $manufacturerNames = null;

    public function __construct(
        private readonly ManufacturerDomainResolver $domains,
    ) {}

    /**
     * @param  list<string>  $ownKeys  zapisy kodu karty (SourceIdentity::ownKeys)
     * @param  list<string>  $hays  ciągi źródła (pageHays, fileHays)
     * @return array{verdict: string, code: ?string}
     */
    public function judge(Product $p, array $ownKeys, array $hays, ManufacturerProfile $prof): array
    {
        if (! $prof->longestCodeWins) {
            return ['verdict' => self::NONE, 'code' => null];
        }
        $own = [];
        foreach ($ownKeys as $value) {
            $code = $this->code((string) $value);
            if ($code !== null) {
                $own[$code['key']] = $code;
            }
        }
        if ($own === []) {
            return ['verdict' => self::NONE, 'code' => null];
        }

        $labels = [];
        $fields = [];
        $plain = [];
        foreach ($hays as $hay) {
            $hay = (string) $hay;
            if (str_starts_with($hay, self::LABEL)) {
                $hay = substr($hay, strlen(self::LABEL));
                $labels[] = $hay;
            } elseif (str_starts_with($hay, self::FIELD)) {
                $hay = substr($hay, strlen(self::FIELD));
                $fields[] = $hay;
            }
            if (trim($hay) !== '') {
                $plain[] = $this->hay($hay);
            }
        }

        $sizeSibling = static function (string $key) use ($own, $prof): bool {
            foreach ($own as $ours) {
                if ($prof->sizeSibling($key, $ours['key'])) {
                    return true;
                }
            }

            return false;
        };
        $accessory = $this->namesAccessory((string) $p->name);
        $ownPresent = array_values(array_filter($own, fn (array $code): bool => $this->present($code, $plain, false)));
        foreach ($this->catalog($prof) as $other) {
            if (isset($own[$other['key']]) || $sizeSibling($other['key']) || ! $this->present($other, $plain, true)) {
                continue;
            }
            if ($ownPresent === []) {
                if ($accessory && $this->onlyAfterFitsWord($other, $plain, (string) $p->name)) {
                    continue;
                }

                return ['verdict' => self::FOREIGN, 'code' => $other['value']];
            }
            foreach ($ownPresent as $ours) {
                if (mb_strlen($other['key']) > mb_strlen($ours['key']) && $this->extends($other, $ours)) {
                    return ['verdict' => self::FOREIGN, 'code' => $other['value']];
                }
            }
        }
        if ($ownPresent !== []) {
            return ['verdict' => self::OWN, 'code' => $ownPresent[0]['value']];
        }

        // pole treści spoza katalogu marki nie daje foreign (opis klasy) — rozstrzyga tylko, że to nie nasz kod; etykieta
        // innego rozmiaru naszego modelu nie przesądza — dalsze etykiety (obcy „model X”) dalej dają foreign
        $foreignLabel = null;
        foreach ([...$labels, ...$fields] as $i => $label) {
            $key = ProductCodeMatch::key($label);
            if ($sizeSibling($key)) {
                continue;
            }
            foreach ($own as $ours) {
                if (str_starts_with($key, $ours['key']) || str_starts_with($ours['key'], $key)) {
                    return ['verdict' => self::NONE, 'code' => null];
                }
            }
            if ($i < count($labels)) {
                $foreignLabel ??= $label;
            }
        }

        return $foreignLabel !== null
            ? ['verdict' => self::FOREIGN, 'code' => $foreignLabel]
            : ['verdict' => self::NONE, 'code' => null];
    }

    /**
     * Ciągi strony do judge: ścieżki adresu i adresu po przekierowaniach bez numerów wpisów sklepu (ShopEntryId), tytuł,
     * sku/mpn głównego wyrobu z mikrodanych (na hoście producenta bez końcówki kombinacji; pole równe numerowi wpisu
     * strony pomijane — securabc.com „sku: 21”; sam numer poza hostem producenta też — sklep wpisuje tam swoje numery),
     * a na hoście producenta etykiety „model / REF / Indeks” z tytułu i adresu oraz pierwsze pole „{index_label}: X”
     * z tekstu (reszty tekstu nie czytamy).
     *
     * @param  array<string, mixed>  $page  strona z ProductPageFetcher (url, final_url, title, text, markup_codes)
     * @return list<string>
     */
    public function pageHays(array $page, ManufacturerProfile $prof): array
    {
        $url = trim((string) ($page['url'] ?? ''));
        $final = trim((string) ($page['final_url'] ?? ''));
        $title = trim((string) ($page['title'] ?? ''));
        $address = $final !== '' ? $final : $url;
        $onManufacturerHost = $address !== '' && $prof->ownsUrl($address);

        $hays = [];
        $paths = [];
        $entryIds = [];
        foreach (array_unique(array_filter([$url, $final])) as $candidate) {
            $path = $this->strippedPath($candidate);
            if ($path !== '') {
                $paths[] = $path;
                $hays[] = $path;
            }
            $entry = ShopEntryId::entryId($candidate);
            if ($entry !== null) {
                $entryIds[(string) $entry] = true;
            }
        }
        if ($title !== '') {
            $hays[] = $title;
        }
        foreach (is_array($page['markup_codes'] ?? null) ? $page['markup_codes'] : [] as $code) {
            if (! is_string($code) && ! (is_array($code) && in_array($code['type'] ?? '', ['sku', 'mpn'], true))) {
                continue;
            }
            $value = trim(is_array($code) ? (string) ($code['value'] ?? '') : $code);
            if ($onManufacturerHost) {
                $value = $prof->withoutCombinationSuffix($value);
            }
            if ($value === '' || isset($entryIds[$value]) || (! $onManufacturerHost && preg_match('/\p{L}/u', $value) !== 1)) {
                continue;
            }
            $hays[] = $value;
        }

        if ($onManufacturerHost) {
            // tytuł przed adresem — w adresie „104/1” to już „1041”
            $labelled = $this->labelledCodes($title, '[\p{L}\p{N}][\p{L}\p{N}.\/\-]*');
            foreach ($paths as $path) {
                array_push($labelled, ...$this->labelledCodes($path, '[\p{L}\p{N}]+'));
            }
            $field = self::indexField((string) ($page['text'] ?? ''), $prof->indexLabel ?? 'Indeks');
            $marked = array_map(static fn (string $label): array => [self::LABEL, $label], array_values(array_unique($labelled)));
            if ($field !== null && ! in_array($field, $labelled, true)) {
                $marked[] = [self::FIELD, $field];
            }
            foreach ($marked as [$marker, $label]) {
                $label = $prof->withoutCombinationSuffix($label);
                $key = ProductCodeMatch::key($label);
                if (mb_strlen($key) >= self::MIN_LENGTH && preg_match('/\p{N}/u', $key) === 1 && ! isset($entryIds[$label])) {
                    $hays[] = $marker.$label;
                }
            }
        }

        return array_values(array_unique($hays));
    }

    /**
     * Ciągi zdjęcia albo pliku do judge: „adres opis” (opis linku, podpis pliku — opcjonalnie). Z adresu ścieżka bez
     * numerów wpisów sklepu i sama nazwa pliku; zapytanie nie (pdf.php?id_product=… rozstrzyga ShopEntryId::entryId).
     *
     * @return list<string>
     */
    public function fileHays(string $urlAndLabel): array
    {
        $urlAndLabel = trim($urlAndLabel);
        if ($urlAndLabel === '') {
            return [];
        }
        $url = '';
        $label = $urlAndLabel;
        if (preg_match('/^(\S+)(?:\s+(.*))?$/su', $urlAndLabel, $m) === 1
            && (preg_match('#^(?:https?:)?//#i', $m[1]) === 1 || str_starts_with($m[1], '/'))) {
            $url = $m[1];
            $label = trim($m[2] ?? '');
        }
        $hays = [];
        if ($url !== '') {
            $path = $this->strippedPath($url);
            if ($path !== '') {
                $hays[] = $path;
                $file = basename($path);
                if ($file !== '' && $file !== $path) {
                    $hays[] = $file;
                }
            }
        }
        if ($label !== '') {
            $hays[] = $label;
        }

        return array_values(array_unique($hays));
    }

    /**
     * Klucze kodów kart marki profilu (bez separatorów, małe litery) — także dla profilu bez longest_code_wins.
     *
     * @return list<string>
     */
    public function brandCodeKeys(ManufacturerProfile $prof): array
    {
        return array_map(static fn (array $code): string => $code['key'], $this->catalog($prof));
    }

    public function forget(): void
    {
        $this->catalogs = [];
        $this->manufacturerNames = null;
    }

    /**
     * SKU kart producentów marki profilu (brand_keys wpisu profilu; bez wpisu — klucz marki karty), każdy kod raz.
     *
     * @return list<array{value: string, key: string, ascii: string, tokens: list<string>}>
     */
    private function catalog(ManufacturerProfile $prof): array
    {
        $cacheKey = $prof->profileKey ?? $prof->brandKey;
        if (isset($this->catalogs[$cacheKey])) {
            return $this->catalogs[$cacheKey];
        }
        $brandKeys = $prof->profileKey !== null
            ? array_values(array_filter((array) config('manufacturer_profiles.profiles.'.$prof->profileKey.'.brand_keys', []), 'is_string'))
            : [];
        if ($brandKeys === []) {
            $brandKeys = [$prof->brandKey];
        }
        $this->manufacturerNames ??= Product::query()->toBase()
            ->whereNotNull('manufacturer')
            ->distinct()
            ->pluck('manufacturer')
            ->map(static fn (mixed $m): string => (string) $m)
            ->all();
        $names = array_values(array_filter(
            $this->manufacturerNames,
            fn (string $name): bool => in_array($this->domains->brandKey($name), $brandKeys, true)
        ));

        $codes = [];
        if ($names !== []) {
            $skus = Product::query()->toBase()
                ->whereIn('manufacturer', $names)
                ->whereNotNull('sku')
                ->distinct()
                ->pluck('sku');
            foreach ($skus as $sku) {
                $code = $this->code((string) $sku);
                if ($code !== null && ! isset($codes[$code['key']])) {
                    $codes[$code['key']] = $code;
                }
            }
        }

        return $this->catalogs[$cacheKey] = array_values($codes);
    }

    /**
     * Kod do porównań: od 3 znaków z cyfrą albo od 5 liter bez cyfry; człony do dopasowania słowami (bez znaków
     * diakrytycznych).
     *
     * @return array{value: string, key: string, ascii: string, tokens: list<string>}|null
     */
    private function code(string $value): ?array
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));
        $key = ProductCodeMatch::key($value);
        $hasDigit = preg_match('/\p{N}/u', $key) === 1;
        if (mb_strlen($key) < ($hasDigit ? self::MIN_LENGTH : self::LETTERS_ONLY_MIN_LENGTH)) {
            return null;
        }

        return ['value' => $value, 'key' => $key, 'ascii' => ProductCodeMatch::key(Str::ascii($value)), 'tokens' => self::tokens($value)];
    }

    /**
     * @return array{text: string, key: string, tokens: array<string, true>}
     */
    private function hay(string $text): array
    {
        return [
            'text' => $text,
            'key' => ProductCodeMatch::key(Str::ascii($text)),
            'tokens' => array_fill_keys(self::tokens($text), true),
        ];
    }

    /**
     * @param  array{value: string, key: string, ascii: string, tokens: list<string>}  $code
     * @param  list<array{text: string, key: string, tokens: array<string, true>}>  $hays
     */
    private function present(array $code, array $hays, bool $guardShortNumber): bool
    {
        $asciiKey = $code['ascii'];
        $shortNumber = $guardShortNumber && ctype_digit($code['key']) && strlen($code['key']) <= 5;
        foreach ($hays as $hay) {
            if ($asciiKey !== '' && str_contains($hay['key'], $asciiKey) && $this->carries($hay['text'], $code, $asciiKey, $shortNumber)) {
                return true;
            }
            // kod z kilku członów: wszystkie jako osobne słowa („SB01 STRONG” w „…-strong-red-sb01”); nie przy członie
            // jednoznakowym („1101 R / 1011 R” — samo „R” to za mało, a bez niego to inny kod)
            if (! $shortNumber && self::matchableByWords($code['tokens'])
                && array_filter($code['tokens'], static fn (string $t): bool => ! isset($hay['tokens'][$t])) === []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{value: string, key: string, ascii: string, tokens: list<string>}  $code
     */
    private function carries(string $text, array $code, string $asciiKey, bool $shortNumber): bool
    {
        $ascii = Str::ascii($text);
        if (! ProductCodeMatch::textCarries($text, $code['key']) && ! ProductCodeMatch::textCarries($ascii, $asciiKey)) {
            return false;
        }
        if (! $shortNumber) {
            return true;
        }
        $trimmed = trim($ascii);
        // całe pole mikrodanych albo nazwa pliku od numeru („6943-sensitive-plasters….jpg”)
        if (ProductCodeMatch::key($trimmed) === $code['key'] || preg_match('/^'.preg_quote($code['key'], '/').'(?![\p{L}\p{N}])/u', $trimmed) === 1) {
            return true;
        }

        return preg_match('/(?<![\p{L}\p{N}])'.self::SHORT_LABEL.'[\s\-_.:#]{1,4}'.preg_quote($code['key'], '/').'(?![\p{L}\p{N}])/iu', $ascii) === 1;
    }

    /**
     * Kod innej karty wydłuża nasz: zaczyna się od naszego, a dalej stoi litera albo granica członu („1011” →
     * „1011R”, „1011 R”; „SB04 AIR” → „SB04 AIR CARP”; „1101” → „1101/1011”), nasz kod jest w nim osobnym członem
     * („1011” w „1101/1011”) albo zawiera wszystkie nasze człony. „1102” nie wydłuża „102”, „1031” nie wydłuża „103”.
     *
     * @param  array{value: string, key: string, ascii: string, tokens: list<string>}  $other
     * @param  array{value: string, key: string, ascii: string, tokens: list<string>}  $ours
     */
    private function extends(array $other, array $ours): bool
    {
        if (str_starts_with($other['key'], $ours['key'])
            && preg_match('/^\p{L}/u', mb_substr($other['key'], mb_strlen($ours['key']))) === 1) {
            return true;
        }
        if (ProductCodeMatch::textCarries($other['value'], $ours['key'])) {
            return true;
        }

        return count($ours['tokens']) >= 2 && array_diff($ours['tokens'], $other['tokens']) === [];
    }

    /**
     * Kody za etykietą „model / REF / Indeks” (§1.2) — jeden ciąg znaków kodu za etykietą.
     *
     * @return list<string>
     */
    private function labelledCodes(string $hay, string $codePattern): array
    {
        if ($hay === '' || preg_match_all('/(?<![\p{L}\p{N}])'.self::CODE_LABEL.'[\s\-_.:#]{1,4}('.$codePattern.')/iu', $hay, $m) < 1) {
            return [];
        }

        return array_values(array_filter(array_map(static fn (string $c): string => rtrim($c, './-'), $m[1]), static fn (string $c): bool => $c !== ''));
    }

    /**
     * Nazwa karty mówi, że to akcesorium innego wyrobu („Zewnętrzny wizjer … do przyłbicy FLASH”, „Filtr dla …”) —
     * zwrot FITS i za nim słowo.
     */
    private function namesAccessory(string $name): bool
    {
        return preg_match('/(?<![a-z0-9])'.self::FITS.'\s+[a-z0-9]/i', self::spaced($name)) === 1;
    }

    /**
     * Każde wystąpienie kodu innej karty w ciągach źródła stoi po zwrocie akcesorium (FITS, najwyżej dwa słowa między
     * nimi, każde z nich także w nazwie karty): „Wizjer zewnętrzny do przyłbicy FLASHV”, „…-do-przylbicy-flashv” przy
     * karcie „…do przyłbicy FLASH”. „do” bywa w nazwach zwykłych wyrobów („Przyłbica do spawania FLASHV”) — słowa między
     * zwrotem a kodem muszą powtarzać nazwę naszego akcesorium. Kod choć raz bez takiego zwrotu — false.
     *
     * @param  array{value: string, key: string, ascii: string, tokens: list<string>}  $other
     * @param  list<array{text: string, key: string, tokens: array<string, true>}>  $hays
     */
    private function onlyAfterFitsWord(array $other, array $hays, string $name): bool
    {
        $nameWords = array_fill_keys(self::tokens($name), true);
        $chars = preg_split('//u', $other['ascii'], -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($chars === []) {
            return false;
        }
        $code = implode('[\s._\/-]?', array_map(static fn (string $c): string => preg_quote($c, '/'), $chars));
        $seen = false;
        foreach ($hays as $hay) {
            $text = self::spaced($hay['text']);
            if ((int) preg_match_all('/(?<![a-z0-9])'.$code.'(?![a-z0-9])/i', $text, $m, PREG_OFFSET_CAPTURE) < 1) {
                continue;
            }
            foreach ($m[0] as [, $offset]) {
                $seen = true;
                $before = substr($text, max(0, $offset - 60), min(60, $offset));
                if (preg_match('/(?<![a-z0-9])'.self::FITS.'((?:\s+[a-z0-9]+){0,2})\s+$/i', $before, $fit) !== 1) {
                    return false;
                }
                foreach (self::tokens($fit[1]) as $word) {
                    if (! isset($nameWords[$word])) {
                        return false;
                    }
                }
            }
        }

        return $seen;
    }

    /** Tekst bez znaków diakrytycznych, separatory adresu („-”, „_”, „/”) jak spacje — do zwrotów FITS. */
    private static function spaced(string $text): string
    {
        return (string) preg_replace('/[\s\-_\/]+/', ' ', Str::ascii($text));
    }

    /** Pierwsze pole „{etykieta}: X” z kodem (z cyfrą) w tekście strony („Indeks: T5912200”). */
    public static function indexField(string $text, string $label): ?string
    {
        if ($text === '' || preg_match_all('/(?<![\p{L}\p{N}])'.preg_quote($label, '/').'\s*[:#]?\s*([\p{L}\p{N}][\p{L}\p{N}.\/\-]*)/iu', $text, $m) < 1) {
            return null;
        }
        foreach ($m[1] as $code) {
            $code = rtrim($code, './-');
            if ($code !== '' && preg_match('/\p{N}/u', $code) === 1) {
                return $code;
            }
        }

        return null;
    }

    private function strippedPath(string $url): string
    {
        $path = parse_url(ShopEntryId::strip($url), PHP_URL_PATH);
        $path = rawurldecode(is_string($path) ? $path : '');

        return trim($path) === '/' ? '' : trim($path);
    }

    /**
     * Człony kodu albo ciągu: małe litery i cyfry bez znaków diakrytycznych.
     *
     * @return list<string>
     */
    private static function tokens(string $text): array
    {
        $parts = preg_split('/[^a-z0-9]+/', mb_strtolower(Str::ascii($text))) ?: [];

        return array_values(array_unique(array_filter($parts, static fn (string $t): bool => $t !== '')));
    }

    /**
     * Kod dopasowywany słowami: co najmniej dwa człony, każdy od 2 znaków.
     *
     * @param  list<string>  $tokens
     */
    private static function matchableByWords(array $tokens): bool
    {
        return count($tokens) >= 2 && array_filter($tokens, static fn (string $t): bool => strlen($t) < 2) === [];
    }
}

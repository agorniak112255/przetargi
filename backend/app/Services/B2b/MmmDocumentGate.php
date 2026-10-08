<?php

declare(strict_types=1);

namespace App\Services\B2b;

/**
 * Które pliki z karty 3M wolno dołączyć do karty numeru magazynowego (audyt 08.10.2026: 2958 dołączeń = 381 różnych
 * plików). Karta pdp 3M podpina pod każdy numer media całej rodziny i kampanii: banery ComTac IX (Facebook, 728×90),
 * ulotki promocyjne, „volume buy”, infografiki, wewnętrzny PDF „cross-sell … e-commerce assets” („3M Confidential”),
 * a do tego karty techniczne sąsiednich modeli (9152 przy 9163, liny Cobra AC230 przy Viper AC405, laryngofon MT9-02
 * przy zestawach LiteCom, TDS smyczy 1260354 przy smyczy 1260350).
 *
 * Reguły działają na tytule i nazwie pliku — bez pobierania PDF-u:
 * - materiał marketingowy albo ogólny (marketingReason) odpada;
 * - plik, którego tytuł albo nazwa podaje kod INNEJ pozycji 3M z tego samego konta, a nie podaje kodu tej karty,
 *   odpada (foreignCode). Kod tej karty w tytule albo nazwie = plik zostaje.
 * - plik bez żadnego kodu (broszura serii „Polish_LR.pdf”, karta techniczna „Vision 3”) zostaje: to dokument rodziny,
 *   do której karta należy. Pomiar 08.10.2026: reguła „tylko z kodem karty w pliku” wycinała właściwe karty
 *   techniczne serii (Scott Vision 3, ProPak Sigma, G3000, osłony serii 5), więc jej nie stosujemy.
 * Tekstu PDF-u nie czytamy: kod naszej karty w treści bywa tylko na liście akcesoriów i zgodności (WP96 w pliku WPAF),
 * a tekst zapisany przy karcie jest obcięty do 8000 znaków.
 */
final class MmmDocumentGate
{
    /** Kod krótszy niż tyle znaków alfanumerycznych nic nie dowodzi („P1”, „5J”, „100”). */
    private const MIN_CODE_LENGTH = 4;

    /** Tyle sąsiednich słów tytułu łączymy w jeden kandydat na kod („mt9-02” → „MT902”, „g5-01” → „G501”). */
    private const MAX_JOINED_TOKENS = 3;

    /**
     * Materiały marketingowe i ogólne (nie o tym wyrobie): banery i reklamy, ulotki sprzedażowe, infografiki,
     * przewodniki sprzedaży, biuletyny o normie lub opakowaniu, wewnętrzne pliki e-commerce. Broszury, ulotki
     * produktowe („leaflet”), plakaty dopasowania i instrukcje zostają.
     */
    private const MARKETING = '/banner|baner|facebook|instagram|linkedin|sell[\s_-]?sheet|flyer|ulotka promocyjna|ulotka wprowadzaj|ulotka 3m śoi'
        .'|advert|reklama|infogra(?:ph|f)|cross[\s_-]?sell|e-?commerce[\s_-]?assets|spon-marketing|volume[\s_-]?(?:buy|brochure)'
        .'|phase[\s_-]?in|sales[\s_-]?guide|technical[\s_-]?bulletin|guidance[\s_-]?document|biuletyn|bulletin/iu';

    /** Kod z nazwy pozycji: ciąg liter/cyfr (z myślnikami, kropkami albo ukośnikami w środku) z co najmniej jedną cyfrą. */
    private const NAME_CODE = '/(?<![\p{L}\p{N}])[\p{L}\p{N}]+(?:[.\/-][\p{L}\p{N}]+)*(?![\p{L}\p{N}])/u';

    /** Miary z nazwy („10 m”, „1000 V”, „15min”, „200 barów”, „2 l”, „50 szt.”) — to nie kody wyrobu. */
    private const MEASURE = '/(?<![\p{L}\p{N}])\d+(?:[.,]\d+)?\s*(?:mm|cm|m|km|kg|g|ml|l|min|minut\p{L}*|bar\p{L}*|db|v|kv|szt\p{L}*|sztuk\p{L}*|mb|lb|%)(?![\p{L}\p{N}])/iu';

    /** Oznaczenie normy w nazwie: „EN 352:2020”, „EN ISO 20345”, „PN-EN 397+A1:2012”. */
    private const NORM = '/(?<![\p{L}\p{N}])(?:PN-)?(?:EN|ISO|IEC)(?:\s+(?:EN|ISO|IEC))*\s*\d[\d\-:+A]*/u';

    /** @var array<string, string> kod bez separatorów → kod, wszystkie pozycje konta */
    private array $accountCodes = [];

    /** @var array<string, string> litery początku kodu (series()) → przykładowy kod konta */
    private array $accountSeries = [];

    /** @var array<string, string> numer bez końcowych liter („9152ES” → „9152”) → przykładowy kod konta */
    private array $accountRoots = [];

    /**
     * @param  list<string>  $accountCodes  kody wszystkich pozycji konta (accountCodes())
     */
    public function __construct(array $accountCodes)
    {
        foreach ($accountCodes as $code) {
            $key = self::compact($code);
            if (mb_strlen($key) >= self::MIN_CODE_LENGTH) {
                $this->accountCodes[$key] = $code;
                $root = (string) preg_replace('/\p{L}+$/u', '', $key);
                // tylko rdzeń z samych cyfr: „G3000” (G3000CUV) i „TR800” (TR-800-IHK) to nazwy serii, które karta
                // G3001 albo jednostka TR-802E dzieli z innymi pozycjami — ich plik serii jest też ich plikiem
                if ($root !== $key && mb_strlen($root) >= self::MIN_CODE_LENGTH && ctype_digit($root)) {
                    $this->accountRoots[$root] ??= $code;
                }
                // nazwa serii tylko z kodu bez spacji: numer katalogowy „AURA 9322+GEN3” zrobiłby z nazwy linii
                // „Aura” (karta techniczna całej serii 9300+) cudzą serię
                $series = self::series($key);
                if (mb_strlen($series) >= self::MIN_CODE_LENGTH && preg_match('/\s/u', $code) !== 1) {
                    $this->accountSeries[$series] ??= $code;
                }
            }
        }
    }

    /**
     * Powód, dla którego plik nie należy do karty; null = zostaje.
     *
     * @param  list<string>  $cardCodes  cardCodes() tej karty
     */
    public function rejection(array $cardCodes, string $title, string $url): ?string
    {
        $marketing = self::marketingReason($title, $url);
        if ($marketing !== null) {
            return $marketing;
        }
        $own = [];
        $ownSeries = [];
        foreach ($cardCodes as $code) {
            $key = self::compact($code);
            $own[$key] = true;
            $ownSeries[self::series($key)] = true;
        }
        $foreign = null;
        foreach ([$title, self::fileName($url)] as $text) {
            foreach (self::candidates($text) as $candidate) {
                if (self::isOwn($candidate, $own)) {
                    return null;
                }
                $foreign ??= $this->foreignCode($candidate, $ownSeries);
            }
        }

        return $foreign !== null ? 'plik innego wyrobu 3M ('.$foreign.' w tytule albo nazwie pliku)' : null;
    }

    /**
     * Kandydat to kod tej karty albo jej rdzeń: „1100” przy 1100R/1100D (karta techniczna wkładek 1100 opisuje też
     * wersje z dozownika), „G5-01” przy G5-01TW.
     *
     * @param  array<string, true>  $own
     */
    private static function isOwn(string $candidate, array $own): bool
    {
        if (isset($own[$candidate])) {
            return true;
        }
        if (preg_match('/\d/', $candidate) !== 1) {
            return false;
        }
        $wildcard = self::wildcardPrefix($candidate);
        foreach (array_keys($own) as $code) {
            if (str_starts_with((string) $code, $candidate)
                || ($wildcard !== null && self::matchesWildcard((string) $code, $candidate, $wildcard))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Kod innej pozycji konta, na który wskazuje kandydat; null = żaden. Cztery postaci:
     * - dokładny kod („AE522”, „MT902”, „9936”);
     * - kod bez końcowych liter wersji („9152” — pozycje 9152E i 9152ES);
     * - kod z „X” w miejscu cyfr („AC2XX” — karta techniczna liny Cobra AC210/AC220/AC230);
     * - sama nazwa serii bez cyfr („WPAF” — osłony przed łukiem WPAF1/WPAF2-0), gdy to litery początku kodów innych
     *   pozycji, a nie tej karty (WP96 ma serię „WP”).
     *
     * @param  array<string, true>  $ownSeries
     */
    private function foreignCode(string $candidate, array $ownSeries): ?string
    {
        if (isset($this->accountCodes[$candidate])) {
            return 'kod '.$this->accountCodes[$candidate];
        }
        // „vflex-9152” przy 9162E: pozycji „9152” nie ma, są 9152E i 9152ES
        if (isset($this->accountRoots[$candidate])) {
            return 'kod '.$candidate.' = '.$this->accountRoots[$candidate];
        }
        $wildcard = self::wildcardPrefix($candidate);
        if ($wildcard !== null) {
            foreach ($this->accountCodes as $key => $code) {
                if (self::matchesWildcard((string) $key, $candidate, $wildcard)) {
                    return 'kod '.$candidate.' = '.$code;
                }
            }
        }
        if (isset($this->accountSeries[$candidate]) && ! isset($ownSeries[$candidate])) {
            return 'seria '.$candidate.' = '.$this->accountSeries[$candidate];
        }

        return null;
    }

    /** „AC2XX” → „AC2”, „186X” → „186”; null = kandydat bez „X” w miejscu końcowych cyfr. */
    private static function wildcardPrefix(string $candidate): ?string
    {
        return preg_match('/^(\p{L}*\d+)X{1,3}$/u', $candidate, $m) === 1 ? $m[1] : null;
    }

    /** Kod tej samej długości co „AC2XX”, z tym samym początkiem i cyframi w miejscu „X” (AC210, AC225). */
    private static function matchesWildcard(string $code, string $candidate, string $prefix): bool
    {
        return mb_strlen($code) === mb_strlen($candidate)
            && str_starts_with($code, $prefix)
            && ctype_digit(mb_substr($code, mb_strlen($prefix)));
    }

    /** Litery początku kodu do pierwszej cyfry: WPAF1 → WPAF, AC405 → AC, 9936 → ''. */
    private static function series(string $compact): string
    {
        return preg_match('/^\p{L}+/u', $compact, $m) === 1 ? $m[0] : '';
    }

    /**
     * Powód odrzucenia materiału marketingowego / ogólnego; null = to może być plik wyrobu.
     */
    public static function marketingReason(string $title, string $url): ?string
    {
        foreach ([$title, self::fileName($url)] as $text) {
            if (preg_match(self::MARKETING, $text, $m) === 1) {
                return 'materiał marketingowy 3M („'.$m[0].'”)';
            }
        }

        return null;
    }

    /**
     * Kody karty: wartości podane wprost (numer katalogowy, magazynowy, poprzedni, EAN) i kody z nazwy pozycji
     * (bez miar i numerów norm). Do rozpoznania „to plik tej karty” — może być szeroko.
     *
     * @param  list<string>  $numbers
     * @param  list<string>  $names
     * @return list<string>
     */
    public static function cardCodes(array $numbers, array $names): array
    {
        $codes = $numbers;
        foreach ($names as $name) {
            preg_match_all(self::NAME_CODE, (string) preg_replace([self::NORM, self::MEASURE], ' ', $name), $m);
            foreach ($m[0] as $token) {
                if (preg_match('/\d/', $token) !== 1) {
                    continue;
                }
                $codes[] = $token;
                // „2003397/21.042.00” — dwa numery tej samej części (nowy i stary)
                if (str_contains($token, '/')) {
                    array_push($codes, ...explode('/', $token));
                }
            }
        }

        return self::distinct($codes);
    }

    /**
     * Kody pozycji do listy całego konta — wąsko, żeby słowo z tytułu nie udawało cudzej pozycji: numer katalogowy
     * i kod z końca nazwy („…, AC230”, „…, G3001DUV1000V-VI”, „…, 1033128/FF-600-44”), o ile ma cyfrę.
     *
     * @return list<string>
     */
    public static function accountCodes(string $catalog, string $name): array
    {
        $codes = [$catalog];
        $parts = explode(',', $name);
        $last = trim((string) end($parts));
        if ($last !== '' && preg_match('/\d/', $last) === 1 && preg_match('/^[\p{L}\p{N}.\/-]+$/u', $last) === 1) {
            $codes[] = $last;
            if (str_contains($last, '/')) {
                array_push($codes, ...explode('/', $last));
            }
        }

        return self::distinct($codes);
    }

    /**
     * Kandydaci na kod w tytule albo nazwie pliku: słowa (litery i cyfry) i do trzech sąsiednich słów sklejonych,
     * bez separatorów, wielkimi literami — „3m-peltor-throat-microphone-mt9-02.pdf” daje m.in. „MT902”. Złożenia
     * muszą mieć cyfrę; pojedyncze słowo z samych liter idzie dalej jako możliwa nazwa serii („WPAF”).
     *
     * @return list<string>
     */
    private static function candidates(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtoupper($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];
        $count = count($words);
        for ($i = 0; $i < $count; $i++) {
            $joined = '';
            for ($j = $i; $j < min($count, $i + self::MAX_JOINED_TOKENS); $j++) {
                $joined .= $words[$j];
                if (mb_strlen($joined) >= self::MIN_CODE_LENGTH && ($j === $i || preg_match('/\d/', $joined) === 1)) {
                    $out[$joined] = true;
                }
            }
        }

        // klucz „9936” PHP zamienia na liczbę — kandydaci wracają jako tekst
        return array_map(strval(...), array_keys($out));
    }

    /**
     * @param  list<string>  $codes
     * @return list<string>
     */
    private static function distinct(array $codes): array
    {
        $out = [];
        foreach ($codes as $code) {
            $code = trim($code);
            $key = self::compact($code);
            if (mb_strlen($key) >= self::MIN_CODE_LENGTH) {
                $out[$key] ??= $code;
            }
        }

        return array_values($out);
    }

    private static function fileName(string $url): string
    {
        return rawurldecode(basename((string) parse_url($url, PHP_URL_PATH)));
    }

    /** Kod bez separatorów, wielkimi literami — klucz porównania. */
    private static function compact(string $code): string
    {
        return mb_strtoupper((string) preg_replace('/[^\p{L}\p{N}]+/u', '', $code));
    }
}

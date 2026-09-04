<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Ai\AiSettingsService;

/**
 * Żargon SIWZ (wampirki, gumiaki…) → frazy jak w cenniku, z filtrem kategorii.
 *
 * @phpstan-type SlangEntry array{category: string, terms: list<string>, phrases: list<string>, note: string, jargon: bool, keywords: list<string>, tags: list<string>}
 */
final class CatalogSlangDictionary
{
    public const MAX_ENTRIES = 600;

    public const MAX_TERMS = 12;

    public const MAX_PHRASES = 12;

    public const MAX_TERM_LEN = 80;

    public const MAX_PHRASE_LEN = 120;

    public const MAX_NOTE_LEN = 200;

    public const MAX_KEYWORDS = 12;

    public const MAX_TAGS = 8;

    /** @var list<string> */
    public const CATEGORIES = [
        'rece',
        'glowa',
        'oczy',
        'twarz',
        'spawanie',
        'sluch',
        'oddech',
        'chemia',
        'odziez',
        'ratownictwo',
        'wysokosc',
        'stopy',
        'cialo',
        'promieniowanie',
        'higiena',
        'esd',
        'pierwsza_pomoc',
    ];

    /** @var array<string, string> */
    public const CATEGORY_LABELS = [
        'rece' => 'Ręce',
        'glowa' => 'Głowa',
        'oczy' => 'Oczy',
        'twarz' => 'Twarz',
        'spawanie' => 'Spawanie',
        'sluch' => 'Słuch',
        'oddech' => 'Oddech',
        'chemia' => 'Chemia',
        'odziez' => 'Odzież',
        'ratownictwo' => 'Ratownictwo',
        'wysokosc' => 'Praca na wysokości',
        'stopy' => 'Stopy / nogi',
        'cialo' => 'Ochrona ciała',
        'promieniowanie' => 'Promieniowanie',
        'higiena' => 'Higiena',
        'esd' => 'ESD',
        'pierwsza_pomoc' => 'Pierwsza pomoc',
    ];

    /** @var list<SlangEntry>|null */
    private ?array $entriesCache = null;

    /** @var array<string, array{needed: string, search_phrases: list<string>, family: string|null}|null> */
    private array $rewriteCache = [];

    public function __construct(
        private readonly AiSettingsService $settings,
        private readonly PpeAssortment $assortment,
    ) {}

    /**
     * @return list<SlangEntry>
     */
    public function entries(): array
    {
        return $this->entriesCache ??= $this->settings->catalogSlang();
    }

    /**
     * @return list<string>
     */
    public function phrasesFor(string $query): array
    {
        $out = [];
        foreach ($this->matchingEntries($query) as $entry) {
            foreach ([...$entry['phrases'], ...($entry['keywords'] ?? [])] as $phrase) {
                $out[] = $phrase;
            }
        }

        return array_values(array_unique($out));
    }

    public function familyFor(string $query): ?string
    {
        $rewrite = $this->searchRewrite($query);

        return $rewrite['family'] ?? null;
    }

    /**
     * Trafienie w żargon: szukaj po notatce i frazach cennika, nie po dosłownym żargonie.
     *
     * @return array{needed: string, search_phrases: list<string>, family: string|null}|null
     */
    public function searchRewrite(string $query): ?array
    {
        if (array_key_exists($query, $this->rewriteCache)) {
            return $this->rewriteCache[$query];
        }
        $entries = $this->matchingEntries($query);
        if ($entries === []) {
            return $this->rewriteCache[$query] = null;
        }
        $phrases = [];
        $notes = [];
        $family = null;
        foreach ($entries as $entry) {
            foreach ([...$entry['phrases'], ...($entry['keywords'] ?? [])] as $phrase) {
                $phrase = trim((string) $phrase);
                if ($phrase !== '') {
                    $phrases[] = $phrase;
                }
            }
            $note = trim((string) ($entry['note'] ?? ''));
            if ($note !== '' && ! str_contains($note, '=')) {
                $notes[] = $note;
            }
            if ($family === null) {
                $family = self::categoryFamily($entry['category']);
            }
        }
        $phrases = array_values(array_unique($phrases));
        $needed = $phrases[0] ?? '';
        if ($notes !== []) {
            $needed = $this->neededFromPhraseAndNote($needed, $notes[0]);
            $tail = $this->noteSearchTail($notes[0], $phrases);
            if ($tail !== '') {
                $phrases[] = $tail;
            }
        }
        if ($needed === '') {
            return $this->rewriteCache[$query] = null;
        }

        return $this->rewriteCache[$query] = [
            'needed' => $needed,
            'search_phrases' => array_values(array_unique($phrases)),
            'family' => $family,
        ];
    }

    public function rejectsProduct(string $query, string $productText): bool
    {
        $rewrite = $this->searchRewrite($query);
        if ($rewrite === null) {
            return false;
        }
        $note = $this->fold($rewrite['needed']);
        $hay = $this->fold($productText);
        $wantsLiquid = preg_match('/\b(ciecz|olej|plyn)/u', $note) === 1;
        $vampire = preg_match('/dziani/', $note) === 1
            && preg_match('/(powlek|nakrap|dlon)/', $note) === 1;
        if (! $wantsLiquid && ! $vampire) {
            return false;
        }
        if (preg_match('/\b(esd|antystat|antyelektro|weglow|miedzian|miedzi)\w*/u', $hay) === 1) {
            return true;
        }
        if (preg_match('/(calkowicie powlek|powlekan\w* w calosc|w calosci powlek|pelne powlec)/u', $hay) === 1) {
            return true;
        }
        if (preg_match('/(dlugim mankiet|dlugi mankiet)/u', $hay) === 1) {
            return true;
        }
        if ($wantsLiquid || $vampire) {
            if (preg_match('/(nieflokowan|unflocked|jednorazow|bezpudrow|examinat|powder\s*free)/u', $hay) === 1) {
                return true;
            }
            if (preg_match('/\b(cuffs|manchon)\b|primacuff|(naramiennik|zarekawk)/u', $hay) === 1) {
                return true;
            }
            if (preg_match('/\b(cold|zimow|0\s*c)\b/u', $hay) === 1) {
                return true;
            }
            if (preg_match('/(cut[\s-]*resist|cut[\s-]*protect|antyprzeciec|przecieci)/u', $hay) === 1) {
                return true;
            }
        }
        if (preg_match('/(arc flash|para-?aramid|para aramid|wlokna szklan|modakryl|piank\w* neopren)/u', $hay) === 1) {
            return true;
        }
        $fingertip = preg_match('/(konce palc|palce powlekan)/u', $hay) === 1
            && preg_match('/\bdlon\b/u', $hay) !== 1;

        return $fingertip;
    }

    /**
     * Rdzenie z notatki/fraz — karta bez któregoś z nich nie jest tym żargonem.
     *
     * @return list<string>
     */
    public function evidenceNeedles(string $query): array
    {
        $rewrite = $this->searchRewrite($query);
        if ($rewrite === null) {
            return [];
        }
        $stop = [
            'proste', 'ochrona', 'przed', 'oraz', 'dla', 'przy', 'bez', 'jak', 'lub',
            'typ', 'rodzaj', 'produkt', 'pracy', 'do', 'na', 'od', 'ze', 'za',
        ];
        $out = [];
        foreach ($rewrite['search_phrases'] as $phrase) {
            foreach (preg_split('/\s+/u', $this->fold($phrase)) ?: [] as $token) {
                if ($token === '' || mb_strlen($token) < 5 || in_array($token, $stop, true)) {
                    continue;
                }
                if (preg_match(
                    '/^(rekawic|glove|spodn|kurtk|bluz|czapk|buty|obuwie|ochronn|robocz)/u',
                    $token
                ) === 1) {
                    continue;
                }
                $out[] = mb_substr($token, 0, 5);
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Grupy AND: karta musi trafić w każdą (wewnątrz grupy wystarczy jedno słowo).
     *
     * @return list<list<string>>
     */
    public function evidenceGroups(string $query): array
    {
        $knit = [];
        $coat = [];
        $other = [];
        foreach ($this->evidenceNeedles($query) as $needle) {
            $needle = trim($needle);
            if ($needle === '' || preg_match('/^(ciecz|olej|plyn)/u', $needle) === 1) {
                continue;
            }
            if (preg_match('/^(dzian|knit)/u', $needle) === 1) {
                $knit[] = $needle;
                continue;
            }
            if (preg_match('/^(powle|nakrap|nakrop|kropk)/u', $needle) === 1) {
                $coat[] = $needle;
                continue;
            }
            $other[] = $needle;
        }
        if ($knit !== []) {
            $knit[] = 'knit';
            $knit[] = 'dzian';
        }
        if ($knit !== [] || $coat !== []) {
            array_push($coat, 'powlek', 'nakrap', 'nakrop', 'kropk', 'coat', 'dotted');
        }
        $groups = [];
        foreach ([$knit, $coat, $other] as $group) {
            $group = array_values(array_unique(array_filter($group)));
            if ($group !== []) {
                $groups[] = $group;
            }
        }

        return $groups;
    }

    public function matchesEvidence(string $query, string $productText): bool
    {
        if ($this->rejectsProduct($query, $productText)) {
            return false;
        }
        $groups = $this->evidenceGroups($query);
        if ($groups === []) {
            return true;
        }
        $hay = $this->fold($productText);
        foreach ($groups as $group) {
            $hit = false;
            foreach ($group as $needle) {
                if ($needle !== '' && str_contains($hay, $needle)) {
                    $hit = true;
                    break;
                }
            }
            if (! $hit) {
                return false;
            }
        }

        return true;
    }

    public function requiresTightEvidence(string $query): bool
    {
        $hasKnit = false;
        $hasCoat = false;
        foreach ($this->evidenceGroups($query) as $group) {
            foreach ($group as $needle) {
                if (preg_match('/^(dzian|knit)/u', $needle) === 1) {
                    $hasKnit = true;
                }
                if (preg_match('/^(powle|nakrap|nakrop|kropk|coat|dotted)/u', $needle) === 1) {
                    $hasCoat = true;
                }
            }
        }

        return $hasKnit && $hasCoat;
    }

    public function isJargonNorm(string $normalizedToken): bool
    {
        $token = trim($normalizedToken);
        if ($token === '' || mb_strlen($token) < 4) {
            return false;
        }
        foreach ($this->entries() as $entry) {
            foreach ($entry['terms'] as $term) {
                if ($this->fold($term) === $token) {
                    return true;
                }
            }
        }

        return false;
    }

    public function promptHint(string $query): string
    {
        $lines = [];
        foreach ($this->matchingEntries($query) as $entry) {
            $terms = implode(', ', $entry['terms']);
            $phrases = implode('; ', $entry['phrases']);
            if ($terms === '' || $phrases === '') {
                continue;
            }
            $note = trim((string) ($entry['note'] ?? ''));
            $lines[] = $note !== ''
                ? $terms.' → '.$note.' ('.$phrases.')'
                : $terms.' → '.$phrases;
            if (count($lines) >= 12) {
                break;
            }
        }
        if ($lines === []) {
            return '';
        }

        return 'Żargon SIWZ — szukaj po notatce i frazach cennika, nie po żargonie ani po słowach typu uniwersalne: '
            .implode(' | ', $lines).'.';
    }

    /** Blok do doklejenia do tekstu użytkownika — nie zastępuje wymagania. */
    public function queryAppendix(string $query): string
    {
        $hint = $this->promptHint($query);
        if ($hint === '') {
            return '';
        }

        return "Żargon SIWZ (dodatek do wymagania, nie zastępuje tekstu):\n".$hint;
    }

    /**
     * @return list<SlangEntry>
     */
    public static function defaults(): array
    {
        return self::normalize(config('ai.catalog_slang', []));
    }

    /**
     * @return list<SlangEntry>
     */
    public static function normalize(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $terms = self::stringList($row['terms'] ?? null, self::MAX_TERMS, self::MAX_TERM_LEN);
            $phrases = self::stringList($row['phrases'] ?? null, self::MAX_PHRASES, self::MAX_PHRASE_LEN);
            if ($terms === [] || $phrases === []) {
                continue;
            }
            $category = self::normalizeCategory($row['category'] ?? null);
            $note = '';
            if (is_string($row['note'] ?? null)) {
                $note = mb_substr(trim($row['note']), 0, self::MAX_NOTE_LEN);
            }
            $tags = self::stringList($row['tags'] ?? null, self::MAX_TAGS, self::MAX_TERM_LEN);
            if ($tags === []) {
                $label = self::CATEGORY_LABELS[$category] ?? $category;
                $tags = array_values(array_filter(['BHP', $label]));
            }
            $out[] = [
                'category' => $category,
                'terms' => $terms,
                'phrases' => $phrases,
                'note' => $note,
                'jargon' => true,
                'keywords' => self::stringList($row['keywords'] ?? null, self::MAX_KEYWORDS, self::MAX_PHRASE_LEN),
                'tags' => $tags,
            ];
            if (count($out) >= self::MAX_ENTRIES) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return list<SlangEntry>
     */
    private function matchingEntries(string $query): array
    {
        $hay = ' '.$this->fold($query).' ';
        $contextCats = $this->contextCategories($query);
        $termCats = $this->termCategoryIndex();
        $out = [];
        foreach ($this->entries() as $entry) {
            $hitTerms = $this->hitTerms($hay, $entry['terms']);
            if ($hitTerms === []) {
                continue;
            }
            if ($contextCats !== []) {
                if (in_array($entry['category'], $contextCats, true)) {
                    $out[] = $entry;
                }

                continue;
            }
            foreach ($hitTerms as $term) {
                $cats = $termCats[$term] ?? [];
                if (count($cats) === 1 && ($cats[0] ?? '') === $entry['category']) {
                    $out[] = $entry;
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function contextCategories(string $query): array
    {
        $norm = $this->fold($query);
        $cats = self::familyCategories($this->assortment->family($query));
        if (preg_match('/\bspawal/u', $norm) === 1) {
            $cats[] = 'spawanie';
        }
        if (preg_match('/\b(chemiczn|kwasow|tyvek|sorbent)/u', $norm) === 1) {
            $cats[] = 'chemia';
        }
        if (preg_match('/\b(esd|antystat|antyelektrostat)/u', $norm) === 1) {
            $cats[] = 'esd';
        }
        if (preg_match('/\b(apteczk|defibryl|rko|oczomyj|eyewash|nrc)\b/u', $norm) === 1) {
            $cats[] = 'pierwsza_pomoc';
        }

        return array_values(array_unique($cats));
    }

    /**
     * @return array<string, list<string>>
     */
    private function termCategoryIndex(): array
    {
        $index = [];
        foreach ($this->entries() as $entry) {
            foreach ($entry['terms'] as $term) {
                $n = $this->fold($term);
                if ($n === '') {
                    continue;
                }
                if (! isset($index[$n])) {
                    $index[$n] = [];
                }
                if (! in_array($entry['category'], $index[$n], true)) {
                    $index[$n][] = $entry['category'];
                }
            }
        }

        return $index;
    }

    /**
     * @param  list<string>  $terms
     * @return list<string>
     */
    private function hitTerms(string $paddedHay, array $terms): array
    {
        $hit = [];
        $tokens = preg_split('/\s+/u', trim($paddedHay)) ?: [];
        foreach ($terms as $term) {
            $n = $this->fold($term);
            if ($n === '') {
                continue;
            }
            if (str_contains($paddedHay, ' '.$n.' ')) {
                $hit[] = $n;
                continue;
            }
            if (str_contains($n, ' ')) {
                continue;
            }
            foreach ($tokens as $qt) {
                if (mb_strlen($qt) < 5) {
                    continue;
                }
                if (str_starts_with($n, $qt)) {
                    $hit[] = $n;
                    break;
                }
            }
        }

        return $hit;
    }

    private function neededFromPhraseAndNote(string $phrase, string $note): string
    {
        $tail = $this->noteSearchTail($note, $phrase !== '' ? [$phrase] : []);
        if ($phrase === '') {
            return $note;
        }
        if ($tail === '') {
            return $phrase;
        }

        return $phrase.', '.$tail;
    }

    /**
     * @param  list<string>  $phrases
     */
    private function noteSearchTail(string $note, array $phrases): string
    {
        $have = $this->fold(implode(' ', $phrases));
        $skip = ['proste', 'oraz', 'dla', 'jak', 'lub', 'typ', 'np'];
        $kept = [];
        foreach (preg_split('/\s+/u', $note) ?: [] as $word) {
            $word = trim($word, " \t\n\r.,;:()[]");
            $n = $this->fold($word);
            if ($n === '' || in_array($n, $skip, true)) {
                continue;
            }
            if ($have !== '' && str_contains($have, $n)) {
                continue;
            }
            $kept[] = $word;
        }

        return trim(implode(' ', $kept));
    }

    /**
     * @return list<string>
     */
    public static function familyCategories(?string $family): array
    {
        return match ($family) {
            PpeAssortment::FAMILY_GLOVES => ['rece'],
            PpeAssortment::FAMILY_HEAD => ['glowa'],
            PpeAssortment::FAMILY_EYES => ['oczy'],
            PpeAssortment::FAMILY_FACE => ['twarz', 'spawanie'],
            PpeAssortment::FAMILY_HEARING => ['sluch'],
            PpeAssortment::FAMILY_RESPIRATORY => ['oddech'],
            PpeAssortment::FAMILY_APPAREL => ['odziez', 'chemia', 'higiena', 'ratownictwo'],
            PpeAssortment::FAMILY_FOOTWEAR => ['stopy'],
            PpeAssortment::FAMILY_FALL => ['wysokosc', 'ratownictwo'],
            PpeAssortment::FAMILY_KNEE => ['cialo'],
            default => [],
        };
    }

    public static function categoryFamily(string $category): ?string
    {
        return match ($category) {
            'rece' => PpeAssortment::FAMILY_GLOVES,
            'glowa' => PpeAssortment::FAMILY_HEAD,
            'oczy' => PpeAssortment::FAMILY_EYES,
            'twarz', 'spawanie' => PpeAssortment::FAMILY_FACE,
            'sluch' => PpeAssortment::FAMILY_HEARING,
            'oddech' => PpeAssortment::FAMILY_RESPIRATORY,
            'odziez', 'chemia', 'higiena', 'ratownictwo' => PpeAssortment::FAMILY_APPAREL,
            'stopy' => PpeAssortment::FAMILY_FOOTWEAR,
            'wysokosc' => PpeAssortment::FAMILY_FALL,
            'cialo' => PpeAssortment::FAMILY_KNEE,
            default => null,
        };
    }

    public static function normalizeCategory(mixed $value): string
    {
        $cat = is_string($value) ? trim($value) : '';
        $cat = match (mb_strtolower($cat)) {
            'nogi', 'noga' => 'stopy',
            'drogi_oddechowe', 'drogi oddechowe' => 'oddech',
            default => $cat,
        };

        return in_array($cat, self::CATEGORIES, true) ? $cat : 'odziez';
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value, int $maxItems, int $maxLen): array
    {
        if (! is_array($value)) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                continue;
            }
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            $min = preg_match('/\d/u', $item) === 1 ? 2 : 3;
            if (mb_strlen($item) < $min) {
                continue;
            }
            $item = mb_substr($item, 0, $maxLen);
            $key = mb_strtolower($item);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $item;
            if (count($out) >= $maxItems) {
                break;
            }
        }

        return $out;
    }

    private function fold(string $text): string
    {
        $t = mb_strtolower($text);
        $map = ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z'];

        return trim((string) preg_replace('/[^a-z0-9]+/u', ' ', strtr($t, $map)));
    }
}

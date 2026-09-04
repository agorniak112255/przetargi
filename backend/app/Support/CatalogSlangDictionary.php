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

    public function __construct(
        private readonly AiSettingsService $settings,
        private readonly PpeAssortment $assortment,
    ) {}

    /**
     * @return list<SlangEntry>
     */
    public function entries(): array
    {
        return $this->settings->catalogSlang();
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

    public function isJargonNorm(string $normalizedToken): bool
    {
        $token = trim($normalizedToken);
        if ($token === '' || mb_strlen($token) < 4) {
            return false;
        }
        foreach ($this->entries() as $entry) {
            if (! $entry['jargon']) {
                continue;
            }
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
            $lines[] = $terms.' = '.$phrases;
            if (count($lines) >= 12) {
                break;
            }
        }
        if ($lines === []) {
            return '';
        }

        return 'Żargon SIWZ → frazy katalogu (nie szukaj dosłownego żargonu na karcie): '
            .implode(' | ', $lines).'.';
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
                'jargon' => (bool) ($row['jargon'] ?? false),
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
        foreach ($terms as $term) {
            $n = $this->fold($term);
            if ($n !== '' && str_contains($paddedHay, ' '.$n.' ')) {
                $hit[] = $n;
            }
        }

        return $hit;
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

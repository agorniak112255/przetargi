<?php

declare(strict_types=1);

namespace App\Support\RequirementCheck;

/**
 * Kategoria ŚOI (rozporządzenie 2016/425): „kat. II”, „kategorii III”, „Kategoria: III”, „Cat.III”, „II kategorii”.
 * Cyfry rzymskie tylko wielkimi literami — „kategorii i wymagań” to spójnik, nie kategoria I; „kategorii S1 P”
 * i „kategorii OB” to klasy obuwia. Najdłuższe dopasowanie: „kat. III” nie jest „kat. II”. Lista albo zakres
 * („Kategoria: I/II/III”, „kat. I–III”, „kategorii I, II i III”) to kilka kategorii naraz — `levels` podaje wszystkie
 * wymienione, `level` najniższą.
 */
final readonly class PpeCategory
{
    private const ROMAN = ['I' => 1, 'II' => 2, 'III' => 3];

    private const ONE = '(?:III|II|I)(?![\p{L}\d])';

    private const LIST = self::ONE.'(?:(?:\h*[\/,\-–—]\h*|\h+(?:i|oraz|lub|albo|do)\h+)'.self::ONE.')*';

    private const PATTERNS = [
        '/(?<![\p{L}\d])(?i:kat\.?|kategori\p{L}*|category|cat\.?)(?:\s+(?i:ochrony|śoi|soi|ppe))?\s*[:\-–]?\s*('.self::LIST.')/u',
        '/(?<![\p{L}\d])('.self::LIST.')\s+(?i:kat\.|kategori\p{L}*)/u',
    ];

    /**
     * @param  list<int>  $levels  wymienione kategorie rosnąco, bez powtórzeń
     */
    private function __construct(
        public int $level,
        public string $text,
        public array $levels,
    ) {}

    public static function first(string $text): ?self
    {
        return self::allIn($text)[0] ?? null;
    }

    /**
     * Kategorie w kolejności w tekście.
     *
     * @return list<self>
     */
    public static function allIn(string $text): array
    {
        $hits = [];
        foreach (self::PATTERNS as $pattern) {
            if (preg_match_all($pattern, $text, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) < 1) {
                continue;
            }
            foreach ($m as $hit) {
                preg_match_all('/'.self::ONE.'/u', $hit[1][0], $romans);
                $levels = array_values(array_unique(array_map(static fn (string $r): int => self::ROMAN[$r], $romans[0])));
                sort($levels);
                $hits[$hit[0][1]] = new self($levels[0], trim($hit[0][0]), $levels);
            }
        }
        ksort($hits);

        return array_values($hits);
    }

    public function roman(): string
    {
        return (string) array_search($this->level, self::ROMAN, true);
    }

    /** Karta wymienia kilka kategorii — nie wiadomo, która dotyczy produktu. */
    public function isList(): bool
    {
        return count($this->levels) > 1;
    }

    /** „I/II/III” */
    public function romans(): string
    {
        return implode('/', array_map(static fn (int $l): string => (string) array_search($l, self::ROMAN, true), $this->levels));
    }
}

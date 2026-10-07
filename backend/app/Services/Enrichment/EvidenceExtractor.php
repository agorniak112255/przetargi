<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

/**
 * Dowody wartości krytycznych opisu (etap 1 opisów z cenników) — liczone kodem, bez modelu. Dla wartości z list
 * opisu (normy, certyfikaty, materiały, parametry, atrybuty) szukamy jej dosłownie w zapisanych tekstach źródeł:
 * - oznaczenia norm i kody poziomów (reguły SourceClaims, jak sito norm w opisie);
 * - liczby z jednostką („9,5 mm” = „9.5 mm”, bez przeliczania „1,2 m” na „1200 mm” — to już wniosek);
 * - numer jednostki notyfikowanej (4 cyfry przy „jednostka notyfikowana” / „notified body” / „NB” / „CE”);
 * - kategoria i klasa (PL → EN/DE: „kategoria III” = „Category III” = „Kategorie III”), klasy obuwia (S3, O2…);
 * - materiał przez mały słownik PL → EN/DE (nitryl = nitrile = Nitril).
 *
 * explicit = wartość stoi w źródle (z cytatem ±100 znaków i sha256 tekstu), inferred = jest w opisie, a źródło jej
 * nie potwierdza. Braki per kategoria wyrobu (completeness) — etap 3; do tego czasu completeness = null.
 */
final class EvidenceExtractor
{
    private const QUOTE_RADIUS = 100;

    /** Listy opisu, z których bierzemy wartości krytyczne — cechy i zastosowania to proza, nie fakty do sprawdzenia. */
    private const FIELDS = ['norms', 'certificates', 'materials', 'specs'];

    /**
     * Materiał (klucz po polsku) => początki słów, które go oznaczają w źródle PL / EN / DE.
     *
     * @var array<string, list<string>>
     */
    private const MATERIALS = [
        'nitryl' => ['nitryl', 'nitril', 'nbr'],
        'lateks' => ['lateks', 'latex'],
        'neopren' => ['neopren', 'chloropren'],
        'poliuretan' => ['poliuretan', 'polyurethan', 'pu'],
        'pvc' => ['pvc', 'polichlorek winylu', 'polyvinyl', 'polyvinylchlorid'],
        'winyl' => ['winyl', 'vinyl'],
        'guma' => ['gum', 'rubber', 'gummi', 'kauczuk'],
        'skóra' => ['skór', 'leather', 'leder'],
        'bawełna' => ['bawełn', 'cotton', 'baumwoll'],
        'poliester' => ['poliester', 'polyester'],
        'poliamid' => ['poliamid', 'polyamid', 'nylon'],
        'pianka' => ['piank', 'foam', 'schaum'],
        'stal nierdzewna' => ['stal nierdzewn', 'stainless steel', 'edelstahl'],
        'aramid' => ['aramid', 'kevlar'],
        'hppe' => ['hppe', 'dyneema'],
        'wełna' => ['wełn', 'wool', 'wolle'],
        'polipropylen' => ['polipropylen', 'polypropylen'],
        'polietylen' => ['polietylen', 'polyethylen'],
    ];

    private const UNIT = '(mm|cm|m|kg|g|µm|μm|%|°C|kN|N|J|kV|V)';

    /**
     * @param  array<string, mixed>  $lists  listy opisu (payloadFromExtraction: features, norms, certificates, materials,
     *                                       use_cases, specs, attributes)
     * @param  list<array{sha256: string, text: string}>  $docs  teksty źródeł
     * @return array{entries: list<array{field: string, value: string, quote: ?string, source_sha256: ?string, status: 'explicit'|'inferred'}>, explicit: int, inferred: int, completeness: ?float}
     */
    public function extract(array $lists, array $docs): array
    {
        $sources = [];
        foreach ($docs as $doc) {
            $text = (string) ($doc['text'] ?? '');
            if ($text !== '') {
                $sources[] = [
                    'sha256' => (string) ($doc['sha256'] ?? hash('sha256', $text)),
                    'text' => $text,
                    'key' => SourceClaims::key($text),
                    'norms' => SourceClaims::designations($text),
                ];
            }
        }

        $entries = [];
        foreach ($this->items($lists) as [$field, $item]) {
            foreach ($this->valuesIn($field, $item) as $value) {
                $id = $value['kind'].'|'.mb_strtolower($value['value']);
                if (isset($entries[$id])) {
                    continue;
                }
                $found = $this->findInSources($value, $sources);
                $entries[$id] = [
                    'field' => $field,
                    'value' => $value['value'],
                    'quote' => $found['quote'] ?? null,
                    'source_sha256' => $found['sha256'] ?? null,
                    'status' => $found !== null ? 'explicit' : 'inferred',
                ];
            }
        }
        $entries = array_values($entries);
        $explicit = count(array_filter($entries, static fn (array $e): bool => $e['status'] === 'explicit'));

        return [
            'entries' => $entries,
            'explicit' => $explicit,
            'inferred' => count($entries) - $explicit,
            'completeness' => null,
        ];
    }

    /**
     * Pary (pole, tekst pozycji): listy FIELDS i atrybuty („attributes.material”).
     *
     * @param  array<string, mixed>  $lists
     * @return list<array{0: string, 1: string}>
     */
    private function items(array $lists): array
    {
        $out = [];
        foreach (self::FIELDS as $field) {
            foreach (is_array($lists[$field] ?? null) ? $lists[$field] : [] as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $out[] = [$field, $item];
                }
            }
        }
        foreach (is_array($lists['attributes'] ?? null) ? $lists['attributes'] : [] as $key => $value) {
            foreach (is_array($value) ? $value : [$value] as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $out[] = ['attributes.'.$key, $item];
                } elseif (is_int($item) || is_float($item)) {
                    $out[] = ['attributes.'.$key, (string) $item];
                }
            }
        }

        return $out;
    }

    /**
     * Wartości krytyczne w jednej pozycji opisu.
     *
     * @return list<array{kind: string, value: string, match?: string}>
     */
    private function valuesIn(string $field, string $item): array
    {
        $out = [];
        // Same cyfry („1200” z wymiaru) to poziom normy tylko w zdaniu o normie — jak sito norm w opisie.
        $normContext = preg_match('/\bEN\s*(?:ISO\s*)?(?:388|407|511|374|381|1149)\b|poziom|level/iu', $item) === 1;
        foreach (SourceClaims::claims($item) as $claim) {
            if (preg_match('/^(?:EN|ISO)/', $claim) === 1) {
                $out[] = ['kind' => 'norm', 'value' => $claim];
            } elseif ($normContext || preg_match('/^\d+$/', $claim) !== 1) {
                $out[] = ['kind' => 'level', 'value' => $claim];
            }
        }
        if (preg_match_all('/(?<![\p{L}\p{N}.,])(\d+(?:[.,]\d+)?)\s*'.self::UNIT.'(?![\p{L}\p{N}])/u', $item, $numbers, PREG_SET_ORDER)) {
            foreach ($numbers as $m) {
                $out[] = ['kind' => 'number', 'value' => str_replace(',', '.', $m[1]).' '.$m[2]];
            }
        }
        if (preg_match_all('/(?:jednostk\w*\s+notyfikowan\w*|notified\s+body|benannte\w*\s+stelle|\bNB\b)\D{0,20}?(?<!\d)(\d{4})(?!\d)/iu', $item, $bodies, PREG_SET_ORDER)) {
            foreach ($bodies as $m) {
                $out[] = ['kind' => 'notified_body', 'value' => 'NB '.$m[1]];
            }
        }
        if (preg_match_all('/(?<!\p{L})kat(?:egori\w*|\.)?\s*(I{1,3})(?![\p{L}\p{N}])/iu', $item, $categories, PREG_SET_ORDER)) {
            foreach ($categories as $m) {
                $out[] = ['kind' => 'category', 'value' => 'kategoria '.mb_strtoupper($m[1])];
            }
        }
        if (preg_match_all('/(?<!\p{L})klas\w*\s+(\d|I{1,3})(?![\p{L}\p{N}])/iu', $item, $classes, PREG_SET_ORDER)) {
            foreach ($classes as $m) {
                $out[] = ['kind' => 'class', 'value' => 'klasa '.mb_strtoupper($m[1])];
            }
        }
        if (preg_match_all('/(?<![\p{L}\p{N}])(S[1-7]L?P?S?|SB|O[1-7])(?![\p{L}\p{N}])/u', $item, $footwear)) {
            foreach ($footwear[1] as $class) {
                $out[] = ['kind' => 'footwear_class', 'value' => $class];
            }
        }
        if ($field === 'materials' || preg_match('/materia/i', $field) === 1) {
            $lower = mb_strtolower($item);
            foreach (self::MATERIALS as $material => $stems) {
                foreach ($stems as $stem) {
                    if (self::hasStem($lower, $stem)) {
                        $out[] = ['kind' => 'material', 'value' => $material];
                        break;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * Pierwsze źródło, które niesie wartość dosłownie, z cytatem.
     *
     * @param  array{kind: string, value: string}  $value
     * @param  list<array{sha256: string, text: string, key: string, norms: array<string, list<string>>}>  $sources
     * @return array{sha256: string, quote: ?string}|null
     */
    private function findInSources(array $value, array $sources): ?array
    {
        foreach ($sources as $source) {
            $pattern = $this->sourcePattern($value, $source);
            if ($pattern === null) {
                continue;
            }
            if (preg_match($pattern, $source['text'], $m, PREG_OFFSET_CAPTURE) === 1) {
                return ['sha256' => $source['sha256'], 'quote' => $this->quote($source['text'], (int) $m[0][1], strlen($m[0][0]))];
            }
        }

        return null;
    }

    /**
     * Wzorzec wartości w tekście źródła; null = to źródło jej nie ma (norma bez pokrycia wydania).
     *
     * @param  array{kind: string, value: string}  $value
     * @param  array{sha256: string, text: string, key: string, norms: array<string, list<string>>}  $source
     */
    private function sourcePattern(array $value, array $source): ?string
    {
        $v = $value['value'];

        return match ($value['kind']) {
            'norm' => preg_match('/(\d{3,5}(?:-\d+)*)/', $v, $core) === 1 && SourceClaims::designationSupported($v, $source['norms'])
                ? '/(?<![\p{L}\p{N}])(?:PN[\s-]*)?(?:EN|ISO|IEC)(?:[\s-]*(?:ISO|IEC))?[\s-]*'.preg_quote(explode('-', $core[1])[0], '/').'/iu'
                : null,
            'level' => str_contains($source['key'], $v)
                ? '/'.implode('\s*', array_map(static fn (string $c): string => preg_quote($c, '/'), mb_str_split($v))).'/iu'
                : null,
            'number' => (static function () use ($v): string {
                [$number, $unit] = explode(' ', $v, 2);
                $digits = str_replace('\.', '[.,]', preg_quote($number, '/'));

                return '/(?<![\p{N}.,])'.$digits.'\s*'.preg_quote($unit, '/').'(?![\p{L}\p{N}])/u';
            })(),
            'notified_body' => '/(?:notyfik|notif|benannt|organisme|\bNB\b|\bCE\b)\D{0,30}?(?<!\d)'.preg_quote(substr($v, 3), '/').'(?!\d)/iu',
            'category' => '/(?<!\p{L})(?:kat(?:egori\w*|\.)?|cat(?:egor\w*|\.)?)\s*'.preg_quote(substr($v, strlen('kategoria ')), '/').'(?![\p{L}\p{N}])/iu',
            'class' => '/(?<!\p{L})(?:klas\w*|class\w*)\s+'.preg_quote(substr($v, strlen('klasa ')), '/').'(?![\p{L}\p{N}])/iu',
            'footwear_class' => '/(?<![\p{L}\p{N}])'.preg_quote($v, '/').'(?![\p{L}\p{N}])/u',
            'material' => '/(?<!\p{L})(?:'.implode('|', array_map(static fn (string $s): string => self::stemPattern($s), self::MATERIALS[$v] ?? [$v])).')/iu',
            default => null,
        };
    }

    /** Okno ±QUOTE_RADIUS znaków wokół trafienia, białe znaki zwinięte. */
    private function quote(string $text, int $byteOffset, int $byteLength): string
    {
        $start = mb_strlen(substr($text, 0, $byteOffset));
        $length = mb_strlen(substr($text, $byteOffset, $byteLength));
        $from = max(0, $start - self::QUOTE_RADIUS);
        $window = mb_substr($text, $from, $start - $from + $length + self::QUOTE_RADIUS);

        return trim((string) preg_replace('/\s+/u', ' ', $window));
    }

    /** Słowo zaczyna się od rdzenia („nitrylowa” ← „nitryl”); krótki rdzeń („pu”) — tylko jako całe słowo. */
    private static function hasStem(string $lower, string $stem): bool
    {
        return preg_match('/(?<!\p{L})'.self::stemPattern($stem).'/u', $lower) === 1;
    }

    private static function stemPattern(string $stem): string
    {
        return preg_quote($stem, '/').(mb_strlen($stem) <= 3 ? '(?!\p{L})' : '');
    }
}

<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Support\ProductSizeVariant;

/**
 * Scalanie pozycji listy UVEX różniących się tylko rozmiarem (decyzja użytkownika 28.09.2026: rozmiary jednego wyrobu
 * w różnych cenach to jedna karta z tabelą rozmiarów; do 28.09.2026 — decyzja 15.09.2026 — rozmiar z inną ceną był
 * osobną kartą). Sklep ma każdy rozmiar jako osobny kod: „8430/2/39”, „1723808 … rozm. XS”, „60542/10 … Wet/10”,
 * „HA2023(M) … rozmiar 8 (M)”, „HECKEL 6273/3/36 …”.
 *
 * Reguła sprawdzona na pełnej liście konta (15.09.2026: 5750 pozycji → 1298 kart, 439 grup; wtedy z ceną w kluczu):
 * - rozmiar z nazwy („rozm. XS”, „rozmiar 10”, „r.38”, „size M”, końcowe „(L)”), inaczej z końcówki kodu po „/” lub „-”,
 *   gdy to rozmiar odzieży/obuwia/rękawic (ProductSizeVariant::looksLikeWearSize); bez rozmiaru — osobna karta;
 *   pozycja bez ceny (nieczytelna albo 0,00 PLN) też osobna karta — rozmiar grupy musi mieć cenę;
 * - klucz grupy: nazwa bez rozmiaru i bez własnego kodu (także jego końcówki, np. „6273/3/36” w nazwie Heckel)
 *   + jednostka (sprzedaż na sztuki i na opakowania to nie rozmiary jednej karty). Cena nie wchodzi do klucza od
 *   28.09.2026. Tylko równość nazw — literówka w nazwie daje osobną kartę, nigdy błędne scalenie;
 * - powtórzony rozmiar w grupie (dwa modele o tej samej nazwie, np. 6823/2 i 6824/2) — podział po rdzeniu kodu;
 *   nadal powtórzony albo kody bez wspólnego początku (najkrótszy kod minus 3 znaki) — każda pozycja osobno.
 *   Bez ceny w kluczu te zabezpieczenia rozdzielają też modele, które dawniej rozdzielała sama różnica ceny.
 */
final class UvexSizeGroups
{
    private const SIZE = '(\d{3}|\d{1,2}(?:[.,]5)?|[2-6]xl|xxxxl|xxxl|xxl|xl|xxs|xs|s|m|l)';

    /** Kolejność rozmiarów literowych w podsumowaniu. */
    private const LETTER_ORDER = ['XXS', 'XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL', 'XXXXL', '5XL', '6XL'];

    public function __construct(private readonly ProductSizeVariant $sizes = new ProductSizeVariant) {}

    /**
     * @template T of array{code: string, name: string, price_cents: int|null, unit: string}
     *
     * @param  list<T>  $rows  pozycje listy (z ceną w groszach; null = cena nieczytelna — pozycja nie jest scalana)
     * @return list<list<array{row: T, size: string|null}>> grupy w kolejności kodu pierwszej pozycji (sortowanie
     *                                                      naturalne), pozycje grupy wg kodu; grupa jednoelementowa = osobna karta
     */
    public function group(array $rows): array
    {
        $groups = [];
        $singles = [];
        foreach ($rows as $row) {
            $size = $row['price_cents'] !== null && $row['price_cents'] > 0 ? $this->sizeOf($row['name'], $row['code']) : null;
            if ($size === null) {
                $singles[] = [['row' => $row, 'size' => null]];

                continue;
            }
            // bez ceny w kluczu (decyzja użytkownika 28.09.2026) — rozmiary w różnych cenach to jedna karta
            $key = $this->nameKey($row['name'], $row['code'], $size).'|'.$row['unit'];
            $groups[$key][] = ['row' => $row, 'size' => $size];
        }

        $out = $singles;
        foreach ($groups as $members) {
            if (count($members) > 1 && $this->hasDuplicateSizes($members)) {
                $byStem = [];
                foreach ($members as $member) {
                    $byStem[$this->codeStem($member['row']['code'], $member['size'])][] = $member;
                }
                foreach ($byStem as $part) {
                    array_push($out, ...$this->accepted($part));
                }
            } else {
                array_push($out, ...$this->accepted($members));
            }
        }

        foreach ($out as &$group) {
            usort($group, static fn (array $a, array $b): int => strnatcasecmp($a['row']['code'], $b['row']['code']));
        }
        unset($group);
        usort($out, static fn (array $a, array $b): int => strnatcasecmp($a[0]['row']['code'], $b[0]['row']['code']));

        return $out;
    }

    /**
     * Rozmiar pozycji; expr = dopasowany fragment nazwy, stem = kod bez końcówki rozmiaru (tylko rozmiar z kodu),
     * raw = rozmiar dosłownie tak, jak stoi w nazwie albo kodzie (size to jego zapis ujednolicony do porównań).
     *
     * @return array{size: string, expr: string|null, stem: string|null, raw: string}|null
     */
    public function sizeOf(string $name, string $code): ?array
    {
        $code = trim($code);
        if (preg_match('~(?:\brozm(?:iar)?\.?|\bsize|(?:^|\s)r\.)\s*:?\s*'.self::SIZE.'(?![\w.,/])~iu', $name, $m) === 1) {
            return ['size' => self::sizeLabel($m[1]), 'expr' => $m[0], 'stem' => null, 'raw' => $m[1]];
        }
        if (preg_match('~\(\s*'.self::SIZE.'\s*\)\s*$~iu', $name, $m) === 1) {
            return ['size' => self::sizeLabel($m[1]), 'expr' => $m[0], 'stem' => null, 'raw' => $m[1]];
        }
        if (preg_match('~[/\-]'.self::SIZE.'$~iu', $code, $m) === 1 && $this->sizes->looksLikeWearSize($m[1])) {
            return ['size' => self::sizeLabel($m[1]), 'expr' => null, 'stem' => substr($code, 0, -strlen($m[0])), 'raw' => $m[1]];
        }

        return null;
    }

    /**
     * Nazwa karty grupy: nazwa pierwszej pozycji bez rozmiaru, a kod z rozmiarem w nazwie skrócony do rdzenia
     * („Półbuty ochronne uvex 1 business 8430/2/39” → „… 8430/2”). Pusty wynik — nazwa bez zmian.
     *
     * @param  array{size: string, expr: string|null, stem: string|null}  $size
     */
    public function groupName(string $name, string $code, array $size): string
    {
        $out = $name;
        if ($size['expr'] !== null) {
            $out = self::removeFirst($out, $size['expr']);
        }
        $out = (string) preg_replace('~\(\s*[a-z0-9]{1,4}\s*\)\s*$~iu', ' ', $out);
        if ($size['stem'] !== null) {
            $codeKey = self::alnum($code);
            $suffix = '~[/\-]'.preg_quote($size['size'], '~').'$~iu';
            $tokens = preg_split('/\s+/u', trim($out)) ?: [];
            foreach ($tokens as $i => $token) {
                $key = self::alnum($token);
                if (strlen($key) >= 4 && str_ends_with($codeKey, $key) && preg_match($suffix, $token) === 1) {
                    $tokens[$i] = (string) preg_replace($suffix, '', $token);
                }
            }
            $out = (string) preg_replace('~[/\-\s]'.preg_quote($size['size'], '~').'\s*$~iu', ' ', implode(' ', $tokens));
        }
        $out = trim((string) preg_replace('/\s+/u', ' ', $out), " \t,;-");

        return $out !== '' ? $out : $name;
    }

    /**
     * Kod modelu każdej grupy (decyzja użytkownika 30.09.2026: kod karty z rozmiarami bez rozmiaru — „6931/2”, nie
     * „6931/2/35”; „NB60SZ”, nie „NB60SZ/9”; „60023”, nie „6002306”; „HA2023”, nie „HA2023(L)”) — modelCode() kodów
     * pozycji grupy. Null (karta zostaje przy kodzie pierwszej pozycji, jak dotąd): pozycja pojedyncza, kody bez
     * wspólnego kodu modelu, kod modelu będący kodem pozycji listy albo kodem modelu innej grupy — dwie karty nie mogą
     * dostać tego samego kodu (products.sku jest unikalne).
     *
     * @param  list<list<array{row: array{code: string}}>>  $groups  wynik group()
     * @return list<string|null> kod modelu grupy o tym samym indeksie
     */
    public function modelCodes(array $groups): array
    {
        $codes = [];
        $stems = [];
        foreach ($groups as $index => $group) {
            foreach ($group as $member) {
                $codes[mb_strtolower(trim($member['row']['code']))] = true;
            }
            $stems[$index] = count($group) > 1 ? $this->modelCode(array_map(static fn (array $m): string => (string) $m['row']['code'], $group)) : null;
        }
        $uses = array_count_values(array_map('mb_strtolower', array_filter($stems, static fn (?string $s): bool => $s !== null)));

        return array_map(
            static fn (?string $stem): ?string => $stem !== null && $uses[mb_strtolower($stem)] === 1 && ! isset($codes[mb_strtolower($stem)]) ? $stem : null,
            array_values($stems),
        );
    }

    /**
     * Wspólny kod modelu kodów rozmiarów jednego wyrobu albo null. Trzy zapisy UVEX (lista konta 30.09.2026), każdy
     * wymaga, żeby WSZYSTKIE kody miały ten sam zapis i ten sam rdzeń:
     * - rozmiar na końcu po „/” albo „-” (sizeOf): „6931/2/35” → „6931/2”, „NB60SZ/9” → „NB60SZ”;
     * - rozmiar w nawiasie na końcu (HexArmor): „HA2023(L)”, „HA3013IMP (L)” → „HA2023”, „HA3013IMP”;
     * - dwie ostatnie cyfry (gwiazdka po nich pomijana): „6002306” → „60023”, „6003006*” → „60030”,
     *   „89880.09” → „89880” (kropka na końcu rdzenia odpada).
     * Kod jest kodem rozmiaru tylko wtedy, gdy wołający wie, że to rozmiary (grupa rozmiarów łącznika, pozycje karty
     * z rozmiarem w nazwie) — sam kod „2600.010” / „2600.011” (kolory) też ma dwie ostatnie cyfry.
     *
     * @param  list<string>  $codes
     */
    public function modelCode(array $codes): ?string
    {
        $codes = array_values(array_unique(array_map('trim', $codes)));
        if (count($codes) < 2) {
            return null;
        }
        $rules = [
            fn (string $code): string => trim((string) ($this->sizeOf('', $code)['stem'] ?? '')),
            static fn (string $code): string => preg_match('~^(.*?\S)\s*\(\s*'.self::SIZE.'\s*\)$~iu', $code, $m) === 1 ? $m[1] : '',
            static fn (string $code): string => preg_match('~^(.{3,})\d{2}\*?$~', $code, $m) === 1 ? rtrim($m[1], '.-/ ') : '',
        ];
        foreach ($rules as $rule) {
            $stems = array_unique(array_map($rule, $codes));
            if (count($stems) === 1 && strlen($stems[0]) >= 3) {
                return $stems[0];
            }
        }

        return null;
    }

    /** Porządek rozmiarów: liczbowe rosnąco, potem literowe XXS…6XL, reszta alfabetycznie. */
    public static function compareSizes(string $a, string $b): int
    {
        return self::sizeRank($a) <=> self::sizeRank($b) ?: strnatcasecmp($a, $b);
    }

    /**
     * @param  array{size: string, expr: string|null, stem: string|null}  $size
     */
    private function nameKey(string $name, string $code, array $size): string
    {
        $out = $name;
        if ($size['expr'] !== null) {
            $out = self::removeFirst($out, $size['expr']);
        }
        $out = (string) preg_replace('~\(\s*[a-z0-9]{1,4}\s*\)\s*$~iu', ' ', $out);
        $codeKey = self::alnum($code);
        $tokens = array_filter(
            preg_split('/\s+/u', trim($out)) ?: [],
            static function (string $token) use ($codeKey): bool {
                $key = self::alnum($token);

                return ! (strlen($key) >= 4 && str_ends_with($codeKey, $key));
            },
        );
        $out = implode(' ', $tokens);
        if ($size['stem'] !== null) {
            $out = (string) preg_replace('~[/\-\s]'.preg_quote($size['size'], '~').'\s*$~iu', ' ', $out);
        }

        return trim((string) preg_replace('/[\s,;]+/u', ' ', mb_strtolower($out)));
    }

    /**
     * @param  list<array{row: array{code: string}, size: array{size: string}}>  $members
     */
    private function hasDuplicateSizes(array $members): bool
    {
        $labels = array_map(static fn (array $m): string => $m['size']['size'], $members);

        return count(array_unique($labels)) !== count($labels);
    }

    /**
     * Grupa po kontroli: poprawna — jedna karta; powtórzony rozmiar albo kody bez wspólnego początku — pozycje osobno.
     *
     * @param  list<array{row: array{code: string}, size: array{size: string, expr: string|null, stem: string|null}}>  $members
     * @return list<list<array{row: array{code: string}, size: array{size: string, expr: string|null, stem: string|null}}>>
     */
    private function accepted(array $members): array
    {
        if (count($members) === 1) {
            return [$members];
        }
        $codes = array_map(static fn (array $m): string => trim($m['row']['code']), $members);
        $minLength = min(array_map('strlen', $codes));
        if ($this->hasDuplicateSizes($members) || self::commonPrefixLength($codes) < $minLength - 3) {
            return array_map(static fn (array $m): array => [$m], $members);
        }

        return [$members];
    }

    /**
     * @param  array{size: string, expr: string|null, stem: string|null}  $size
     */
    private function codeStem(string $code, array $size): string
    {
        if ($size['stem'] !== null) {
            return $size['stem'];
        }
        $stem = (string) preg_replace('~\(\s*[a-z0-9]{1,4}\s*\)$~i', '', trim($code));
        $stem = rtrim($stem, '/*');

        return (string) preg_replace('~^(.{3,}?)\d{2}$~', '$1', $stem);
    }

    /**
     * @param  list<string>  $codes
     */
    private static function commonPrefixLength(array $codes): int
    {
        $prefix = $codes[0];
        foreach ($codes as $code) {
            while ($prefix !== '' && ! str_starts_with($code, $prefix)) {
                $prefix = substr($prefix, 0, -1);
            }
        }

        return strlen($prefix);
    }

    private static function sizeLabel(string $raw): string
    {
        return str_replace(',', '.', mb_strtoupper(trim($raw)));
    }

    /**
     * @return array{0: int, 1: float}
     */
    private static function sizeRank(string $size): array
    {
        if (is_numeric($size)) {
            return [0, (float) $size];
        }
        $label = match ($size) {
            '2XL' => 'XXL',
            '3XL' => 'XXXL',
            '4XL' => 'XXXXL',
            default => $size,
        };
        $index = array_search($label, self::LETTER_ORDER, true);

        return $index !== false ? [1, (float) $index] : [2, 0.0];
    }

    private static function removeFirst(string $haystack, string $needle): string
    {
        $pos = mb_strpos($haystack, $needle);

        return $pos === false ? $haystack : mb_substr($haystack, 0, $pos).' '.mb_substr($haystack, $pos + mb_strlen($needle));
    }

    private static function alnum(string $text): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower($text));
    }
}

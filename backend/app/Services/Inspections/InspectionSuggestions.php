<?php

declare(strict_types=1);

namespace App\Services\Inspections;

use App\Models\InspectionPosition;
use App\Support\PolishTime;
use Illuminate\Support\Facades\DB;

/**
 * Podpowiedzi pozycji przeglądów z wzorca: człowiek dodał np. usługę „PRZEGLĄD GAŚNICY PROSZKOWEJ GP-6” (wzorzec),
 * a w katalogu XL są „… GP-2”, „… GP-4” — to tylko PROPOZYCJE na podstawie podobnej nazwy; człowiek je zatwierdza albo
 * odrzuca (odrzucenie trwałe dla wzorca). Interwał kandydata = interwał wzorca, też do zmiany przed zatwierdzeniem.
 *
 * Reguły:
 * - token modelu: 1–4 litery (polskie też), opcjonalnie „-” albo spacja, liczba (całkowita albo z przecinkiem /
 *   kropką), przyrostek 0–2 liter; granice słowa. Klucz = litery + liczba (bez przyrostka): GP-6X i GP-6 to ten sam
 *   klucz, a AP-25 ≠ AP-250, GP-6 ≠ GP-60;
 * - rdzeń: pozostałe słowa nazwy (po wycięciu tokenów) z co najmniej 3 literami, bez cyfr, porównywane wielkimi
 *   literami bez polskich znaków;
 * - kandydat wzorca W: pozycja katalogu tego samego rodzaju (usługa / towar), aktywna w XL, jeszcze nie na liście
 *   przeglądów, nieodrzucona dla W, z tokenem o tych samych literach co pierwszy token W i innej liczbie, zawierająca
 *   wszystkie słowa rdzenia W;
 * - wzorcem jest pozycja aktywna dodana ręcznie (source = manual) z tokenem i niepustym rdzeniem — bez rdzenia każda
 *   nazwa z „GP-…” byłaby kandydatem; pozycja z przyjętej podpowiedzi nie jest wzorcem (te same propozycje wracałyby
 *   pod kolejnymi wzorcami). Kandydat pasujący do kilku wzorców — pod pierwszym (najstarszym) z nich;
 * - usługa odnawiająca kandydata-towaru: gdy W ma usługę odnawiającą S — aktywna usługa z tokenem o literach tokenu S
 *   i liczbie tokenu kandydata, zawierająca rdzeń S; brak albo więcej niż jedna — null (nie zgadujemy).
 *
 * Katalog (~40 tys. nazw) czytany kursorem po kolumnach, bez modeli Eloquent; w pamięci zostają tylko nazwy z tokenem
 * o literach, których szukamy.
 */
final class InspectionSuggestions
{
    private const TOKEN = '/(?<![\p{L}\p{N}])(\p{L}{1,4})[- ]?(\d+(?:[.,]\d+)?)(\p{L}{0,2})(?![\p{L}\p{N}])/u';

    private const PL = ['Ą' => 'A', 'Ć' => 'C', 'Ę' => 'E', 'Ł' => 'L', 'Ń' => 'N', 'Ó' => 'O', 'Ś' => 'S', 'Ź' => 'Z', 'Ż' => 'Z'];

    public function __construct(private readonly InspectionQuery $query) {}

    /**
     * Tokeny modelu w nazwie, w kolejności wystąpienia.
     *
     * @return list<array{letters: string, number: string, key: string, label: string}>
     */
    public static function tokens(string $name): array
    {
        if (preg_match_all(self::TOKEN, $name, $m, PREG_SET_ORDER) === false) {
            return [];
        }
        $out = [];
        foreach ($m as $match) {
            $letters = self::normalize($match[1]);
            $number = self::number($match[2]);
            $out[] = [
                'letters' => $letters,
                'number' => $number,
                'key' => $letters.$number,
                'label' => mb_strtoupper($match[1]).'-'.str_replace('.', ',', $number),
            ];
        }

        return $out;
    }

    /**
     * Rdzeń nazwy: słowa po wycięciu tokenów, co najmniej 3 litery, bez cyfr — wielkie litery bez polskich znaków.
     *
     * @return list<string>
     */
    public static function core(string $name): array
    {
        $rest = (string) preg_replace(self::TOKEN, ' ', $name);
        $words = preg_split('/[^\p{L}\p{N}]+/u', $rest, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];
        foreach ($words as $word) {
            if (preg_match('/\d/', $word) === 1 || mb_strlen($word) < 3) {
                continue;
            }
            $out[self::normalize($word)] = true;
        }

        return array_keys($out);
    }

    /**
     * Wzorce z kandydatami (tylko te, które mają kandydatów).
     *
     * @return list<array{pattern: array{id: int, name: string, interval_months: int}, candidates: list<array<string, mixed>>}>
     */
    public function suggestions(): array
    {
        $patterns = $this->patterns();
        if ($patterns === []) {
            return [];
        }
        $defined = array_fill_keys(DB::table('inspection_positions')->pluck('xl_gid')->map(static fn ($g): int => (int) $g)->all(), true);
        $rejected = [];
        foreach (DB::table('inspection_suggestion_rejections')->get(['pattern_position_id', 'xl_gid']) as $r) {
            $rejected[(int) $r->pattern_position_id][(int) $r->xl_gid] = true;
        }

        // litery, których szukamy w każdym katalogu (towary: wzorce-towary; usługi: wzorce-usługi i usługi odnawiające)
        $wanted = [InspectionPosition::TYPE_GOODS => [], InspectionPosition::TYPE_SERVICE => []];
        foreach ($patterns as $p) {
            $wanted[$p['type']][$p['token']['letters']] = true;
            if ($p['renewal'] !== null) {
                $wanted[InspectionPosition::TYPE_SERVICE][$p['renewal']['token']['letters']] = true;
            }
        }
        $services = $this->catalog('erp_services', $wanted[InspectionPosition::TYPE_SERVICE], true);
        $goods = $wanted[InspectionPosition::TYPE_GOODS] !== [] ? $this->catalog('erp_items', $wanted[InspectionPosition::TYPE_GOODS], false) : [];

        $taken = [];
        $groups = [];
        foreach ($patterns as $p) {
            $catalog = $p['type'] === InspectionPosition::TYPE_SERVICE ? $services : $goods;
            $candidates = [];
            foreach ($catalog as $entry) {
                $gid = $entry['xl_gid'];
                if (isset($defined[$gid]) || isset($rejected[$p['id']][$gid]) || isset($taken[$gid])) {
                    continue;
                }
                $token = $this->matchingToken($entry, $p['token']['letters'], $p['core']);
                if ($token === null || $token['number'] === $p['token']['number']) {
                    continue;
                }
                $taken[$gid] = true;
                $candidates[] = [
                    'xl_gid' => $gid,
                    'xl_type' => $entry['xl_type'],
                    'code' => $entry['code'],
                    'name' => $entry['name'],
                    'unit' => $entry['unit'],
                    'interval_months' => $p['interval_months'],
                    'renewed_by' => $p['renewal'] !== null ? $this->renewalFor($services, $p['renewal'], $token['number']) : null,
                    'token' => $token['label'],
                    'sort' => (float) $token['number'],
                ];
            }
            if ($candidates !== []) {
                usort($candidates, static fn (array $a, array $b): int => [$a['sort'], $a['name']] <=> [$b['sort'], $b['name']]);
                $groups[] = ['pattern' => $p, 'candidates' => $candidates];
            }
        }
        if ($groups === []) {
            return [];
        }

        $goodsGids = [];
        $serviceGids = [];
        foreach ($groups as $g) {
            foreach ($g['candidates'] as $c) {
                if ($c['xl_type'] === InspectionPosition::TYPE_SERVICE) {
                    $serviceGids[] = $c['xl_gid'];
                } else {
                    $goodsGids[] = $c['xl_gid'];
                }
            }
        }
        $stats = $this->query->stats24m($goodsGids, $serviceGids, PolishTime::today());

        return array_map(static fn (array $g): array => [
            'pattern' => ['id' => $g['pattern']['id'], 'name' => $g['pattern']['name'], 'interval_months' => $g['pattern']['interval_months']],
            'candidates' => array_map(static function (array $c) use ($stats): array {
                unset($c['sort']);

                return [
                    ...$c,
                    'customers_24m' => $stats[$c['xl_gid']]['customers_24m'] ?? 0,
                    'quantity_24m' => $stats[$c['xl_gid']]['quantity_24m'] ?? 0.0,
                ];
            }, $g['candidates']),
        ], $groups);
    }

    /**
     * Wzorce: aktywne pozycje dodane ręcznie, z tokenem i rdzeniem; najstarsze pierwsze.
     *
     * @return list<array{id: int, name: string, interval_months: int, type: int, token: array{letters: string, number: string, key: string, label: string}, core: list<string>, renewal: array{token: array{letters: string, number: string, key: string, label: string}, core: list<string>}|null}>
     */
    private function patterns(): array
    {
        $rows = DB::table('inspection_positions')
            ->where('active', true)
            ->where('source', InspectionPosition::SOURCE_MANUAL)
            ->orderBy('id')
            ->get(['id', 'xl_type', 'name', 'interval_months', 'renewed_by_xl_gid']);
        $renewingNames = DB::table('erp_services')
            ->whereIn('xl_gid', $rows->pluck('renewed_by_xl_gid')->filter()->map(static fn ($g): int => (int) $g)->unique()->values()->all())
            ->pluck('name', 'xl_gid');

        $out = [];
        foreach ($rows as $row) {
            $tokens = self::tokens((string) $row->name);
            $core = self::core((string) $row->name);
            if ($tokens === [] || $core === []) {
                continue;
            }
            $type = (int) $row->xl_type === InspectionPosition::TYPE_SERVICE ? InspectionPosition::TYPE_SERVICE : InspectionPosition::TYPE_GOODS;
            $renewal = null;
            $renewingName = $row->renewed_by_xl_gid !== null ? ($renewingNames[(int) $row->renewed_by_xl_gid] ?? null) : null;
            if ($type === InspectionPosition::TYPE_GOODS && $renewingName !== null) {
                $sTokens = self::tokens((string) $renewingName);
                if ($sTokens !== []) {
                    $renewal = ['token' => $sTokens[0], 'core' => self::core((string) $renewingName)];
                }
            }
            $out[] = [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'interval_months' => (int) $row->interval_months,
                'type' => $type,
                'token' => $tokens[0],
                'core' => $core,
                'renewal' => $renewal,
            ];
        }

        return $out;
    }

    /**
     * Aktywne pozycje katalogu XL z tokenem o szukanych literach — kursorem, bez modeli.
     *
     * @param  array<string, true>  $letters
     * @return list<array{xl_gid: int, xl_type: int, code: string, name: string, unit: string|null, tokens: list<array{letters: string, number: string, key: string, label: string}>, words: array<string, true>}>
     */
    private function catalog(string $table, array $letters, bool $services): array
    {
        if ($letters === []) {
            return [];
        }
        $columns = $services ? ['xl_gid', 'xl_type', 'code', 'name', 'unit'] : ['xl_gid', 'code', 'name', 'unit'];
        $out = [];
        foreach (DB::table($table)->whereNull('removed_at')->where('archived', false)->orderBy('id')->select($columns)->cursor() as $row) {
            $name = (string) $row->name;
            $tokens = array_values(array_filter(self::tokens($name), static fn (array $t): bool => isset($letters[$t['letters']])));
            if ($tokens === []) {
                continue;
            }
            $out[] = [
                'xl_gid' => (int) $row->xl_gid,
                'xl_type' => $services ? InspectionPosition::TYPE_SERVICE : InspectionPosition::TYPE_GOODS,
                'code' => (string) $row->code,
                'name' => $name,
                'unit' => $row->unit !== null ? (string) $row->unit : null,
                'tokens' => $tokens,
                'words' => array_fill_keys(self::core($name), true),
            ];
        }

        return $out;
    }

    /**
     * Token pozycji katalogu o podanych literach, jeśli pozycja ma wszystkie słowa rdzenia (pierwszy taki token).
     *
     * @param  array{tokens: list<array{letters: string, number: string, key: string, label: string}>, words: array<string, true>}  $entry
     * @param  list<string>  $core
     * @return array{letters: string, number: string, key: string, label: string}|null
     */
    private function matchingToken(array $entry, string $letters, array $core): ?array
    {
        foreach ($core as $word) {
            if (! isset($entry['words'][$word])) {
                return null;
            }
        }
        foreach ($entry['tokens'] as $token) {
            if ($token['letters'] === $letters) {
                return $token;
            }
        }

        return null;
    }

    /**
     * Usługa odnawiająca dla kandydata-towaru z liczbą $number: jedna pasująca usługa albo null.
     *
     * @param  list<array<string, mixed>>  $services
     * @param  array{token: array{letters: string, number: string, key: string, label: string}, core: list<string>}  $renewal
     * @return array{xl_gid: int, code: string, name: string}|null
     */
    private function renewalFor(array $services, array $renewal, string $number): ?array
    {
        $found = [];
        foreach ($services as $entry) {
            $token = $this->matchingToken($entry, $renewal['token']['letters'], $renewal['core']);
            if ($token === null) {
                continue;
            }
            foreach ($entry['tokens'] as $t) {
                if ($t['letters'] === $renewal['token']['letters'] && $t['number'] === $number) {
                    $found[$entry['xl_gid']] = ['xl_gid' => $entry['xl_gid'], 'code' => $entry['code'], 'name' => $entry['name']];
                }
            }
        }

        return count($found) === 1 ? array_values($found)[0] : null;
    }

    private static function normalize(string $text): string
    {
        return strtr(mb_strtoupper($text), self::PL);
    }

    /** „06” → „6”, „2,50” → „2.5” — ta sama liczba zapisana inaczej to ten sam klucz. */
    private static function number(string $raw): string
    {
        $n = str_replace(',', '.', $raw);
        if (str_contains($n, '.')) {
            $n = rtrim(rtrim($n, '0'), '.');
        }
        $n = ltrim($n, '0');

        return $n === '' || $n[0] === '.' ? '0'.$n : $n;
    }
}

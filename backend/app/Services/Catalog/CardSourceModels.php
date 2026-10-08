<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\ProductIdentifier;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

/**
 * Modele połączone w jednej karcie — numery artykułów źródła z nazwą artykułu u dostawcy (zgłoszenie 06.10.2026:
 * ELTEN pokazuje 5 modeli MAVERICK, a u nas 4 karty, bo red 0723341-0 i black 0723381-0 to jedna karta).
 *
 * Numer modelu = numer artykułu źródła (product_identifiers source_code, bez zdjętych), od którego zaczynają się kody
 * aktywnych rozmiarów karty („0723381-0” → „0723381-0 38”), a który sam kodem rozmiaru nie jest. Numer pojedynczego
 * rozmiaru (Portwest „S503NVRM” = kod wiersza rozmiaru) modelem nie jest — inaczej lista modeli byłaby listą rozmiarów.
 *
 * Rozmiary modelu = ostatni człon etykiet jego wierszy rozmiarów — ten sam model pod dwoma numerami (ELTEN MATTHEW
 * 1768502-0 i 7685502-0) różni się tylko nimi.
 *
 * Nazwa = nazwa pozycji u dostawcy (b2b_product_links.remote_name) bez rozmiaru z końca („MAVERICK black Low ESD S3S 38”
 * → „MAVERICK black Low ESD S3S”, Atlas „Flash 4000 | ESD, tęgość 12 / 36” → „Flash 4000 | ESD, tęgość 12”);
 * rozmiar z etykiety wiersza rozmiaru tej pozycji. Bez powiązania (cennik z pliku) — sam numer.
 *
 * Bez osobnego numeru modelu (prośba 08.10.2026: lista jak u ELTEN dla wszystkich cenników — Portwest, Mascot, JHK,
 * MAVIBO, Hultafors, SIR, Sara, Canis, Brubeck, Safety Jogger, 3M, MSA zapisują kolor tylko w etykiecie i kodzie
 * każdego rozmiaru) model = grupa wierszy rozmiarów jednego konta o tej samej etykiecie bez rozmiaru:
 * „czerwień kubańska / XS” → „czerwień kubańska”, „Czarny 3XL” → „Czarny”, sam kolor („biały”) to cała etykieta,
 * sam rozmiar („36”, „S/48”, „rozmiar 22,5/35,0”) grupy nie tworzy. Nazwa = etykieta grupy dosłownie, numer = wspólna
 * część kodów jej rozmiarów (zob. groupNumber), rozmiary = rozmiary z etykiet.
 */
final class CardSourceModels
{
    /**
     * Pojedynczy rozmiar: liczba (36, 22,5), wymiar (100x50), Mascot (82C42, C46), literowy (S, XL, 3XL, XXXXXL, LD, 4X,
     * Sara XXLA / LS), spodnie (W42L30), uniwersalny („One-size”, „1SIZE”), słowny (mały, duży, regulowany); po nim krój
     * (Hultafors „XS Regular*”, Mascot „2XLONE”) i zakres Ejendals / Safety Jogger („M=37-40”, „S (34-38)”, Profix „S (48)”).
     */
    private const SIZE_ELEMENT = '(?:\d+\s?[x×]\s?\d+|\d+(?:[.,]\d+)?|\d{0,2}[CD]\d{2,3}|\d{0,2}X{0,7}(?:S|M|L|LD|XL)[ABST]?|\d{0,2}X{1,7}'
        .'|W\d{2}(?:\s?L\d{2})?|(?:ONE|1)[\s-]?SIZE|UNI|UNIWERSALNY|ONE|U|jeden\srozmiar|ma[łl][ya]|ma[łl]e|[śs]redni[a]?|du[żz][ya]|du[żz]e'
        .'|small|medium|large|regulowan[yae]|adjustable)'
        .'(?:\s*(?:Regular|Long|Short|Tall|ONE))?\*?'
        .'(?:\s*(?:=\s*|\(\s*)\d+(?:[.,]\d+)?(?:\s*[-–]\s*\d+(?:[.,]\d+)?)?\+?\s*\)?)?';

    /**
     * Rozmiar w etykiecie: element albo para/zakres („6/7”, „S/M”, „M - XL”, „48; 50”), z opcjonalnym „rozmiar” i liczbą
     * sztuk w opakowaniu (skarpety Mascot „35/383PC”).
     */
    private const SIZE = '(?:(?:rozmiar|rozm\.?|roz\.?|size)\s*:?\s*)?'.self::SIZE_ELEMENT.'(?:\s*[\/–;-]\s*'.self::SIZE_ELEMENT.')*'
        .'(?:\s?\d+\s?(?:PC|PCS|SZT\.?|PAR|PARY))?';

    /** Znaki rozdzielające człony kodu („51584-967-202 XS”, „22160_20_XS”, „91300 S3/36”). */
    private const CODE_SEPARATORS = " -_/.\t";

    /**
     * @param  list<int>  $productIds
     * @return array<int, list<array{number: string, name: string|null, sizes: list<string>}>> tylko karty z co najmniej
     *                                                                                         dwoma modelami
     */
    public function forProducts(array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if ($productIds === []) {
            return [];
        }

        $byCard = $this->sourceCodes($productIds);
        // wiersz rozmiaru = pozycja B2B powiązana z kartą, więc kilka modeli ma tylko karta z co najmniej dwoma
        // powiązaniami (produkcja 08.10.2026: 4289 kart z listą, każda z ≥ 2) — strona bez takich kart nie czyta wierszy
        $candidates = array_values(array_unique([
            ...array_keys($byCard),
            ...DB::table('b2b_product_links')
                ->whereIntegerInRaw('product_id', $productIds)
                ->groupBy('product_id')
                ->havingRaw('COUNT(*) > 1')
                ->pluck('product_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all(),
        ]));
        if ($candidates === []) {
            return [];
        }

        $sizes = [];
        foreach (ProductVariant::query()
            ->toBase()
            ->where('kind', ProductVariant::KIND_SIZE)
            ->whereNull('removed_at')
            ->whereIntegerInRaw('product_id', $candidates)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['product_id', 'b2b_account_id', 'remote_id', 'sku', 'label']) as $size) {
            $sizes[(int) $size->product_id][] = $size;
        }

        $out = $this->byModelNumbers($byCard, $sizes);
        foreach ($sizes as $productId => $rows) {
            if (! isset($out[$productId])) {
                $groups = self::byLabelGroups($rows);
                if (count($groups) > 1) {
                    $out[$productId] = $groups;
                }
            }
        }

        return $out;
    }

    /**
     * Numery źródła kart z co najmniej dwoma (bez zdjętych), po karcie i numerze małymi literami.
     *
     * @param  list<int>  $productIds
     * @return array<int, array<string, object>>
     */
    private function sourceCodes(array $productIds): array
    {
        $identifiers = ProductIdentifier::query()
            ->toBase()
            ->where('type', ProductIdentifier::TYPE_SOURCE_CODE)
            ->whereNull('removed_at')
            ->whereIntegerInRaw('product_id', $productIds)
            ->orderBy('id')
            ->get(['product_id', 'b2b_account_id', 'position_key', 'value']);
        $byCard = [];
        foreach ($identifiers as $row) {
            $byCard[(int) $row->product_id][mb_strtolower((string) $row->value)] ??= $row;
        }

        return array_filter($byCard, static fn (array $rows): bool => count($rows) > 1);
    }

    /**
     * Droga ELTEN/Atlas: osobne numery modeli w product_identifiers.
     *
     * @param  array<int, array<string, object>>  $byCard
     * @param  array<int, list<object>>  $sizes
     * @return array<int, list<array{number: string, name: string|null, sizes: list<string>}>>
     */
    private function byModelNumbers(array $byCard, array $sizes): array
    {
        $models = [];
        foreach ($byCard as $productId => $rows) {
            foreach ($rows as $key => $row) {
                if (self::isModelNumber((string) $key, $sizes[$productId] ?? [])) {
                    $models[$productId][] = $row;
                }
            }
        }
        $models = array_filter($models, static fn (array $rows): bool => count($rows) > 1);
        if ($models === []) {
            return [];
        }

        $names = $this->positionNames($models);
        $out = [];
        foreach ($models as $productId => $rows) {
            $list = [];
            foreach ($rows as $row) {
                $position = (string) $row->position_key;
                $name = $names[(int) $row->b2b_account_id."\n".$position] ?? null;
                $list[] = [
                    'number' => (string) $row->value,
                    'name' => $name === null ? null : self::withoutSize($name, self::sizeOf($position, $sizes[$productId] ?? [])),
                    'sizes' => self::modelSizes(mb_strtolower((string) $row->value), $sizes[$productId] ?? []),
                ];
            }
            usort($list, static fn (array $a, array $b): int => strnatcasecmp($a['number'], $b['number']));
            $out[$productId] = $list;
        }

        return $out;
    }

    /**
     * Modele z etykiet wierszy rozmiarów — co najmniej dwie grupy z nazwą, inaczej karta to jeden model w rozmiarach.
     * Wiersze bez nazwy grupy (sam rozmiar) idą osobną pozycją bez nazwy, kolejność jak u dostawcy.
     *
     * @param  list<object>  $rows
     * @return list<array{number: string, name: string|null, sizes: list<string>}>
     */
    private static function byLabelGroups(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            [$name, $size] = self::splitLabel((string) $row->label);
            $key = (int) $row->b2b_account_id."\n".mb_strtolower($name ?? '');
            $groups[$key] ??= ['name' => $name, 'rows' => [], 'sizes' => []];
            $sku = trim((string) $row->sku);
            $code = $sku !== '' ? $sku : trim((string) $row->remote_id);
            // bez rozmiaru łącznik zapisuje w etykiecie kod pozycji (ELTEN sznurówki „black / 0260090-0”) — to nie rozmiar
            if ($size !== null && mb_strtolower($size) === mb_strtolower($code)) {
                $size = null;
            }
            $groups[$key]['rows'][] = ['code' => $code, 'size' => $size];
            if ($size !== null && ! in_array($size, $groups[$key]['sizes'], true)) {
                $groups[$key]['sizes'][] = $size;
            }
        }
        if (count(array_filter($groups, static fn (array $g): bool => $g['name'] !== null)) < 2) {
            return [];
        }

        $out = [];
        foreach ($groups as $group) {
            $out[] = [
                'number' => self::groupNumber($group['rows'], $group['name']),
                'name' => $group['name'],
                'sizes' => self::sortedSizes($group['sizes']),
            ];
        }
        // ten sam numer kilku grup: kod bez koloru (Raw-Pol „RNYDO8”, „RNYDO9” — kolor = jeden rozmiar → po odcięciu
        // rozmiaru „RNYDO” wszędzie); grupa z jednym kodem pokazuje go w całości, z kilkoma — zostaje wspólna część
        $used = array_count_values(array_map(static fn (array $m): string => mb_strtolower($m['number']), $out));
        foreach (array_values($groups) as $i => $group) {
            $codes = array_values(array_unique(array_column($group['rows'], 'code')));
            if ($used[mb_strtolower($out[$i]['number'])] > 1 && count($codes) === 1 && $codes[0] !== '') {
                $out[$i]['number'] = $codes[0];
            }
        }

        return $out;
    }

    /**
     * Rozmiary od najmniejszego (lista pokazuje „pierwszy–ostatni”, a Portwest podaje je alfabetycznie: 4XL, L, M…).
     * Gdy któregoś nie da się uszeregować — kolejność dostawcy.
     *
     * @param  list<string>  $sizes
     * @return list<string>
     */
    private static function sortedSizes(array $sizes): array
    {
        $ranks = [];
        foreach ($sizes as $i => $size) {
            $rank = self::sizeRank($size);
            if ($rank === null) {
                return $sizes;
            }
            $ranks[$i] = $rank;
        }
        $order = array_keys($sizes);
        usort($order, static fn (int $a, int $b): int => $ranks[$a] <=> $ranks[$b] ?: $a <=> $b);

        return array_map(static fn (int $i): string => $sizes[$i], $order);
    }

    /**
     * Miejsce rozmiaru: [0, liczba] dla liczbowych, [1, stopień] dla literowych (XS < S < M < L < XL < 2XL…); pierwszy
     * człon pary („S/M” → S). Null — rozmiar spoza tych dwóch rodzajów.
     *
     * @return array{0: int, 1: float}|null
     */
    private static function sizeRank(string $size): ?array
    {
        $size = mb_strtoupper(trim($size));
        if (preg_match('/^(\d+(?:[.,]\d+)?)(?![\dX×C])/u', $size, $m) === 1) {
            return [0, (float) str_replace(',', '.', $m[1])];
        }
        if (preg_match('/^(\d{0,2})(X*)(S|M|L|XL)?(?![A-Z])/u', $size, $m) === 1 && ($m[2] !== '' || ($m[3] ?? '') !== '')) {
            $count = ($m[1] !== '' ? (int) $m[1] : strlen($m[2])) + (($m[3] ?? '') === 'XL' ? 1 : 0);
            $letter = $m[3] ?? '';
            if ($letter === '' && $m[2] !== '') {
                // „4X” = 4XL
                $letter = 'L';
            }

            return match ($letter) {
                'S' => [1, 1 - $count],
                'M' => [1, 2],
                'L', 'XL' => [1, 3 + $count],
                default => null,
            };
        }

        return null;
    }

    /**
     * Etykieta wiersza rozmiaru → [grupa, rozmiar]. „kolor / rozmiar” (zapis łączników) dzieli ostatnie „ / ”; bez niego
     * rozmiar z końca po spacji („Czarny 3XL”, „czarny uni”), chyba że przed liczbą stoi „kolor” („kolor 20” to kolor).
     * Sam rozmiar — bez grupy; bez rozmiaru — cała etykieta jest grupą („biały”, „kolor biały”).
     *
     * @return array{0: string|null, 1: string|null}
     */
    private static function splitLabel(string $label): array
    {
        $label = trim($label);
        if ($label === '') {
            return [null, null];
        }
        // „6-”, „48;” — znak po rozmiarze z zapisu dostawcy
        $isSize = static fn (string $text): bool => preg_match('/^'.self::SIZE.'$/iu', trim($text, " \t;,.-")) === 1;
        $slash = mb_strrpos($label, ' / ');
        if ($slash !== false) {
            $name = trim(mb_substr($label, 0, $slash));
            $size = trim(mb_substr($label, $slash + 3));

            return [$name !== '' && ! $isSize($name) ? $name : null, $size !== '' ? $size : null];
        }
        if ($isSize($label)) {
            return [null, $label];
        }
        if (preg_match('/^(.+?)[\s,]+('.self::SIZE.')$/iu', $label, $m) === 1
            && preg_match('/(?:kolor|color|colour|nr|no\.?)$/iu', trim($m[1])) !== 1) {
            return [trim($m[1], " \t,"), $m[2]];
        }

        return [$label, null];
    }

    /**
     * Numer grupy: kod bez rozmiaru z końca, gdy wszystkie wiersze dają ten sam („S843BGRL/XL”, „S843BGRXXL” → „S843BGR”,
     * „51584-967-202 XS” → „51584-967-202”); inaczej wspólny początek kodów do granicy członu („1610-001-100-92”,
     * „1610-001-100-97” → „1610-001-100”), a gdy kody nie mają członów — wspólny początek z „…” („M5PA3TSTRNO…”), bo
     * dalszy ciąg może już być rozmiarem — chyba że ten początek zawiera kod koloru z etykiety grupy (Hultafors
     * „0404 - Black\Black”: „110004040…” → „11000404”). Jeden kod — on sam.
     *
     * @param  list<array{code: string, size: string|null}>  $rows
     */
    private static function groupNumber(array $rows, ?string $name): string
    {
        $codes = array_values(array_filter(array_column($rows, 'code'), static fn (string $c): bool => $c !== ''));
        if ($codes === []) {
            return '';
        }
        $bases = [];
        foreach ($rows as $row) {
            $base = $row['code'] !== '' && $row['size'] !== null ? self::withoutTrailingSize($row['code'], $row['size']) : null;
            if ($base === null) {
                $bases = null;
                break;
            }
            $bases[mb_strtolower($base)] = $base;
        }
        if ($bases !== null && count($bases) === 1) {
            return (string) reset($bases);
        }
        if (count(array_unique(array_map('mb_strtolower', $codes))) === 1) {
            return $codes[0];
        }

        $prefix = self::commonPrefix($codes);
        $length = mb_strlen($prefix);
        $boundary = $length > 0 && str_contains(self::CODE_SEPARATORS, mb_substr($prefix, -1));
        if ($length > 0 && ! $boundary) {
            $boundary = true;
            foreach ($codes as $code) {
                $next = mb_substr($code, $length, 1);
                if ($next !== '' && ! str_contains(self::CODE_SEPARATORS, $next)) {
                    $boundary = false;
                    break;
                }
            }
        }
        $trimmed = rtrim($prefix, self::CODE_SEPARATORS);
        if ($boundary && mb_strlen($trimmed) >= 3) {
            return $trimmed;
        }
        $cut = -1;
        foreach (mb_str_split($prefix) as $i => $char) {
            if (str_contains(self::CODE_SEPARATORS, $char)) {
                $cut = $i;
            }
        }
        // cofnięcie do członu tylko o same cyfry (Atlas „91300 S3/3” — początek rozmiaru 36/37); litery mogą być kolorem
        // (Raw-Pol „KOS-5P” + M/L: cofnięcie dałoby „KOS” wspólne dla wszystkich kolorów)
        if ($cut > 0 && preg_match('/^\d+$/', mb_substr($prefix, $cut + 1)) === 1) {
            $head = rtrim(mb_substr($prefix, 0, $cut), self::CODE_SEPARATORS);
            if (mb_strlen($head) >= 3) {
                return $head;
            }
        }
        $withLabelCode = $name !== null ? self::upToLabelCode($trimmed, $name) : null;
        if ($withLabelCode !== null) {
            return $withLabelCode;
        }

        return mb_strlen($trimmed) >= 3 ? $trimmed.'…' : $codes[0];
    }

    /**
     * Początek kodu do końca kodu koloru z etykiety (człon z cyfrą, co najmniej 3 znaki: „0404”, „5574”), gdy ten kod
     * w nim występuje; najdalsze wystąpienie. Null — etykieta bez takiego kodu albo kod poza początkiem.
     */
    private static function upToLabelCode(string $prefix, string $name): ?string
    {
        $end = 0;
        $lower = mb_strtolower($prefix);
        foreach (preg_split('/[^\p{L}\d]+/u', mb_strtolower($name), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            if (mb_strlen($token) < 3 || preg_match('/\d/u', $token) !== 1) {
                continue;
            }
            $at = mb_strrpos($lower, $token);
            if ($at !== false && $at > 0) {
                $end = max($end, $at + mb_strlen($token));
            }
        }

        return $end >= 3 ? mb_substr($prefix, 0, $end) : null;
    }

    /**
     * Kod bez rozmiaru z końca (rozmiar też w zapisie kodu: „S/M” → „S_M”, „1-2” → „1/2”, „35,5” → „35.5”, Raw-Pol
     * „2xl” → „XXL” i odwrotnie).
     */
    private static function withoutTrailingSize(string $code, string $size): ?string
    {
        $variants = [
            $size,
            str_replace('/', '_', $size),
            str_replace('-', '/', $size),
            str_replace(' ', '', $size),
            str_replace(',', '.', $size),
        ];
        if (preg_match('/^([2-9])XL$/iu', $size, $m) === 1) {
            $variants[] = str_repeat('X', (int) $m[1]).'L';
        } elseif (preg_match('/^(X{2,9})L$/iu', $size, $m) === 1) {
            $variants[] = strlen($m[1]).'XL';
        }
        $variants = array_unique($variants);
        foreach ($variants as $variant) {
            $length = mb_strlen($variant);
            if ($length > 0 && mb_strlen($code) > $length && mb_strtolower(mb_substr($code, -$length)) === mb_strtolower($variant)) {
                $base = rtrim(mb_substr($code, 0, -$length), self::CODE_SEPARATORS);
                if ($base !== '') {
                    return $base;
                }
            }
        }

        return null;
    }

    /**
     * Wspólny początek kodów; spacja i podkreślnik liczą się jako ten sam znak (MAVIBO „21172 20 XS” i „21172_20_S”).
     *
     * @param  list<string>  $codes
     */
    private static function commonPrefix(array $codes): string
    {
        $fold = static fn (string $char): string => $char === '_' ? ' ' : mb_strtolower($char);
        $first = mb_str_split($codes[0]);
        $length = count($first);
        foreach (array_slice($codes, 1) as $code) {
            $chars = mb_str_split($code);
            $length = min($length, count($chars));
            for ($i = 0; $i < $length; $i++) {
                if ($fold($first[$i]) !== $fold($chars[$i])) {
                    $length = $i;
                    break;
                }
            }
        }

        return implode('', array_slice($first, 0, $length));
    }

    /**
     * @param  list<object>  $sizes
     */
    private static function isModelNumber(string $number, array $sizes): bool
    {
        $prefixOf = false;
        foreach ($sizes as $size) {
            $sku = mb_strtolower(trim((string) $size->sku));
            if ($sku === $number) {
                return false;
            }
            if (mb_strlen($sku) > mb_strlen($number) && str_starts_with($sku, $number)) {
                $prefixOf = true;
            }
        }

        return $prefixOf;
    }

    /**
     * @param  list<object>  $sizes
     * @return list<string>
     */
    private static function modelSizes(string $number, array $sizes): array
    {
        $out = [];
        foreach ($sizes as $size) {
            $sku = mb_strtolower(trim((string) $size->sku));
            if (mb_strlen($sku) > mb_strlen($number) && str_starts_with($sku, $number)) {
                $parts = explode('/', (string) $size->label);
                $last = trim((string) end($parts));
                if ($last !== '' && ! in_array($last, $out, true)) {
                    $out[] = $last;
                }
            }
        }

        return $out;
    }

    /**
     * Nazwy pozycji u dostawcy: konto + remote_id → remote_name.
     *
     * @param  array<int, list<object>>  $models
     * @return array<string, string>
     */
    private function positionNames(array $models): array
    {
        $byAccount = [];
        foreach ($models as $rows) {
            foreach ($rows as $row) {
                if ($row->b2b_account_id !== null) {
                    $byAccount[(int) $row->b2b_account_id][] = (string) $row->position_key;
                }
            }
        }
        $names = [];
        foreach ($byAccount as $accountId => $positions) {
            foreach (DB::table('b2b_product_links')
                ->where('b2b_account_id', $accountId)
                ->whereIn('remote_id', array_values(array_unique($positions)))
                ->get(['remote_id', 'remote_name']) as $link) {
                $name = trim((string) $link->remote_name);
                if ($name !== '') {
                    $names[$accountId."\n".$link->remote_id] = $name;
                }
            }
        }

        return $names;
    }

    /**
     * Rozmiar pozycji: ostatni człon etykiety jej wiersza rozmiaru („black / 38” → „38”).
     *
     * @param  list<object>  $sizes
     */
    private static function sizeOf(string $position, array $sizes): ?string
    {
        foreach ($sizes as $size) {
            if ((string) $size->remote_id === $position) {
                $parts = explode('/', (string) $size->label);
                $last = trim((string) end($parts));

                return $last !== '' ? $last : null;
            }
        }

        return null;
    }

    private static function withoutSize(string $name, ?string $size): string
    {
        if ($size === null) {
            return $name;
        }
        $cut = preg_replace('/[\s,\/]+'.preg_quote($size, '/').'$/u', '', $name);

        return $cut !== null && trim($cut) !== '' ? trim($cut) : $name;
    }
}

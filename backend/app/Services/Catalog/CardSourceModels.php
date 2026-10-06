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
 */
final class CardSourceModels
{
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
        $byCard = array_filter($byCard, static fn (array $rows): bool => count($rows) > 1);
        if ($byCard === []) {
            return [];
        }

        $sizes = [];
        foreach (ProductVariant::query()
            ->toBase()
            ->where('kind', ProductVariant::KIND_SIZE)
            ->whereNull('removed_at')
            ->whereIntegerInRaw('product_id', array_keys($byCard))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['product_id', 'remote_id', 'sku', 'label']) as $size) {
            $sizes[(int) $size->product_id][] = $size;
        }

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

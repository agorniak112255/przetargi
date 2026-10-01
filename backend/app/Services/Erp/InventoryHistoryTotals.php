<?php

declare(strict_types=1);

namespace App\Services\Erp;

use OverflowException;
use SplFixedArray;

/**
 * Sumy odtwarzania historii zapasów (InventoryHistoryRebuild) w zwartych tablicach liczb: etykieta (dzień) × klucz
 * (oddział|magazyny albo kod magazynu) × koszyk → liczba pozycji i dwie kwoty. Zagnieżdżone tablice PHP dla 2 lat
 * (731 dni × ~30 kluczy × 13 koszyków) przekraczały limit CLI 128 MB (próba na produkcji 01.10.2026); tu ~20 MB.
 */
final class InventoryHistoryTotals
{
    private SplFixedArray $items;

    private SplFixedArray $first;

    private SplFixedArray $second;

    /** @var array<string, int> */
    private array $keys = [];

    /** @var array<string, int> */
    private array $buckets;

    /**
     * @param  list<string>  $buckets
     */
    public function __construct(private readonly int $labels, array $buckets, private readonly int $maxKeys = 48)
    {
        $this->buckets = array_flip(array_values($buckets));
        $size = $labels * $maxKeys * count($this->buckets);
        $this->items = new SplFixedArray($size);
        $this->first = new SplFixedArray($size);
        $this->second = new SplFixedArray($size);
    }

    /** Pozycja +1, do kwot dodane $a i $b. */
    public function add(int $label, string $key, string $bucket, float $a, float $b = 0.0): void
    {
        $i = $this->index($label, $this->key($key), $this->buckets[$bucket]);
        $this->items[$i] = ($this->items[$i] ?? 0) + 1;
        $this->first[$i] = ($this->first[$i] ?? 0.0) + $a;
        $this->second[$i] = ($this->second[$i] ?? 0.0) + $b;
    }

    /** @return array{0: int, 1: float, 2: float} pozycje i obie kwoty (zera, gdy nic nie dodano) */
    public function get(int $label, string $key, string $bucket): array
    {
        if (! isset($this->keys[$key])) {
            return [0, 0.0, 0.0];
        }
        $i = $this->index($label, $this->keys[$key], $this->buckets[$bucket]);

        return [(int) ($this->items[$i] ?? 0), (float) ($this->first[$i] ?? 0.0), (float) ($this->second[$i] ?? 0.0)];
    }

    /** @return list<string> klucze, które cokolwiek dostały */
    public function keys(): array
    {
        return array_keys($this->keys);
    }

    private function key(string $key): int
    {
        if (! isset($this->keys[$key])) {
            if (count($this->keys) >= $this->maxKeys) {
                throw new OverflowException('Za dużo kluczy sum historii zapasów: '.$this->maxKeys);
            }
            $this->keys[$key] = count($this->keys);
        }

        return $this->keys[$key];
    }

    private function index(int $label, int $key, int $bucket): int
    {
        return ($label * $this->maxKeys + $key) * count($this->buckets) + $bucket;
    }
}

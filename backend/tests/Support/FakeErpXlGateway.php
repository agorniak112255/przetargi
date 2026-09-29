<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Erp\ErpXlGateway;

/** Atrapa Comarch ERP XL: wiersze w postaci, jaką zwraca ErpXlClient. */
final class FakeErpXlGateway implements ErpXlGateway
{
    /** @var list<array{gid: int, code: string, name: string, name1: string, ean: string, unit: string, archived: bool}> */
    public array $items = [];

    /** @var list<array{gid: int, warehouse_code: string, warehouse_name: string, quantity: float}> */
    public array $stockRows = [];

    /** @var list<array<string, mixed>> najnowsze pierwsze */
    public array $purchaseRows = [];

    /** @var list<array<string, mixed>> */
    public array $supplierRows = [];

    /** @var array<int, int> */
    public array $sales = [];

    /** @var list<array<string, mixed>> RW/PW w postaci ErpXlGateway::internalMoves */
    public array $moveRows = [];

    public ?int $movesFrom = null;

    /** @var list<array<string, mixed>> partie zdjęte przez RW w postaci ErpXlGateway::internalMoveLots */
    public array $lotRows = [];

    public bool $isConfigured = true;

    public function configured(): bool
    {
        return $this->isConfigured;
    }

    public function ping(): array
    {
        return ['ok' => $this->isConfigured, 'message' => 'atrapa', 'items' => count($this->items)];
    }

    public function items(int $afterGid, int $limit): array
    {
        $rows = array_values(array_filter($this->items, static fn (array $i): bool => $i['gid'] > $afterGid));
        usort($rows, static fn (array $a, array $b): int => $a['gid'] <=> $b['gid']);

        return array_slice($rows, 0, $limit);
    }

    public function stock(array $gids): array
    {
        return array_values(array_filter($this->stockRows, static fn (array $r): bool => in_array($r['gid'], $gids, true)));
    }

    public function purchases(array $gids, int $perItem): array
    {
        $out = [];
        $count = [];
        foreach ($this->purchaseRows as $row) {
            if (! in_array($row['gid'], $gids, true)) {
                continue;
            }
            $count[$row['gid']] = ($count[$row['gid']] ?? 0) + 1;
            if ($count[$row['gid']] <= $perItem) {
                $out[] = $row;
            }
        }

        return $out;
    }

    public function suppliers(array $gids): array
    {
        return array_values(array_filter($this->supplierRows, static fn (array $r): bool => in_array($r['gid'], $gids, true)));
    }

    public function lastSales(array $gids): array
    {
        return array_intersect_key($this->sales, array_flip($gids));
    }

    public function internalMoves(int $fromClarionDate): array
    {
        $this->movesFrom = $fromClarionDate;

        return array_values(array_filter($this->moveRows, static fn (array $r): bool => $r['date'] >= $fromClarionDate));
    }

    public function internalMoveLots(int $fromClarionDate): array
    {
        return $this->lotRows;
    }

    /** @return array<string, mixed> — $receivedAt: znacznik XL (sekundy od 1.01.1990) */
    public static function lot(int $documentId, int $gid, int $receivedAt, float $quantity, string $source = 'PZ-01H/1/21/08', int $sourceType = 1489, string $feature = '', string $type = 'rw'): array
    {
        return ['type' => $type, 'document_id' => $documentId, 'gid' => $gid, 'received_at' => $receivedAt, 'quantity' => $quantity, 'feature' => $feature, 'source_type' => $sourceType, 'source_number' => $source];
    }

    /** @return array<string, mixed> */
    public static function move(string $type, int $documentId, int $date, int $gid, float $quantity, float $value, ?string $operator = 'NOMA', ?string $warehouse = '01H', ?string $approver = null): array
    {
        return [
            'type' => $type,
            'document_id' => $documentId,
            'number' => strtoupper($type).'-'.($warehouse ?? '01H').'/'.$documentId.'/26/09',
            'date' => $date,
            'warehouse' => $warehouse,
            'operator' => $operator,
            'approver' => $approver ?? $operator,
            'gid' => $gid,
            'quantity' => $quantity,
            'value' => $value,
        ];
    }

    /** @return array{gid: int, code: string, name: string, name1: string, ean: string, unit: string, archived: bool} */
    public static function item(int $gid, string $code, string $name, string $name1 = '', bool $archived = false): array
    {
        return ['gid' => $gid, 'code' => $code, 'name' => $name, 'name1' => $name1, 'ean' => '', 'unit' => 'szt', 'archived' => $archived];
    }

    /** @return array<string, mixed> */
    public static function purchase(int $gid, int $documentId, int $date, string $supplier, float $quantity, float $net, string $currency = 'PLN', ?float $price = null): array
    {
        return [
            'gid' => $gid,
            'document_type' => 1489,
            'document_id' => $documentId,
            'document_line' => 1,
            'document_state' => 3,
            'date' => $date,
            'supplier_id' => 100 + $gid,
            'supplier' => $supplier,
            'quantity' => $quantity,
            'document_unit' => 'szt',
            'net_value_pln' => $net,
            'document_price' => $price ?? ($quantity > 0 ? $net / $quantity : 0.0),
            'currency' => $currency,
        ];
    }
}

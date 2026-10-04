<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Erp\ErpXlGateway;
use Illuminate\Database\QueryException;

/** Atrapa Comarch ERP XL: wiersze w postaci, jaką zwraca ErpXlClient. */
final class FakeErpXlGateway implements ErpXlGateway
{
    /** @var list<array{gid: int, code: string, name: string, name1: string, ean: string, unit: string, archived: bool}> */
    public array $items = [];

    /** @var list<array{gid: int, warehouse_code: string, warehouse_name: string, quantity: float}> */
    public array $stockRows = [];

    /** @var list<array{gid: int, warehouse_code: string, received_at: int|null, quantity: float, value: float|null}> */
    public array $stockLotRows = [];

    /** @var list<array<string, mixed>> najnowsze pierwsze */
    public array $purchaseRows = [];

    /** @var list<array<string, mixed>> */
    public array $supplierRows = [];

    /** @var array<int, int> gid => data sprzedaży z dokumentu bez magazynu */
    public array $sales = [];

    /** @var list<array{gid: int, warehouse_code: string|null, date: int}> sprzedaż z magazynem dokumentu */
    public array $saleRows = [];

    /** @var list<array<string, mixed>> RW/PW w postaci ErpXlGateway::internalMoves */
    public array $moveRows = [];

    public ?int $movesFrom = null;

    /** @var list<array<string, mixed>> partie zdjęte przez RW w postaci ErpXlGateway::internalMoveLots */
    public array $lotRows = [];

    /** @var list<array{gid: int, acronym: string, name: string, nip: ?string, city: ?string, email: ?string, archived: bool}> */
    public array $customers = [];

    /** @var list<array{gid: int, email: string}> */
    public array $addressEmails = [];

    /** @var list<array{customer_gid: int, item_gid: int, last_date: int, documents: int, quantity: float}> */
    public array $customerSaleRows = [];

    /** @var list<array{customer_gid: int, operator: string, operator_name: ?string, documents: int}> */
    public array $customerOperatorRows = [];

    public ?int $customerSalesFrom = null;

    /** @var list<array{document_type: int, document_id: int, line: int, document_number: string, date: int, customer_gid: int, item_gid: int, quantity: float, net_value: float, cost: float, corrects_type: int|null, corrects_id: int|null}> */
    public array $saleLineRows = [];

    /** @var list<array{items: list<int>, from: int}> wywołania itemSaleLines */
    public array $saleLineCalls = [];

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

    public function stockLots(array $gids): array
    {
        return array_values(array_filter($this->stockLotRows, static fn (array $r): bool => in_array($r['gid'], $gids, true)));
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
        $rows = [];
        foreach (array_intersect_key($this->sales, array_flip($gids)) as $gid => $date) {
            $rows[] = ['gid' => (int) $gid, 'warehouse_code' => null, 'date' => $date];
        }

        return [...$rows, ...array_values(array_filter($this->saleRows, static fn (array $r): bool => in_array($r['gid'], $gids, true)))];
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

    public function customers(int $afterGid, int $limit): array
    {
        $rows = array_values(array_filter($this->customers, static fn (array $c): bool => $c['gid'] > $afterGid));
        usort($rows, static fn (array $a, array $b): int => $a['gid'] <=> $b['gid']);

        return array_slice($rows, 0, $limit);
    }

    public function customerAddressEmails(): iterable
    {
        yield from $this->addressEmails;
    }

    public function customerSales(int $fromClarionDate): iterable
    {
        $this->customerSalesFrom = $fromClarionDate;
        foreach ($this->customerSaleRows as $row) {
            if ($row['last_date'] >= $fromClarionDate) {
                yield $row;
            }
        }
    }

    public function customerOperators(int $fromClarionDate): iterable
    {
        yield from $this->customerOperatorRows;
    }

    /** @var list<array{gid: int, dst: int, warehouse_code: string, received_at: int|null, quantity: float, value: float}> */
    public array $historyLots = [];

    /** @var list<array{gid: int, dst: int, warehouse_code: string, received_at: int|null, type: int, day: int, quantity: float, cost: float}> */
    public array $historyMoves = [];

    /** @var list<array{gid: int, warehouse_code: string|null, date: int}> wszystkie dni sprzedaży (Clarion) */
    public array $historySales = [];

    public int $historyCalls = 0;

    /** Tyle pierwszych wywołań lotHistory kończy się zakleszczeniem (SQL Server wybiera nasze zapytanie jako ofiarę). */
    public int $historyDeadlocks = 0;

    public function lotHistory(array $gids, int $sinceTimestamp): array
    {
        $this->historyCalls++;
        if ($this->historyDeadlocks > 0) {
            $this->historyDeadlocks--;

            throw new QueryException('erpxl', 'SELECT 1', [], new \PDOException('Transaction was deadlocked on lock resources with another process'));
        }

        return [
            'lots' => array_values(array_filter($this->historyLots, static fn (array $r): bool => in_array($r['gid'], $gids, true))),
            'moves' => array_values(array_filter($this->historyMoves, static fn (array $r): bool => in_array($r['gid'], $gids, true) && $sinceTimestamp <= $r['day'] * 86400)),
        ];
    }

    public function saleHistory(array $gids, int $fromClarionDate): array
    {
        $before = [];
        $days = [];
        foreach ($this->historySales as $r) {
            if (! in_array($r['gid'], $gids, true)) {
                continue;
            }
            if ($r['date'] >= $fromClarionDate) {
                $days[] = $r;
            } else {
                $k = $r['gid'].'|'.$r['warehouse_code'];
                if (! isset($before[$k]) || $before[$k]['date'] < $r['date']) {
                    $before[$k] = $r;
                }
            }
        }

        return ['before' => array_values($before), 'days' => $days];
    }

    public function itemSaleLines(array $itemGids, int $fromClarionDate): iterable
    {
        $this->saleLineCalls[] = ['items' => array_values($itemGids), 'from' => $fromClarionDate];
        foreach ($this->saleLineRows as $row) {
            if ($row['date'] >= $fromClarionDate && in_array($row['item_gid'], $itemGids, true)) {
                yield $row;
            }
        }
    }

    /** @var list<array{customer_gid: int, net: float, documents: int, last_date: int}> */
    public array $salesTotalRows = [];

    /** @var array{0: int, 1: int}|null ostatni okres customerSalesTotals */
    public ?array $salesTotalsPeriod = null;

    /** @var list<array<string, mixed>> karty jak z customerCards (pomocnik card()) */
    public array $cardRows = [];

    /** @var list<string> */
    public array $cardUnavailable = [];

    /** @var list<array{customer_gid: int, name: ?string, position: ?string, email: ?string, phone: ?string, mobile: ?string}> */
    public array $contactRows = [];

    /** @var list<string> */
    public array $contactUnavailable = [];

    /** @var list<array{customer_gid: int, employee_gid?: ?int, first_name: ?string, last_name: ?string, acronym: ?string, email: ?string}> */
    public array $managerRows = [];

    /** @var list<list<int>> listy kontrahentów, o które pytano customerCards */
    public array $cardCalls = [];

    public function customerSalesTotals(int $fromClarionDate, int $toClarionDate): array
    {
        $this->salesTotalsPeriod = [$fromClarionDate, $toClarionDate];

        return $this->salesTotalRows;
    }

    public function customerCards(array $gids): array
    {
        $this->cardCalls[] = array_values($gids);
        $rows = array_values(array_filter($this->cardRows, static fn (array $r): bool => in_array($r['gid'], $gids, true)));
        foreach ($rows as &$row) {
            foreach ($this->cardUnavailable as $field) {
                $row[$field] = null;
            }
        }

        return ['rows' => $rows, 'unavailable' => $this->cardUnavailable];
    }

    public function customerContacts(array $gids): array
    {
        $rows = array_values(array_filter($this->contactRows, static fn (array $r): bool => in_array($r['customer_gid'], $gids, true)));

        return ['rows' => $rows, 'unavailable' => $this->contactUnavailable];
    }

    public function customerManagers(array $gids, int $onClarionDate): array
    {
        $rows = array_values(array_filter($this->managerRows, static fn (array $r): bool => in_array($r['customer_gid'], $gids, true)));

        // starsze testy podają opiekuna bez numeru pracownika — jak XL bez KtO_PrcNumer
        return array_map(static fn (array $r): array => ['employee_gid' => null, ...$r], $rows);
    }

    /** @var list<array{document_type: int, document_id: int, document_number: string, date: int, customer_gid: int, net_value: float}> */
    public array $documentRows = [];

    /** @var list<array{document_type: int, document_id: int, document_number: string, date: int, customer_gid: int, item_gid: int, quantity: float, net_value: float}> */
    public array $documentLineRows = [];

    /** @var list<array{gids: list<int>, from: int}> wywołania customerDocuments */
    public array $documentCalls = [];

    /** @var list<array{gids: list<int>, from: int}> wywołania customerDocumentLines */
    public array $documentLineCalls = [];

    public function customerDocuments(array $gids, int $fromClarionDate): iterable
    {
        $this->documentCalls[] = ['gids' => array_values($gids), 'from' => $fromClarionDate];
        foreach ($this->documentRows as $row) {
            if ($row['date'] >= $fromClarionDate && in_array($row['customer_gid'], $gids, true)) {
                yield $row;
            }
        }
    }

    public function customerDocumentLines(array $gids, int $fromClarionDate): iterable
    {
        $this->documentLineCalls[] = ['gids' => array_values($gids), 'from' => $fromClarionDate];
        foreach ($this->documentLineRows as $row) {
            if ($row['date'] >= $fromClarionDate && in_array($row['customer_gid'], $gids, true)) {
                yield $row;
            }
        }
    }

    /**
     * Nagłówek dokumentu sprzedaży jak z customerDocuments (2033 FS, 2034 PA, 2037 FSE, 2041/2042 korekty).
     *
     * @return array{document_type: int, document_id: int, document_number: string, date: int, customer_gid: int, net_value: float}
     */
    public static function saleDocument(int $documentId, int $date, int $customerGid, float $netValue, int $type = 2033): array
    {
        $prefix = [2033 => 'FS', 2034 => 'PA', 2037 => 'FSE', 2041 => 'FSK', 2042 => 'PAK'][$type] ?? 'dok. '.$type;

        return [
            'document_type' => $type, 'document_id' => $documentId, 'document_number' => $prefix.'-01H/'.$documentId.'/26/09',
            'date' => $date, 'customer_gid' => $customerGid, 'net_value' => $netValue,
        ];
    }

    /**
     * Pozycja dokumentu sprzedaży jak z customerDocumentLines.
     *
     * @return array{document_type: int, document_id: int, document_number: string, date: int, customer_gid: int, item_gid: int, quantity: float, net_value: float}
     */
    public static function documentLine(int $documentId, int $date, int $customerGid, int $itemGid, float $quantity, float $netValue, int $type = 2033): array
    {
        $prefix = [2033 => 'FS', 2034 => 'PA', 2037 => 'FSE'][$type] ?? 'dok. '.$type;

        return [
            'document_type' => $type, 'document_id' => $documentId, 'document_number' => $prefix.'-01H/'.$documentId.'/26/09',
            'date' => $date, 'customer_gid' => $customerGid, 'item_gid' => $itemGid, 'quantity' => $quantity, 'net_value' => $netValue,
        ];
    }

    /** @return array<string, mixed> karta kontrahenta z customerCards, nadpisania w $overrides */
    public static function card(int $gid, string $acronym, array $overrides = []): array
    {
        return [
            'gid' => $gid, 'acronym' => $acronym, 'name' => 'Firma '.$acronym, 'nip' => null, 'nip_prefix' => 'PL', 'regon' => null,
            'street' => 'ul. Długa 1', 'address_line2' => null, 'postal_code' => '35-001', 'city' => 'Rzeszów', 'county' => null,
            'commune' => null, 'voivodeship' => 'podkarpackie', 'country' => 'Polska', 'phone' => '17 850 00 00', 'phone2' => null,
            'fax' => null, 'email' => null, 'website' => null, 'archived' => false, ...$overrides,
        ];
    }

    /** @return array{customer_gid: int, net: float, documents: int, last_date: int} */
    public static function salesTotal(int $customerGid, float $net, int $lastDate, int $documents = 1): array
    {
        return ['customer_gid' => $customerGid, 'net' => $net, 'documents' => $documents, 'last_date' => $lastDate];
    }

    /**
     * Pozycja FS/PA jak z itemSaleLines; $cost = TrE_KosztKsiegowy (0 = XL nie podał kosztu).
     *
     * @return array{document_type: int, document_id: int, line: int, document_number: string, date: int, customer_gid: int, item_gid: int, quantity: float, net_value: float, cost: float, corrects_type: int|null, corrects_id: int|null}
     */
    public static function saleLine(int $documentId, int $date, int $customerGid, int $itemGid, float $quantity, float $netValue, int $line = 1, int $type = 2033, float $cost = 0.0): array
    {
        $prefix = [2033 => 'FS', 2034 => 'PA', 2041 => 'FSK', 2042 => 'PAK'][$type] ?? 'dok. '.$type;

        return [
            'document_type' => $type, 'document_id' => $documentId, 'line' => $line, 'document_number' => $prefix.'-01H/'.$documentId.'/26/09',
            'date' => $date, 'customer_gid' => $customerGid, 'item_gid' => $itemGid, 'quantity' => $quantity, 'net_value' => $netValue,
            'cost' => $cost, 'corrects_type' => null, 'corrects_id' => null,
        ];
    }

    /**
     * Pozycja korekty (FSK 2041 / PAK 2042) jak z itemSaleLines: ilość, wartość i koszt ze znakiem, dokument korygowany
     * z nagłówka korekty ($correctsType / $correctsId).
     *
     * @return array{document_type: int, document_id: int, line: int, document_number: string, date: int, customer_gid: int, item_gid: int, quantity: float, net_value: float, cost: float, corrects_type: int|null, corrects_id: int|null}
     */
    public static function correctionLine(int $documentId, int $date, int $customerGid, int $itemGid, float $quantity, float $netValue, int $correctsId, float $cost = 0.0, int $line = 1, int $type = 2041, ?int $correctsType = null): array
    {
        return [
            ...self::saleLine($documentId, $date, $customerGid, $itemGid, $quantity, $netValue, $line, $type, $cost),
            'corrects_type' => $correctsType ?? ($type === 2042 ? 2034 : 2033),
            'corrects_id' => $correctsId,
        ];
    }

    /** @return array{gid: int, acronym: string, name: string, nip: ?string, city: ?string, email: ?string, archived: bool} */
    public static function customer(int $gid, string $acronym, ?string $email = null, bool $archived = false, string $name = ''): array
    {
        return ['gid' => $gid, 'acronym' => $acronym, 'name' => $name !== '' ? $name : 'Firma '.$acronym, 'nip' => null, 'city' => 'Rzeszów', 'email' => $email, 'archived' => $archived];
    }

    /** @return array{customer_gid: int, item_gid: int, last_date: int, documents: int, quantity: float} */
    public static function customerSale(int $customerGid, int $itemGid, int $lastDate, int $documents = 1, float $quantity = 1.0): array
    {
        return ['customer_gid' => $customerGid, 'item_gid' => $itemGid, 'last_date' => $lastDate, 'documents' => $documents, 'quantity' => $quantity];
    }

    /** @return array{customer_gid: int, operator: string, operator_name: ?string, documents: int} */
    public static function customerOperator(int $customerGid, string $operator, int $documents, ?string $name = null): array
    {
        return ['customer_gid' => $customerGid, 'operator' => $operator, 'operator_name' => $name ?? ($operator !== '' ? 'Osoba '.$operator : null), 'documents' => $documents];
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
            'operator_name' => $operator !== null ? 'Osoba '.$operator : null,
            'approver_name' => ($approver ?? $operator) !== null ? 'Osoba '.($approver ?? $operator) : null,
            'note' => null,
            'foreign_number' => null,
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

<?php

declare(strict_types=1);

namespace App\Services\Erp;

/**
 * Odczyt z Comarch ERP XL — zwykłe tablice, żeby synchronizację dało się sprawdzić bez MS SQL (atrapa w testach).
 * Liczby i daty dosłownie z XL (data Clarion jako int); przeliczenia robi ErpItemSync.
 */
interface ErpXlGateway
{
    public function configured(): bool;

    /**
     * @return array{ok: bool, message: string, items: int}
     */
    public function ping(): array;

    /**
     * Towary (Twr_Typ = 1) o numerze większym niż $afterGid, rosnąco.
     *
     * @return list<array{gid: int, code: string, name: string, name1: string, ean: string, unit: string, archived: bool}>
     */
    public function items(int $afterGid, int $limit): array;

    /**
     * Stan na magazynach (suma zasobów/partii na magazyn, bez zer) z wartością księgową netto partii w PLN i znacznikiem
     * czasu XL przyjęcia najstarszej partii (XlTimestamp).
     *
     * @param  list<int>  $gids
     * @return list<array{gid: int, warehouse_code: string, warehouse_name: string, quantity: float, value?: float|null, oldest_lot?: int|null}>
     */
    public function stock(array $gids): array;

    /**
     * Ostatnie pozycje PZ na towar (najnowsze pierwsze), dodatnia ilość.
     *
     * @param  list<int>  $gids
     * @return list<array{gid: int, document_type: int, document_id: int, document_line: int, document_state: int|null, date: int, supplier_id: int|null, supplier: string, quantity: float, document_unit: string, net_value_pln: float, document_price: float, currency: string}>
     */
    public function purchases(array $gids, int $perItem): array;

    /**
     * Dostawcy z karty towaru (CDN.TwrDost) — cena u dostawcy z datą aktualizacji.
     *
     * @param  list<int>  $gids
     * @return list<array{gid: int, supplier_id: int|null, supplier: string, price: float, currency: string, updated: int}>
     */
    public function suppliers(array $gids): array;

    /**
     * Data (Clarion) ostatniej sprzedaży (FS, PA, WZ) na towar.
     *
     * @param  list<int>  $gids
     * @return array<int, int> gid => data
     */
    public function lastSales(array $gids): array;

    /**
     * Zatwierdzone RW (rozchód wewnętrzny) i PW (przychód wewnętrzny) od daty — suma pozycji na dokument i towar, tylko
     * towary, które w tym okresie miały choć jedno PW. Magazyn: RW — źródłowy (MagZ), PW — docelowy (MagD).
     * Operator: akronim z CDN.OpeKarty — kto wystawił (W) i kto zatwierdził (Z).
     *
     * @return list<array{type: 'rw'|'pw', document_id: int, number: string, date: int, warehouse: string|null, operator: string|null, approver: string|null, gid: int, quantity: float, value: float}>
     */
    public function internalMoves(int $fromClarionDate): array;
}

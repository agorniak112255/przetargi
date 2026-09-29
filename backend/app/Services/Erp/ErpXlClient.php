<?php

declare(strict_types=1);

namespace App\Services\Erp;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Comarch ERP XL przez połączenie „erpxl” (MS SQL, login z samym SELECT). Tabele CDN: TwrKarty, TwrZasoby, Magazyny,
 * TraNag, TraElem, TwrDost, KntKarty (tylko numer i akronim).
 */
final class ErpXlClient implements ErpXlGateway
{
    /** PZ — przyjęcie zewnętrzne. Faktury zakupu (1521) w tej bazie nie mają powiązanych towarów (29.09.2026). */
    private const PURCHASE_TYPES = [1489];

    /** FS, PA, WZ. */
    private const SALE_TYPES = [2033, 2034, 2001];

    public function configured(): bool
    {
        return (bool) config('erpxl.enabled')
            && (string) config('database.connections.erpxl.host') !== ''
            && (string) config('database.connections.erpxl.username') !== '';
    }

    public function ping(): array
    {
        if (! $this->configured()) {
            return ['ok' => false, 'message' => 'Połączenie z ERP XL jest wyłączone albo nieuzupełnione (ERPXL_*).', 'items' => 0];
        }
        try {
            $count = (int) $this->db()->table('CDN.TwrKarty')->where('Twr_Typ', 1)->count();

            return ['ok' => true, 'message' => 'Połączenie OK. Towarów w XL: '.$count, 'items' => $count];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'items' => 0];
        }
    }

    public function items(int $afterGid, int $limit): array
    {
        $rows = $this->db()->table('CDN.TwrKarty')
            ->select(['Twr_GIDNumer', 'Twr_Kod', 'Twr_Nazwa', 'Twr_Nazwa1', 'Twr_Ean', 'Twr_Jm', 'Twr_Archiwalny'])
            ->where('Twr_Typ', 1)
            ->where('Twr_GIDNumer', '>', $afterGid)
            ->orderBy('Twr_GIDNumer')
            ->limit($limit)
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'gid' => (int) $r->Twr_GIDNumer,
                'code' => trim((string) $r->Twr_Kod),
                'name' => trim((string) $r->Twr_Nazwa),
                'name1' => trim((string) $r->Twr_Nazwa1),
                'ean' => trim((string) $r->Twr_Ean),
                'unit' => trim((string) $r->Twr_Jm),
                'archived' => (int) $r->Twr_Archiwalny !== 0,
            ];
        }

        return $out;
    }

    public function stock(array $gids): array
    {
        if ($gids === []) {
            return [];
        }
        $rows = $this->db()->table('CDN.TwrZasoby as z')
            ->join('CDN.Magazyny as m', function ($join): void {
                $join->on('m.MAG_GIDNumer', '=', 'z.TwZ_MagNumer')->on('m.MAG_GIDTyp', '=', 'z.TwZ_MagTyp');
            })
            ->whereIn('z.TwZ_TwrNumer', $gids)
            ->groupBy('z.TwZ_TwrNumer', 'm.MAG_Kod', 'm.MAG_Nazwa')
            ->havingRaw('SUM(z.TwZ_Ilosc) <> 0')
            // wartość księgowa netto partii i przyjęcie najstarszej partii z dodatnią ilością — te same wiersze co stan
            ->selectRaw('z.TwZ_TwrNumer AS gid, m.MAG_Kod AS warehouse_code, m.MAG_Nazwa AS warehouse_name, SUM(z.TwZ_Ilosc) AS quantity,'
                .' SUM(z.TwZ_KsiegowaNetto) AS book_value, MIN(CASE WHEN z.TwZ_Ilosc > 0 AND z.TwZ_DataP > 0 THEN z.TwZ_DataP END) AS oldest_lot')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'gid' => (int) $r->gid,
                'warehouse_code' => trim((string) $r->warehouse_code),
                'warehouse_name' => trim((string) $r->warehouse_name),
                'quantity' => (float) $r->quantity,
                'value' => $r->book_value !== null ? (float) $r->book_value : null,
                'oldest_lot' => $r->oldest_lot !== null ? (int) $r->oldest_lot : null,
            ];
        }

        return $out;
    }

    public function purchases(array $gids, int $perItem): array
    {
        if ($gids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($gids), '?'));
        $types = implode(',', self::PURCHASE_TYPES);
        $sql = <<<SQL
            SELECT gid, document_type, document_id, document_line, document_state, doc_date, supplier_id, supplier,
                   quantity, document_unit, net_value_pln, document_price, currency
            FROM (
                SELECT e.TrE_TwrNumer AS gid, e.TrE_GIDTyp AS document_type, e.TrE_GIDNumer AS document_id,
                       e.TrE_GIDLp AS document_line, n.TrN_Stan AS document_state, n.TrN_Data2 AS doc_date,
                       e.TrE_KntNumer AS supplier_id, k.Knt_Akronim AS supplier, e.TrE_Ilosc AS quantity,
                       e.TrE_JmZ AS document_unit, e.TrE_KsiegowaNetto AS net_value_pln, e.TrE_Cena AS document_price,
                       e.TrE_Waluta AS currency,
                       ROW_NUMBER() OVER (PARTITION BY e.TrE_TwrNumer
                                          ORDER BY n.TrN_Data2 DESC, e.TrE_GIDNumer DESC, e.TrE_GIDLp DESC) AS nr
                FROM CDN.TraElem e
                JOIN CDN.TraNag n ON n.TrN_GIDTyp = e.TrE_GIDTyp AND n.TrN_GIDNumer = e.TrE_GIDNumer
                LEFT JOIN CDN.KntKarty k ON k.Knt_GIDNumer = e.TrE_KntNumer AND k.Knt_GIDTyp = e.TrE_KntTyp
                WHERE e.TrE_GIDTyp IN ($types) AND e.TrE_Ilosc > 0 AND e.TrE_TwrNumer IN ($in)
            ) x
            WHERE nr <= ?
            SQL;
        $rows = $this->db()->select($sql, [...array_values($gids), $perItem]);

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'gid' => (int) $r->gid,
                'document_type' => (int) $r->document_type,
                'document_id' => (int) $r->document_id,
                'document_line' => (int) $r->document_line,
                'document_state' => $r->document_state === null ? null : (int) $r->document_state,
                'date' => (int) $r->doc_date,
                'supplier_id' => $r->supplier_id === null ? null : (int) $r->supplier_id,
                'supplier' => trim((string) $r->supplier),
                'quantity' => (float) $r->quantity,
                'document_unit' => trim((string) $r->document_unit),
                'net_value_pln' => (float) $r->net_value_pln,
                'document_price' => (float) $r->document_price,
                'currency' => trim((string) $r->currency),
            ];
        }

        return $out;
    }

    public function suppliers(array $gids): array
    {
        if ($gids === []) {
            return [];
        }
        $rows = $this->db()->table('CDN.TwrDost as d')
            ->leftJoin('CDN.KntKarty as k', function ($join): void {
                $join->on('k.Knt_GIDNumer', '=', 'd.TWD_KntNumer')->on('k.Knt_GIDTyp', '=', 'd.TWD_KntTyp');
            })
            ->whereIn('d.TWD_TwrNumer', $gids)
            ->select(['d.TWD_TwrNumer', 'd.TWD_KntNumer', 'k.Knt_Akronim', 'd.TWD_Cena', 'd.TWD_Waluta', 'd.TWD_DataAkt'])
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'gid' => (int) $r->TWD_TwrNumer,
                'supplier_id' => $r->TWD_KntNumer === null ? null : (int) $r->TWD_KntNumer,
                'supplier' => trim((string) $r->Knt_Akronim),
                'price' => (float) $r->TWD_Cena,
                'currency' => trim((string) $r->TWD_Waluta),
                'updated' => (int) $r->TWD_DataAkt,
            ];
        }

        return $out;
    }

    public function lastSales(array $gids): array
    {
        if ($gids === []) {
            return [];
        }
        $rows = $this->db()->table('CDN.TraElem as e')
            ->join('CDN.TraNag as n', function ($join): void {
                $join->on('n.TrN_GIDTyp', '=', 'e.TrE_GIDTyp')->on('n.TrN_GIDNumer', '=', 'e.TrE_GIDNumer');
            })
            ->whereIn('e.TrE_GIDTyp', self::SALE_TYPES)
            ->whereIn('e.TrE_TwrNumer', $gids)
            ->groupBy('e.TrE_TwrNumer')
            ->selectRaw('e.TrE_TwrNumer AS gid, MAX(n.TrN_Data2) AS last_date')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->gid] = (int) $r->last_date;
        }

        return $out;
    }

    private function db(): ConnectionInterface
    {
        if (! $this->configured()) {
            throw new RuntimeException('Połączenie z ERP XL jest wyłączone albo nieuzupełnione (ERPXL_*).');
        }

        return DB::connection('erpxl');
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Erp;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Comarch ERP XL przez połączenie „erpxl” (MS SQL, login z samym SELECT). Tabele CDN: TwrKarty, TwrZasoby, Magazyny,
 * TraNag, TraElem, TwrDost, KntKarty i KntAdresy (tylko kolumny, do których login ma prawo), OpeKarty (Ope_Ident,
 * Ope_Nazwisko).
 */
final class ErpXlClient implements ErpXlGateway
{
    /** PZ — przyjęcie zewnętrzne. Faktury zakupu (1521) w tej bazie nie mają powiązanych towarów (29.09.2026). */
    private const PURCHASE_TYPES = [1489];

    /** FS, PA, WZ. */
    private const SALE_TYPES = [2033, 2034, 2001];

    /** RW — rozchód wewnętrzny, PW — przychód wewnętrzny (numery z XL: RW-15H/30/26/07 = 1616, PW-15H/38/23/03 = 1617). */
    private const RW_TYPE = 1616;

    private const PW_TYPE = 1617;

    /**
     * TrN_Stan dokumentu zatwierdzonego: 30.09.2026 wszystkie RW/PW oglądane w XL jako „Zatwierdzone” miały 5. Stan 6
     * (najpewniej anulowane) i 2 (w toku) się nie liczą — do potwierdzenia w XL.
     */
    private const CONFIRMED_STATE = 5;

    /** Kontrahent (Knt_GIDTyp, TrN_KntTyp, KnA_KntTyp). */
    private const CUSTOMER_TYPE = 32;

    /** Aktywny adres kontrahenta; 896 to archiwalne kopie adresów z dokumentów (sprawdzone 30.09.2026). */
    private const ADDRESS_TYPE = 864;

    /** Sprzedaż do klienta w kampaniach: FS i PA. WZ pomijamy — dubluje FS. */
    private const CUSTOMER_SALE_TYPES = [2033, 2034];

    /** Stany FS/PA, które się liczą (30.09.2026: FS ma 0–6, 5 = ~97%; 6 = anulowane, 0–2 = bufor/w toku). */
    private const CUSTOMER_SALE_STATES = [3, 4, 5];

    /** Skróty dokumentów, którymi partia weszła na magazyn (CDN.Dostawy.Dst_TrnTyp). */
    private const DOCUMENT_PREFIXES = [1489 => 'PZ', 1617 => 'PW', 1521 => 'FZ', 1616 => 'RW'];

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
            ->leftJoin('CDN.Magazyny as m', function ($join): void {
                $join->on('m.MAG_GIDNumer', '=', 'n.TrN_MagZNumer')->on('m.MAG_GIDTyp', '=', 'n.TrN_MagZTyp');
            })
            ->whereIn('e.TrE_GIDTyp', self::SALE_TYPES)
            ->whereIn('e.TrE_TwrNumer', $gids)
            ->groupBy('e.TrE_TwrNumer', 'm.MAG_Kod')
            ->selectRaw('e.TrE_TwrNumer AS gid, m.MAG_Kod AS warehouse_code, MAX(n.TrN_Data2) AS last_date')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $code = $r->warehouse_code !== null ? trim((string) $r->warehouse_code) : '';
            $out[] = ['gid' => (int) $r->gid, 'warehouse_code' => $code !== '' ? $code : null, 'date' => (int) $r->last_date];
        }

        return $out;
    }

    public function internalMoves(int $fromClarionDate): array
    {
        $rw = self::RW_TYPE;
        $pw = self::PW_TYPE;
        $state = self::CONFIRMED_STATE;
        // tylko towary z choć jednym PW w okresie — reszta RW (zwykłe wydania do zużycia) nie ma z czym tworzyć pary
        $sql = <<<SQL
            SELECT n.TrN_GIDTyp AS doc_type, n.TrN_GIDNumer AS document_id, n.TrN_TrNSeria AS series,
                   n.TrN_TrNNumer AS doc_number, n.TrN_TrNRok AS doc_year, n.TrN_TrNMiesiac AS doc_month,
                   n.TrN_Data2 AS doc_date, m.MAG_Kod AS warehouse, ow.Ope_Ident AS operator, oz.Ope_Ident AS approver,
                   ow.Ope_Nazwisko AS operator_name, oz.Ope_Nazwisko AS approver_name, n.TrN_DokumentObcy AS foreign_number,
                   (SELECT TOP 1 o.TnO_Opis FROM CDN.TrNOpisy o
                     WHERE o.TnO_TrnTyp = n.TrN_GIDTyp AND o.TnO_TrnNumer = n.TrN_GIDNumer AND o.TnO_TrnLp = 0
                     ORDER BY o.TnO_Typ) AS note,
                   e.TrE_TwrNumer AS gid, SUM(e.TrE_Ilosc) AS quantity, SUM(e.TrE_KsiegowaNetto) AS book_value
            FROM CDN.TraElem e
            JOIN CDN.TraNag n ON n.TrN_GIDTyp = e.TrE_GIDTyp AND n.TrN_GIDNumer = e.TrE_GIDNumer
            LEFT JOIN CDN.Magazyny m
                ON m.MAG_GIDNumer = CASE WHEN n.TrN_GIDTyp = $pw THEN n.TrN_MagDNumer ELSE n.TrN_MagZNumer END
               AND m.MAG_GIDTyp = CASE WHEN n.TrN_GIDTyp = $pw THEN n.TrN_MagDTyp ELSE n.TrN_MagZTyp END
            LEFT JOIN CDN.OpeKarty ow ON ow.Ope_GIDNumer = n.TrN_OpeNumerW AND ow.Ope_GIDTyp = n.TrN_OpeTypW
            LEFT JOIN CDN.OpeKarty oz ON oz.Ope_GIDNumer = n.TrN_OpeNumerZ AND oz.Ope_GIDTyp = n.TrN_OpeTypZ
            WHERE n.TrN_GIDTyp IN ($rw, $pw) AND n.TrN_Stan = $state AND n.TrN_Data2 >= ?
              AND e.TrE_TwrNumer IN (
                  SELECT e2.TrE_TwrNumer FROM CDN.TraElem e2
                  JOIN CDN.TraNag n2 ON n2.TrN_GIDTyp = e2.TrE_GIDTyp AND n2.TrN_GIDNumer = e2.TrE_GIDNumer
                  WHERE n2.TrN_GIDTyp = $pw AND n2.TrN_Stan = $state AND n2.TrN_Data2 >= ?
              )
            GROUP BY n.TrN_GIDTyp, n.TrN_GIDNumer, n.TrN_TrNSeria, n.TrN_TrNNumer, n.TrN_TrNRok, n.TrN_TrNMiesiac,
                     n.TrN_Data2, m.MAG_Kod, ow.Ope_Ident, oz.Ope_Ident, ow.Ope_Nazwisko, oz.Ope_Nazwisko,
                     n.TrN_DokumentObcy, e.TrE_TwrNumer
            SQL;
        $rows = $this->db()->select($sql, [$fromClarionDate, $fromClarionDate]);

        $out = [];
        foreach ($rows as $r) {
            $isPw = (int) $r->doc_type === $pw;
            $out[] = [
                'type' => $isPw ? 'pw' : 'rw',
                'document_id' => (int) $r->document_id,
                'number' => $this->documentNumber($isPw ? 'PW' : 'RW', $r->series, $r->doc_number, $r->doc_year, $r->doc_month),
                'date' => (int) $r->doc_date,
                'warehouse' => $r->warehouse !== null && trim((string) $r->warehouse) !== '' ? trim((string) $r->warehouse) : null,
                'operator' => $r->operator !== null && trim((string) $r->operator) !== '' ? trim((string) $r->operator) : null,
                'approver' => $r->approver !== null && trim((string) $r->approver) !== '' ? trim((string) $r->approver) : null,
                // imię i nazwisko dosłownie z XL (bywa „Nazwisko Imię” i „Imię Nazwisko”)
                'operator_name' => $this->text($r->operator_name),
                'approver_name' => $this->text($r->approver_name),
                'note' => $this->text($r->note),
                'foreign_number' => $this->text($r->foreign_number),
                'gid' => (int) $r->gid,
                'quantity' => (float) $r->quantity,
                'value' => (float) $r->book_value,
            ];
        }

        return $out;
    }

    public function internalMoveLots(int $fromClarionDate): array
    {
        $rw = self::RW_TYPE;
        $pw = self::PW_TYPE;
        $state = self::CONFIRMED_STATE;
        // TraSElem: pozycja RW/PW → partia (TrS_DstNumer), ilość i cecha; Dostawy: przyjęcie partii i jej dokument
        $sql = <<<SQL
            SELECT s.TrS_GIDTyp AS doc_type, s.TrS_GIDNumer AS document_id, d.Dst_TwrNumer AS gid,
                   d.Dst_DstTStamp AS received_at, s.TrS_Cecha AS feature,
                   SUM(s.TrS_Ilosc) AS quantity, d.Dst_TrnTyp AS source_type, src.TrN_TrNSeria AS series,
                   src.TrN_TrNNumer AS doc_number, src.TrN_TrNRok AS doc_year, src.TrN_TrNMiesiac AS doc_month
            FROM CDN.TraSElem s
            JOIN CDN.TraNag n ON n.TrN_GIDTyp = s.TrS_GIDTyp AND n.TrN_GIDNumer = s.TrS_GIDNumer
            JOIN CDN.Dostawy d ON d.Dst_GIDTyp = s.TrS_DstTyp AND d.Dst_GIDNumer = s.TrS_DstNumer
            LEFT JOIN CDN.TraNag src ON src.TrN_GIDTyp = d.Dst_TrnTyp AND src.TrN_GIDNumer = d.Dst_TrnNumer
            WHERE s.TrS_GIDTyp IN ($rw, $pw) AND n.TrN_Stan = $state AND n.TrN_Data2 >= ?
              AND d.Dst_TwrNumer IN (
                  SELECT e2.TrE_TwrNumer FROM CDN.TraElem e2
                  JOIN CDN.TraNag n2 ON n2.TrN_GIDTyp = e2.TrE_GIDTyp AND n2.TrN_GIDNumer = e2.TrE_GIDNumer
                  WHERE n2.TrN_GIDTyp = $pw AND n2.TrN_Stan = $state AND n2.TrN_Data2 >= ?
              )
            GROUP BY s.TrS_GIDTyp, s.TrS_GIDNumer, d.Dst_TwrNumer, d.Dst_GIDNumer, d.Dst_DstTStamp, s.TrS_Cecha, d.Dst_TrnTyp,
                     src.TrN_TrNSeria, src.TrN_TrNNumer, src.TrN_TrNRok, src.TrN_TrNMiesiac
            SQL;
        $rows = $this->db()->select($sql, [$fromClarionDate, $fromClarionDate]);

        $out = [];
        foreach ($rows as $r) {
            $type = (int) $r->source_type;
            $out[] = [
                'type' => (int) $r->doc_type === $pw ? 'pw' : 'rw',
                'document_id' => (int) $r->document_id,
                'gid' => (int) $r->gid,
                'received_at' => (int) $r->received_at,
                'quantity' => (float) $r->quantity,
                'feature' => trim((string) $r->feature),
                'source_type' => $type,
                'source_number' => $r->doc_number === null ? null
                    : $this->documentNumber(self::DOCUMENT_PREFIXES[$type] ?? 'dok. '.$type, $r->series, $r->doc_number, $r->doc_year, $r->doc_month),
            ];
        }

        return $out;
    }

    public function customers(int $afterGid, int $limit): array
    {
        $rows = $this->db()->table('CDN.KntKarty')
            ->select(['Knt_GIDNumer', 'Knt_Akronim', 'Knt_Nazwa1', 'Knt_Nazwa2', 'Knt_Nazwa3', 'Knt_Nip', 'Knt_NipE', 'Knt_Miasto', 'Knt_EMail', 'Knt_Archiwalny'])
            ->where('Knt_GIDTyp', self::CUSTOMER_TYPE)
            ->where('Knt_GIDNumer', '>', $afterGid)
            ->orderBy('Knt_GIDNumer')
            ->limit($limit)
            ->get();

        $out = [];
        foreach ($rows as $r) {
            // nazwa w XL rozpisana na trzy wiersze — łączymy dosłownie spacją
            $name = implode(' ', array_filter(
                [$this->text($r->Knt_Nazwa1), $this->text($r->Knt_Nazwa2), $this->text($r->Knt_Nazwa3)],
                static fn (?string $part): bool => $part !== null,
            ));
            $out[] = [
                'gid' => (int) $r->Knt_GIDNumer,
                'acronym' => trim((string) $r->Knt_Akronim),
                'name' => $name,
                // NIP jak na karcie; gdy pusty — postać elektroniczna (same cyfry)
                'nip' => $this->text($r->Knt_Nip) ?? $this->text($r->Knt_NipE),
                'city' => $this->text($r->Knt_Miasto),
                'email' => $this->text($r->Knt_EMail),
                'archived' => (int) $r->Knt_Archiwalny !== 0,
            ];
        }

        return $out;
    }

    public function customerAddressEmails(): iterable
    {
        $rows = $this->db()->table('CDN.KntAdresy')
            ->select(['KnA_KntNumer', 'KnA_EMail'])
            ->where('KnA_GIDTyp', self::ADDRESS_TYPE)
            ->where('KnA_KntTyp', self::CUSTOMER_TYPE)
            ->whereNotNull('KnA_EMail')
            ->where('KnA_EMail', '<>', '')
            ->orderBy('KnA_KntNumer')
            ->orderBy('KnA_GIDNumer')
            ->cursor();

        foreach ($rows as $r) {
            $email = $this->text($r->KnA_EMail);
            if ($email !== null) {
                yield ['gid' => (int) $r->KnA_KntNumer, 'email' => $email];
            }
        }
    }

    public function customerSales(int $fromClarionDate): iterable
    {
        [$where, $bindings] = $this->customerSaleFilter($fromClarionDate);
        [$fs, $pa] = self::CUSTOMER_SALE_TYPES;
        // numery dokumentów liczone osobno dla FS i PA — GIDNumer jest unikalny w obrębie typu
        $sql = <<<SQL
            SELECT n.TrN_KntNumer AS customer_gid, e.TrE_TwrNumer AS item_gid, MAX(n.TrN_Data2) AS last_date,
                   COUNT(DISTINCT CASE WHEN n.TrN_GIDTyp = $fs THEN n.TrN_GIDNumer END)
                   + COUNT(DISTINCT CASE WHEN n.TrN_GIDTyp = $pa THEN n.TrN_GIDNumer END) AS documents,
                   SUM(e.TrE_Ilosc) AS quantity
            FROM CDN.TraElem e
            JOIN CDN.TraNag n ON n.TrN_GIDTyp = e.TrE_GIDTyp AND n.TrN_GIDNumer = e.TrE_GIDNumer
            WHERE $where AND e.TrE_Ilosc > 0
            GROUP BY n.TrN_KntNumer, e.TrE_TwrNumer
            SQL;

        foreach ($this->db()->cursor($sql, $bindings) as $r) {
            yield [
                'customer_gid' => (int) $r->customer_gid,
                'item_gid' => (int) $r->item_gid,
                'last_date' => (int) $r->last_date,
                'documents' => (int) $r->documents,
                'quantity' => (float) $r->quantity,
            ];
        }
    }

    public function customerOperators(int $fromClarionDate): iterable
    {
        [$where, $bindings] = $this->customerSaleFilter($fromClarionDate);
        // tylko dokumenty z choć jedną pozycją z dodatnią ilością — te same, które liczy customerSales
        $sql = <<<SQL
            SELECT n.TrN_KntNumer AS customer_gid, o.Ope_Ident AS operator, o.Ope_Nazwisko AS operator_name,
                   COUNT(*) AS documents
            FROM CDN.TraNag n
            LEFT JOIN CDN.OpeKarty o ON o.Ope_GIDNumer = n.TrN_OpeNumerW AND o.Ope_GIDTyp = n.TrN_OpeTypW
            WHERE $where
              AND EXISTS (SELECT 1 FROM CDN.TraElem e
                          WHERE e.TrE_GIDTyp = n.TrN_GIDTyp AND e.TrE_GIDNumer = n.TrN_GIDNumer AND e.TrE_Ilosc > 0)
            GROUP BY n.TrN_KntNumer, o.Ope_Ident, o.Ope_Nazwisko
            SQL;

        foreach ($this->db()->cursor($sql, $bindings) as $r) {
            yield [
                'customer_gid' => (int) $r->customer_gid,
                'operator' => mb_strtoupper(trim((string) $r->operator)),
                'operator_name' => $this->text($r->operator_name),
                'documents' => (int) $r->documents,
            ];
        }
    }

    public function itemSaleLines(array $itemGids, int $fromClarionDate): iterable
    {
        [$where, $bindings] = $this->customerSaleFilter($fromClarionDate);
        $prefixes = [2033 => 'FS', 2034 => 'PA'];
        // paczkami — lista towarów kampanii jest krótka, ale limit parametrów MS SQL to 2100
        foreach (array_chunk(array_values(array_unique($itemGids)), 500) as $chunk) {
            $in = implode(',', array_map('intval', $chunk));
            $sql = <<<SQL
                SELECT n.TrN_GIDTyp AS doc_type, n.TrN_GIDNumer AS document_id, e.TrE_GIDLp AS line, n.TrN_TrNSeria AS series,
                       n.TrN_TrNNumer AS doc_number, n.TrN_TrNRok AS doc_year, n.TrN_TrNMiesiac AS doc_month, n.TrN_Data2 AS doc_date,
                       n.TrN_KntNumer AS customer_gid, e.TrE_TwrNumer AS item_gid, e.TrE_Ilosc AS quantity, e.TrE_KsiegowaNetto AS net_value
                FROM CDN.TraElem e
                JOIN CDN.TraNag n ON n.TrN_GIDTyp = e.TrE_GIDTyp AND n.TrN_GIDNumer = e.TrE_GIDNumer
                WHERE $where AND e.TrE_Ilosc > 0 AND e.TrE_TwrNumer IN ($in)
                SQL;
            foreach ($this->db()->cursor($sql, $bindings) as $r) {
                $type = (int) $r->doc_type;
                yield [
                    'document_type' => $type,
                    'document_id' => (int) $r->document_id,
                    'line' => (int) $r->line,
                    'document_number' => $this->documentNumber($prefixes[$type] ?? 'dok. '.$type, $r->series, $r->doc_number, $r->doc_year, $r->doc_month),
                    'date' => (int) $r->doc_date,
                    'customer_gid' => (int) $r->customer_gid,
                    'item_gid' => (int) $r->item_gid,
                    'quantity' => (float) $r->quantity,
                    'net_value' => (float) $r->net_value,
                ];
            }
        }
    }

    /**
     * Warunek sprzedaży do kontrahenta: FS/PA zatwierdzone (TrN_Stan 3–5; 6 = anulowane, 0–2 = bufor/w toku) od daty.
     *
     * @return array{0: string, 1: list<int>}
     */
    private function customerSaleFilter(int $fromClarionDate): array
    {
        $types = implode(',', self::CUSTOMER_SALE_TYPES);
        $states = implode(',', self::CUSTOMER_SALE_STATES);
        $customer = self::CUSTOMER_TYPE;

        return ["n.TrN_KntTyp = $customer AND n.TrN_GIDTyp IN ($types) AND n.TrN_Stan IN ($states) AND n.TrN_Data2 >= ?", [$fromClarionDate]];
    }

    /** Tekst z XL bez zbędnych spacji; pusty = null. */
    private function text(mixed $value): ?string
    {
        $value = $value === null ? '' : trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** Numer jak w XL: PW-15H/38/23/03 (seria/numer/rok dwucyfrowo/miesiąc). */
    private function documentNumber(string $prefix, mixed $series, mixed $number, mixed $year, mixed $month): string
    {
        return sprintf('%s-%s/%d/%02d/%02d', $prefix, trim((string) $series), (int) $number, (int) $year % 100, (int) $month);
    }

    private function db(): ConnectionInterface
    {
        if (! $this->configured()) {
            throw new RuntimeException('Połączenie z ERP XL jest wyłączone albo nieuzupełnione (ERPXL_*).');
        }

        return DB::connection('erpxl');
    }
}

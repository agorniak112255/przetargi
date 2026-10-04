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

    /**
     * Korekty FS (FSK 2041) i PA (PAK 2042) w wyniku kampanii — stany jak FS/PA, ilość i wartość ze znakiem; nagłówek
     * korekty wskazuje dokument korygowany (TrN_ZwrTyp / TrN_ZwrNumer, sprawdzone na produkcji 04.10.2026).
     */
    private const CUSTOMER_SALE_CORRECTION_TYPES = [2041, 2042];

    /**
     * Zakupy klienta w zakładce Klienci: FS, PA i FSE (2037, faktura eksportowa — Mittal w EUR, wartość księgowa w PLN).
     * 2036 to nie sprzedaż: seria 01K, kontrahenci-dostawcy (Ansell, Ardon, Malfini) — sprawdzone na produkcji 02.10.2026.
     */
    private const CLIENT_SALE_TYPES = [2033, 2034, 2037];

    /** Korekty FS (2041) i PA (2042) — wartości ze znakiem, pomniejszają zakupy. */
    private const CLIENT_CORRECTION_TYPES = [2041, 2042];

    /**
     * Skróty numerów dokumentów sprzedaży klienta. FSK i PAK (korekty FS i PA) — oznaczenia przyjęte w aplikacji,
     * do potwierdzenia z numeracją w XL (TraNag nie przechowuje symbolu dokumentu).
     */
    private const CLIENT_DOCUMENT_PREFIXES = [2033 => 'FS', 2034 => 'PA', 2037 => 'FSE', 2041 => 'FSK', 2042 => 'PAK'];

    /**
     * Data sprzedaży dokumentu (Clarion) do „ostatniej sprzedaży” (decyzja właściciela 01.10.2026, wariant B): dokument
     * w buforze (TrN_Stan < 3) ma TrN_Data2 przestawiane przez XL co noc na dziś — wtedy dzień ostatniej zmiany nagłówka
     * (TrN_LastMod, XlTimestamp → dni od 1.01.1990 + 69035 = Clarion). Zbiorcza WZ dopisywana co kilka dni zostaje
     * bieżącą sprzedażą, rezerwacja nieruszana od stycznia 2025 przestaje udawać sprzedaż z dzisiaj (01WZR, SZP21PPS).
     */
    private const SALE_DATE_SQL = 'CASE WHEN n.TrN_Stan < 3 AND n.TrN_LastMod > 0 THEN n.TrN_LastMod / 86400 + 69035 ELSE n.TrN_Data2 END';

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

    public function stockLots(array $gids): array
    {
        if ($gids === []) {
            return [];
        }
        // te same wiersze zasobów co stock(), tylko z dodatnią ilością i osobno na chwilę przyjęcia partii
        $rows = $this->db()->table('CDN.TwrZasoby as z')
            ->join('CDN.Magazyny as m', function ($join): void {
                $join->on('m.MAG_GIDNumer', '=', 'z.TwZ_MagNumer')->on('m.MAG_GIDTyp', '=', 'z.TwZ_MagTyp');
            })
            ->whereIn('z.TwZ_TwrNumer', $gids)
            ->where('z.TwZ_Ilosc', '>', 0)
            ->groupBy('z.TwZ_TwrNumer', 'm.MAG_Kod', 'z.TwZ_DataP')
            ->selectRaw('z.TwZ_TwrNumer AS gid, m.MAG_Kod AS warehouse_code, z.TwZ_DataP AS received_at, SUM(z.TwZ_Ilosc) AS quantity,'
                .' SUM(z.TwZ_KsiegowaNetto) AS book_value')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'gid' => (int) $r->gid,
                'warehouse_code' => trim((string) $r->warehouse_code),
                'received_at' => $r->received_at !== null ? (int) $r->received_at : null,
                'quantity' => (float) $r->quantity,
                'value' => $r->book_value !== null ? (float) $r->book_value : null,
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
            ->selectRaw('e.TrE_TwrNumer AS gid, m.MAG_Kod AS warehouse_code, MAX('.self::SALE_DATE_SQL.') AS last_date')
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
        $sales = implode(',', self::CUSTOMER_SALE_TYPES);
        $corrections = implode(',', self::CUSTOMER_SALE_CORRECTION_TYPES);
        $states = implode(',', self::CUSTOMER_SALE_STATES);
        $customer = self::CUSTOMER_TYPE;
        // FS/PA z dodatnią ilością jak dotąd; korekty z każdą niezerową ilością albo wartością (ilość 0 = korekta ceny)
        $where = "n.TrN_KntTyp = $customer AND n.TrN_Stan IN ($states) AND n.TrN_Data2 >= ?"
            ." AND ((n.TrN_GIDTyp IN ($sales) AND e.TrE_Ilosc > 0)"
            ." OR (n.TrN_GIDTyp IN ($corrections) AND (e.TrE_Ilosc <> 0 OR e.TrE_KsiegowaNetto <> 0)))";
        // paczkami — lista towarów kampanii jest krótka, ale limit parametrów MS SQL to 2100
        foreach (array_chunk(array_values(array_unique($itemGids)), 500) as $chunk) {
            $in = implode(',', array_map('intval', $chunk));
            $sql = <<<SQL
                SELECT n.TrN_GIDTyp AS doc_type, n.TrN_GIDNumer AS document_id, e.TrE_GIDLp AS line, n.TrN_TrNSeria AS series,
                       n.TrN_TrNNumer AS doc_number, n.TrN_TrNRok AS doc_year, n.TrN_TrNMiesiac AS doc_month, n.TrN_Data2 AS doc_date,
                       n.TrN_KntNumer AS customer_gid, e.TrE_TwrNumer AS item_gid, e.TrE_Ilosc AS quantity, e.TrE_KsiegowaNetto AS net_value,
                       e.TrE_KosztKsiegowy AS cost, n.TrN_ZwrTyp AS corrects_type, n.TrN_ZwrNumer AS corrects_id
                FROM CDN.TraElem e
                JOIN CDN.TraNag n ON n.TrN_GIDTyp = e.TrE_GIDTyp AND n.TrN_GIDNumer = e.TrE_GIDNumer
                WHERE $where AND e.TrE_TwrNumer IN ($in)
                SQL;
            foreach ($this->db()->cursor($sql, [$fromClarionDate]) as $r) {
                $type = (int) $r->doc_type;
                // dokument korygowany tylko z nagłówka korekty (0 = XL go nie podał)
                $isCorrection = in_array($type, self::CUSTOMER_SALE_CORRECTION_TYPES, true);
                $correctsType = $isCorrection && (int) $r->corrects_type > 0 ? (int) $r->corrects_type : null;
                $correctsId = $isCorrection && (int) $r->corrects_id > 0 ? (int) $r->corrects_id : null;
                yield [
                    'document_type' => $type,
                    'document_id' => (int) $r->document_id,
                    'line' => (int) $r->line,
                    'document_number' => $this->documentNumber(self::CLIENT_DOCUMENT_PREFIXES[$type] ?? 'dok. '.$type, $r->series, $r->doc_number, $r->doc_year, $r->doc_month),
                    'date' => (int) $r->doc_date,
                    'customer_gid' => (int) $r->customer_gid,
                    'item_gid' => (int) $r->item_gid,
                    'quantity' => (float) $r->quantity,
                    'net_value' => (float) $r->net_value,
                    'cost' => (float) ($r->cost ?? 0),
                    'corrects_type' => $correctsType !== null && $correctsId !== null ? $correctsType : null,
                    'corrects_id' => $correctsType !== null && $correctsId !== null ? $correctsId : null,
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

    public function lotHistory(array $gids, int $sinceTimestamp): array
    {
        if ($gids === []) {
            return ['lots' => [], 'moves' => []];
        }
        $in = implode(',', array_map('intval', $gids));
        $since = max(0, $sinceTimestamp);
        $this->yieldToXl();
        $lots = $this->db()->select(<<<SQL
            SELECT z.TwZ_TwrNumer AS gid, z.TwZ_DstNumer AS dst, m.MAG_Kod AS warehouse_code, MIN(d.Dst_DstTStamp) AS received_at,
                   SUM(z.TwZ_Ilosc) AS quantity, SUM(z.TwZ_KsiegowaNetto) AS book_value
            FROM CDN.TwrZasoby z
            JOIN CDN.Magazyny m ON m.MAG_GIDNumer = z.TwZ_MagNumer AND m.MAG_GIDTyp = z.TwZ_MagTyp
            LEFT JOIN CDN.Dostawy d ON d.Dst_GIDNumer = z.TwZ_DstNumer AND d.Dst_GIDTyp = z.TwZ_DstTyp
            WHERE z.TwZ_TwrNumer IN ($in)
            GROUP BY z.TwZ_TwrNumer, z.TwZ_DstNumer, m.MAG_Kod
            SQL);
        // Chwila ruchu (sprawdzone 01.10.2026 na stanie z nocy i 1000 towarach): TrS_TrnTStamp, z dwoma wyjątkami —
        // dokument w buforze (TrN_Stan < 3): XL co dzień przestawia jego datę na dziś, a towar zdjął przy wystawieniu →
        // ostatnia zmiana nagłówka (TrN_LastMod), gdy wcześniejsza; sztuczna godzina 00:00/23:59 i zmiana do 2 dni później
        // (MM, FS zatwierdzone dziś „na wczoraj”) → TrN_LastMod. Szerzej LastMod nie: zbiorcze RW (01K, 00:00 końca
        // miesiąca) mają LastMod z założenia nagłówka — partie wstecz schodziły poniżej zera (581 zamiast 143 na 12 tys.).
        $moves = $this->db()->select(<<<SQL
            SELECT d.Dst_TwrNumer AS gid, s.TrS_DstNumer AS dst, m.MAG_Kod AS warehouse_code, MIN(d.Dst_DstTStamp) AS received_at,
                   s.TrS_GIDTyp AS type, t.at / 86400 AS day, SUM(s.TrS_Ilosc) AS quantity, SUM(s.TrS_KosztKsiegowy) AS cost
            FROM CDN.TraSElem s
            JOIN CDN.Dostawy d ON d.Dst_GIDNumer = s.TrS_DstNumer AND d.Dst_GIDTyp = s.TrS_DstTyp
            JOIN CDN.Magazyny m ON m.MAG_GIDNumer = s.TrS_MagNumer AND m.MAG_GIDTyp = s.TrS_MagTyp
            LEFT JOIN CDN.TraNag n ON n.TrN_GIDTyp = s.TrS_GIDTyp AND n.TrN_GIDNumer = s.TrS_GIDNumer
            CROSS APPLY (SELECT CASE
                WHEN n.TrN_Stan < 3 AND ISNULL(n.TrN_LastMod, 0) > 0 AND n.TrN_LastMod < s.TrS_TrnTStamp THEN n.TrN_LastMod
                WHEN (s.TrS_TrnTStamp % 86400 = 0 OR s.TrS_TrnTStamp % 86400 >= 86340)
                     AND n.TrN_LastMod > s.TrS_TrnTStamp AND n.TrN_LastMod - s.TrS_TrnTStamp <= 172800 THEN n.TrN_LastMod
                ELSE s.TrS_TrnTStamp END AS at) t
            WHERE d.Dst_TwrNumer IN ($in) AND (s.TrS_TrnTStamp >= $since OR n.TrN_LastMod >= $since)
            GROUP BY d.Dst_TwrNumer, s.TrS_DstNumer, m.MAG_Kod, s.TrS_GIDTyp, t.at / 86400
            SQL);
        $stamp = static fn ($v): ?int => $v !== null && (int) $v > 0 ? (int) $v : null;

        return [
            'lots' => array_map(static fn ($r): array => [
                'gid' => (int) $r->gid, 'dst' => (int) $r->dst, 'warehouse_code' => trim((string) $r->warehouse_code),
                'received_at' => $stamp($r->received_at), 'quantity' => (float) $r->quantity, 'value' => (float) ($r->book_value ?? 0),
            ], $lots),
            'moves' => array_map(static fn ($r): array => [
                'gid' => (int) $r->gid, 'dst' => (int) $r->dst, 'warehouse_code' => trim((string) $r->warehouse_code),
                'received_at' => $stamp($r->received_at), 'type' => (int) $r->type, 'day' => (int) $r->day,
                'quantity' => (float) $r->quantity, 'cost' => (float) ($r->cost ?? 0),
            ], $moves),
        ];
    }

    public function saleHistory(array $gids, int $fromClarionDate): array
    {
        if ($gids === []) {
            return ['before' => [], 'days' => []];
        }
        $in = implode(',', array_map('intval', $gids));
        $types = implode(',', self::SALE_TYPES);
        $from = (int) $fromClarionDate;
        $this->yieldToXl();
        $base = <<<SQL
            FROM CDN.TraElem e
            JOIN CDN.TraNag n ON n.TrN_GIDTyp = e.TrE_GIDTyp AND n.TrN_GIDNumer = e.TrE_GIDNumer
            LEFT JOIN CDN.Magazyny m ON m.MAG_GIDNumer = n.TrN_MagZNumer AND m.MAG_GIDTyp = n.TrN_MagZTyp
            WHERE e.TrE_GIDTyp IN ($types) AND e.TrE_TwrNumer IN ($in)
            SQL;
        // data sprzedaży jak lastSales (bufor — dzień ostatniej zmiany)
        $date = self::SALE_DATE_SQL;
        $before = $this->db()->select("SELECT e.TrE_TwrNumer AS gid, m.MAG_Kod AS warehouse_code, MAX($date) AS date $base AND $date < $from GROUP BY e.TrE_TwrNumer, m.MAG_Kod");
        $days = $this->db()->select("SELECT DISTINCT e.TrE_TwrNumer AS gid, m.MAG_Kod AS warehouse_code, $date AS date $base AND $date >= $from");
        $map = static function ($r): array {
            $code = $r->warehouse_code !== null ? trim((string) $r->warehouse_code) : '';

            return ['gid' => (int) $r->gid, 'warehouse_code' => $code !== '' ? $code : null, 'date' => (int) $r->date];
        };

        return ['before' => array_map($map, $before), 'days' => array_map($map, $days)];
    }

    public function customerSalesTotals(int $fromClarionDate, int $toClarionDate): array
    {
        $sales = implode(',', self::CLIENT_SALE_TYPES);
        $types = implode(',', [...self::CLIENT_SALE_TYPES, ...self::CLIENT_CORRECTION_TYPES]);
        $states = implode(',', self::CUSTOMER_SALE_STATES);
        $customer = self::CUSTOMER_TYPE;
        $this->yieldToXl();
        // numery dokumentów liczone na typ — GIDNumer jest unikalny w obrębie typu
        $rows = $this->db()->select(<<<SQL
            SELECT n.TrN_KntNumer AS customer_gid, SUM(e.TrE_KsiegowaNetto) AS net,
                   COUNT(DISTINCT CASE WHEN n.TrN_GIDTyp IN ($sales) THEN CAST(n.TrN_GIDTyp AS bigint) * 100000000 + n.TrN_GIDNumer END) AS documents,
                   MAX(CASE WHEN n.TrN_GIDTyp IN ($sales) THEN n.TrN_Data2 ELSE 0 END) AS last_date
            FROM CDN.TraElem e
            JOIN CDN.TraNag n ON n.TrN_GIDTyp = e.TrE_GIDTyp AND n.TrN_GIDNumer = e.TrE_GIDNumer
            WHERE n.TrN_KntTyp = $customer AND n.TrN_KntNumer > 0 AND n.TrN_GIDTyp IN ($types)
              AND n.TrN_Stan IN ($states) AND n.TrN_Data2 BETWEEN ? AND ?
            GROUP BY n.TrN_KntNumer
            SQL, [$fromClarionDate, $toClarionDate]);

        return array_map(static fn ($r): array => [
            'customer_gid' => (int) $r->customer_gid,
            'net' => round((float) $r->net, 2),
            'documents' => (int) $r->documents,
            'last_date' => (int) $r->last_date,
        ], $rows);
    }

    public function customerCards(array $gids): array
    {
        // pole => kolumna XL; nazwa, akronim i NIP są w podstawowym GRANT-cie (login-przetargi*.sql)
        $columns = [
            'nip_prefix' => 'Knt_NipPrefiks', 'regon' => 'Knt_Regon', 'street' => 'Knt_Ulica', 'address_line2' => 'Knt_Adres',
            'postal_code' => 'Knt_KodP', 'city' => 'Knt_Miasto', 'county' => 'Knt_Powiat', 'commune' => 'Knt_Gmina',
            'voivodeship' => 'Knt_Wojewodztwo', 'country' => 'Knt_Kraj', 'phone' => 'Knt_Telefon1', 'phone2' => 'Knt_Telefon2',
            'fax' => 'Knt_Fax', 'email' => 'Knt_EMail', 'website' => 'Knt_URL',
        ];
        [$readable, $unavailable] = $this->readableColumns('CDN.KntKarty', $columns);
        $base = ['Knt_GIDNumer', 'Knt_Akronim', 'Knt_Nazwa1', 'Knt_Nazwa2', 'Knt_Nazwa3', 'Knt_Nip', 'Knt_NipE', 'Knt_Archiwalny'];

        $out = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $gids))), 500) as $chunk) {
            $rows = $this->db()->table('CDN.KntKarty')
                ->select([...$base, ...array_values($readable)])
                ->where('Knt_GIDTyp', self::CUSTOMER_TYPE)
                ->whereIn('Knt_GIDNumer', $chunk)
                ->orderBy('Knt_GIDNumer')
                ->get();
            foreach ($rows as $r) {
                $row = [
                    'gid' => (int) $r->Knt_GIDNumer,
                    'acronym' => trim((string) $r->Knt_Akronim),
                    // nazwa w XL rozpisana na trzy wiersze — łączymy dosłownie spacją (jak customers())
                    'name' => implode(' ', array_filter(
                        [$this->text($r->Knt_Nazwa1), $this->text($r->Knt_Nazwa2), $this->text($r->Knt_Nazwa3)],
                        static fn (?string $part): bool => $part !== null,
                    )),
                    'nip' => $this->text($r->Knt_Nip) ?? $this->text($r->Knt_NipE),
                ];
                foreach ($columns as $field => $column) {
                    $row[$field] = isset($readable[$field]) ? $this->text($r->{$column}) : null;
                }
                $row['archived'] = (int) $r->Knt_Archiwalny !== 0;
                $out[] = $row;
            }
        }

        return ['rows' => $out, 'unavailable' => $unavailable];
    }

    public function customerContacts(array $gids): array
    {
        $columns = ['name' => 'KnS_Nazwa', 'position' => 'KnS_Stanowisko', 'email' => 'KnS_EMail', 'phone' => 'KnS_Telefon', 'mobile' => 'KnS_TelefonK'];
        [$readable, $unavailable] = $this->readableColumns('CDN.KntOsoby', $columns);

        $out = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $gids))), 500) as $chunk) {
            $rows = $this->db()->table('CDN.KntOsoby')
                ->select(['KnS_KntNumer', ...array_values($readable)])
                ->where('KnS_KntTyp', self::CUSTOMER_TYPE)
                ->whereIn('KnS_KntNumer', $chunk)
                ->where('KnS_Archiwalny', 0)
                ->orderBy('KnS_KntNumer')
                ->orderBy('KnS_Nazwa')
                ->get();
            foreach ($rows as $r) {
                $row = ['customer_gid' => (int) $r->KnS_KntNumer];
                foreach ($columns as $field => $column) {
                    $row[$field] = isset($readable[$field]) ? $this->text($r->{$column}) : null;
                }
                $out[] = $row;
            }
        }

        return ['rows' => $out, 'unavailable' => $unavailable];
    }

    public function customerManagers(array $gids, int $onClarionDate): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $gids))), 500) as $chunk) {
            $rows = $this->db()->table('CDN.KntOpiekun as o')
                ->leftJoin('CDN.PrcKarty as p', 'p.Prc_GIDNumer', '=', 'o.KtO_PrcNumer')
                ->select(['o.KtO_KntNumer', 'o.KtO_PrcNumer', 'p.Prc_Imie1', 'p.Prc_Nazwisko', 'p.Prc_Akronim', 'p.Prc_EMail'])
                ->where('o.KtO_KntTyp', self::CUSTOMER_TYPE)
                ->whereIn('o.KtO_KntNumer', $chunk)
                ->where('o.KtO_DataOd', '<=', $onClarionDate)
                ->where('o.KtO_DataDo', '>=', $onClarionDate)
                ->orderBy('o.KtO_KntNumer')
                ->orderByDesc('o.KtO_Glowny')
                ->get();
            foreach ($rows as $r) {
                $out[] = [
                    'customer_gid' => (int) $r->KtO_KntNumer,
                    'employee_gid' => (int) $r->KtO_PrcNumer > 0 ? (int) $r->KtO_PrcNumer : null,
                    'first_name' => $this->text($r->Prc_Imie1),
                    'last_name' => $this->text($r->Prc_Nazwisko),
                    'acronym' => $this->text($r->Prc_Akronim),
                    'email' => $this->text($r->Prc_EMail),
                ];
            }
        }

        return $out;
    }

    public function customerDocuments(array $gids, int $fromClarionDate): iterable
    {
        $types = implode(',', [...self::CLIENT_SALE_TYPES, ...self::CLIENT_CORRECTION_TYPES]);
        $states = implode(',', self::CUSTOMER_SALE_STATES);
        $customer = self::CUSTOMER_TYPE;
        $this->yieldToXl();
        // paczki po 500 kontrahentów (limit parametrów MS SQL 2100 — numery wpisane w zapytanie jako liczby całkowite);
        // GROUP BY wszystkich kolumn nagłówka, bo MS SQL nie pozwala wybrać kolumny spoza grupowania
        foreach (array_chunk(array_values(array_unique(array_map('intval', $gids))), 500) as $chunk) {
            $in = implode(',', $chunk);
            $sql = <<<SQL
                SELECT n.TrN_GIDTyp AS doc_type, n.TrN_GIDNumer AS document_id, n.TrN_TrNSeria AS series,
                       n.TrN_TrNNumer AS doc_number, n.TrN_TrNRok AS doc_year, n.TrN_TrNMiesiac AS doc_month,
                       n.TrN_Data2 AS doc_date, n.TrN_KntNumer AS customer_gid, SUM(e.TrE_KsiegowaNetto) AS net_value
                FROM CDN.TraElem e
                JOIN CDN.TraNag n ON n.TrN_GIDTyp = e.TrE_GIDTyp AND n.TrN_GIDNumer = e.TrE_GIDNumer
                WHERE n.TrN_KntTyp = $customer AND n.TrN_KntNumer IN ($in) AND n.TrN_GIDTyp IN ($types)
                  AND n.TrN_Stan IN ($states) AND n.TrN_Data2 >= ?
                GROUP BY n.TrN_GIDTyp, n.TrN_GIDNumer, n.TrN_TrNSeria, n.TrN_TrNNumer, n.TrN_TrNRok, n.TrN_TrNMiesiac,
                         n.TrN_Data2, n.TrN_KntNumer
                SQL;
            foreach ($this->db()->cursor($sql, [$fromClarionDate]) as $r) {
                $type = (int) $r->doc_type;
                yield [
                    'document_type' => $type,
                    'document_id' => (int) $r->document_id,
                    'document_number' => $this->documentNumber(self::CLIENT_DOCUMENT_PREFIXES[$type] ?? 'dok. '.$type, $r->series, $r->doc_number, $r->doc_year, $r->doc_month),
                    'date' => (int) $r->doc_date,
                    'customer_gid' => (int) $r->customer_gid,
                    'net_value' => round((float) $r->net_value, 2),
                ];
            }
        }
    }

    public function customerDocumentLines(array $gids, int $fromClarionDate): iterable
    {
        $types = implode(',', self::CLIENT_SALE_TYPES);
        $states = implode(',', self::CUSTOMER_SALE_STATES);
        $customer = self::CUSTOMER_TYPE;
        $this->yieldToXl();
        foreach (array_chunk(array_values(array_unique(array_map('intval', $gids))), 500) as $chunk) {
            $in = implode(',', $chunk);
            $sql = <<<SQL
                SELECT n.TrN_GIDTyp AS doc_type, n.TrN_GIDNumer AS document_id, n.TrN_TrNSeria AS series,
                       n.TrN_TrNNumer AS doc_number, n.TrN_TrNRok AS doc_year, n.TrN_TrNMiesiac AS doc_month,
                       n.TrN_Data2 AS doc_date, n.TrN_KntNumer AS customer_gid, e.TrE_TwrNumer AS item_gid,
                       e.TrE_Ilosc AS quantity, e.TrE_KsiegowaNetto AS net_value
                FROM CDN.TraElem e
                JOIN CDN.TraNag n ON n.TrN_GIDTyp = e.TrE_GIDTyp AND n.TrN_GIDNumer = e.TrE_GIDNumer
                WHERE n.TrN_KntTyp = $customer AND n.TrN_KntNumer IN ($in) AND n.TrN_GIDTyp IN ($types)
                  AND n.TrN_Stan IN ($states) AND n.TrN_Data2 >= ? AND e.TrE_Ilosc > 0
                SQL;
            foreach ($this->db()->cursor($sql, [$fromClarionDate]) as $r) {
                $type = (int) $r->doc_type;
                yield [
                    'document_type' => $type,
                    'document_id' => (int) $r->document_id,
                    'document_number' => $this->documentNumber(self::CLIENT_DOCUMENT_PREFIXES[$type] ?? 'dok. '.$type, $r->series, $r->doc_number, $r->doc_year, $r->doc_month),
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
     * Które z kolumn login XL może czytać — prawa nadawane są kolumnami (login-przetargi*.sql), a SELECT choć jednej
     * niedozwolonej kolumny kończy całe zapytanie błędem.
     *
     * @param  array<string, string>  $columns  pole => kolumna XL
     * @return array{0: array<string, string>, 1: list<string>} [czytelne pole => kolumna, pola bez prawa odczytu]
     */
    private function readableColumns(string $table, array $columns): array
    {
        $allowed = [];
        foreach ($this->db()->select(
            "SELECT c.name, HAS_PERMS_BY_NAME(?, 'OBJECT', 'SELECT', c.name, 'COLUMN') AS readable FROM sys.columns c WHERE c.object_id = OBJECT_ID(?)",
            [$table, $table],
        ) as $r) {
            if ((int) $r->readable === 1) {
                $allowed[(string) $r->name] = true;
            }
        }
        $readable = array_filter($columns, static fn (string $column): bool => isset($allowed[$column]));

        return [$readable, array_keys(array_diff_key($columns, $readable))];
    }

    /**
     * Długie odczyty historii w dzień (01.10.2026: zapytanie wybrane przez SQL Server jako ofiara zakleszczenia z pracą
     * w XL): bez blokad współdzielonych (odczyt niezatwierdzonych — dla historii zapasu bez znaczenia) i przy konflikcie
     * zawsze ustępuje użytkownikom XL. Ustawienie sesji połączenia — tylko w procesie, który czyta historię.
     */
    private function yieldToXl(): void
    {
        $this->db()->statement('SET DEADLOCK_PRIORITY LOW; SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED');
    }

    private function db(): ConnectionInterface
    {
        if (! $this->configured()) {
            throw new RuntimeException('Połączenie z ERP XL jest wyłączone albo nieuzupełnione (ERPXL_*).');
        }

        return DB::connection('erpxl');
    }
}

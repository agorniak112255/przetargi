<?php

declare(strict_types=1);

namespace App\Services\Erp;

/**
 * Odczyt z Comarch ERP XL — zwykłe tablice, żeby synchronizację dało się sprawdzić bez MS SQL (atrapa w testach).
 * Liczby i daty dosłownie z XL (data Clarion jako int); przeliczenia robią ErpItemSync, ErpRwPwSync i ErpCustomerSync.
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
     * Partie na stanie (TwZ_Ilosc > 0) z datą przyjęcia (XlTimestamp; null = XL nie podał) — do rozbicia wieku zapasu.
     * Wiersz = suma partii towaru przyjętych w tej samej chwili na ten sam magazyn; wartość = księgowa netto w PLN.
     *
     * @param  list<int>  $gids
     * @return list<array{gid: int, warehouse_code: string, received_at: int|null, quantity: float, value: float|null}>
     */
    public function stockLots(array $gids): array;

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
     * Data (Clarion) ostatniej sprzedaży (FS, PA, WZ) na towar i magazyn dokumentu (nagłówek, TrN_MagZNumer; null —
     * dokument bez magazynu, np. FS wystawiona do WZ). Ostatnia sprzedaż towaru = najpóźniejsza data z jego wierszy.
     *
     * @param  list<int>  $gids
     * @return list<array{gid: int, warehouse_code: string|null, date: int}>
     */
    public function lastSales(array $gids): array;

    /**
     * Zatwierdzone RW (rozchód wewnętrzny) i PW (przychód wewnętrzny) od daty — suma pozycji na dokument i towar, tylko
     * towary, które w tym okresie miały choć jedno PW. Magazyn: RW — źródłowy (MagZ), PW — docelowy (MagD).
     * Operator: akronim i imię z nazwiskiem z CDN.OpeKarty — kto wystawił (W) i kto zatwierdził (Z). Uwagi dokumentu
     * z CDN.TrNOpisy (np. „ZAMIANA ROZMIARÓW”, w PW często numer RW) i dokument obcy.
     *
     * @return list<array{type: 'rw'|'pw', document_id: int, number: string, date: int, warehouse: string|null, operator: string|null, approver: string|null, operator_name: string|null, approver_name: string|null, note: string|null, foreign_number: string|null, gid: int, quantity: float, value: float}>
     */
    public function internalMoves(int $fromClarionDate): array;

    /**
     * Partie w zatwierdzonych RW i PW od daty — dla tych samych towarów co internalMoves. Z CDN.TraSElem: ilość i cecha
     * (zwykle rozmiar: „38”, „XL”); z CDN.Dostawy: znacznik przyjęcia partii (XlTimestamp) i dokument, którym weszła
     * (PZ, PW…). Dla RW to partia zdjęta z magazynu, dla PW — nowa partia.
     *
     * @return list<array{type: 'rw'|'pw', document_id: int, gid: int, received_at: int, quantity: float, feature: string, source_type: int, source_number: string|null}>
     */
    public function internalMoveLots(int $fromClarionDate): array;

    /**
     * Kontrahenci (Knt_GIDTyp = 32) o numerze większym niż $afterGid, rosnąco. E-mail dosłownie z karty (Knt_EMail —
     * bywa listą rozdzieloną ; , albo spacją); rozbija go ErpCustomerSync.
     *
     * @return list<array{gid: int, acronym: string, name: string, nip: ?string, city: ?string, email: ?string, archived: bool}>
     */
    public function customers(int $afterGid, int $limit): array;

    /**
     * E-maile z aktywnych adresów kontrahentów (KnA_GIDTyp = 864; 896 to archiwalne kopie z dokumentów), niepuste,
     * dosłownie z XL.
     *
     * @return iterable<array{gid: int, email: string}> gid = numer kontrahenta
     */
    public function customerAddressEmails(): iterable;

    /**
     * Sprzedaż (FS, PA — bez WZ, bo dubluje FS) od daty: na kontrahenta i towar data ostatniego dokumentu (Clarion),
     * liczba dokumentów i ilość. Strumień (setki tysięcy wierszy).
     *
     * @return iterable<array{customer_gid: int, item_gid: int, last_date: int, documents: int, quantity: float}>
     */
    public function customerSales(int $fromClarionDate): iterable;

    /**
     * Liczba FS/PA od daty na kontrahenta i operatora, który je wystawił (Ope_Ident po trim i wielkich literach; pusty,
     * gdy XL nie zna operatora). Strumień.
     *
     * @return iterable<array{customer_gid: int, operator: string, operator_name: ?string, documents: int}>
     */
    public function customerOperators(int $fromClarionDate): iterable;

    /**
     * Pozycje FS i PA (zatwierdzone, do kontrahenta) z towarami z listy od daty — wynik kampanii „kupili odbiorcy”.
     * net_value = TrE_KsiegowaNetto (PLN netto; na paragonie bez VAT). Strumień.
     *
     * @param  list<int>  $itemGids
     * @return iterable<array{document_type: int, document_id: int, line: int, document_number: string, date: int, customer_gid: int, item_gid: int, quantity: float, net_value: float}>
     */
    public function itemSaleLines(array $itemGids, int $fromClarionDate): iterable;

    /**
     * Historia zapasów wstecz: partie towarów na dziś (TwrZasoby, partia × magazyn) i ich ruchy od chwili
     * `$sinceTimestamp` (TraSElem, XlTimestamp) zsumowane na dzień ruchu (day = sekundy XL / 86400, dni od 1.01.1990)
     * i typ dokumentu — ilość i koszt księgowy bez znaku dokumentu (korekty mają ilość ze znakiem). received_at =
     * przyjęcie partii (Dostawy.Dst_DstTStamp = TwZ_DataP, sprawdzone 01.10.2026).
     *
     * @param  list<int>  $gids
     * @return array{lots: list<array{gid: int, dst: int, warehouse_code: string, received_at: int|null, quantity: float, value: float}>, moves: list<array{gid: int, dst: int, warehouse_code: string, received_at: int|null, type: int, day: int, quantity: float, cost: float}>}
     */
    public function lotHistory(array $gids, int $sinceTimestamp): array;

    /**
     * Sprzedaż jak lastSales (FS, PA, WZ, magazyn nagłówka, data Clarion — dokument w buforze: dzień ostatniej zmiany):
     * ostatnia przed `$fromClarionDate` na towar × magazyn i każdy dzień sprzedaży od tej daty. Magazyn null = dokument
     * bez magazynu.
     *
     * @param  list<int>  $gids
     * @return array{before: list<array{gid: int, warehouse_code: string|null, date: int}>, days: list<array{gid: int, warehouse_code: string|null, date: int}>}
     */
    public function saleHistory(array $gids, int $fromClarionDate): array;

    /**
     * Zakupy kontrahentów w okresie [od, do] (daty Clarion, TrN_Data2): netto PLN (TrE_KsiegowaNetto) z FS, PA i FSE
     * zatwierdzonych (TrN_Stan 3–5) pomniejszone o ich korekty; documents = liczba FS/PA/FSE bez korekt; last_date —
     * ostatni z tych dokumentów (0, gdy kontrahent ma same korekty). Bez kontrahenta jednorazowego (numer 0).
     *
     * @return list<array{customer_gid: int, net: float, documents: int, last_date: int}>
     */
    public function customerSalesTotals(int $fromClarionDate, int $toClarionDate): array;

    /**
     * Pełna karta kontrahentów z listy (Knt_GIDTyp = 32), wartości dosłownie z XL (bez zbędnych spacji, pusty = null).
     * Kolumny, do których login XL nie ma prawa odczytu (GRANT kolumnowy), wracają jako null i ich nazwy pól są w
     * `unavailable` — żeby wywołujący nie nadpisał nimi zapisanych wcześniej danych.
     *
     * @param  list<int>  $gids
     * @return array{rows: list<array{gid: int, acronym: string, name: string, nip: ?string, nip_prefix: ?string, regon: ?string, street: ?string, address_line2: ?string, postal_code: ?string, city: ?string, county: ?string, commune: ?string, voivodeship: ?string, country: ?string, phone: ?string, phone2: ?string, fax: ?string, email: ?string, website: ?string, archived: bool}>, unavailable: list<string>}
     */
    public function customerCards(array $gids): array;

    /**
     * Osoby kontaktowe kontrahentów z listy (KntOsoby), bez archiwalnych; kolumny bez prawa odczytu jak w customerCards.
     *
     * @param  list<int>  $gids
     * @return array{rows: list<array{customer_gid: int, name: ?string, position: ?string, email: ?string, phone: ?string, mobile: ?string}>, unavailable: list<string>}
     */
    public function customerContacts(array $gids): array;

    /**
     * Opiekunowie z karty kontrahenta (KntOpiekun → PrcKarty) przypisani w dniu `$onClarionDate` (DataOd ≤ dzień ≤ DataDo),
     * główny pierwszy. employee_gid = numer pracownika XL (KtO_PrcNumer; null, gdy XL go nie podał).
     *
     * @param  list<int>  $gids
     * @return list<array{customer_gid: int, employee_gid: ?int, first_name: ?string, last_name: ?string, acronym: ?string, email: ?string}>
     */
    public function customerManagers(array $gids, int $onClarionDate): array;

    /**
     * Nagłówki dokumentów sprzedaży kontrahentów z listy od daty (Clarion, TrN_Data2): FS, PA, FSE i korekty FS/PA —
     * zatwierdzone (TrN_Stan 3–5), jak customerSalesTotals. net_value = suma TrE_KsiegowaNetto pozycji dokumentu (netto
     * PLN; korekty ze znakiem). Kontrahenci paczkami po 500, strumień (kursor).
     *
     * @param  list<int>  $gids
     * @return iterable<array{document_type: int, document_id: int, document_number: string, date: int, customer_gid: int, net_value: float}>
     */
    public function customerDocuments(array $gids, int $fromClarionDate): iterable;

    /**
     * Pozycje FS, PA i FSE (zatwierdzone, TrE_Ilosc > 0) kontrahentów z listy od daty — do podpowiedzi „możliwe
     * zamówienie z oferty”. Wiersz = pozycja dokumentu (ten sam towar może wystąpić kilka razy). net_value =
     * TrE_KsiegowaNetto. Kontrahenci paczkami po 500, strumień.
     *
     * @param  list<int>  $gids
     * @return iterable<array{document_type: int, document_id: int, document_number: string, date: int, customer_gid: int, item_gid: int, quantity: float, net_value: float}>
     */
    public function customerDocumentLines(array $gids, int $fromClarionDate): iterable;
}

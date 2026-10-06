<?php

declare(strict_types=1);

namespace App\Services\Erp;

/**
 * Odczyt z Comarch ERP XL — zwykłe tablice, żeby synchronizację dało się sprawdzić bez MS SQL (atrapa w testach).
 * Liczby i daty dosłownie z XL (data Clarion jako int); przeliczenia robią ErpItemSync, ErpRwPwSync, ErpCustomerSync
 * i InspectionSaleSync.
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
     * Sprzedaż od daty dokumentu na kontrahenta i towar: pozycje FS i PA oraz WZ — faktura do WZ nie ma w XL własnych
     * pozycji, więc jej towary to pozycje WZ ze spinacza (kontrahent i data okna z faktury); WZ bez zatwierdzonej
     * faktury liczy się sama (zob. ErpXlClient::saleLinesSql). last_date = ostatni dzień wydania towaru (data FS/PA,
     * dla WZ data WZ), documents = liczba dokumentów (faktura do WZ = jeden dokument), ilość. Strumień.
     *
     * @return iterable<array{customer_gid: int, item_gid: int, last_date: int, documents: int, quantity: float}>
     */
    public function customerSales(int $fromClarionDate): iterable;

    /**
     * Liczba dokumentów jak w customerSales (FS/PA z pozycjami, faktury do WZ, WZ bez faktury) od daty na kontrahenta
     * i operatora, który je wystawił (faktura do WZ — operator faktury; Ope_Ident po trim i wielkich literach; pusty,
     * gdy XL nie zna operatora). Strumień.
     *
     * @return iterable<array{customer_gid: int, operator: string, operator_name: ?string, documents: int}>
     */
    public function customerOperators(int $fromClarionDate): iterable;

    /**
     * Pozycje FS, PA i WZ 2001 (zatwierdzone, do kontrahenta, ilość > 0) i ich korekt FSK 2041 / PAK 2042 / WZK 2009
     * (te same stany, ilość albo wartość ≠ 0 — ze znakiem, ilość 0 = korekta ceny) z towarami z listy od daty — wynik
     * kampanii „kupili odbiorcy”. Faktura do WZ nie ma własnych pozycji: wiersz = pozycja WZ / WZK (document_type
     * 2001 / 2009, data WZ), document_number i kontrahent — z faktury (korekty FSK) ze spinacza, a gdy jej jeszcze nie ma
     * — z WZ. net_value = TrE_KsiegowaNetto (PLN netto; na paragonie bez VAT), cost = TrE_KosztKsiegowy (PLN, ze
     * znakiem; 0 = XL nie podał kosztu). corrects_type / corrects_id — dokument korygowany z nagłówka korekty
     * (TrN_ZwrTyp / TrN_ZwrNumer; WZK → WZ); null dla sprzedaży i gdy XL go nie podał. Strumień.
     *
     * @param  list<int>  $itemGids
     * @return iterable<array{document_type: int, document_id: int, line: int, document_number: string, date: int, customer_gid: int, item_gid: int, quantity: float, net_value: float, cost: float, corrects_type: int|null, corrects_id: int|null}>
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
     * Zakupy kontrahentów w okresie [od, do] (daty Clarion, TrN_Data2 dokumentu): netto PLN (TrE_KsiegowaNetto) z FS,
     * PA i FSE zatwierdzonych (TrN_Stan 3–5) pomniejszone o ich korekty — faktura (korekta) do WZ / WZE / WZK z pozycji
     * dokumentów ze spinacza, WZ bez zatwierdzonej faktury jako osobny dokument z datą WZ; documents = liczba
     * dokumentów sprzedaży bez korekt; last_date — ostatni z nich (0, gdy kontrahent ma same korekty). Bez kontrahenta
     * jednorazowego (numer 0).
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
     * zatwierdzone (TrN_Stan 3–5), jak customerSalesTotals (ta sama reguła WZ: faktura do WZ z sumą pozycji jej WZ,
     * WZ 2001 / WZE 2005 / WZK 2009 bez zatwierdzonej faktury jako własny nagłówek). net_value = suma TrE_KsiegowaNetto
     * pozycji (netto PLN; korekty ze znakiem). Kontrahenci paczkami po 500, strumień (kursor).
     *
     * @param  list<int>  $gids
     * @return iterable<array{document_type: int, document_id: int, document_number: string, date: int, customer_gid: int, net_value: float}>
     */
    public function customerDocuments(array $gids, int $fromClarionDate): iterable;

    /**
     * Pozycje FS, PA i FSE (zatwierdzone, TrE_Ilosc > 0) kontrahentów z listy od daty — do podpowiedzi „możliwe
     * zamówienie z oferty”; faktura do WZ / WZE z pozycjami swoich WZ (dokument, data i kontrahent z faktury), WZ bez
     * zatwierdzonej faktury pod własnym numerem — dokumenty jak w customerDocuments. Wiersz = pozycja (ten sam towar
     * może wystąpić kilka razy). net_value = TrE_KsiegowaNetto. Kontrahenci paczkami po 500, strumień.
     *
     * @param  list<int>  $gids
     * @return iterable<array{document_type: int, document_id: int, document_number: string, date: int, customer_gid: int, item_gid: int, quantity: float, net_value: float}>
     */
    public function customerDocumentLines(array $gids, int $fromClarionDate): iterable;

    /**
     * Kontrola reguły WZ za okres (daty WZ / korekty, stan 3–5, kontrahent 32) — czego sprzedaż z pozycji NIE bierze:
     * invoice_with_lines = WZ / WZE / WZK, której faktura w spinaczu ma własne pozycje (bezpiecznik przed podwójnym
     * liczeniem; od 2019 nie było), invoice_lines_mode = WZ ze spinaczem −2033 (od 04.2026: faktura z własnymi pozycjami
     * — towar liczy się z faktury), unknown_link = inny typ w spinaczu, correction_of_lineless = korekta z własnymi
     * pozycjami do dokumentu bez pozycji (np. FSK do faktury do WZ — kampania jej nie powiąże). net = TrN_NettoR.
     *
     * @return array{invoice_with_lines: array{documents: int, net: float}, invoice_lines_mode: array{documents: int, net: float}, unknown_link: array{documents: int, net: float}, correction_of_lineless: array{documents: int, net: float}}
     */
    public function deliveryCheck(int $fromClarionDate, int $toClarionDate): array;

    /**
     * Usługi (Twr_Typ = 4) o numerze większym niż $afterGid, rosnąco — katalog modułu Przeglądy. Jednostka pusta = null.
     *
     * @return list<array{gid: int, type: int, code: string, name: string, unit: string|null, archived: bool}>
     */
    public function services(int $afterGid, int $limit): array;

    /**
     * Pozycje faktur sprzedaży i WZ do modułu Przeglądy od daty (TrN_Data2): FS 2033, FSE 2037 i WZ 2001 (ilość > 0;
     * faktura do WZ nie ma własnych pozycji) oraz korekty 2041, 2045 i WZK 2009 (ilość albo wartość ≠ 0, ze znakiem), zatwierdzone (TrN_Stan 3–5), do kontrahenta (TrN_KntTyp 32, numer > 0).
     * Paragonów (2034, 2042) nie ma — jeden kontrahent detaliczny. Towary z listy $itemGids i — gdy $allServices — każda
     * pozycja z usługą (Twr_Typ 4); pozycja usługi z listy nie wraca dwa razy.
     *
     * issued = TrN_Data2, sold = TrN_Data3 (0 = XL nie podał), recipient_gid = TrN_KnDNumer (0 = brak), warehouse_code =
     * MAG_Kod magazynu nagłówka (null = dokument bez magazynu), operator = Ope_Ident wystawiającego (wielkie litery, null =
     * nieznany), corrects_type / corrects_id = dokument korygowany z nagłówka korekty (null dla FS/FSE i gdy XL go nie
     * podał), net_value = TrE_KsiegowaNetto (PLN netto), invoice_number = numer faktury ze spinacza WZ (null dla faktur,
     * korekt i WZ bez faktury). Strumień (kursor), towary paczkami po 500.
     *
     * @param  list<int>  $itemGids
     * @return iterable<array{doc_type: int, document_id: int, line: int, document_number: string, issued: int, sold: int, customer_gid: int, recipient_gid: int, item_gid: int, item_type: int, quantity: float, net_value: float, warehouse_code: string|null, operator: string|null, corrects_type: int|null, corrects_id: int|null, invoice_number: string|null}>
     */
    public function inspectionSaleLines(array $itemGids, bool $allServices, int $fromClarionDate): iterable;
}

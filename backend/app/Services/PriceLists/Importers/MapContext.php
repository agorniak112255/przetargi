<?php

declare(strict_types=1);

namespace App\Services\PriceLists\Importers;

use App\Models\PriceList;
use App\Models\Product;
use App\Services\Enrichment\PartsTable\PinResult;

/**
 * Narzędzia importera przy ustalaniu źródła karty — wyłącznie odczyt (indeks stron catalog_pages, tabele części,
 * pobranie strony). Implementacja: DefaultMapContext. W podglądzie importu fetch() zwraca null (bez sieci, bez cache
 * w bazie), więc mapSource musi umieć zdecydować z samego indeksu albo zwrócić unresolved('nie sprawdzono w podglądzie').
 */
interface MapContext
{
    public function priceList(): PriceList;

    /**
     * Hosty producenta marki karty (ManufacturerDomainResolver::assignedDomainsFor — config + przypisane ręcznie).
     *
     * @return list<string>
     */
    public function manufacturerHosts(Product $card): array;

    /**
     * Strony dostawców z opisami ustawione przy cenniku (price_lists.enrichment_sites), w kolejności ważności.
     *
     * @return list<string>
     */
    public function listHosts(): array;

    /**
     * Strony z indeksu (catalog_pages) na podanych hostach, pasujące do karty — tylko odczyt.
     *
     * @param  list<string>  $hosts
     * @return list<array{url: string, title: string}>
     */
    public function indexHits(Product $card, array $hosts): array;

    /**
     * Strony z indeksu na podanych hostach, których adres albo tytuł zawiera dokładnie kod (tokeny catalog_page_tokens).
     *
     * @param  list<string>  $hosts
     * @return list<array{url: string, title: string}>
     */
    public function pagesWithCode(string $code, array $hosts): array;

    /**
     * Czy tekst niesie któryś z kodów jako całe słowo (bez dopasowań podciągów: 1011 ≠ 1011 R, 104/1 ≠ 104/1 OC).
     *
     * @param  list<string>  $codes
     */
    public function carriesCode(string $text, array $codes): bool;

    /**
     * Pobranie strony (ProductPageFetcher). null, gdy pobieranie wyłączone (podgląd) albo strona nie odpowiedziała.
     *
     * @return array{url: string, final_url: string, title: ?string, text: string, html: string}|null
     */
    public function fetch(string $url): ?array;

    public function liveFetch(): bool;

    /** Przypięcie z tabeli części producenta (PartsTables, np. Coba) — null, gdy marka nie ma resolvera. */
    public function partsPin(Product $card): ?PinResult;
}

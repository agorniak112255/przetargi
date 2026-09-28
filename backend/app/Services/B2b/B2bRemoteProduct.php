<?php

declare(strict_types=1);

namespace App\Services\B2b;

final readonly class B2bRemoteProduct
{
    /**
     * Klucz members[].availability (opcjonalny) — dostępność tej jednej pozycji dosłownie ze źródła. Używana, gdy mapa
     * połączeń rozdziela grupę na osobne karty (B2bCatalogSync, tryb pojedynczy); bez niego slot takiej karty zachowuje
     * zapisaną dostępność (dostępność grupy mówi o wszystkich jej rozmiarach naraz).
     *
     * Klucze members[].size i members[].price (opcjonalne, decyzja użytkownika 28.09.2026: rozmiary w różnych cenach
     * = jedna karta): size — rozmiar pozycji dosłownie (etykieta wiersza rozmiaru; bez niego — kod pozycji), price —
     * cena konta tej pozycji (B2bRemotePrice: net, base gdy źródło ją podaje, waluta). Ceny wszystko albo nic: albo
     * każda pozycja grupy ma cenę, albo żadna; jedna waluta; remoteId musi być wśród pozycji z ceną. Grupa, która tego
     * nie spełnia, jest pomijana z powodem (B2bCatalogSync). Z cenami: karta ma cenę najtańszej pozycji (slot konta),
     * każda pozycja — wiersz rozmiaru w product_variants (kind „size”) ze swoją ceną; price() łącznika jest wołane jak
     * dotąd (jego błąd pomija grupę, warunek zamawiania i warunek ceny idą z niego).
     * Klucz members[].legacy_remote_id (opcjonalny) — remote_id, pod którym pozycja była powiązana przed zmianą łącznika
     * (Protekt 28.09.2026: długość „LB100 ~p4278” była kartą „LB100 / biały”); tylko do znalezienia jej obecnej karty
     * (B2bCatalogSync::cardGroups) i dopuszczenia dawnego powiązania przy scalaniu (B2bSizePriceMerger).
     *
     * @param  array<string, mixed>  $raw  pozycja listy dostawcy (dla łącznika)
     * @param  string|null  $availability  dostępność u dostawcy dosłownie ze źródła (slot ceny konta); null = źródło jej nie podaje
     * @param  string|null  $variantSummary  lista rozmiarów/kodów karty (products.variant_summary); null = nie zmieniać, '' = wyczyść
     * @param  list<array{remote_id: string, sku: string, name: string, availability?: string, size?: string, price?: B2bRemotePrice, legacy_remote_id?: string}>  $members  pozycje dostawcy
     *                                                                                                                                                                       scalone w tę kartę (rozmiary jednego wyrobu), razem z remoteId; [] = jedna pozycja
     * @param  list<B2bRemoteIdentifier>|null  $identifiers  identyfikatory pozycji (EAN, kod producenta); null = łącznik ich
     *                                                       nie podaje (zapisane zostają), [] = podaje i nie ma żadnych
     * @param  string|null  $cardName  nazwa nowej karty, gdy nazwa u dostawcy opisuje tylko część pozycji (Protekt: nagłówek
     *                                 podaje rozmiar jednej podstrony, a numer katalogowy obejmuje wszystkie rozmiary); null =
     *                                 $name. $name zostaje dosłowną nazwą ze źródła (b2b_product_links.remote_name, dziennik).
     */
    public function __construct(
        public string $remoteId,
        public string $sku,
        public string $name,
        public ?string $category = null,
        public ?string $sourceUrl = null,
        public array $raw = [],
        public ?string $availability = null,
        public ?string $variantSummary = null,
        public array $members = [],
        public ?array $identifiers = null,
        public ?string $cardName = null,
    ) {}
}

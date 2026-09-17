<?php

declare(strict_types=1);

namespace App\Services\B2b;

/**
 * Łącznik, którego sklep pokazuje kartę wyrobu jako tabelkę nazwa→wartość (u Anro zakładka „Informacje
 * o produkcie”, u Protektu specyfikacja techniczna, u UVEX-a „Specifications”). Synchronizacja zapisuje te
 * wiersze jako App\Models\ProductShopCard — osobno od products.description, żeby dane ze sklepu nie udawały
 * opisu wyrobu i nie zamykały karty na uzupełnianie AI (App\Services\B2b\B2bDescriptionSource).
 *
 * Metoda może dopytać dostawcę (np. Anro pobiera parametry techniczne osobnym zapytaniem), dlatego
 * synchronizacja woła ją pod bramą czasu — B2bCatalogSync::SHOP_FIELDS_TTL_DAYS. Łącznik, który dane ma już
 * w B2bRemoteProduct::$raw albo w pobranej stronie, nie powinien wysyłać niczego dodatkowego.
 */
interface B2bShopFieldSource
{
    /**
     * Wiersze karty wyrobu u dostawcy w kolejności ze źródła; [] gdy sklep nie podaje żadnych.
     *
     * @return list<B2bRemoteShopField>
     */
    public function shopFields(B2bRemoteProduct $product): array;
}

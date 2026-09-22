<?php

declare(strict_types=1);

namespace App\Support\RequirementCheck;

/**
 * Jeden fragment karty z nazwą pola, z którego pochodzi — każda wartość w porównaniu ma dać
 * się wskazać w źródle. Pozycje list (specs, features…) są osobnymi fragmentami, żeby cytat
 * nie sklejał sąsiednich parametrów („Długość: 19 cali” obok „Kolor: …”).
 */
final readonly class CardSource
{
    public const NAME = 'name';

    public const NORMS = 'norms';

    /** Norma z karty wyrobu u jego producenta — cytat od autora wyrobu, najmocniejszy przy normach. */
    public const MANUFACTURER = 'manufacturer';

    /** Parametry wypisane w kolumnach cennika dostawcy — cytat z dokumentu producenta. */
    public const PRICE_LIST = 'price_list';

    /** Parametr wpisany ręcznie na karcie — czego nie ma w żadnym źródle automatycznym. */
    public const MANUAL = 'manual';

    /**
     * Wiersz tabelki z karty wyrobu u dostawcy (products.shop_fields_summary) — dane sklepu, nie opis. Bywa
     * pomylona z sąsiednim wariantem (ARTRA: „ARMEN 900 6060 O1 FO” z tabelką „EN ISO 20345:2011 S1 P SRC”),
     * więc jako osobne pole pokazuje sprzeczność z nazwą zamiast ją rozstrzygać.
     */
    public const SHOP_FIELDS = 'shop_fields';

    public const SPECS = 'specs';

    public const FEATURES = 'features';

    public const PAYLOAD_NORMS = 'payload_norms';

    public const MATERIALS = 'materials';

    public const DESCRIPTION = 'description';

    public function __construct(
        public string $source,
        public string $text,
    ) {}

    /** Nazwy i tabelki dostawcy nie ma w tekście opisu okna weryfikacji — „Szukaj w opisie” nic by nie znalazło. */
    public function searchable(): bool
    {
        return $this->source !== self::NAME && $this->source !== self::SHOP_FIELDS;
    }
}

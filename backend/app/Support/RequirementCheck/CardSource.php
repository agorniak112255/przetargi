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

    /** Parametry wypisane w kolumnach cennika dostawcy — cytat z dokumentu producenta. */
    public const PRICE_LIST = 'price_list';

    /** Parametr wpisany ręcznie na karcie — czego nie ma w żadnym źródle automatycznym. */
    public const MANUAL = 'manual';

    public const SPECS = 'specs';

    public const FEATURES = 'features';

    public const PAYLOAD_NORMS = 'payload_norms';

    public const MATERIALS = 'materials';

    public const DESCRIPTION = 'description';

    public function __construct(
        public string $source,
        public string $text,
    ) {}

    /** Nazwy nie ma w tekście opisu okna weryfikacji — kliknięcie „Szukaj w opisie” nic by nie znalazło. */
    public function searchable(): bool
    {
        return $this->source !== self::NAME;
    }
}
